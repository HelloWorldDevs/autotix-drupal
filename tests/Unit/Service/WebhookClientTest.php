<?php

namespace Drupal\Tests\autotix\Unit\Service;

use Drupal\autotix\Service\WebhookClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\State\StateInterface;
use Drupal\key\KeyRepositoryInterface;
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
  private KeyRepositoryInterface $keyRepository;
  private StateInterface $state;
  /** In-memory state backing for the State mock. */
  private array $stateValues = [];
  private WebhookClient $client;

  /**
   * Default config values for a working setup.
   */
  private array $configValues = [
    'timeout' => 20,
    'auth_method' => 'none',
    'auth_token_key' => '',
    'auth_secret_key' => '',
    'debug' => FALSE,
  ];

  /**
   * Stubbed Key entities, keyed by ID. Each stored value becomes the return
   * value of getKeyValue() on the matching Key mock.
   */
  private array $keyValues = [];

  protected function setUp(): void {
    parent::setUp();

    // Clear any env var overrides.
    putenv('AUTOTIX_AUTH_TOKEN');
    putenv('AUTOTIX_HMAC_SECRET');

    $this->httpClient = $this->createMock(Client::class);
    $this->config = $this->createMock(ImmutableConfig::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->keyRepository = $this->createMock(KeyRepositoryInterface::class);

    $this->config->method('get')->willReturnCallback(
      fn(string $key) => $this->configValues[$key] ?? NULL
    );
    $this->configFactory->method('get')
      ->with('autotix.settings')
      ->willReturn($this->config);

    // KeyRepository::getKey($id) returns a stub Key whose getKeyValue()
    // returns whatever's in $this->keyValues[$id], or null when missing.
    $this->keyRepository->method('getKey')->willReturnCallback(function ($id) {
      if (!array_key_exists($id, $this->keyValues)) {
        return NULL;
      }
      $value = $this->keyValues[$id];
      return new class($value) {
        public function __construct(private mixed $value) {}
        public function getKeyValue() { return $this->value; }
      };
    });

    // In-memory State stub so we can also assert delivery counters / status.
    $this->stateValues = [];
    $this->state = $this->createMock(StateInterface::class);
    $this->state->method('get')->willReturnCallback(
      fn(string $key, $default = NULL) => $this->stateValues[$key] ?? $default
    );
    $this->state->method('set')->willReturnCallback(function (string $key, $value): void {
      $this->stateValues[$key] = $value;
    });

    $this->client = new WebhookClient(
      $this->httpClient,
      $this->configFactory,
      $this->keyRepository,
      $this->state,
    );

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
    $this->configValues['auth_token_key'] = 'autotix_token';
    $this->keyValues['autotix_token'] = 'my-secret-token';

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
    $this->configValues['auth_secret_key'] = 'autotix_hmac';
    $this->keyValues['autotix_hmac'] = 'hmac-secret';

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
  public function testEnvVarOverridesKeyToken(): void {
    $this->configValues['auth_method'] = 'token';
    $this->configValues['auth_token_key'] = 'autotix_token';
    $this->keyValues['autotix_token'] = 'key-token';
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
  public function testEnvVarOverridesKeyHmacSecret(): void {
    $this->configValues['auth_method'] = 'hmac';
    $this->configValues['auth_secret_key'] = 'autotix_hmac';
    $this->keyValues['autotix_hmac'] = 'key-secret';
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
   * @covers ::resolveKey
   */
  public function testMissingKeyEntityYieldsNoAuthHeader(): void {
    $this->configValues['auth_method'] = 'token';
    $this->configValues['auth_token_key'] = 'nonexistent';
    // Note: keyValues is empty, so getKey() returns NULL.

    $this->httpClient->expects($this->once())
      ->method('post')
      ->with(
        $this->anything(),
        $this->callback(
          fn(array $opts) => !isset($opts['headers']['X-Webhook-Token'])
        )
      )
      ->willReturn(new Response(200));

    $this->client->send(['message' => 'test']);
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
      $this->assertStringContainsString('HTTP 500', $e->getMessage());
    }

    // The warning is always logged (status + url, no body); the debug
    // response-body log must NOT appear when debug is off.
    $logs = \Drupal::getLoggerEntries();
    $debug_entries = array_filter($logs, fn(array $l): bool => $l['level'] === 'debug');
    $this->assertEmpty(
      $debug_entries,
      'No debug-level entries should exist when debug is off'
    );

    // The warning entry should NOT contain the response body.
    foreach ($logs as $entry) {
      $this->assertStringNotContainsString(
        'Internal Server Error',
        $entry['message'] . ' ' . json_encode($entry['context'])
      );
    }
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
  public function testDebugDisabledProducesNoDebugEntries(): void {
    $this->configValues['debug'] = FALSE;

    $this->httpClient->method('post')
      ->willReturn(new Response(200));

    $this->client->send(['message' => 'test']);

    // The "delivered" info entry is always written; only debug-level entries
    // are gated on the debug flag.
    $logs = \Drupal::getLoggerEntries();
    $debug_entries = array_filter($logs, fn(array $l): bool => $l['level'] === 'debug');
    $this->assertEmpty($debug_entries, 'No debug-level entries when debug is disabled');
  }

  /**
   * @covers ::send
   */
  public function testDebugEnabledProducesDebugEntries(): void {
    $this->configValues['debug'] = TRUE;

    $this->httpClient->method('post')
      ->willReturn(new Response(200));

    $this->client->send(['source' => 'drupal', 'message' => 'test', 'url' => 'https://example.com', 'level' => 'error']);

    // 2 debug entries (send + response) + 1 always-on info entry (delivered).
    $logs = \Drupal::getLoggerEntries();
    $debug_entries = array_values(array_filter($logs, fn(array $l): bool => $l['level'] === 'debug'));
    $this->assertCount(2, $debug_entries, 'Two debug entries expected (send + response)');
    $this->assertSame('autotix_internal', $debug_entries[0]['channel']);
  }

  /**
   * @covers ::send
   */
  public function testNoAuthHeadersWhenMethodIsNone(): void {
    $this->configValues['auth_method'] = 'none';
    $this->configValues['auth_token_key'] = 'autotix_token';
    $this->configValues['auth_secret_key'] = 'autotix_hmac';
    $this->keyValues['autotix_token'] = 'ignored';
    $this->keyValues['autotix_hmac'] = 'ignored';

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

  /**
   * @covers ::send
   * @covers ::recordOutcome
   */
  public function testSuccessfulSendUpdatesStateCounters(): void {
    $this->stateValues['autotix.total_delivered'] = 4;
    $this->httpClient->method('post')->willReturn(new Response(200));

    $this->client->send(['message' => 'test']);

    $this->assertSame('ok', $this->stateValues['autotix.last_status']);
    $this->assertSame(5, $this->stateValues['autotix.total_delivered']);
    $this->assertIsInt($this->stateValues['autotix.last_delivery_at']);
  }

  /**
   * @covers ::send
   * @covers ::recordOutcome
   */
  public function testFailedSendUpdatesFailureCountersBeforeThrowing(): void {
    $this->stateValues['autotix.total_failed'] = 2;
    $this->httpClient->method('post')->willReturn(new Response(500, [], 'oops'));

    try {
      $this->client->send(['message' => 'test']);
      $this->fail('Expected RuntimeException');
    }
    catch (\RuntimeException) {
      // Expected.
    }

    $this->assertSame('failed', $this->stateValues['autotix.last_status']);
    $this->assertSame(3, $this->stateValues['autotix.total_failed']);
    $this->assertIsInt($this->stateValues['autotix.last_delivery_at']);
  }

}
