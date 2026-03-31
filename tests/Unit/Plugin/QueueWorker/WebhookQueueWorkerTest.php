<?php

namespace Drupal\Tests\autotix\Unit\Plugin\QueueWorker;

use Drupal\autotix\Plugin\QueueWorker\WebhookQueueWorker;
use Drupal\autotix\Service\WebhookClient;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\autotix\Plugin\QueueWorker\WebhookQueueWorker
 */
class WebhookQueueWorkerTest extends TestCase {

  private WebhookClient $client;
  private WebhookQueueWorker $worker;

  protected function setUp(): void {
    parent::setUp();

    $this->client = $this->createMock(WebhookClient::class);
    $this->worker = new WebhookQueueWorker([], 'autotix', [], $this->client);

    \Drupal::resetLoggerEntries();
  }

  /**
   * @covers ::processItem
   */
  public function testValidArrayCallsSend(): void {
    $payload = ['source' => 'drupal', 'message' => 'test'];

    $this->client->expects($this->once())
      ->method('send')
      ->with($payload)
      ->willReturn(TRUE);

    $this->worker->processItem($payload);
  }

  /**
   * @covers ::processItem
   */
  public function testNonArrayDataIsSilentlySkipped(): void {
    $this->client->expects($this->never())->method('send');

    // Should not throw.
    $this->worker->processItem('not an array');
    $this->worker->processItem(NULL);
    $this->worker->processItem(42);
  }

  /**
   * @covers ::processItem
   */
  public function testClientExceptionIsLoggedAndRethrown(): void {
    $exception = new \RuntimeException('Webhook failed');

    $this->client->method('send')
      ->willThrowException($exception);

    try {
      $this->worker->processItem(['message' => 'test']);
      $this->fail('Expected RuntimeException to be rethrown');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('Webhook failed', $e->getMessage());
    }

    // Verify the error was logged.
    $logs = \Drupal::getLoggerEntries();
    $this->assertNotEmpty($logs);

    $errorLog = $logs[0];
    $this->assertSame('autotix_internal', $errorLog['channel']);
    $this->assertSame('error', $errorLog['level']);
  }

  /**
   * @covers ::processItem
   */
  public function testRethrowPreservesOriginalException(): void {
    $original = new \RuntimeException('Original error');
    $this->client->method('send')->willThrowException($original);

    try {
      $this->worker->processItem(['message' => 'test']);
      $this->fail('Expected exception');
    }
    catch (\RuntimeException $caught) {
      $this->assertSame($original, $caught, 'The exact same exception instance should be rethrown');
    }
  }

  /**
   * @covers ::processItem
   */
  public function testEmptyArrayStillCallsSend(): void {
    $this->client->expects($this->once())
      ->method('send')
      ->with([]);

    $this->worker->processItem([]);
  }

}
