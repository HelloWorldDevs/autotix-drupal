<?php

namespace Drupal\autotix\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * HTTP client that sends error payloads to the Autotix webhook endpoint.
 */
class WebhookClient {

  /** Production webhook endpoint — not configurable. */
  protected const WEBHOOK_URL = 'https://app.autotix.io/api/webhook/error';

  protected ClientInterface $httpClient;
  protected ConfigFactoryInterface $configFactory;
  protected KeyRepositoryInterface $keyRepository;

  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    KeyRepositoryInterface $key_repository,
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->keyRepository = $key_repository;
  }

  /**
   * Send a payload to the Autotix webhook.
   *
   * @return bool
   *   TRUE on success.
   *
   * @throws \RuntimeException
   *   When JSON encoding fails or the endpoint returns a non-2xx response.
   *   Callers (e.g. queue workers) should let this bubble up so the item
   *   is retried.
   */
  public function send(array $payload): bool {
    $config = $this->configFactory->get('autotix.settings');
    $url = static::WEBHOOK_URL;

    try {
      $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
    catch (\JsonException $e) {
      throw new \RuntimeException('Failed to encode Autotix webhook payload as JSON', 0, $e);
    }

    $options = [
      'body' => $body,
      'timeout' => $config->get('timeout') ?? 20,
      // Disable Guzzle's default exception-on-4xx/5xx so we can inspect the
      // response and throw a consistent RuntimeException with status info.
      'http_errors' => FALSE,
      'headers' => [
        'Content-Type' => 'application/json',
        'User-Agent' => 'Autotix-Drupal/1.0',
      ],
    ];

    // Authentication — env vars still win (back-compat for existing setups);
    // otherwise look up the configured drupal/key Key entity. Storing secrets
    // in Key entities (with file/env provider) keeps them out of exportable
    // config / the database.
    $auth_method = $config->get('auth_method') ?? 'token';
    $token = getenv('AUTOTIX_AUTH_TOKEN') ?: $this->resolveKey($config->get('auth_token_key'));
    $secret = getenv('AUTOTIX_HMAC_SECRET') ?: $this->resolveKey($config->get('auth_secret_key'));

    if ($auth_method === 'token' && $token) {
      $options['headers']['X-Webhook-Token'] = $token;
    }
    elseif ($auth_method === 'hmac' && $secret) {
      $signature = hash_hmac('sha256', $body, $secret);
      $options['headers']['X-Webhook-Signature'] = $signature;
    }

    $debug = (bool) $config->get('debug');

    if ($debug) {
      \Drupal::logger('autotix_internal')->debug(
        'Sending to @url | payload_url: @payload_url | source: @source | level: @level | message: @message',
        [
          '@url' => $url,
          '@payload_url' => $payload['url'] ?? '(none)',
          '@source' => $payload['source'] ?? '(none)',
          '@level' => $payload['level'] ?? '(none)',
          '@message' => mb_strimwidth($payload['message'] ?? '(empty)', 0, 200, '...'),
        ]
      );
    }

    // With http_errors disabled, network failures (DNS, timeout, etc.) still
    // throw ConnectException which will bubble up for queue retry.
    $response = $this->httpClient->post($url, $options);
    $status = $response->getStatusCode();

    if ($debug) {
      \Drupal::logger('autotix_internal')->debug(
        'Response: HTTP @status from @url',
        ['@status' => $status, '@url' => $url]
      );
    }

    if ($status < 200 || $status >= 300) {
      if ($debug) {
        $response_body = (string) $response->getBody();
        \Drupal::logger('autotix_internal')->debug(
          'Autotix webhook returned HTTP @status from @url | response: @response',
          [
            '@status' => $status,
            '@url' => $url,
            '@response' => mb_strimwidth($response_body, 0, 200, '...'),
          ]
        );
      }

      \Drupal::logger('autotix_internal')->warning(
        'Autotix delivery FAILED — HTTP @status to @url | source: @source | level: @level',
        [
          '@status' => $status,
          '@url' => $url,
          '@source' => $payload['source'] ?? '(none)',
          '@level' => $payload['level'] ?? '(none)',
        ]
      );

      throw new \RuntimeException("Autotix webhook returned HTTP {$status}.");
    }

    // Always log successful deliveries so admins can verify the pipeline.
    \Drupal::logger('autotix_internal')->info(
      'Autotix delivered error to @url — source: @source | level: @level | message: @message',
      [
        '@url' => $url,
        '@source' => $payload['source'] ?? '(none)',
        '@level' => $payload['level'] ?? '(none)',
        '@message' => mb_strimwidth($payload['message'] ?? '(empty)', 0, 120, '...'),
      ]
    );

    return TRUE;
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
