<?php

namespace Drupal\comparenator\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ComparenatorGlobalLocalTask extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;

  protected $routeProvider;

  public function __construct(RouteProviderInterface $route_provider) {
    $this->routeProvider = $route_provider;
  }

  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('router.route_provider')
    );
  }

  /**
   * Generate a Comparenator tab for every route that can display a page.
   */
  public function getDerivativeDefinitions($base_plugin_definition) {

    $routes = $this->routeProvider->getAllRoutes();

    foreach ($routes as $route_name => $route) {

      // Must have controller or form to be a page.
      if (
        !$route->hasDefault('_controller') &&
        !$route->hasDefault('_form')
      ) {
        continue;
      }

      // Ensure the route actually renders HTML. 
      if ($route->getDefault('_format') === 'json') {
        continue;
      }

      // Some system routes that cannot support tabs must be skipped.
      if (in_array($route_name, [
        '_front',
        'system.403',
        'system.404',
      ])) {
        continue;
      }

      $this->derivatives[$route_name] = $base_plugin_definition + [
        'title'      => $this->t('Comparenator'),
        'route_name' => 'comparenator.page_compare',
        'base_route' => $route_name,
        'weight'     => 100,
      ];
    }

    return $this->derivatives;
  }
}
