<?php

/**
 * @file
 * Bootstrap for Autotix unit tests.
 *
 * Loads Drupal interface/class stubs so the module source can be tested
 * without a Drupal installation.
 *
 * Autoloader resolution order:
 *   1. AUTOTIX_TEST_AUTOLOAD env var (explicit override)
 *   2. Module-local vendor/autoload.php  (composer install in module dir)
 *   3. Project-root vendor/autoload.php  (Drupal site-level install)
 */

$autoload_candidates = array_filter([
  getenv('AUTOTIX_TEST_AUTOLOAD') ?: null,
  __DIR__ . '/../vendor/autoload.php',
  __DIR__ . '/../../../../../vendor/autoload.php',
]);

$autoload_path = null;
foreach ($autoload_candidates as $candidate) {
  if (is_file($candidate)) {
    $autoload_path = $candidate;
    break;
  }
}

if ($autoload_path === null) {
  throw new RuntimeException(
    'Unable to locate Composer autoload.php for Autotix tests. '
    . 'Set AUTOTIX_TEST_AUTOLOAD or install dependencies in the module or project root.'
  );
}

require_once $autoload_path;
require_once __DIR__ . '/Stubs/DrupalStubs.php';
