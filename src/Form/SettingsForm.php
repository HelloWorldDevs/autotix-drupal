<?php

namespace Drupal\autotix\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Admin configuration form for the Autotix module.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['autotix.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'autotix_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('autotix.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Autotix'),
      '#default_value' => $config->get('enabled'),
      '#description' => $this->t('When enabled, watchdog entries meeting the severity threshold will be sent to Autotix for automated ticket creation.'),
    ];

    $form['webhook_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Webhook URL'),
      '#default_value' => $config->get('webhook_url'),
      '#description' => $this->t('The full URL of your Autotix webhook endpoint (e.g. https://app.autotix.io/api/webhook/error).'),
      '#maxlength' => 2048,
    ];

    $form['auth'] = [
      '#type' => 'details',
      '#title' => $this->t('Authentication'),
      '#open' => TRUE,
    ];

    $form['auth']['auth_method'] = [
      '#type' => 'select',
      '#title' => $this->t('Authentication method'),
      '#options' => [
        'token' => $this->t('Token (X-Webhook-Token header)'),
        'hmac' => $this->t('HMAC-SHA256 signature (X-Webhook-Signature header)'),
        'none' => $this->t('None'),
      ],
      '#default_value' => $config->get('auth_method') ?? 'token',
    ];

    $form['auth']['auth_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Auth token'),
      '#default_value' => $config->get('auth_token'),
      '#description' => $this->t('Your Autotix webhook token. You can get this by connecting your site via the Autotix dashboard.'),
      '#states' => [
        'visible' => [
          ':input[name="auth_method"]' => ['value' => 'token'],
        ],
      ],
    ];

    $form['auth']['auth_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('HMAC secret'),
      '#default_value' => $config->get('auth_secret'),
      '#description' => $this->t('The HMAC signing secret. Must match the WEBHOOK_HMAC_SECRET on your Autotix instance.'),
      '#states' => [
        'visible' => [
          ':input[name="auth_method"]' => ['value' => 'hmac'],
        ],
      ],
    ];

    $form['filtering'] = [
      '#type' => 'details',
      '#title' => $this->t('Filtering'),
      '#open' => TRUE,
    ];

    $form['filtering']['severity_threshold'] = [
      '#type' => 'select',
      '#title' => $this->t('Minimum severity'),
      '#options' => [
        0 => $this->t('Emergency'),
        1 => $this->t('Alert'),
        2 => $this->t('Critical'),
        3 => $this->t('Error'),
        4 => $this->t('Warning'),
        5 => $this->t('Notice'),
        6 => $this->t('Info'),
        7 => $this->t('Debug'),
      ],
      '#default_value' => $config->get('severity_threshold') ?? 3,
      '#description' => $this->t('Only send entries at this severity level or higher. Default: Error.'),
    ];

    $form['filtering']['dedup_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Deduplication window (seconds)'),
      '#default_value' => $config->get('dedup_window') ?? 300,
      '#min' => 0,
      '#description' => $this->t('Suppress duplicate messages within this many seconds. Set to 0 to disable.'),
    ];

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced'),
      '#open' => FALSE,
    ];

    $form['advanced']['environment'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Environment'),
      '#default_value' => $config->get('environment') ?? 'production',
      '#description' => $this->t('Environment label sent in the payload (e.g. production, staging, dev).'),
    ];

    $form['advanced']['include_backtrace'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include backtrace'),
      '#default_value' => $config->get('include_backtrace'),
      '#description' => $this->t('Include a PHP backtrace in the payload. Useful for debugging but increases payload size.'),
    ];

    $form['advanced']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('HTTP timeout (seconds)'),
      '#default_value' => $config->get('timeout') ?? 20,
      '#min' => 1,
      '#max' => 60,
      '#description' => $this->t('How long to wait for the Autotix endpoint to respond.'),
    ];

    // Link to the test page.
    $form['test'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('<a href=":url">Send a test error</a> to verify Autotix is working.', [
        ':url' => '/admin/config/services/autotix/test',
      ]) . '</p>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('autotix.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('webhook_url', $form_state->getValue('webhook_url'))
      ->set('auth_method', $form_state->getValue('auth_method'))
      ->set('auth_token', $form_state->getValue('auth_token'))
      ->set('auth_secret', $form_state->getValue('auth_secret'))
      ->set('severity_threshold', (int) $form_state->getValue('severity_threshold'))
      ->set('dedup_window', (int) $form_state->getValue('dedup_window'))
      ->set('environment', $form_state->getValue('environment'))
      ->set('include_backtrace', (bool) $form_state->getValue('include_backtrace'))
      ->set('timeout', (int) $form_state->getValue('timeout'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
