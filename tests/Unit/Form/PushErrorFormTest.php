<?php

namespace Drupal\Tests\autotix\Unit\Form;

use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * Tests for PushErrorForm logic.
 *
 * Since the form extends Drupal\Core\Form\FormBase which requires a full
 * Drupal runtime, we test the logic (levelToInt mapping) via reflection
 * rather than instantiating the form.
 */
class PushErrorFormTest extends TestCase {

  /**
   * Map of PSR-3 levels to expected RFC 5424 integers.
   */
  private const EXPECTED_MAP = [
    LogLevel::EMERGENCY => 0,
    LogLevel::ALERT => 1,
    LogLevel::CRITICAL => 2,
    LogLevel::ERROR => 3,
    LogLevel::WARNING => 4,
    LogLevel::NOTICE => 5,
    LogLevel::INFO => 6,
    LogLevel::DEBUG => 7,
  ];

  /**
   * Invoke the protected levelToInt method via reflection.
   */
  private function callLevelToInt(string $level): int {
    // Use reflection to test the protected method without instantiating the
    // full form (which requires FormBase dependencies).
    $class = new \ReflectionClass(\Drupal\autotix\Form\PushErrorForm::class);
    $method = $class->getMethod('levelToInt');
    $method->setAccessible(TRUE);

    // Create an instance without invoking the constructor.
    $instance = $class->newInstanceWithoutConstructor();
    return $method->invoke($instance, $level);
  }

  /**
   * @dataProvider levelProvider
   */
  public function testLevelToIntMapsCorrectly(string $level, int $expected): void {
    $this->assertSame($expected, $this->callLevelToInt($level));
  }

  /**
   * Data provider for all PSR-3 levels.
   */
  public static function levelProvider(): array {
    $cases = [];
    foreach (self::EXPECTED_MAP as $level => $int) {
      $cases[$level] = [$level, $int];
    }
    return $cases;
  }

  /**
   * Unknown level strings should default to 3 (Error).
   */
  public function testUnknownLevelDefaultsToError(): void {
    $this->assertSame(3, $this->callLevelToInt('nonexistent'));
  }

  /**
   * Empty string should default to 3 (Error).
   */
  public function testEmptyStringDefaultsToError(): void {
    $this->assertSame(3, $this->callLevelToInt(''));
  }

}
