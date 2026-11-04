<?php

namespace Drupal\comparenator\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for Comparenator.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['comparenator.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'comparenator_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('comparenator.settings');

    $form['reference_site_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Reference environment base URL'),
      '#description' => $this->t('The base URL of the reference environment (e.g., production).'),
      '#default_value' => $config->get('reference_site_url'),
      '#required' => TRUE,
    ];

    $form['current_site_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Local environment base URL'),
      '#description' => $this->t('An explicit HTTP(S) URL reachable by Chromium, including any Drupal installation subdirectory. Captures are anonymous.'),
      '#default_value' => $config->get('current_site_url'),
      '#required' => TRUE,
    ];

    $form['node_binary'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Node.js executable'),
      '#description' => $this->t('Executable name on the PHP worker PATH, or an absolute path. Install the module npm dependencies and Chromium on this host.'),
      '#default_value' => $config->get('node_binary') ?: 'node',
      '#required' => TRUE,
    ];

    $form['retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Screenshot retention (days)'),
      '#default_value' => $config->get('retention_days') ?? 7,
      '#min' => 1,
      '#description' => $this->t('How long screenshots should be kept before being purged.'),
    ];

    $form['screen_sizes'] = [
      '#type' => 'details',
      '#title' => $this->t('Screen Sizes'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t('Define available screen sizes for comparison. Format: WIDTHxHEIGHT'),
    ];

    $sizes = $config->get('screen_sizes') ?? [];

    foreach ($sizes as $key => $value) {
      $form['screen_sizes'][$key] = [
        '#type' => 'textfield',
        '#title' => $this->t('@key size', ['@key' => $key]),
        '#default_value' => $value,
        '#description' => $this->t('Example: 1440x900'),
        '#required' => TRUE,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    foreach (['reference_site_url', 'current_site_url'] as $key) {
      $url = $form_state->getValue($key);
      if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], TRUE) || parse_url($url, PHP_URL_USER) !== NULL || parse_url($url, PHP_URL_QUERY) !== NULL || parse_url($url, PHP_URL_FRAGMENT) !== NULL) {
        $form_state->setErrorByName($key, $this->t('Use an HTTP(S) base URL without credentials, a query string or a fragment.'));
      }
    }
    foreach ($form_state->getValue('screen_sizes', []) as $key => $dimensions) {
      if (!preg_match('/^([1-9][0-9]{0,3})x([1-9][0-9]{0,3})$/D', $dimensions, $matches) || (int) $matches[1] > 4096 || (int) $matches[2] > 4096) {
        $form_state->setErrorByName("screen_sizes][$key", $this->t('Use WIDTHxHEIGHT with each dimension between 1 and 4096.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('comparenator.settings')
      ->set('reference_site_url', $form_state->getValue('reference_site_url'))
      ->set('current_site_url', $form_state->getValue('current_site_url'))
      ->set('node_binary', $form_state->getValue('node_binary'))
      ->set('retention_days', $form_state->getValue('retention_days'))
      ->set('screen_sizes', $form_state->getValue('screen_sizes'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}

