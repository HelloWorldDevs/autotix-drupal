<?php

namespace Drupal\autotix\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * HTTP client that sends error payloads to the Autotix webhook endpoint.
 */
class WebhookClient {

  protected ClientInterface $httpClient;
  protected ConfigFactoryInterface $configFactory;

  public function __construct(ClientInterface $http_client, ConfigFactoryInterface $config_factory) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
  }

  /**
   * Send a payload to the configured webhook URL.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function send(array $payload): bool {
    $config = $this->configFactory->get('autotix.settings');
    $url = $config->get('webhook_url');

    if (empty($url)) {
      return FALSE;
    }

    $body = json_encode($payload);

    $options = [
      'body' => $body,
      'timeout' => $config->get('timeout') ?? 20,
      'headers' => [
        'Content-Type' => 'application/json',
        'User-Agent' => 'Autotix-Drupal/1.0',
      ],
    ];

    // Authentication.
    $auth_method = $config->get('auth_method') ?? 'token';
    $token = $config->get('auth_token');
    $secret = $config->get('auth_secret');

    if ($auth_method === 'token' && $token) {
      $options['headers']['X-Webhook-Token'] = $token;
    }
    elseif ($auth_method === 'hmac' && $secret) {
      $signature = hash_hmac('sha256', $body, $secret);
      $options['headers']['X-Webhook-Signature'] = $signature;
    }

    // Log what we're sending for debugging.
    \Drupal::logger('autotix_internal')->info(
      'Sending to @url | payload_url: @payload_url | source: @source | level: @level | message: @message',
      [
        '@url' => $url,
        '@payload_url' => $payload['url'] ?? '(none)',
        '@source' => $payload['source'] ?? '(none)',
        '@level' => $payload['level'] ?? '(none)',
        '@message' => mb_strimwidth($payload['message'] ?? '(empty)', 0, 200, '...'),
      ]
    );

    try {
      $response = $this->httpClient->post($url, $options);
      $status = $response->getStatusCode();
      $success = $status >= 200 && $status < 300;

      \Drupal::logger('autotix_internal')->info(
        'Response: HTTP @status from @url',
        ['@status' => $status, '@url' => $url]
      );

      return $success;
    }
    catch (RequestException $e) {
      \Drupal::logger('autotix_internal')->error(
        'Send failed to @url: @error',
        ['@url' => $url, '@error' => $e->getMessage()]
      );
      return FALSE;
    }
  }

}
