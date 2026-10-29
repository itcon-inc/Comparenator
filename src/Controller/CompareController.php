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
 * Controller for rendering comparison pages.
 */
class CompareController extends ControllerBase {

  /**
   * Screenshot manager.
   *
   * @var \Drupal\comparenator\Service\ScreenshotManager
   */
  protected $screenshots;

  /**
   * Pixel diff generator.
   *
   * @var \Drupal\comparenator\Service\PixelDiffGenerator
   */
  protected $pixelDiff;

  /**
   * Environment manager.
   *
   * @var \Drupal\comparenator\Service\EnvironmentManager
   */
  protected $envManager;

  /**
   * Revision manager.
   *
   * @var \Drupal\comparenator\Service\RevisionManager
   */
  protected $revisionManager;

  /**
   * Request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructor.
   */
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

  /**
   * {@inheritdoc}
   */
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
    $mode = $request->query->get('mode', 'onion'); // onion, side, diff.

    // Load reference & current screenshot URLs.
    $reference_url = $this->screenshots->getEnvironmentScreenshotUrl($reference, $identifier, $size);
    $current_url = $this->screenshots->getEnvironmentScreenshotUrl($current, $identifier, $size);

    if (!$reference_url || !$current_url) {
      return [
        '#markup' => $this->t('One or more screenshots are missing.'),
      ];
    }

    switch ($mode) {
      case 'side':
        return $this->renderSideBySide($reference_url, $current_url);

      case 'diff':
        $diff_url = $this->pixelDiff->generateDiff($reference_url, $current_url, $identifier, $size);
        return $this->renderDiff($diff_url);

      case 'onion':
      default:
        return $this->renderOnionSkin($reference_url, $current_url);
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

    if (!$ref_url || !$cur_url) {
      return [
        '#markup' => $this->t('Revision screenshots are missing.'),
      ];
    }

    switch ($mode) {
      case 'side':
        return $this->renderSideBySide($ref_url, $cur_url);

      case 'diff':
        $identifier = "node-{$node}-{$revA}-{$revB}";
        $diff_url = $this->pixelDiff->generateDiff($ref_url, $cur_url, $identifier, $size);
        return $this->renderDiff($diff_url);

      case 'onion':
      default:
        return $this->renderOnionSkin($ref_url, $cur_url);
    }
  }

  /**
   * Render onion skin comparison.
   */
  protected function renderOnionSkin($ref_url, $cur_url) {
    return [
      '#theme' => 'comparenator_onion',
      '#reference' => [
        '#theme' => 'image',
        '#uri' => $ref_url,
        '#attributes' => [
          'class' => ['layer', 'layer-bottom'],
        ],
      ],
      '#current' => [
        '#theme' => 'image',
        '#uri' => $cur_url,
        '#attributes' => [
          'class' => ['layer', 'layer-top'],
          'id' => 'comparanator-top',
        ],
      ],
      '#attached' => [
        'library' => ['comparenator/viewer'],
      ],
    ];
  }

  /**
   * Render side-by-side view.
   */
  protected function renderSideBySide($ref_url, $cur_url) {
    return [
      '#theme' => 'comparenator_side_by_side',
      '#reference' => [
        '#theme' => 'image',
        '#uri' => $ref_url,
      ],
      '#current' => [
        '#theme' => 'image',
        '#uri' => $cur_url,
      ],
      '#attached' => [
        'library' => ['comparenator/viewer'],
      ],
    ];
  }

  /**
   * Render pixel diff view.
   */
  protected function renderDiff($diff_url) {
    return [
      '#theme' => 'comparenator_diff',
      '#diff' => [
        '#theme' => 'image',
        '#uri' => $diff_url,
      ],
      '#attached' => [
        'library' => ['comparenator/viewer'],
      ],
    ];
  }

}
