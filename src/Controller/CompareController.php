<?php

namespace Drupal\comparenator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\comparenator\Service\ScreenshotManager;
use Drupal\comparenator\Service\PixelDiffGenerator;
use Drupal\comparenator\Service\EnvironmentManager;
use Drupal\comparenator\Service\RevisionManager;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for rendering Comparenator comparison pages.
 */
class CompareController extends ControllerBase {

  protected $screenshots;
  protected $pixelDiff;
  protected $envManager;
  protected $revisionManager;
  protected $requestStack;

  public function __construct(
    ScreenshotManager $screenshots,
    PixelDiffGenerator $pixelDiff,
    EnvironmentManager $envManager,
    RevisionManager $revisionManager,
    RequestStack $request_stack
  ) {
    $this->screenshots = $screenshots;
    $this->pixelDiff = $pixelDiff;
    $this->envManager = $envManager;
    $this->revisionManager = $revisionManager;
    $this->requestStack = $request_stack;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('comparenator.screenshot_manager'),
      $container->get('comparenator.pixel_diff'),
      $container->get('comparenator.environment_manager'),
      $container->get('comparenator.revision_manager'),
      $container->get('request_stack')
    );
  }

  /**
   * Build environment comparison page.
   */
  public function envCompare($identifier, $size, $reference, $current) {
    $request = $this->requestStack->getCurrentRequest();
    $mode = $request->query->get('mode', 'onion');

    // Load screenshots.
    $reference_url = $this->screenshots->getEnvironmentScreenshotUrl($reference, $identifier, $size);
    $current_url = $this->screenshots->getEnvironmentScreenshotUrl($current, $identifier, $size);

    // Build UI toolbar.
    $toolbar = $this->buildToolbar($identifier, $size, $reference, $current);

    if (!$reference_url || !$current_url) {
      return [
        '#theme' => 'comparenator_missing',
        'toolbar' => $toolbar,
        '#message' => $this->t('One or more screenshots are missing for the selected environment or size.'),
      ];
    }

    switch ($mode) {
      case 'side':
        return $this->renderSideBySide($reference_url, $current_url, $toolbar);

      case 'diff':
        $diff_url = $this->pixelDiff->generateDiff($reference_url, $current_url, $identifier, $size);
        return $this->renderDiff($diff_url, $toolbar);

      case 'onion':
      default:
        return $this->renderOnionSkin($reference_url, $current_url, $toolbar);
    }
  }

  /**
   * Build revision comparison page.
   */
  public function revisionCompare($node, $revA, $revB, $size) {
    $request = $this->requestStack->getCurrentRequest();
    $mode = $request->query->get('mode', 'onion');

    $ref_url = $this->revisionManager->getRevisionScreenshotUrl($node, $revA, $size);
    $cur_url = $this->revisionManager->getRevisionScreenshotUrl($node, $revB, $size);

    $toolbar = $this->buildToolbar("node-$node", $size, $revA, $revB);
\Drupal::logger('compare')->notice('Toolbar created');
    if (!$ref_url || !$cur_url) {
      return [
        '#theme' => 'comparenator_missing',
        'toolbar' => $toolbar,
        '#message' => $this->t('Revision screenshots are missing.'),
      ];
    }

    switch ($mode) {
      case 'side':
        return $this->renderSideBySide($ref_url, $cur_url, $toolbar);

      case 'diff':
        $identifier = "node-{$node}-{$revA}-{$revB}";
        $diff_url = $this->pixelDiff->generateDiff($ref_url, $cur_url, $identifier, $size);
        return $this->renderDiff($diff_url, $toolbar);

      case 'onion':
      default:
        return $this->renderOnionSkin($ref_url, $cur_url, $toolbar);
    }
  }

  /**
   * Global Comparenator toolbar button click handler.
   * This page normally should not be visited directly.
   */
  public function pageCompare() {
    return [
      '#type' => 'inline_template',
      '#template' => '<p>{{ msg }}</p>',
      '#context' => [
        'msg' => $this->t('Use the Comparenator toolbar button to open comparisons.'),
      ],
    ];
  }

  /**
   * Build UI toolbar (mode selector + size selector).
   */
  protected function buildToolbar($identifier, $size, $ref, $cur) {
    $config = $this->config('comparenator.settings');
    $sizes = $config->get('screen_sizes') ?? [];

    $base_path = "/comparenator/env/$identifier";

    // Mode switching links.
    $mode_links = [
      'onion' => "$base_path/$size/$ref/$cur?mode=onion",
      'side'  => "$base_path/$size/$ref/$cur?mode=side",
      'diff'  => "$base_path/$size/$ref/$cur?mode=diff",
    ];

    // Screen size switching links.
    $size_links = [];
    foreach ($sizes as $size_key => $resolution) {
      $size_links[$size_key] = "$base_path/$size_key/$ref/$cur";
    }

    return [
      '#theme' => 'comparenator_toolbar',
      '#modes' => $mode_links,
      '#sizes' => $size_links,
    ];
  }

  /**
   * Render onion skin comparison.
   */
  protected function renderOnionSkin($ref_url, $cur_url, $toolbar) {
    return [
      '#theme' => 'comparenator_onion',
      '#reference' => [
        '#theme' => 'image',
        '#uri' => $ref_url,
        '#attributes' => ['class' => ['layer', 'layer-bottom']],
      ],
      '#current' => [
        '#theme' => 'image',
        '#uri' => $cur_url,
        '#attributes' => ['class' => ['layer', 'layer-top'], 'id' => 'comparanator-top'],
      ],
      'toolbar' => $toolbar,
      '#attached' => [
        'library' => ['comparenator/viewer'],
      ],
    ];
  }

  /**
   * Render side-by-side comparison.
   */
  protected function renderSideBySide($ref_url, $cur_url, $toolbar) {
    return [
      '#theme' => 'comparenator_side_by_side',
      '#reference' => ['#theme' => 'image', '#uri' => $ref_url],
      '#current' => ['#theme' => 'image', '#uri' => $cur_url],
      'toolbar' => $toolbar,
      '#attached' => [
        'library' => ['comparenator/viewer'],
      ],
    ];
  }

  /**
   * Render pixel diff comparison.
   */
  protected function renderDiff($diff_url, $toolbar) {
    return [
      '#theme' => 'comparenator_diff',
      '#diff' => ['#theme' => 'image', '#uri' => $diff_url],
      'toolbar' => $toolbar,
      '#attached' => [
        'library' => ['comparenator/viewer'],
      ],
    ];
  }

}
