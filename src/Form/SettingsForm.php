<?php

namespace Drupal\autotix\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Admin configuration form for the Autotix module.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['autotix.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'autotix_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('autotix.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Autotix'),
      '#default_value' => $config->get('enabled'),
      '#description' => $this->t('When enabled, watchdog entries meeting the severity threshold will be sent to Autotix for automated ticket creation.'),
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

    $has_token_env = !empty(getenv('AUTOTIX_AUTH_TOKEN'));
    $has_token_config = !empty($config->get('auth_token'));
    $token_description = $this->t('Your Autotix webhook token. Can also be set via the <code>AUTOTIX_AUTH_TOKEN</code> environment variable (recommended).');
    if ($has_token_env) {
      $token_description .= ' ' . $this->t('<strong>Currently provided by environment variable.</strong>');
    }
    elseif ($has_token_config) {
      $token_description .= ' ' . $this->t('<em>A value is saved. Leave blank to keep it.</em>');
    }

    $form['auth']['auth_token'] = [
      '#type' => 'password',
      '#title' => $this->t('Auth token'),
      '#description' => $token_description,
      '#states' => [
        'visible' => [
          ':input[name="auth_method"]' => ['value' => 'token'],
        ],
      ],
    ];

    if ($has_token_config && !$has_token_env) {
      $form['auth']['clear_auth_token'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Clear saved auth token'),
        '#default_value' => FALSE,
        '#states' => [
          'visible' => [
            ':input[name="auth_method"]' => ['value' => 'token'],
          ],
        ],
      ];
    }

    $has_secret_env = !empty(getenv('AUTOTIX_HMAC_SECRET'));
    $has_secret_config = !empty($config->get('auth_secret'));
    $secret_description = $this->t('The HMAC signing secret. Can also be set via the <code>AUTOTIX_HMAC_SECRET</code> environment variable (recommended).');
    if ($has_secret_env) {
      $secret_description .= ' ' . $this->t('<strong>Currently provided by environment variable.</strong>');
    }
    elseif ($has_secret_config) {
      $secret_description .= ' ' . $this->t('<em>A value is saved. Leave blank to keep it.</em>');
    }

    $form['auth']['auth_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('HMAC secret'),
      '#description' => $secret_description,
      '#states' => [
        'visible' => [
          ':input[name="auth_method"]' => ['value' => 'hmac'],
        ],
      ],
    ];

    if ($has_secret_config && !$has_secret_env) {
      $form['auth']['clear_auth_secret'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Clear saved HMAC secret'),
        '#default_value' => FALSE,
        '#states' => [
          'visible' => [
            ':input[name="auth_method"]' => ['value' => 'hmac'],
          ],
        ],
      ];
    }

    $form['delivery'] = [
      '#type' => 'details',
      '#title' => $this->t('Delivery'),
      '#open' => TRUE,
    ];

    $form['delivery']['send_immediately'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send errors immediately'),
      '#default_value' => $config->get('send_immediately'),
      '#description' => $this->t('When checked, errors are sent to Autotix in real time during the request. When unchecked, errors are queued and delivered on the next cron run. Immediate mode falls back to the queue if the request fails.'),
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
      '#description' => $this->t('Only send entries at this severity level or more severe. Default: Error.'),
    ];

    $form['filtering']['dedup_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Deduplication window (seconds)'),
      '#default_value' => $config->get('dedup_window') ?? 86400,
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

    $form['advanced']['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Debug logging'),
      '#default_value' => $config->get('debug'),
      '#description' => $this->t('Log outbound requests and responses to the autotix_internal channel. Disable in production to avoid doubling log volume during error bursts.'),
    ];

    $form['advanced']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('HTTP timeout (seconds)'),
      '#default_value' => $config->get('timeout') ?? 20,
      '#min' => 1,
      '#max' => 60,
      '#description' => $this->t('How long to wait for the Autotix endpoint to respond.'),
    ];

    // Webhook delivery status widget (loaded via JS).
    $form['status_widget'] = [
      '#type' => 'container',
      '#attributes' => ['data-autotix-status' => TRUE],
      '#attached' => [
        'library' => ['autotix/status'],
        'drupalSettings' => [
          'autotix' => [
            'statusEndpoint' => '/admin/config/services/autotix/status',
          ],
        ],
      ],
    ];

    // Links to test and push pages.
    $form['test_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Send a test error to verify Autotix is working'),
      '#url' => Url::fromRoute('autotix.test'),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];
    $form['push_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Push a custom error to Autotix manually'),
      '#url' => Url::fromRoute('autotix.push_error'),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $settings = $this->config('autotix.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('auth_method', $form_state->getValue('auth_method'))
      ->set('send_immediately', (bool) $form_state->getValue('send_immediately'))
      ->set('severity_threshold', (int) $form_state->getValue('severity_threshold'))
      ->set('dedup_window', (int) $form_state->getValue('dedup_window'))
      ->set('environment', $form_state->getValue('environment'))
      ->set('include_backtrace', (bool) $form_state->getValue('include_backtrace'))
      ->set('debug', (bool) $form_state->getValue('debug'))
      ->set('timeout', (int) $form_state->getValue('timeout'));

    // Only overwrite secrets when a new value is entered (password fields
    // are always submitted empty when the user doesn't touch them).
    // Explicit "clear" checkboxes allow admins to remove stored secrets.
    if ($form_state->getValue('clear_auth_token')) {
      $settings->set('auth_token', '');
    }
    else {
      $token = $form_state->getValue('auth_token');
      if (!empty($token)) {
        $settings->set('auth_token', $token);
      }
    }

    if ($form_state->getValue('clear_auth_secret')) {
      $settings->set('auth_secret', '');
    }
    else {
      $secret = $form_state->getValue('auth_secret');
      if (!empty($secret)) {
        $settings->set('auth_secret', $secret);
      }
    }

    $settings->save();

    parent::submitForm($form, $form_state);
  }

}
