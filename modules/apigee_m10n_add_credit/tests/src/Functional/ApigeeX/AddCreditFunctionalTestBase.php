<?php

/*
 * Copyright 2021 Google Inc.
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

namespace Drupal\Tests\apigee_m10n_add_credit\Functional\ApigeeX;

use Apigee\Edge\Api\Monetization\Entity\Developer;
use Apigee\Edge\Api\Monetization\Entity\SupportedCurrency;
use Drupal\apigee_edge\Job\Job;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Tests\apigee_m10n\Functional\ApigeeX\MonetizationFunctionalTestBase;
use Drupal\Tests\apigee_m10n_add_credit\Traits\ApigeeX\AddCreditFunctionalTestTrait;
use Drupal\user\UserInterface;

/**
 * Base class for monetization add credit functional tests.
 */
class AddCreditFunctionalTestBase extends MonetizationFunctionalTestBase {

  use AddCreditFunctionalTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // Base modules.
    'key',
    'file',
    'apigee_edge',
    'apigee_m10n',
    'apigee_mock_api_client',
    'system',
    // Modules for this test.
    'apigee_m10n_add_credit',
    'commerce_order',
    'commerce_price',
    'commerce_cart',
    'commerce_checkout',
    'commerce_product',
    'commerce_payment_test',
    'commerce_store',
    'commerce',
    'user',
    'field_ui',
  ];

  /**
   * Helper to checkout a product for a developer.
   *
   * @param \Drupal\user\UserInterface $developer
   *   The user entity.
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product entity.
   * @param string $amount
   *   The amount in the cart.
   *
   * @throws \Behat\Mink\Exception\ElementTextException
   * @throws \Twig_Error_Loader
   * @throws \Twig_Error_Runtime
   * @throws \Twig_Error_Syntax
   */
  protected function checkout(UserInterface $developer, ProductInterface $product, string $amount) {
    $this->drupalGet('cart');
    $this->submitForm([], 'Checkout');
    $this->assertCssElementContains('h1.page-title', 'Order information');

    // Submit payment information.
    $this->submitForm([
      'payment_information[add_payment_method][payment_details][expiration][year]' => (string) (date("Y") + 1),
      'payment_information[add_payment_method][payment_details][security_code]' => '123',
      'payment_information[add_payment_method][billing_information][address][0][address][given_name]' => $developer->first_name->value,
      'payment_information[add_payment_method][billing_information][address][0][address][family_name]' => $developer->last_name->value,
      'payment_information[add_payment_method][billing_information][address][0][address][address_line1]' => '1600 Amphitheatre Parkway',
      'payment_information[add_payment_method][billing_information][address][0][address][locality]' => 'Mountain View',
      'payment_information[add_payment_method][billing_information][address][0][address][administrative_area]' => 'CA',
      'payment_information[add_payment_method][billing_information][address][0][address][postal_code]' => '94043',
    ], 'Continue to review');
    $this->assertCssElementContains('h1.page-title', 'Review');
    $this->assertCssElementContains('.checkout-order-summary', $product->label());
    $this->assertCssElementContains('.checkout-order-summary', "Total $$amount");

    // Before finalizing the payment, we have to add a couple of responses to
    // the queue.

    $this->warmApigeexOrganizationCache();

    $this->stack
      ->queueMockResponse([
        'get-supported-currencies' => [
          'currencies' => [
            new SupportedCurrency([
              "description" => "United States Dollars",
              "displayName" => "United States Dollars",
              "id" => "usd",
              "minimumTopupAmount" => 11.0000,
              "name" => "USD",
              "status" => "ACTIVE",
              "virtualCurrency" => FALSE,
            ]),
          ],
        ],
      ])
      ->queueMockResponse([
        'post-apigeex-prepaid-balance',
      ])
      ->queueMockResponse([
        'get-apigeex-prepaid-balances' => [
          "current_units_usd" => $amount,
          "current_nano_usd" => "000000000",
        ],
      ]);
    $this->queueApigeexDeveloperResponse($developer);

    // Finalize the payment.
    $this->submitForm([], 'Pay and complete purchase');

    $this->assertCssElementContains('h1.page-title', 'Complete');
    $this->assertCssElementContains('div.checkout-complete', 'Your order number is 1.');
    $this->assertCssElementContains('div.checkout-complete', 'You can view your order on your account page when logged in.');

    // The new balance will be re-read so queue the response.
    $this->stack->queueMockResponse([
      'get-apigeex-prepaid-balances' => [
        "current_units_usd" => $amount,
        "current_nano_usd" => "000000000",
      ],
    ]);
    $new_balance = \Drupal::service('apigee_m10n.sdk_controller_factory')
      ->developerBalancexController($developer);

    static::assertSame((double) $amount, $new_balance->getAmount());
  }

}
