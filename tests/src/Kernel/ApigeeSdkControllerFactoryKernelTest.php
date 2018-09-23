<?php
/**
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

namespace Drupal\Tests\apigee_m10n\Kernel;

use Apigee\Edge\Api\Monetization\Controller\DeveloperPrepaidBalanceControllerInterface;
use Drupal\apigee_m10n\ApigeeSdkControllerFactoryInterface;
use Drupal\user\UserInterface;

/**
 * Tests the `apigee_m10n.sdk_controller_factory` service.
 *
 * @package Drupal\Tests\apigee_m10n\Kernel
 *
 * @group apigee_m10n
 * @group apigee_m10n_kernel
 *
 * @coversDefaultClass \Drupal\apigee_m10n\ApigeeSdkControllerFactory
 */
class ApigeeSdkControllerFactoryKernelTest extends MonetizationKernelTestBase {


  /**
   * The controller factory.
   *
   * @var \Drupal\apigee_m10n\ApigeeSdkControllerFactoryInterface
   */
  protected $controller_factory;

  /**
   * {@inheritdoc}
   *
   * @throws \Exception
   */
  public function setUp() {
    parent::setUp();

    $this->controller_factory = $this->container->get('apigee_m10n.sdk_controller_factory');
    static::assertInstanceOf(ApigeeSdkControllerFactoryInterface::class, $this->controller_factory);
  }

  /**
   * Tests the developer balance controller.
   *
   * @covers ::developerBalanceController
   */
  public function testDeveloperBalanceController() {
    $email = $this->randomMachineName() . '@example.com';

    $account = $this
      ->getMockBuilder(UserInterface::class)
      ->disableOriginalConstructor()
      ->getMock();
    $account->expects($this->any())
      ->method('getEmail')
      ->will($this->returnValue($email));

    /** @var DeveloperPrepaidBalanceControllerInterface $controller */
    $controller  = $this->controller_factory->developerBalanceController($account);

    static::assertInstanceOf(DeveloperPrepaidBalanceControllerInterface::class, $controller);

    static::assertSame($this->sdk_connector->getOrganization(), $controller->getOrganisationName());
  }
}
