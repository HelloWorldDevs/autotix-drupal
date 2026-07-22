<?php

namespace Drupal\Tests\autotix\Unit\Service;

use Drupal\autotix\Service\EnvReporter;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\autotix\Service\EnvReporter
 *
 * Covers the pure npm lockfile / manifest parsers that back the
 * Vulnerability Scanning env report. Filesystem discovery
 * (npmLockfilePaths / collectNpmPackages) is exercised by the live
 * end-to-end path; here we pin the parsing logic across every lockfile
 * format.
 */
class EnvReporterNpmTest extends TestCase {

  /**
   * @covers ::parseNpmLockfile
   */
  public function testParsesV3PackagesMap(): void {
    // npm v7+ (lockfileVersion 2/3): flat "packages" map keyed by path.
    $json = json_encode([
      'name' => 'demo',
      'lockfileVersion' => 3,
      'packages' => [
        '' => ['name' => 'demo', 'version' => '1.0.0'],
        'node_modules/lodash' => ['version' => '4.17.11'],
        'node_modules/minimist' => ['version' => '1.2.0'],
      ],
    ]);

    $result = EnvReporter::parseNpmLockfile($json);

    // Root ("") entry is skipped; the two deps are reported.
    $this->assertContains(['name' => 'lodash', 'version' => '4.17.11'], $result);
    $this->assertContains(['name' => 'minimist', 'version' => '1.2.0'], $result);
    $this->assertCount(2, $result);
  }

  /**
   * @covers ::parseNpmLockfile
   */
  public function testNestedAndVersionlessPackagesEntries(): void {
    $json = json_encode([
      'lockfileVersion' => 3,
      'packages' => [
        '' => ['version' => '1.0.0'],
        // Nested install: name is the segment after the LAST node_modules/.
        'node_modules/gulp/node_modules/glob' => ['version' => '7.1.2'],
        // Scoped package name is preserved.
        'node_modules/@babel/core' => ['version' => '7.24.0'],
        // Symlinked workspace: no version -> skipped.
        'node_modules/local-lib' => ['resolved' => '../packages/local-lib', 'link' => TRUE],
      ],
    ]);

    $result = EnvReporter::parseNpmLockfile($json);

    $this->assertContains(['name' => 'glob', 'version' => '7.1.2'], $result);
    $this->assertContains(['name' => '@babel/core', 'version' => '7.24.0'], $result);
    $this->assertCount(2, $result);
  }

  /**
   * @covers ::parseNpmLockfile
   */
  public function testParsesV1DependenciesTreeRecursively(): void {
    // npm v6 (lockfileVersion 1): recursive "dependencies" tree by name.
    $json = json_encode([
      'lockfileVersion' => 1,
      'dependencies' => [
        'lodash' => ['version' => '4.17.11'],
        'gulp' => [
          'version' => '4.0.2',
          'dependencies' => [
            'glob' => ['version' => '7.1.2'],
          ],
        ],
      ],
    ]);

    $result = EnvReporter::parseNpmLockfile($json);

    $this->assertContains(['name' => 'lodash', 'version' => '4.17.11'], $result);
    $this->assertContains(['name' => 'gulp', 'version' => '4.0.2'], $result);
    $this->assertContains(['name' => 'glob', 'version' => '7.1.2'], $result);
    $this->assertCount(3, $result);
  }

  /**
   * @covers ::parseNpmLockfile
   */
  public function testInvalidLockfileYieldsNoPackages(): void {
    $this->assertSame([], EnvReporter::parseNpmLockfile('not json'));
    $this->assertSame([], EnvReporter::parseNpmLockfile('[]'));
    $this->assertSame([], EnvReporter::parseNpmLockfile('{}'));
  }

  /**
   * @covers ::parseNpmManifest
   */
  public function testManifestReportsExactVersionsAndSkipsRanges(): void {
    $json = json_encode([
      'dependencies' => [
        'lodash' => '4.17.11',
        'left-pad' => '=1.3.0',
        'exact-v' => 'v2.5.1',
        'ranged' => '^1.2.0',
        'tilde' => '~1.2',
        'wild' => '*',
        'tagged' => 'latest',
        'from-git' => 'github:user/repo',
      ],
      'devDependencies' => [
        'prerelease' => '3.0.0-beta.1',
      ],
    ]);

    $result = EnvReporter::parseNpmManifest($json);

    $this->assertContains(['name' => 'lodash', 'version' => '4.17.11'], $result);
    $this->assertContains(['name' => 'left-pad', 'version' => '1.3.0'], $result);
    $this->assertContains(['name' => 'exact-v', 'version' => '2.5.1'], $result);
    $this->assertContains(['name' => 'prerelease', 'version' => '3.0.0-beta.1'], $result);
    // Ranges / wildcards / tags / git specs are not resolved installs.
    foreach (['ranged', 'tilde', 'wild', 'tagged', 'from-git'] as $skipped) {
      $this->assertNotContains($skipped, array_column($result, 'name'));
    }
    $this->assertCount(4, $result);
  }

  /**
   * @covers ::parseNpmManifest
   */
  public function testInvalidManifestYieldsNoPackages(): void {
    $this->assertSame([], EnvReporter::parseNpmManifest('not json'));
    $this->assertSame([], EnvReporter::parseNpmManifest('{}'));
  }

}
