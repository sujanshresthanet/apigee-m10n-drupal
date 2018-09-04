<?php
/**
 * Copyright 2018 Google Inc.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * version 2 as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 * MA 02110-1301, USA.
 */

namespace Drupal\apigee_m10n_test;

use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Class ApigeeM10nTestServiceProvide
 *
 * This class is automatically picked up by the container builder.
 * See: https://www.drupal.org/docs/8/api/services-and-dependency-injection/altering-existing-services-providing-dynamic-services
 *
 * @package Drupal\apigee_m10n_test
 */
class ApigeeM10nTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    // Override the ClientFactory with our mock client factory.
    $container->getDefinition('apigee_edge.sdk_connector')
      ->replaceArgument(0, new Reference('apigee_m10n_test.mock_http_client_factory'));
    // This middleware will block outgoing requests from KernelTestBase so we remove it.
    // See: https://www.drupal.org/project/drupal/issues/2571475.
    $container->removeDefinition('test.http_client.middleware');
  }
}
