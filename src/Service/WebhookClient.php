<?php

namespace Drupal\autotix\Service;

use Autotix\PhpSdk\StateRecorderInterface;
use Autotix\PhpSdk\WebhookClient as CoreClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\HttpFactory;

/**
 * Drupal-side adapter around \Autotix\PhpSdk\WebhookClient.
 *
 * The HTTP client, JSON encoding, auth, exception shape, and outcome
 * recording all live in the shared SDK. This adapter only handles things
 * Drupal owns: pulling secrets out of Key entities, reading config, and
 * pointing the SDK at Drupal's logger channel + state service.
 *
 * If you find yourself adding non-Drupal logic here, push it down into
 * autotix/php-sdk so WordPress and Laravel inherit it for free.
 */
class WebhookClient {

  protected ClientInterface $httpClient;
  protected ConfigFactoryInterface $configFactory;
  protected KeyRepositoryInterface $keyRepository;
  protected StateInterface $state;
  protected LoggerChannelFactoryInterface $loggerFactory;

  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    KeyRepositoryInterface $key_repository,
    StateInterface $state,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->keyRepository = $key_repository;
    $this->state = $state;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Send a payload to the Autotix webhook.
   *
   * @return bool TRUE on success.
   *
   * @throws \RuntimeException Non-2xx response or JSON encoding failure.
   * @throws \Psr\Http\Client\ClientExceptionInterface Network failure.
   */
  public function send(array $payload): bool {
    $core = new CoreClient(
      $this->httpClient,
      new HttpFactory(),
      new HttpFactory(),
      $this->buildConfig(),
      new DrupalStateRecorder($this->state),
      $this->loggerFactory->get('autotix_internal'),
    );
    return $core->send($payload);
  }

  /**
   * Build the SDK config from autotix.settings + Key entities + env vars.
   * Env vars still win over Key lookups for back-compat with existing setups
   * where AUTOTIX_AUTH_TOKEN was set before the drupal/key migration.
   */
  protected function buildConfig(): array {
    $config = $this->configFactory->get('autotix.settings');
    $token = getenv('AUTOTIX_AUTH_TOKEN') ?: $this->resolveKey($config->get('auth_token_key'));
    $secret = getenv('AUTOTIX_HMAC_SECRET') ?: $this->resolveKey($config->get('auth_secret_key'));
    return [
      'auth_method' => $config->get('auth_method') ?? 'token',
      'auth_token' => $token,
      'auth_secret' => $secret,
      'debug' => (bool) $config->get('debug'),
      'user_agent' => 'Autotix-Drupal/1.0',
    ];
  }

  /**
   * Resolve a Key entity ID to its raw value, or NULL if missing.
   */
  protected function resolveKey(?string $key_id): ?string {
    if (empty($key_id)) {
      return NULL;
    }
    $key = $this->keyRepository->getKey($key_id);
    if (!$key) {
      return NULL;
    }
    $value = $key->getKeyValue();
    return is_string($value) && $value !== '' ? $value : NULL;
  }

}

/**
 * Adapter that satisfies StateRecorderInterface using Drupal's State API.
 *
 * Lives in this file to keep the surface area of the wrapper small. Each
 * Autotix delivery updates `autotix.last_status`, `autotix.last_delivery_at`,
 * and the appropriate counter so the admin dashboard widget reflects reality.
 */
class DrupalStateRecorder implements StateRecorderInterface {

  public function __construct(protected StateInterface $state) {}

  public function recordOutcome(string $status, array $context = []): void {
    try {
      $this->state->set('autotix.last_status', $status);
      $this->state->set('autotix.last_delivery_at', time());
      $key = $status === 'ok' ? 'autotix.total_delivered' : 'autotix.total_failed';
      $this->state->set($key, ((int) $this->state->get($key, 0)) + 1);
    }
    catch (\Throwable) {
      // State writes never break delivery.
    }
  }
}
