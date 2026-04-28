<?php

namespace Drupal\Tests\autotix\Unit\Service;

use Drupal\autotix\Service\WebhookClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\autotix\Service\WebhookClient
 */
class WebhookClientTest extends TestCase {

  private Client $httpClient;
  private ConfigFactoryInterface $configFactory;
  private ImmutableConfig $config;
  private WebhookClient $client;

  /**
   * Default config values for a working setup.
   */
  private array $configValues = [
    'timeout' => 20,
    'auth_method' => 'none',
    'auth_token' => '',
    'auth_secret' => '',
    'debug' => FALSE,
  ];

  protected function setUp(): void {
    parent::setUp();

    // Clear any env var overrides.
    putenv('AUTOTIX_AUTH_TOKEN');
    putenv('AUTOTIX_HMAC_SECRET');

    $this->httpClient = $this->createMock(Client::class);
    $this->config = $this->createMock(ImmutableConfig::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);

    $this->config->method('get')->willReturnCallback(
      fn(string $key) => $this->configValues[$key] ?? NULL
    );
    $this->configFactory->method('get')
      ->with('autotix.settings')
      ->willReturn($this->config);

    $this->client = new WebhookClient($this->httpClient, $this->configFactory);

    \Drupal::resetLoggerEntries();
  }

  protected function tearDown(): void {
    putenv('AUTOTIX_AUTH_TOKEN');
    putenv('AUTOTIX_HMAC_SECRET');
    parent::tearDown();
  }

  /**
   * @covers ::send
   */
  public function testSendSuccessReturnsTrue(): void {
    $this->httpClient->method('post')
      ->willReturn(new Response(200, [], '{"ok":true}'));

    $result = $this->client->send(['source' => 'drupal', 'message' => 'test']);
    $this->assertTrue($result);
  }

  /**
   * @covers ::send
   */
  public function testSendPassesCorrectJsonBody(): void {
    $payload = ['source' => 'drupal', 'message' => 'Hello world'];

    $this->httpClient->expects($this->once())
      ->method('post')
      ->with(
        'https://app.autotix.io/api/webhook/error',
        $this->callback(function (array $options) use ($payload) {
          $decoded = json_decode($options['body'], TRUE);
          return $decoded === $payload
            && $options['headers']['Content-Type'] === 'application/json'
            && $options['http_errors'] === FALSE;
        })
      )
      ->willReturn(new Response(200));

    $this->client->send($payload);
  }

  /**
   * @covers ::send
   */
  public function testTokenAuthSetsHeader(): void {
    $this->configValues['auth_method'] = 'token';
    $this->configValues['auth_token'] = 'my-secret-token';

    $this->httpClient->expects($this->once())
      ->method('post')
      ->with(
        $this->anything(),
        $this->callback(fn(array $opts) => ($opts['headers']['X-Webhook-Token'] ?? '') === 'my-secret-token')
      )
      ->willReturn(new Response(200));

    $this->client->send(['message' => 'test']);
  }

  /**
   * @covers ::send
   */
  public function testHmacAuthSetsSignatureHeader(): void {
    $this->configValues['auth_method'] = 'hmac';
    $this->configValues['auth_secret'] = 'hmac-secret';

    $payload = ['message' => 'test'];
    $expectedBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $expectedSignature = hash_hmac('sha256', $expectedBody, 'hmac-secret');

    $this->httpClient->expects($this->once())
      ->method('post')
      ->with(
        $this->anything(),
        $this->callback(fn(array $opts) => ($opts['headers']['X-Webhook-Signature'] ?? '') === $expectedSignature)
      )
      ->willReturn(new Response(200));

    $this->client->send($payload);
  }

  /**
   * @covers ::send
   */
  public function testEnvVarOverridesConfigToken(): void {
    $this->configValues['auth_method'] = 'token';
    $this->configValues['auth_token'] = 'config-token';
    putenv('AUTOTIX_AUTH_TOKEN=env-token');

    $this->httpClient->expects($this->once())
      ->method('post')
      ->with(
        $this->anything(),
        $this->callback(fn(array $opts) => ($opts['headers']['X-Webhook-Token'] ?? '') === 'env-token')
      )
      ->willReturn(new Response(200));

    $this->client->send(['message' => 'test']);
  }

  /**
   * @covers ::send
   */
  public function testEnvVarOverridesConfigHmacSecret(): void {
    $this->configValues['auth_method'] = 'hmac';
    $this->configValues['auth_secret'] = 'config-secret';
    putenv('AUTOTIX_HMAC_SECRET=env-secret');

    $payload = ['message' => 'test'];
    $expectedBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $expectedSignature = hash_hmac('sha256', $expectedBody, 'env-secret');

    $this->httpClient->expects($this->once())
      ->method('post')
      ->with(
        $this->anything(),
        $this->callback(fn(array $opts) => ($opts['headers']['X-Webhook-Signature'] ?? '') === $expectedSignature)
      )
      ->willReturn(new Response(200));

    $this->client->send($payload);
  }

