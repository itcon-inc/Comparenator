<?php

namespace Drupal\comparenator\Service;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds a permission-aware comparison link for the current page.
 */
class PageLinkBuilder {

  public function __construct(
    protected RequestStack $requestStack,
    protected ConfigFactoryInterface $configFactory,
    protected AccountProxyInterface $currentUser,
    protected TranslationInterface $translation,
  ) {}

  /**
   * Builds a link independent of the Toolbar module and JavaScript.
   */
  public function build(): array {
    $config = $this->configFactory->get('comparenator.settings');
    $build = [
      '#access' => $this->currentUser->hasPermission('view environment comparisons'),
    ];
    CacheableMetadata::createFromObject($config)
      ->addCacheContexts(['url.path', 'url.query_args:path', 'user.permissions'])
      ->applyTo($build);

    if (!$build['#access']) {
      return $build;
    }

    // Exclude the installation base path and query string.
    $request = $this->requestStack->getMainRequest();
    $path = $request->getPathInfo();
    if (str_starts_with($path, '/comparenator/')) {
      $path = $request->query->get('path', '/');
    }
    // Hash the full path to avoid collisions between aliases containing "_".
    $identifier = $path === '/' ? 'frontpage' : hash('sha256', $path);
    $sizes = $config->get('screen_sizes') ?? [];
    $size = isset($sizes['laptop']) ? 'laptop' : array_key_first($sizes);
    $url = $size === NULL
      ? Url::fromRoute('comparenator.page_compare')
      : Url::fromRoute('comparenator.environment_compare', [
        'identifier' => $identifier,
        'size' => $size,
        'reference' => 'prod',
        'current' => 'local',
      ], ['query' => ['mode' => 'onion', 'path' => $path]]);

    return $build + [
      '#type' => 'link',
      '#title' => $this->translation->translate('Comparenator (opens in a new tab)'),
      '#url' => $url,
      '#attributes' => [
        'class' => ['comparenator-page-link'],
        'target' => '_blank',
        'rel' => 'noopener noreferrer',
      ],
      '#attached' => ['library' => ['comparenator/page_link']],
    ];
  }

}

