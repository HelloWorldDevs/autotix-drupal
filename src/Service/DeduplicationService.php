<?php

namespace Drupal\autotix\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Cache-based deduplication to avoid sending the same error repeatedly.
 */
class DeduplicationService {

  protected CacheBackendInterface $cache;
  protected ConfigFactoryInterface $configFactory;

  public function __construct(CacheBackendInterface $cache, ConfigFactoryInterface $config_factory) {
    $this->cache = $cache;
    $this->configFactory = $config_factory;
  }

  /**
   * Check if this error was already sent within the dedup window.
   *
   * @return bool
   *   TRUE if this is a duplicate (should be skipped).
   */
  public function isDuplicate(string $channel, string $message): bool {
    $config = $this->configFactory->get('autotix.settings');
    $window = (int) ($config->get('dedup_window') ?? 86400);

    if ($window <= 0) {
      return FALSE;
    }

    $key = 'autotix:dedup:' . md5($channel . '|' . $message);
    $cached = $this->cache->get($key);

    if ($cached) {
      return TRUE;
    }

    // Mark as seen for the duration of the window.
    $this->cache->set($key, TRUE, time() + $window);
    return FALSE;
  }

}
