<?php

namespace Drupal\Tests\autotix\Unit\Service;

use Drupal\autotix\Service\DeduplicationService;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\autotix\Service\DeduplicationService
 */
class DeduplicationServiceTest extends TestCase {

  private CacheBackendInterface $cache;
  private ConfigFactoryInterface $configFactory;
  private ImmutableConfig $config;
  private DeduplicationService $dedup;

  private array $configValues = [
    'dedup_window' => 300,
  ];

  protected function setUp(): void {
    parent::setUp();

    $this->cache = $this->createMock(CacheBackendInterface::class);
    $this->config = $this->createMock(ImmutableConfig::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);

    $this->config->method('get')->willReturnCallback(
      fn(string $key) => $this->configValues[$key] ?? NULL
    );
    $this->configFactory->method('get')
      ->with('autotix.settings')
      ->willReturn($this->config);

    $this->dedup = new DeduplicationService($this->cache, $this->configFactory);
  }

  /**
   * @covers ::isDuplicate
   */
  public function testFirstOccurrenceIsNotDuplicate(): void {
    $this->cache->method('get')->willReturn(FALSE);

    $startTime = time();

    $this->cache->expects($this->once())
      ->method('set')
      ->with(
        $this->stringContains('autotix:dedup:'),
        TRUE,
        $this->callback(function ($expiry) use ($startTime): bool {
          return is_int($expiry) && $expiry >= $startTime + $this->configValues['dedup_window'];
        })
      );

    $result = $this->dedup->isDuplicate('php', 'Some error');
    $this->assertFalse($result);
  }

  /**
   * @covers ::isDuplicate
   */
  public function testSecondOccurrenceIsDuplicate(): void {
    // Simulate cache hit — entry already exists.
    $this->cache->method('get')->willReturn((object) ['data' => TRUE]);

    $this->cache->expects($this->never())->method('set');

    $result = $this->dedup->isDuplicate('php', 'Some error');
    $this->assertTrue($result);
  }

  /**
   * @covers ::isDuplicate
   */
  public function testWindowDisabledAlwaysReturnsNotDuplicate(): void {
    $this->configValues['dedup_window'] = 0;

    $this->cache->expects($this->never())->method('get');
    $this->cache->expects($this->never())->method('set');

    $result = $this->dedup->isDuplicate('php', 'Some error');
    $this->assertFalse($result);

    // Call again — still not a duplicate.
    $result2 = $this->dedup->isDuplicate('php', 'Some error');
    $this->assertFalse($result2);
  }

  /**
   * @covers ::isDuplicate
   */
  public function testDifferentMessagesAreNotDuplicates(): void {
    $cacheStore = [];

    $this->cache->method('get')->willReturnCallback(
      fn(string $key) => $cacheStore[$key] ?? FALSE
    );
    $this->cache->method('set')->willReturnCallback(
      function (string $key, $data, $expire) use (&$cacheStore) {
        $cacheStore[$key] = (object) ['data' => $data];
      }
    );

    $result1 = $this->dedup->isDuplicate('php', 'Error A');
    $result2 = $this->dedup->isDuplicate('php', 'Error B');

    $this->assertFalse($result1, 'First message should not be duplicate');
    $this->assertFalse($result2, 'Different message should not be duplicate');
  }

  /**
   * @covers ::isDuplicate
   */
  public function testDifferentChannelsAreNotDuplicates(): void {
    $cacheStore = [];

    $this->cache->method('get')->willReturnCallback(
      fn(string $key) => $cacheStore[$key] ?? FALSE
    );
    $this->cache->method('set')->willReturnCallback(
      function (string $key, $data, $expire) use (&$cacheStore) {
        $cacheStore[$key] = (object) ['data' => $data];
      }
    );

    $result1 = $this->dedup->isDuplicate('php', 'Same error');
    $result2 = $this->dedup->isDuplicate('system', 'Same error');

    $this->assertFalse($result1, 'First channel should not be duplicate');
    $this->assertFalse($result2, 'Same message on different channel should not be duplicate');
  }

  /**
   * @covers ::isDuplicate
   */
  public function testCacheKeyIsDeterministic(): void {
    $this->cache->method('get')->willReturn(FALSE);

    $capturedKeys = [];
    $this->cache->method('set')->willReturnCallback(
      function (string $key) use (&$capturedKeys) {
        $capturedKeys[] = $key;
      }
    );

    $this->dedup->isDuplicate('php', 'Error message');
    $this->dedup->isDuplicate('php', 'Error message');

    // Same inputs should produce the same cache key.
    $this->assertCount(2, $capturedKeys);
    $this->assertSame($capturedKeys[0], $capturedKeys[1]);
  }

  /**
   * @covers ::isDuplicate
   */
  public function testNegativeWindowTreatedAsDisabled(): void {
    $this->configValues['dedup_window'] = -10;

    $this->cache->expects($this->never())->method('get');

    $result = $this->dedup->isDuplicate('php', 'Some error');
    $this->assertFalse($result);
  }

}
