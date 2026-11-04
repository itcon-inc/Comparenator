<?php

namespace Drupal\comparenator\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\comparenator\Service\ScreenshotManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Provides a CSRF-protected POST to recapture both comparison images.
 */
class RefreshForm extends FormBase {

  public function __construct(
    protected ScreenshotManager $screenshots,
    protected LoggerInterface $logger,
    protected AccountProxyInterface $account,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('comparenator.screenshot_manager'),
      $container->get('logger.channel.comparenator'),
      $container->get('current_user'),
    );
  }

  public function getFormId() {
    return 'comparenator_refresh_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, array $parameters = [], string $path = '/', string $mode = 'onion') {
    $form_state->set('comparison', [$parameters, $path, $mode]);
    // Drupal's automatic Form API CSRF tokens are session-bound.
    $form['#access'] = $this->account->isAuthenticated() && $this->account->hasPermission('view environment comparisons');
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['refresh'] = [
      '#type' => 'submit',
      '#value' => $this->t('Refresh screenshots'),
    ];
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    if (!$this->account->isAuthenticated() || !$this->account->hasPermission('view environment comparisons')) {
      throw new AccessDeniedHttpException('Sign in with comparison permission to refresh screenshots.');
    }
    [$parameters, $path, $mode] = $form_state->get('comparison');
    try {
      $this->screenshots->ensureComparison(
        $parameters['identifier'],
        $parameters['size'],
        $parameters['reference'],
        $parameters['current'],
        $path,
        TRUE,
      );
      $this->messenger()->addStatus($this->t('Both screenshots have been refreshed.'));
    }
    catch (\InvalidArgumentException | \RuntimeException $exception) {
      $this->logger->error('Screenshot refresh failed: @message', ['@message' => $exception->getMessage()]);
      $this->messenger()->addError($this->t('Screenshot refresh failed. Check the Comparenator settings, browser installation and site logs.'));
    }
    $form_state->setRedirect('comparenator.environment_compare', $parameters, ['query' => ['path' => $path, 'mode' => $mode]]);
  }

}
