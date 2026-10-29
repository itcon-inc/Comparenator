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
      '#type' => 'textfield',
      '#title' => $this->t('Reference environment base URL'),
      '#description' => $this->t('The base URL of the reference environment (e.g., production).'),
      '#default_value' => $config->get('reference_site_url'),
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
      '#description' => $this->t('Define available screen sizes for comparison. Format: WIDTHxHEIGHT'),
    ];

    $sizes = $config->get('screen_sizes') ?? [];

    foreach ($sizes as $key => $value) {
      $form['screen_sizes'][$key] = [
        '#type' => 'textfield',
        '#title' => $this->t('@key size', ['@key' => $key]),
        '#default_value' => $value,
        '#description' => $this->t('Example: 1440x900'),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('comparenator.settings')
      ->set('reference_site_url', $form_state->getValue('reference_site_url'))
      ->set('retention_days', $form_state->getValue('retention_days'))
      ->set('screen_sizes', $form_state->getValue('screen_sizes'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
