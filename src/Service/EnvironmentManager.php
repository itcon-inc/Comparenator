<?php

namespace Drupal\comparenator\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Provides environment URL configuration and utilities.
 */
class EnvironmentManager {

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructor.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Returns the reference environment URL.
   */
  public function getReferenceUrl() {
    $config = $this->configFactory->get('comparenator.settings');
    return rtrim($config->get('reference_site_url'), '/');
  }

  /**
   * Normalize environment identifiers (prod / uat / local).
   *
   * Since you said the exact variants do not matter,
   * this function just lowers the string and strips symbols.
   */
  public function normalizeEnv($env) {
    $env = strtolower(trim($env));
    return preg_replace('/[^a-z0-9\-]+/', '', $env);
  }

  /**
   * Map an arbitrary URL or keyword to an environment name.
   *
   * Useful if auto-detection is needed in the future.
   */
  public function getEnvironmentKeyFromUrl($url) {
    $host = parse_url($url, PHP_URL_HOST);

    if (strpos($host, 'prod') !== FALSE) {
      return 'prod';
    }
    if (strpos($host, 'uat') !== FALSE) {
      return 'uat';
    }
    if (strpos($host, 'local') !== FALSE || $host === 'localhost') {
      return 'local';
    }

    // Fallback—use the full hostname normalized.
    return $this->normalizeEnv($host);
  }

}
