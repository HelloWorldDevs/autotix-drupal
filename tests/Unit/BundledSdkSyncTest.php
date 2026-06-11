<?php

namespace Drupal\Tests\autotix\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards against drift between the canonical SDK (packages/php-sdk/src)
 * and the copy bundled in this module (lib/php-sdk/).
 *
 * The bundled copy exists because most Drupal sites install the module by
 * copying the directory — without it, Autotix\PhpSdk\* classes are missing
 * and the autotix.client service fatals. See autotix.module's fallback
 * autoloader. Run bin/sync-sdk.sh after changing the SDK.
 *
 * Skipped outside the monorepo (deployed sites don't have packages/).
 */
class BundledSdkSyncTest extends TestCase {

  public function testBundledSdkMatchesCanonicalSdk(): void {
    $moduleRoot = dirname(__DIR__, 2);
    $canonical = $moduleRoot . '/../../packages/php-sdk/src';
    $bundled = $moduleRoot . '/lib/php-sdk';

    if (!is_dir($canonical)) {
      $this->markTestSkipped('Canonical SDK not present (running outside the monorepo).');
    }

    $this->assertDirectoryExists($bundled, 'lib/php-sdk is missing — run bin/sync-sdk.sh');

    $canonicalFiles = $this->phpFiles($canonical);
    $bundledFiles = $this->phpFiles($bundled);

    $this->assertSame(
      array_keys($canonicalFiles),
      array_keys($bundledFiles),
      'Bundled SDK file list differs from packages/php-sdk/src — run bin/sync-sdk.sh'
    );

    foreach ($canonicalFiles as $name => $hash) {
      $this->assertSame(
        $hash,
        $bundledFiles[$name],
        "lib/php-sdk/{$name} differs from packages/php-sdk/src/{$name} — run bin/sync-sdk.sh"
      );
    }
  }

  /**
   * @return array<string, string> filename => sha256, sorted by filename.
   */
  private function phpFiles(string $dir): array {
    $files = [];
    foreach (glob($dir . '/*.php') as $path) {
      $files[basename($path)] = hash_file('sha256', $path);
    }
    ksort($files);
    return $files;
  }

}
