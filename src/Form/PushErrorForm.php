<?php

namespace Drupal\autotix\Form;

use Drupal\autotix\Service\WebhookClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Form to push a custom error directly to Autotix.
 */
class PushErrorForm extends FormBase {

  // Note: $configFactory and $requestStack are inherited from FormBase
  // (untyped). We use FormBase's setters so we don't conflict with the
  // parent class's property declarations on PHP 8.2+.
  protected QueueFactory $queueFactory;
  protected AccountProxyInterface $currentUser;
  protected WebhookClient $webhookClient;

  public function __construct(
    QueueFactory $queue_factory,
    ConfigFactoryInterface $config_factory,
    AccountProxyInterface $current_user,
    RequestStack $request_stack,
    WebhookClient $webhook_client,
  ) {
    $this->queueFactory = $queue_factory;
    $this->setConfigFactory($config_factory);
    $this->currentUser = $current_user;
    $this->setRequestStack($request_stack);
    $this->webhookClient = $webhook_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('queue'),
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('request_stack'),
      $container->get('autotix.client'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'autotix_push_error_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->configFactory->get('autotix.settings');

    if (!$config->get('enabled')) {
      $form['warning'] = [
        '#markup' => '<p><strong>' . $this->t('Autotix is currently disabled. Enable it in the <a href=":url">settings</a> first.', [
          ':url' => Url::fromRoute('autotix.settings_form')->toString(),
        ]) . '</strong></p>',
      ];
    }

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Error message'),
      '#required' => TRUE,
      '#description' => $this->t('The error message to send to Autotix.'),
      '#rows' => 4,
    ];

    $form['level'] = [
      '#type' => 'select',
      '#title' => $this->t('Severity'),
      '#options' => [
        LogLevel::EMERGENCY => $this->t('Emergency'),
        LogLevel::ALERT => $this->t('Alert'),
        LogLevel::CRITICAL => $this->t('Critical'),
        LogLevel::ERROR => $this->t('Error'),
        LogLevel::WARNING => $this->t('Warning'),
        LogLevel::NOTICE => $this->t('Notice'),
        LogLevel::INFO => $this->t('Info'),
        LogLevel::DEBUG => $this->t('Debug'),
      ],
      '#default_value' => LogLevel::ERROR,
    ];

    $form['url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Related URL'),
      '#description' => $this->t('Optional URL where the error occurred or is relevant to.'),
      '#maxlength' => 2048,
    ];

    $form['details_wrapper'] = [
      '#type' => 'details',
      '#title' => $this->t('Additional details'),
      '#open' => FALSE,
    ];

    $form['details_wrapper']['channel'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Channel'),
      '#default_value' => 'custom',
      '#description' => $this->t('Log channel / category for this error.'),
    ];

    $form['details_wrapper']['extra_context'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Extra context (JSON)'),
      '#description' => $this->t('Optional JSON object with additional context to include in the payload details.'),
      '#rows' => 3,
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Push to Autotix'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory->get('autotix.settings');
    if (!$config->get('enabled')) {
      $form_state->setErrorByName('', $this->t('Autotix is currently disabled. Enable it in settings before pushing errors.'));
      return;
    }

    $extra = trim($form_state->getValue('extra_context') ?? '');
    if ($extra !== '') {
      json_decode($extra);
      if (json_last_error() !== JSON_ERROR_NONE) {
        $form_state->setErrorByName('extra_context', $this->t('Extra context must be valid JSON.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory->get('autotix.settings');
    $message = $form_state->getValue('message');
    $level = $form_state->getValue('level');
    $url = $form_state->getValue('url') ?: '';
    $channel = $form_state->getValue('channel') ?: 'custom';
    $extra = trim($form_state->getValue('extra_context') ?? '');

    // Build the URL if not provided.
    if (empty($url)) {
      $request = $this->requestStack->getCurrentRequest();
      $url = $request ? $request->getSchemeAndHttpHost() : '';
    }

    $payload = [
      'source' => 'drupal',
      'level' => $level,
      'message' => $message,
      'url' => $url,
      'details' => [
        'channel' => $channel,
        'severity' => $this->levelToInt($level),
        'uid' => $this->currentUser->id(),
        'request_uri' => $url,
        'environment' => $config->get('environment') ?? 'production',
        'timestamp' => time(),
        'custom' => TRUE,
      ],
    ];

    // Merge extra context if provided.
    if ($extra !== '') {
      $decoded = json_decode($extra, TRUE);
      if (is_array($decoded)) {
        $payload['details'] = array_merge($payload['details'], $decoded);
      }
    }

    // Send immediately or enqueue, based on config.
    $config = $this->configFactory->get('autotix.settings');
    if ($config->get('send_immediately')) {
      try {
        $this->webhookClient->send($payload);
        $this->messenger()->addStatus(
          $this->t('Custom error has been sent to Autotix.')
        );
      }
      catch (\Exception $e) {
        // Fall back to queue on failure.
        $queue = $this->queueFactory->get('autotix');
        $queue->createItem($payload);
        $this->messenger()->addWarning(
          $this->t('Immediate send failed; error has been queued for delivery on next cron run.')
        );
      }
    }
    else {
      $queue = $this->queueFactory->get('autotix');
      $queue->createItem($payload);
      $this->messenger()->addStatus(
        $this->t('Custom error has been queued. Run cron to deliver it to Autotix.')
      );
    }

    $form_state->setRedirect('autotix.settings_form');
  }

  /**
   * Convert a PSR-3 string log level to an RFC 5424 integer.
   */
  protected function levelToInt(string $level): int {
    $map = [
      LogLevel::EMERGENCY => 0,
      LogLevel::ALERT => 1,
      LogLevel::CRITICAL => 2,
      LogLevel::ERROR => 3,
      LogLevel::WARNING => 4,
      LogLevel::NOTICE => 5,
      LogLevel::INFO => 6,
      LogLevel::DEBUG => 7,
    ];
    return $map[$level] ?? 3;
  }

}