  /**
   * @covers ::send
   */
  public function testThrowsRuntimeExceptionOnJsonEncodingFailure(): void {
    // Create a payload with invalid UTF-8 that json_encode cannot handle.
    $payload = ['message' => "bad utf8: \xB1\x31"];

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Failed to encode Autotix webhook payload as JSON');

    $this->client->send($payload);
  }

  /**
   * @covers ::send
   */
  public function testThrowsOnNon2xxResponse(): void {
    $this->httpClient->method('post')
      ->willReturn(new Response(500, [], 'Internal Server Error'));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Autotix webhook returned HTTP 500.');

    $this->client->send(['message' => 'test']);
  }

  /**
   * @covers ::send
   */
  public function testThrowsOnNon2xx403Response(): void {
    $this->httpClient->method('post')
      ->willReturn(new Response(403, [], '{"error":"forbidden"}'));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Autotix webhook returned HTTP 403.');

    $this->client->send(['message' => 'test']);
  }

  /**
   * @covers ::send
   */
  public function testDebugLogsResponseBodyOnError(): void {
    $this->configValues['debug'] = TRUE;

    $this->httpClient->method('post')
      ->willReturn(new Response(502, [], 'Bad Gateway'));

    try {
      $this->client->send(['message' => 'test']);
      $this->fail('Expected RuntimeException');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('HTTP 502', $e->getMessage());
      // Response body should NOT be in the exception message.
      $this->assertStringNotContainsString('Bad Gateway', $e->getMessage());
    }

    // Response body should appear only in the debug log.
    $logs = \Drupal::getLoggerEntries();
    $errorLogs = array_filter($logs, fn($l) => str_contains($l['message'], 'HTTP'));
    $this->assertNotEmpty($errorLogs, 'Debug log should contain the error response details');
  }

  /**
   * @covers ::send
   */
  public function testNonDebugDoesNotLogResponseBodyOnError(): void {
    $this->configValues['debug'] = FALSE;

    $this->httpClient->method('post')
      ->willReturn(new Response(500, [], 'Internal Server Error'));

    try {
      $this->client->send(['message' => 'test']);
      $this->fail('Expected RuntimeException');
    }
    catch (\RuntimeException $e) {
      // Exception should still be thrown.
      $this->assertStringContainsString('HTTP 500', $e->getMessage());
    }

    // No debug logs should be produced.
    $logs = \Drupal::getLoggerEntries();
    $this->assertEmpty($logs, 'No log entries should exist when debug is off');
  }

  /**
   * @covers ::send
   */
  public function testNetworkErrorBubblesUp(): void {
    $this->httpClient->method('post')
      ->willThrowException(
        new ConnectException('Connection refused', new Request('POST', 'https://example.com'))
      );

    $this->expectException(ConnectException::class);

    $this->client->send(['message' => 'test']);
  }

  /**
   * @covers ::send
   */
  public function testDebugDisabledProducesNoLogEntries(): void {
    $this->configValues['debug'] = FALSE;

    $this->httpClient->method('post')
      ->willReturn(new Response(200));

    $this->client->send(['message' => 'test']);

    $logs = \Drupal::getLoggerEntries();
    $this->assertEmpty($logs, 'No log entries should be produced when debug is disabled');
  }

  /**
   * @covers ::send
   */
  public function testDebugEnabledProducesLogEntries(): void {
    $this->configValues['debug'] = TRUE;

    $this->httpClient->method('post')
      ->willReturn(new Response(200));

    $this->client->send(['source' => 'drupal', 'message' => 'test', 'url' => 'https://example.com', 'level' => 'error']);

    $logs = \Drupal::getLoggerEntries();
    $this->assertCount(2, $logs, 'Two debug log entries expected (send + response)');
    $this->assertSame('autotix_internal', $logs[0]['channel']);
    $this->assertSame('debug', $logs[0]['level']);
  }

  /**
   * @covers ::send
   */
  public function testNoAuthHeadersWhenMethodIsNone(): void {
    $this->configValues['auth_method'] = 'none';
    $this->configValues['auth_token'] = 'ignored';
    $this->configValues['auth_secret'] = 'ignored';

    $this->httpClient->expects($this->once())
      ->method('post')
      ->with(
        $this->anything(),
        $this->callback(function (array $opts) {
          return !isset($opts['headers']['X-Webhook-Token'])
            && !isset($opts['headers']['X-Webhook-Signature']);
        })
      )
      ->willReturn(new Response(200));

    $this->client->send(['message' => 'test']);
  }

}
