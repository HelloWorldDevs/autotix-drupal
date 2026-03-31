<?php

namespace Drupal\Tests\autotix\Unit\Logger;

use Drupal\autotix\Logger\WebhookLogger;
use Drupal\autotix\Service\DeduplicationService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * @coversDefaultClass \Drupal\autotix\Logger\WebhookLogger
 */
class WebhookLoggerTest extends TestCase {

  private ConfigFactoryInterface $configFactory;
  private ImmutableConfig $config;
  private DeduplicationService $dedup;
  private QueueFactory $queueFactory;
  private QueueInterface $queue;
  private WebhookLogger $logger;

  private array $configValues = [
    'enabled' => TRUE,
    'severity_threshold' => 3, // Error.
    'include_backtrace' => FALSE,
    'environment' => 'testing',
  ];

  /** @var array Captures items passed to queue->createItem(). */
  private array $enqueuedItems = [];

  protected function setUp(): void {
    parent::setUp();

    $this->config = $this->createMock(ImmutableConfig::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->dedup = $this->createMock(DeduplicationService::class);
    $this->queueFactory = $this->createMock(QueueFactory::class);
    $this->queue = $this->createMock(QueueInterface::class);

    $this->config->method('get')->willReturnCallback(
      fn(string $key) => $this->configValues[$key] ?? NULL
    );
    $this->configFactory->method('get')
      ->with('autotix.settings')
      ->willReturn($this->config);

    $this->dedup->method('isDuplicate')->willReturn(FALSE);

    $this->enqueuedItems = [];
    $this->queue->method('createItem')->willReturnCallback(
      function ($data) {
        $this->enqueuedItems[] = $data;
      }
    );
    $this->queueFactory->method('get')
      ->with('autotix')
      ->willReturn($this->queue);

    $this->logger = new WebhookLogger(
      $this->configFactory,
      $this->dedup,
      $this->queueFactory
    );
  }

  /**
   * @covers ::log
   */
  public function testErrorIsEnqueued(): void {
    $this->logger->log(3, 'Something broke: @detail', [
      'channel' => 'php',
      '@detail' => 'null pointer',
      'uid' => 1,
      'request_uri' => '/test',
      'timestamp' => 1000000,
    ]);

    $this->assertCount(1, $this->enqueuedItems);
    $payload = $this->enqueuedItems[0];
    $this->assertSame('drupal', $payload['source']);
    $this->assertSame('error', $payload['level']);
    $this->assertSame('Something broke: null pointer', $payload['message']);
    $this->assertSame('php', $payload['details']['channel']);
    $this->assertSame('testing', $payload['details']['environment']);
  }

  /**
   * @covers ::log
   */
  public function testDisabledModuleSkipsEnqueue(): void {
    $this->configValues['enabled'] = FALSE;

    $this->queue->expects($this->never())->method('createItem');

    $this->logger->log(3, 'Error', ['channel' => 'php']);

    $this->assertEmpty($this->enqueuedItems);
  }

  /**
   * @covers ::log
   */
  public function testInternalChannelIsSkipped(): void {
    $this->queue->expects($this->never())->method('createItem');

    $this->logger->log(3, 'Internal log', ['channel' => 'autotix_internal']);

    $this->assertEmpty($this->enqueuedItems);
  }

  /**
   * @covers ::log
   */
  public function testBelowThresholdIsSkipped(): void {
    $this->configValues['severity_threshold'] = 3; // Error.

    $this->queue->expects($this->never())->method('createItem');

    // Info (6) is below Error (3) threshold (higher number = less severe).
    $this->logger->log(6, 'Info message', ['channel' => 'php']);

    $this->assertEmpty($this->enqueuedItems);
  }

  /**
   * @covers ::log
   */
  public function testAtThresholdIsEnqueued(): void {
    $this->configValues['severity_threshold'] = 4; // Warning.

    // Warning (4) is at threshold — should be enqueued.
    $this->logger->log(4, 'Warning message', ['channel' => 'php']);

    $this->assertCount(1, $this->enqueuedItems);
    $this->assertSame('warning', $this->enqueuedItems[0]['level']);
  }

  /**
   * @covers ::log
   */
  public function testAboveThresholdIsEnqueued(): void {
    $this->configValues['severity_threshold'] = 4; // Warning.

    // Error (3) is more severe than Warning (4) — should be enqueued.
    $this->logger->log(3, 'Error message', ['channel' => 'php']);

    $this->assertCount(1, $this->enqueuedItems);
    $this->assertSame('error', $this->enqueuedItems[0]['level']);
  }

  /**
   * @covers ::log
   */
  public function testDuplicateIsSuppressed(): void {
    $this->dedup = $this->createMock(DeduplicationService::class);
    $this->dedup->method('isDuplicate')->willReturn(TRUE);

    $this->logger = new WebhookLogger(
      $this->configFactory,
      $this->dedup,
      $this->queueFactory
    );

    $this->queue->expects($this->never())->method('createItem');

    $this->logger->log(3, 'Duplicate error', ['channel' => 'php']);

    $this->assertEmpty($this->enqueuedItems);
  }

  /**
   * @covers ::log
   */
  public function testDedupReceivesRawMessageTemplate(): void {
    $this->dedup = $this->createMock(DeduplicationService::class);
    $this->dedup->expects($this->once())
      ->method('isDuplicate')
      ->with('php', 'Error at @time on @page')
      ->willReturn(FALSE);

    $this->logger = new WebhookLogger(
      $this->configFactory,
      $this->dedup,
      $this->queueFactory
    );

    $this->logger->log(3, 'Error at @time on @page', [
      'channel' => 'php',
      '@time' => '2026-01-01',
      '@page' => '/home',
    ]);
  }

  /**
   * @covers ::log
   */
  public function testPlaceholderRenderingOnlySubstitutesMarkedKeys(): void {
    $this->logger->log(3, 'Error @detail with uid', [
      'channel' => 'php',
      '@detail' => 'injected value',
      'uid' => 42,
      'ip' => '127.0.0.1',
      'timestamp' => 9999,
    ]);

    $this->assertCount(1, $this->enqueuedItems);
    $message = $this->enqueuedItems[0]['message'];

    // @detail should be replaced.
    $this->assertStringContainsString('injected value', $message);
    // Bare context keys like 'uid' should NOT replace substrings.
    $this->assertStringNotContainsString('42', $message);
  }

  /**
   * @covers ::log
   */
  public function testStringLevelIsConverted(): void {
    $this->configValues['severity_threshold'] = 7; // Allow everything.

    $this->logger->log('error', 'String level error', ['channel' => 'php']);

    $this->assertCount(1, $this->enqueuedItems);
    $this->assertSame('error', $this->enqueuedItems[0]['level']);
    $this->assertSame(3, $this->enqueuedItems[0]['details']['severity']);
  }

  /**
   * @covers ::log
   */
  public function testBacktraceIncludedWhenEnabled(): void {
    $this->configValues['include_backtrace'] = TRUE;

    $this->logger->log(3, 'Error with trace', ['channel' => 'php']);

    $this->assertCount(1, $this->enqueuedItems);
    $this->assertArrayHasKey('backtrace', $this->enqueuedItems[0]['details']);
    $this->assertNotEmpty($this->enqueuedItems[0]['details']['backtrace']);
  }

  /**
   * @covers ::log
   */
  public function testBacktraceNotIncludedByDefault(): void {
    $this->configValues['include_backtrace'] = FALSE;

    $this->logger->log(3, 'Error without trace', ['channel' => 'php']);

    $this->assertCount(1, $this->enqueuedItems);
    $this->assertArrayNotHasKey('backtrace', $this->enqueuedItems[0]['details']);
  }

  /**
   * @covers ::log
   */
  public function testPayloadContainsExpectedStructure(): void {
    $this->logger->log(2, 'Critical failure', [
      'channel' => 'system',
      'uid' => 5,
      'referer' => 'https://example.com/prev',
      'ip' => '10.0.0.1',
      'hostname' => 'web01',
      'timestamp' => 1234567890,
    ]);

    $this->assertCount(1, $this->enqueuedItems);
    $payload = $this->enqueuedItems[0];

    $this->assertSame('drupal', $payload['source']);
    $this->assertSame('critical', $payload['level']);
    $this->assertArrayHasKey('url', $payload);
    $this->assertArrayHasKey('details', $payload);
    $this->assertSame('system', $payload['details']['channel']);
    $this->assertSame(2, $payload['details']['severity']);
    $this->assertSame(5, $payload['details']['uid']);
    $this->assertSame('https://example.com/prev', $payload['details']['referer']);
    $this->assertSame('10.0.0.1', $payload['details']['ip']);
    $this->assertSame('web01', $payload['details']['hostname']);
    $this->assertSame(1234567890, $payload['details']['timestamp']);
    $this->assertSame('testing', $payload['details']['environment']);
  }

  /**
   * @covers ::log
   */
  public function testEmergencyLevelMapsCorrectly(): void {
    $this->configValues['severity_threshold'] = 7;

    $this->logger->log(0, 'Emergency!', ['channel' => 'php']);

    $this->assertCount(1, $this->enqueuedItems);
    $this->assertSame('emergency', $this->enqueuedItems[0]['level']);
  }

  /**
   * @covers ::log
   */
  public function testContextWithBacktraceUsesProvidedBacktrace(): void {
    $this->configValues['include_backtrace'] = TRUE;

    $this->logger->log(3, 'Error', [
      'channel' => 'php',
      'backtrace' => 'custom backtrace string',
    ]);

    $this->assertCount(1, $this->enqueuedItems);
    $this->assertSame('custom backtrace string', $this->enqueuedItems[0]['details']['backtrace']);
  }

}
