<?php

/*
 * Copyright 2018 Google Inc.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License version 2 as published by the
 * Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public
 * License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc., 51
 * Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 */

namespace Drupal\Tests\apigee_m10n\Traits;

use Apigee\Edge\Api\Management\Entity\DeveloperInterface;
use Apigee\Edge\Api\Monetization\Controller\OrganizationProfileController;
use Apigee\Edge\Api\Monetization\Controller\SupportedCurrencyController;
use Apigee\Edge\Api\Monetization\Entity\ApiPackage;
use Apigee\Edge\Api\Monetization\Entity\ApiProduct as MonetizationApiProduct;
use Apigee\Edge\Api\Monetization\Entity\Developer;
use Apigee\Edge\Api\Monetization\Entity\DeveloperRatePlan;
use Apigee\Edge\Api\Monetization\Entity\Property\FreemiumPropertiesInterface;
use Apigee\Edge\Api\Monetization\Structure\RatePlanDetail;
use Apigee\Edge\Api\Monetization\Structure\RatePlanRateRateCard;
use Behat\Mink\Exception\UnsupportedDriverActionException;
use Drupal\apigee_edge\Entity\ApiProduct;
use Drupal\apigee_m10n\Entity\ProductBundle;
use Drupal\apigee_m10n\Entity\ProductBundleInterface;
use Drupal\apigee_edge\Entity\Developer as EdgeDeveloper;
use Drupal\apigee_edge\UserDeveloperConverterInterface;
use Drupal\apigee_edge\Plugin\EdgeKeyTypeInterface;
use Drupal\apigee_m10n\Entity\Package;
use Drupal\apigee_m10n\Entity\PackageInterface;
use Drupal\apigee_m10n\Entity\RatePlan;
use Drupal\apigee_m10n\Entity\RatePlanInterface;
use Drupal\apigee_m10n\Entity\PurchasedPlan;
use Drupal\apigee_m10n\Entity\PurchasedPlanInterface;
use Drupal\apigee_m10n\EnvironmentVariable;
use Drupal\apigee_m10n_test\Plugin\KeyProvider\TestEnvironmentVariablesKeyProvider;
use Drupal\key\Entity\Key;
use Drupal\Tests\apigee_edge\Traits\ApigeeEdgeFunctionalTestTrait;
use Drupal\Tests\apigee_mock_api_client\Traits\ApigeeMockApiClientHelperTrait;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Setup helpers for monetization tests.
 */
trait ApigeeMonetizationTestTrait {

  use AccountProphecyTrait;
  use ApigeeEdgeFunctionalTestTrait {
    createAccount as edgeCreateAccount;
  }
  use ApigeeMockApiClientHelperTrait {
    ApigeeEdgeFunctionalTestTrait::createDeveloperApp insteadof ApigeeMockApiClientHelperTrait;
  }

  /**
   * The SDK Connector client.
   *
   * This will have it's http client stack replaced a mock stack.
   * mock.
   *
   * @var \Drupal\apigee_edge\SDKConnectorInterface
   */
  protected $sdk_connector;

  /**
   * The SDK controller factory.
   *
   * @var \Drupal\apigee_m10n\ApigeeSdkControllerFactoryInterface
   */
  protected $controller_factory;

  /**
   * The clean up queue.
   *
   * @var array
   *   An associative array with a `callback` and a `weight` key. Some items
   *   will need to be called before others which is the reason for the weight
   *   system.
   */
  protected $cleanup_queue;

  /**
   * The default org timezone.
   *
   * @var string
   */
  protected $org_default_timezone = 'America/Los_Angeles';

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function setUp(): void {
    // Skipping the test if instance type is Hybrid.
    $instance_type = getenv('APIGEE_EDGE_INSTANCE_TYPE');
    if (!empty($instance_type) && $instance_type === EdgeKeyTypeInterface::INSTANCE_TYPE_HYBRID) {
      $this->markTestSkipped('This test suite is expecting a PUBLIC instance type.');
    }
    $this->apigeeTestHelperSetup();
    $this->sdk_connector = $this->sdkConnector;

    // `::initAuth` in above has to happen before getting the controller factory.
    $this->controller_factory = $this->container->get('apigee_m10n.sdk_controller_factory');
  }

