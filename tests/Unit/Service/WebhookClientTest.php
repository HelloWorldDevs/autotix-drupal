<?php

namespace Drupal\Tests\autotix\Unit\Service;

use Drupal\autotix\Service\DrupalStateRecorder;
use Drupal\autotix\Service\WebhookClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests focus on the Drupal-side adapter only — Key resolution, env-var
 * precedence, and config wiring. The HTTP / JSON / auth header / debug
 * logging / exception shape is owned by autotix/php-sdk and tested there.
 *
 * @coversDefaultClass \Drupal\autotix\Service\WebhookClient
 */
class WebhookClientTest extends TestCase {

  private Client $httpClient;
  private ConfigFactoryInterface $configFactory;
  private ImmutableConfig $config;
  private KeyRepositoryInterface $keyRepository;
  private StateInterface $state;
  private LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Stubbed Key entities, keyed by ID. KeyRepository::getKey($id) returns
   * an anonymous Key whose getKeyValue() returns the corresponding value.
   */
  private array $keyValues = [];

  /**
   * Mutable config the mocked ImmutableConfig reads from.
   */
  private array $configValues = [
    'auth_method' => 'none',
    'auth_token_key' => '',
    'auth_secret_key' => '',
    'debug' => FALSE,
  ];

  protected function setUp(): void {
    parent::setUp();

    putenv('AUTOTIX_AUTH_TOKEN');
    putenv('AUTOTIX_HMAC_SECRET');

    $this->httpClient = $this->createMock(Client::class);
    $this->config = $this->createMock(ImmutableConfig::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->keyRepository = $this->createMock(KeyRepositoryInterface::class);
    $this->state = $this->createMock(StateInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);

    $this->config->method('get')->willReturnCallback(
      fn(string $key) => $this->configValues[$key] ?? NULL,
    );
    $this->configFactory->method('get')
      ->with('autotix.settings')
      ->willReturn($this->config);

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

    $this->loggerFactory->method('get')
      ->willReturn($this->createMock(LoggerChannelInterface::class));
  }

  protected function tearDown(): void {
    putenv('AUTOTIX_AUTH_TOKEN');
    putenv('AUTOTIX_HMAC_SECRET');
    parent::tearDown();
  }

  private function client(): WebhookClient {
    return new WebhookClient(
      $this->httpClient,
      $this->configFactory,
      $this->keyRepository,
      $this->state,
      $this->loggerFactory,
    );
  }

  /**
   * Reach into the protected `buildConfig` so we can assert what gets
   * forwarded to the SDK without firing real HTTP requests.
   */
  private function builtConfig(): array {
    $method = new ReflectionMethod(WebhookClient::class, 'buildConfig');
    return $method->invoke($this->client());
  }

  // -------------------------------------------------------------------
  // Token resolution: Key entity → SDK config
  // -------------------------------------------------------------------

  public function testReadsTokenFromKeyEntity(): void {
    $this->configValues['auth_token_key'] = 'autotix_token';
    $this->keyValues['autotix_token'] = 'iat_from_key';

    $built = $this->builtConfig();
    $this->assertSame('iat_from_key', $built['auth_token']);
  }

  public function testReadsHmacSecretFromKeyEntity(): void {
    $this->configValues['auth_secret_key'] = 'autotix_hmac';
    $this->keyValues['autotix_hmac'] = 'hmac-secret';

    $built = $this->builtConfig();
    $this->assertSame('hmac-secret', $built['auth_secret']);
  }

  public function testEnvVarOverridesKeyToken(): void {
    $this->configValues['auth_token_key'] = 'autotix_token';
    $this->keyValues['autotix_token'] = 'iat_from_key';
    putenv('AUTOTIX_AUTH_TOKEN=iat_from_env');

    $built = $this->builtConfig();
    $this->assertSame('iat_from_env', $built['auth_token']);
  }

  public function testEnvVarOverridesKeyHmacSecret(): void {
    $this->configValues['auth_secret_key'] = 'autotix_hmac';
    $this->keyValues['autotix_hmac'] = 'key-secret';
    putenv('AUTOTIX_HMAC_SECRET=env-secret');

    $built = $this->builtConfig();
    $this->assertSame('env-secret', $built['auth_secret']);
  }

  public function testMissingKeyEntityYieldsNullToken(): void {
    $this->configValues['auth_token_key'] = 'nonexistent';
    // keyValues empty -> getKey() returns NULL -> resolveKey returns NULL.
    $built = $this->builtConfig();
    $this->assertNull($built['auth_token']);
  }

  public function testEmptyKeyIdYieldsNullToken(): void {
    $this->configValues['auth_token_key'] = '';
    $built = $this->builtConfig();
    $this->assertNull($built['auth_token']);
  }

  // -------------------------------------------------------------------
  // Config wiring
  // -------------------------------------------------------------------

  public function testForwardsAuthMethodFromConfig(): void {
    $this->configValues['auth_method'] = 'hmac';
    $this->assertSame('hmac', $this->builtConfig()['auth_method']);
  }

  public function testDefaultsAuthMethodToTokenWhenUnset(): void {
    unset($this->configValues['auth_method']);
    $this->assertSame('token', $this->builtConfig()['auth_method']);
  }

  public function testForwardsDebugFlag(): void {
    $this->configValues['debug'] = TRUE;
    $this->assertTrue($this->builtConfig()['debug']);
  }

  public function testIdentifiesItselfWithDrupalUserAgent(): void {
    $this->assertStringStartsWith('Autotix-Drupal/', $this->builtConfig()['user_agent']);
  }

  // -------------------------------------------------------------------
  // End-to-end sanity check via the real SDK + mocked HTTP
  // -------------------------------------------------------------------

  public function testSendDelegatesToSdkAndReturnsTrueOn2xx(): void {
    $this->httpClient->method('sendRequest')
      ->willReturn(new Response(202, [], '{"ok":true}'));

    $this->assertTrue(
      $this->client()->send(['source' => 'drupal', 'message' => 'hi']),
    );
  }

  public function testSendThrowsOnNon2xxFromSdk(): void {
    $this->httpClient->method('sendRequest')
      ->willReturn(new Response(500));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('HTTP 500');
    $this->client()->send(['message' => 'x']);
  }

  // -------------------------------------------------------------------
  // DrupalStateRecorder — adapter for SDK's StateRecorderInterface
  // -------------------------------------------------------------------

  public function testStateRecorderWritesOkOutcome(): void {
    $writes = [];
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn(0);
    $state->method('set')->willReturnCallback(function (string $k, $v) use (&$writes): void {
      $writes[$k] = $v;
    });

    (new DrupalStateRecorder($state))->recordOutcome('ok');

    $this->assertSame('ok', $writes['autotix.last_status']);
    $this->assertIsInt($writes['autotix.last_delivery_at']);
    $this->assertSame(1, $writes['autotix.total_delivered']);
  }

  public function testStateRecorderWritesFailedOutcomeToFailureCounter(): void {
    $writes = [];
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn(0);
    $state->method('set')->willReturnCallback(function (string $k, $v) use (&$writes): void {
      $writes[$k] = $v;
    });

    (new DrupalStateRecorder($state))->recordOutcome('failed');

    $this->assertSame('failed', $writes['autotix.last_status']);
    $this->assertSame(1, $writes['autotix.total_failed']);
    $this->assertArrayNotHasKey('autotix.total_delivered', $writes);
  }

  public function testStateRecorderSwallowsThrowables(): void {
    $state = $this->createMock(StateInterface::class);
    $state->method('set')->willThrowException(new \RuntimeException('db down'));

    // Must not throw — state failures never break delivery.
    (new DrupalStateRecorder($state))->recordOutcome('ok');
    $this->assertTrue(TRUE);
  }
}
