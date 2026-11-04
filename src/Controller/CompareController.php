<?php

namespace Drupal\comparenator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\comparenator\Service\ScreenshotManager;
use Drupal\comparenator\Service\PixelDiffGenerator;
use Drupal\comparenator\Service\EnvironmentManager;
use Drupal\comparenator\Service\RevisionManager;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Url;
use Drupal\comparenator\Form\RefreshForm;
use Psr\Log\LoggerInterface;

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
    RequestStack $request_stack,
    FormBuilderInterface $form_builder,
    ConfigFactoryInterface $config_factory,
    protected LoggerInterface $logger,
  ) {
    $this->screenshots = $screenshots;
    $this->pixelDiff = $pixelDiff;
    $this->envManager = $envManager;
    $this->revisionManager = $revisionManager;
    $this->requestStack = $request_stack;
    $this->configFactory = $config_factory;
    $this->formBuilder = $form_builder;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('comparenator.screenshot_manager'),
      $container->get('comparenator.pixel_diff'),
      $container->get('comparenator.environment_manager'),
      $container->get('comparenator.revision_manager'),
      $container->get('request_stack'),
      $container->get('form_builder'),
      $container->get('config.factory'),
      $container->get('logger.channel.comparenator'),
    );
  }

  /**
   * Build environment comparison page.
   */
  public function envCompare($identifier, $size, $reference, $current) {
    $request = $this->requestStack->getCurrentRequest();
    $mode = $request->query->get('mode', 'onion');

    $toolbar = $this->buildToolbar($identifier, $size, $reference, $current);
    try {
      $path = $request->query->get('path');
      if ($path !== NULL) {
        [$reference_url, $current_url] = $this->screenshots->ensureComparison($identifier, $size, $reference, $current, $path);
      }
      else {
        // Legacy identifiers are not reversible: do not guess a capture URL.
        $reference_url = $this->screenshots->getEnvironmentScreenshotUrl($reference, $identifier, $size);
        $current_url = $this->screenshots->getEnvironmentScreenshotUrl($current, $identifier, $size);
      }
      if (!$reference_url || !$current_url) {
        return [
          '#theme' => 'comparenator_missing',
          '#toolbar' => $toolbar,
          '#message' => $this->t('Screenshots are missing. Open Comparenator using the link on the page you want to compare to enable on-demand capture.'),
          '#cache' => ['max-age' => 0],
        ];
      }
      $build = match ($mode) {
        'side' => $this->renderSideBySide($reference_url, $current_url, $toolbar),
        'diff' => $this->renderDiff($this->pixelDiff->generateDiff($reference_url, $current_url, $identifier, $size), $toolbar),
        default => $this->renderOnionSkin($reference_url, $current_url, $toolbar),
      };
      $build['#cache']['max-age'] = 0;
      return $build;
    }
    catch (\InvalidArgumentException | \RuntimeException $exception) {
      $this->logger->error('Comparison failed: @message', ['@message' => $exception->getMessage()]);
      return [
        '#theme' => 'comparenator_missing',
        '#toolbar' => $toolbar,
        '#message' => $this->t('Unable to prepare screenshots. Check the Comparenator settings, browser installation and site logs, then try Refresh screenshots.'),
        '#cache' => ['max-age' => 0],
      ];
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
        'msg' => $this->t('Configure screenshot viewport sizes, then open Comparenator using the link at the top of the page you want to compare.'),
      ],
    ];
  }

  /**
   * Build UI toolbar (mode selector + size selector).
   */
  protected function buildToolbar($identifier, $size, $ref, $cur) {
    $config = $this->config('comparenator.settings');
    $sizes = $config->get('screen_sizes') ?? [];

    $request = $this->requestStack->getCurrentRequest();
    $path = $request->query->get('path');
    $mode = $request->query->get('mode', 'onion');
    $parameters = ['identifier' => $identifier, 'size' => $size, 'reference' => $ref, 'current' => $cur];
    $query = $path === NULL ? [] : ['path' => $path];
    $mode_links = [];
    foreach (['onion' => $this->t('Onion skin'), 'side' => $this->t('Side-by-side'), 'diff' => $this->t('Pixel diff')] as $key => $label) {
      $mode_links[$key] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => Url::fromRoute('comparenator.environment_compare', $parameters, ['query' => $query + ['mode' => $key]]),
      ];
    }
    $size_links = [];
    foreach ($sizes as $size_key => $resolution) {
      $size_parameters = array_replace($parameters, ['size' => $size_key]);
      $size_links[$size_key] = [
        '#type' => 'link',
        '#title' => $this->t('@size (@resolution)', ['@size' => $size_key, '@resolution' => $resolution]),
        '#url' => Url::fromRoute('comparenator.environment_compare', $size_parameters, ['query' => $query + ['mode' => $mode]]),
      ];
    }

    return [
      '#theme' => 'comparenator_toolbar',
      '#modes' => $mode_links,
      '#sizes' => $size_links,
      '#refresh' => $path === NULL ? [] : $this->formBuilder->getForm(RefreshForm::class, $parameters, $path, $mode),
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
      '#toolbar' => $toolbar,
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
      '#toolbar' => $toolbar,
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
      '#toolbar' => $toolbar,
      '#attached' => [
        'library' => ['comparenator/viewer'],
      ],
    ];
  }

}