  /**
   * Create an account.
   *
   * We override this function from `ApigeeEdgeFunctionalTestTrait` so we can queue the
   * appropriate response upon account creation.
   *
   * {@inheritdoc}
   */
  protected function createAccount(array $permissions = [], bool $status = TRUE, string $prefix = '', $attributes = []): ?UserInterface {
    $rid = NULL;
    $this->warmOrganizationCache();
    if ($permissions) {
      $rid = $this->createRole($permissions);
      $this->assertNotEmpty($rid, 'Role created');
    }

    $edit = [
      'first_name' => $this->randomMachineName(),
      'last_name' => $this->randomMachineName(),
      'name' => $this->randomMachineName(),
      'pass' => \Drupal::service('password_generator')->generate(),
      'status' => $status,
    ];
    if ($rid) {
      $edit['roles'][] = $rid;
    }
    if ($prefix) {
      $edit['mail'] = "{$prefix}.{$edit['name']}@example.com";
    }
    else {
      $edit['mail'] = "{$edit['name']}@example.com";
    }

    $account = User::create($edit);

    $billing_type = empty($attributes['billing_type']) ? NULL : $attributes['billing_type'];

    // Queue up a created response.
    $this->queueDeveloperResponse($account, 201, $billing_type);

    // Save the user.
    $account->save();

    $this->assertNotEmpty($account->id());
    if (!$account->id()) {
      return NULL;
    }

    // This is here to make drupalLogin() work.
    $account->passRaw = $edit['pass'];

    // Assume the account has no purchased plans initially.
    $this->warmPurchasedPlanCache($account);

    $this->cleanup_queue[] = [
      'weight' => 99,
      // Prepare for deleting the developer.
      'callback' => function () use ($account, $billing_type) {
        try {
          // Delete it.
          $account->delete();
        }
        catch (\Exception $e) {
          // We only care about deleting from Edge, do nothing if exception
          // gets thrown if it couldn't delete remotely.
        }
        catch (\Error $e) {
          // We only care about deleting from Edge, do nothing if exception
          // gets thrown if it couldn't delete remotely.
        }
      },
    ];

    return $account;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Exception
   */
  protected function createProduct(): MonetizationApiProduct {
    /** @var \Drupal\apigee_edge\Entity\ApiProduct $product */
    $product = ApiProduct::create([
      'id'            => strtolower($this->randomMachineName()),
      'name'          => $this->randomMachineName(),
      'description'   => $this->getRandomGenerator()->sentences(3),
      'displayName'   => $this->getRandomGenerator()->word(16),
      'approvalType'  => ApiProduct::APPROVAL_TYPE_AUTO,
    ]);
    // Need to queue the management spi product.
    $this->stack->queueMockResponse(['api_product' => ['product' => $product]]);
    $product->save();
    // Warm the entity static cache.
    \Drupal::service('entity.memory_cache')->set("values:api_product:{$product->id()}", $product);

    // Remove the product in the cleanup queue.
    $this->cleanup_queue[] = [
      'weight' => 20,
      'callback' => function () use ($product) {
        $this->stack->queueMockResponse(['api_product' => ['product' => $product]]);
        $product->delete();
      },
    ];

    // Queue another response for the entity load.
    $this->stack->queueMockResponse(['api_product_mint' => ['product' => $product]]);
    $controller = $this->controller_factory->apiProductController();

    return $controller->load($product->getName());
  }

  /**
   * Create a product bundle.
   *
   * @throws \Exception
   */
  protected function createProductBundle(): ProductBundleInterface {
    $products = [];

    for ($i = rand(1, 4); $i > 0; $i--) {
      $products[] = $this->createProduct();
    }

    $api_package = new ApiPackage([
      'id'          => $this->randomMachineName(),
      'name'        => $this->randomMachineName(),
      'description' => $this->getRandomGenerator()->sentences(3),
      'displayName' => $this->getRandomGenerator()->word(16),
      'apiProducts' => $products,
      // CREATED, ACTIVE, INACTIVE.
      'status'      => 'CREATED',
    ]);

    $this->stack->queueMockResponse(['package' => ['package' => $api_package]]);
    // Load the product_bundle drupal entity and warm the entity cache.
    $product_bundle = ProductBundle::load($api_package->id());
    // Remove the product bundle in the cleanup queue.
    $this->cleanup_queue[] = [
      'weight' => 10,
      'callback' => function () use ($product_bundle) {
        $this->stack
          ->queueMockResponse(['get_monetization_package' => ['package' => $product_bundle]]);
        $product_bundle->delete();
      },
    ];

    return $product_bundle;
  }

  /**
   * Create a rate plan for a given product bundle.
   *
   * @param \Drupal\apigee_m10n\Entity\ProductBundleInterface $product_bundle
   *   The rate plan product bundle.
   * @param string $type
   *   The type of plan.
   * @param string $id
   *   The rate plan Id. It not set it will randomly generated.
   * @param array $properties
   *   Optional properties to set on the decorated object.
   *
   * @return \Drupal\apigee_m10n\Entity\RatePlanInterface
   *   A rate plan entity.
   *
   * @throws \Exception
   */
  protected function createRatePlan(ProductBundleInterface $product_bundle, $type = RatePlanInterface::TYPE_STANDARD, string $id = NULL, array $properties = []): RatePlanInterface {
    $client = $this->sdk_connector->getClient();
    $org_name = $this->sdk_connector->getOrganization();

    // Load the org profile.
    $org_controller = new OrganizationProfileController($org_name, $client);
    $this->stack->queueMockResponse('get_organization_profile');
    $org = $org_controller->load();

    // The usd currency should be available by default.
    $currency_controller = new SupportedCurrencyController($org_name, $this->sdk_connector->getClient());
    $this->stack->queueMockResponse('get_supported_currency');
    $currency = $currency_controller->load('usd');

    $rate_plan_rate = new RatePlanRateRateCard([
      'id'        => strtolower($this->randomMachineName()),
      'rate'      => rand(5, 20),
    ]);
    $rate_plan_rate->setStartUnit(1);

    $start_date = new \DateTimeImmutable('2018-07-26 00:00:00', new \DateTimeZone($this->org_default_timezone));
    $end_date = new \DateTimeImmutable('today +1 year', new \DateTimeZone($this->org_default_timezone));
    $properties += [
      'advance'               => TRUE,
      'customPaymentTerm'     => TRUE,
      'description'           => $this->getRandomGenerator()->sentences(3),
      'displayName'           => $this->getRandomGenerator()->word(16),
      'earlyTerminationFee'   => '2.0000',
      'endDate'               => $end_date,
      'frequencyDuration'     => 1,
      'frequencyDurationType' => FreemiumPropertiesInterface::FREEMIUM_DURATION_MONTH,
      'freemiumUnit'          => 1,
      'id'                    => $id ?: strtolower($this->randomMachineName()),
      'isPrivate'             => 'false',
      'name'                  => $this->randomMachineName(),
      'paymentDueDays'        => '30',
      'prorate'               => FALSE,
      'published'             => TRUE,
      'ratePlanDetails'       => [
        new RatePlanDetail([
          "aggregateFreemiumCounters" => TRUE,
          "aggregateStandardCounters" => TRUE,
          "aggregateTransactions"     => TRUE,
          'currency'                  => $currency,
          "customPaymentTerm"         => TRUE,
          "duration"                  => 1,
          "durationType"              => "MONTH",
          "freemiumDuration"          => 1,
          "freemiumDurationType"      => "MONTH",
          "freemiumUnit"              => 110,
          "id"                        => strtolower($this->randomMachineName(16)),
          "meteringType"              => "UNIT",
          'org'                       => $org,
          "paymentDueDays"            => "30",
          'ratePlanRates'             => [$rate_plan_rate],
          "ratingParameter"           => "VOLUME",
          "type"                      => "RATECARD",
        ]),
      ],
      'recurringFee'          => '3.0000',
      'recurringStartUnit'    => '1',
      'recurringType'         => 'CALENDAR',
      'setUpFee'              => '1.0000',
      'startDate'             => $start_date,
      'type'                  => $type,
      'organization'          => $org,
      'currency'              => $currency,
      'package'               => $product_bundle->decorated(),
      'purchase'             => [],
    ];

    switch ($type) {
      case RatePlanInterface::TYPE_DEVELOPER:
        $this->stack->queueMockResponse(['rate_plan' => ['plan' => $properties]]);
        $rate_plan = RatePlan::loadById($product_bundle->id(), $properties['id']);
        break;

      default:
        /** @var \Drupal\apigee_m10n\Entity\RatePlanInterface $rate_plan */
        $rate_plan = RatePlan::create($properties);
        $this->stack->queueMockResponse(['rate_plan' => ['plan' => $rate_plan]]);
        $rate_plan->save();

        // Warm the cache.
        $this->stack->queueMockResponse(['rate_plan' => ['plan' => $rate_plan]]);
        $rate_plan = RatePlan::loadById($product_bundle->id(), $rate_plan->id());

        // Warm the future plan cache.
        $this->stack->queueMockResponse(['get_monetization_package_plans' => ['plans' => [$rate_plan]]]);
        $rate_plan->getFuturePlanStartDate();

        // Make sure the dates loaded the same as they were originally set.
        static::assertEquals($start_date, $rate_plan->getStartDate());
        static::assertEquals($end_date, $rate_plan->getEndDate());
    }

    // Remove the rate plan in the cleanup queue.
    $this->cleanup_queue[] = [
      'weight' => 9,
      'callback' => function () use ($rate_plan) {
        $this->stack->queueMockResponse('no_content');
        $rate_plan->delete();
      },
    ];

    return $rate_plan;
  }

  /**
   * Creates a purchased plan.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user to purchase the rate plan.
   * @param \Drupal\apigee_m10n\Entity\RatePlanInterface $rate_plan
   *   The rate plan to purchase.
   *
   * @return \Drupal\apigee_m10n\Entity\PurchasedPlanInterface
   *   The purchased plan.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Twig_Error_Loader
   * @throws \Twig_Error_Runtime
   * @throws \Twig_Error_Syntax
   */
  protected function createPurchasedPlan(UserInterface $user, RatePlanInterface $rate_plan): PurchasedPlanInterface {
    $start_date = new \DateTimeImmutable('today', new \DateTimeZone($this->org_default_timezone));
    $purchased_plan = PurchasedPlan::create([
      'ratePlan' => $rate_plan,
      'developer' => new Developer([
        'email' => $user->getEmail(),
        'name' => $user->getDisplayName(),
      ]),
      'startDate' => $start_date,
    ]);

    $this->stack->queueMockResponse(['purchased_plan' => ['purchased_plan' => $purchased_plan]]);
    $purchased_plan->save();

    // Warm the cache for this purchased_plan.
    $purchased_plan->set('id', $this->getRandomUniqueId());
    $this->stack->queueMockResponse(['purchased_plan' => ['purchased_plan' => $purchased_plan]]);
    $purchased_plan = PurchasedPlan::load($purchased_plan->id());

    // Make sure the start date is unchanged while loading.
    static::assertEquals($start_date, $purchased_plan->decorated()->getStartDate());

    // The purchased_plan controller does not have a delete operation so there is
    // nothing to add to the cleanup queue.
    return $purchased_plan;
  }

  /**
   * Populates the purchased plan cache for a user.
   *
   * Use this for tests that fetch purchased plans.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   *
   * @see \Drupal\apigee_m10n\Monetization::isDeveloperAlreadySubscribed()
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Twig_Error_Loader
   * @throws \Twig_Error_Runtime
   * @throws \Twig_Error_Syntax
   */
  protected function warmPurchasedPlanCache(UserInterface $user): void {
    \Drupal::cache()->set("apigee_m10n:dev:purchased_plans:{$user->getEmail()}", []);
  }

  /**
   * Queues up a mock developer response.
   *
   * @param \Drupal\user\UserInterface $developer
   *   The developer user to get properties from.
   * @param string|null $response_code
   *   Add a response code to override the default.
   * @param string|null $billing_type
   *   The developer billing type.
   */
  protected function queueDeveloperResponse(UserInterface $developer, $response_code = NULL, $billing_type = NULL) {
    $context = empty($response_code) ? [] : ['status_code' => $response_code];

    $context['developer'] = $developer;
    $context['org_name'] = $this->sdk_connector->getOrganization();

    if ($billing_type) {
      $context['billing_type'] = $billing_type;
    }

    $this->stack->queueMockResponse(['get_developer_mint' => $context]);
  }

  /**
   * Helper function to queue up an org response since every test will need it,.
   *
   * @param bool $monetized
   *   Whether or not the org is monetized.
   *
   * @throws \Exception
   */
  protected function warmOrganizationCache($monetized = TRUE) {
    $this->stack
      ->queueMockResponse([
        'get_organization' => [
          'monetization_enabled' => $monetized ? 'true' : 'false',
          'timezone' => $this->org_default_timezone,
        ],
      ]);
    \Drupal::service('apigee_m10n.monetization')->getOrganization();
  }

  /**
   * Helper function to queue up a product packages and plan.
   */
  protected function queuePackagesAndPlansResponse() {
    $integration_enabled = !empty(getenv(EnvironmentVariable::APIGEE_INTEGRATION_ENABLE));
    if ($integration_enabled) {
      return;
    }

    // Set up product bundles and plans for the developer.
    $rate_plans = [];
    /** @var \Drupal\apigee_m10n\Entity\ProductBundleInterface[] $product_bundles */
    $product_bundles = [
      $this->createProductBundle(),
      $this->createProductBundle(),
    ];

    // Some of the entities referenced will be loaded from the API unless we
    // warm the static cache with them.
    $entity_static_cache = \Drupal::service('entity.memory_cache');

    // Create a random number of rate plans for each product bundle.
    foreach ($product_bundles as $product_bundle) {
      // Warm the static cache for each product bundle.
      $entity_static_cache->set("values:product_bundle:{$product_bundle->id()}", $product_bundle);
      // Warm the static cache for each product bundle product.
      foreach ($product_bundle->decorated()->getApiProducts() as $product) {
        $entity_static_cache->set("values:api_product:{$product->id()}", ApiProduct::create([
          'id' => $product->id(),
          'name' => $product->getName(),
          'displayName' => $product->getDisplayName(),
          'description' => $product->getDescription(),
        ]));
      }
      $rate_plans[$product_bundle->id()] = [];
      for ($i = rand(1, 3); $i > 0; $i--) {
        $rate_plans[$product_bundle->id()][] = $this->createRatePlan($product_bundle);
      }
    }

    // Queue the product bundle response.
    $this->stack->queueMockResponse(['get_monetization_packages' => ['packages' => $product_bundles]]);
    foreach ($rate_plans as $product_bundle_id => $plans) {
      $this->stack->queueMockResponse(['get_monetization_package_plans' => ['plans' => $plans]]);
    }
  }

  /**
   * Helper for testing element text matches by css selector.
   *
   * @param string $selector
   *   The css selector.
   * @param string $text
   *   The test to look for.
   *
   * @throws \Behat\Mink\Exception\ElementTextException
   */
  protected function assertCssElementText($selector, $text) {
    static::assertSame(
      $this->getSession()->getPage()->find('css', $selector)->getText(),
      $text
    );
  }

  /**
   * Helper for testing element text by css selector.
   *
   * @param string $selector
   *   The css selector.
   * @param string $text
   *   The test to look for.
   *
   * @throws \Behat\Mink\Exception\ElementTextException
   */
  protected function assertCssElementContains($selector, $text) {
    $this->assertSession()->elementTextContains('css', $selector, $text);
  }

  /**
   * Helper for testing the lack of element text by css selector.
   *
   * @param string $selector
   *   The css selector.
   * @param string $text
   *   The test to look for.
   *
   * @throws \Behat\Mink\Exception\ElementTextException
   */
  protected function assertCssElementNotContains($selector, $text) {
    $this->assertSession()->elementTextNotContains('css', $selector, $text);
  }

  /**
   * Makes sure no HTTP Client exceptions have been logged.
   */
  public function assertNoClientError() {
    $exceptions = $this->sdk_connector->getClient()->getJournal()->getLastException();
    static::assertEmpty(
      $exceptions,
      'A HTTP error has been logged in the Journal.'
    );
  }

  /**
   * Performs cleanup tasks after each individual test method has been run.
   */
  protected function tearDown(): void {
    if (!empty($this->cleanup_queue)) {
      $errors = [];
      // Sort all callbacks by weight. Lower weights will be executed first.
      usort($this->cleanup_queue, function ($a, $b) {
        return ($a['weight'] === $b['weight']) ? 0 : (($a['weight'] < $b['weight']) ? -1 : 1);
      });
      // Loop through the queue and execute callbacks.
      foreach ($this->cleanup_queue as $claim) {
        try {
          $claim['callback']();
        }
        catch (\Exception $ex) {
          $errors[] = $ex;
        }
      }

      parent::tearDown();

      if (!empty($errors)) {
        throw new \Exception('Errors found while processing the cleanup queue', 0, reset($errors));
      }
    }
  }

  /**
   * Helper to current response code equals to provided one.
   *
   * @param int $code
   *   The expected status code.
   */
  protected function assertStatusCodeEquals($code) {
    $this->checkDriverHeaderSupport();

    $this->assertSession()->statusCodeEquals($code);
  }

  /**
   * Helper to check headers.
   *
   * @param mixed $expected
   *   The expected header.
   * @param mixed $actual
   *   The actual header.
   * @param string $message
   *   The message.
   */
  protected function assertHeaderEquals($expected, $actual, $message = '') {
    $this->checkDriverHeaderSupport();

    $this->assertEquals($expected, $actual, $message);
  }

  /**
   * Checks if the driver supports headers.
   */
  protected function checkDriverHeaderSupport() {
    try {
      $this->getSession()->getResponseHeaders();
    }
    catch (UnsupportedDriverActionException $exception) {
      $this->markTestSkipped($exception->getMessage());
    }
  }

  /**
   * Warm the terms and services cache.
   */
  protected function warmTnsCache() {
    $this->stack->queueMockResponse([
      'get_terms_conditions',
    ]);

    \Drupal::service('apigee_m10n.monetization')->getLatestTermsAndConditions();
  }

  /**
   * Warm the terms and services cache accepted cache for a developer.
   *
   * @param \Drupal\user\UserInterface $developer
   *   The developer.
   */
  protected function warmDeveloperTnsCache(UserInterface $developer) {
    $this->stack->queueMockResponse([
      'get_developer_terms_conditions',
    ]);

    \Drupal::service('apigee_m10n.monetization')
      ->isLatestTermsAndConditionAccepted($developer->getEmail());
  }

  /**
   * Convert a Drupal developer to a Apigee Edge developer.
   *
   * @param \Drupal\user\UserInterface $user
   *   The Drupal user.
   * @param array $attributes
   *   any attributes that should be added to the Apigee Edge developer.
   *
   * @return \Apigee\Edge\Api\Management\Entity\DeveloperInterface
   *   An edge developer.
   */
  public function convertUserToEdgeDeveloper(UserInterface $user, $attributes = []): DeveloperInterface {
    // Create a developer.
    $developer = EdgeDeveloper::create([]);
    // Synchronise values of base fields.
    foreach (UserDeveloperConverterInterface::DEVELOPER_PROP_USER_BASE_FIELD_MAP as $developer_prop => $base_field) {
      $setter = 'set' . ucfirst($developer_prop);
      $developer->{$setter}($user->get($base_field)->value);
    }
    // Set the developer attributes.
    foreach ($attributes as $name => $value) {
      $developer->setAttribute($name, $value);
    }

    return $developer;
  }

}
