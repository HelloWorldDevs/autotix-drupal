<?php

namespace Drupal\autotix;

/**
 * Robustly read a process/platform environment variable.
 *
 * PHP-FPM defaults to `clear_env = yes`, which wipes the environment for
 * `getenv()` in web requests even when the container/platform (Pantheon,
 * Platform.sh, Lando, Docker, …) has the variable set — so `getenv()`
 * returns FALSE in FPM while PHP-CLI (cron, drush) sees it fine. That
 * split is exactly why credentials can work for cron-driven delivery yet
 * look "missing" to a web-served status check. `$_ENV` / `$_SERVER` still
 * carry the value depending on `variables_order`, so fall back to those.
 */
trait EnvVarTrait {

  /**
   * Return the env var's value, or NULL when unset/empty in every source.
   */
  protected function envVar(string $name): ?string {
    $value = getenv($name);
    if ($value === FALSE || $value === '') {
      $value = $_ENV[$name] ?? $_SERVER[$name] ?? '';
    }
    return is_string($value) && $value !== '' ? $value : NULL;
  }

}
