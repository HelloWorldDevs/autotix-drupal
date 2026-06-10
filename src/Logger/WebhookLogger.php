<?php

namespace Drupal\autotix\Logger;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\Core\Queue\QueueFactory;
use Drupal\autotix\Service\DeduplicationService;
use Drupal\autotix\Service\WebhookClient;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * PSR-3 logger that enqueues qualifying watchdog entries for Autotix delivery.
 */
class WebhookLogger implements LoggerInterface {

  use RfcLoggerTrait;
  // Tagged loggers live inside every LoggerChannel, and LoggerChannel has
  // no __sleep — so this object gets serialized whenever a channel does
  // (e.g. Form API batches that stash $form_state holding a form object
  // with a logger). Without this trait our real dependencies serialize
  // too, and autotix.client drags in Guzzle's closure-based handler stack:
  // "Serialization of 'Closure' is not allowed". The trait swaps injected
  // services for their container IDs on sleep, exactly like core's DbLog.
  use DependencySerializationTrait;

  /**
   * Map RFC 5424 severity integers to PSR-3 log level strings.
   */
  protected const LEVEL_MAP = [
    0 => LogLevel::EMERGENCY,
    1 => LogLevel::ALERT,
    2 => LogLevel::CRITICAL,
    3 => LogLevel::ERROR,
    4 => LogLevel::WARNING,
    5 => LogLevel::NOTICE,
    6 => LogLevel::INFO,
    7 => LogLevel::DEBUG,
  ];

  protected ConfigFactoryInterface $configFactory;
  protected DeduplicationService $dedup;
  protected QueueFactory $queueFactory;
  protected WebhookClient $client;

  public function __construct(
    ConfigFactoryInterface $config_factory,
    DeduplicationService $dedup,
    QueueFactory $queue_factory,
    WebhookClient $client,
  ) {
    $this->configFactory = $config_factory;
    $this->dedup = $dedup;
    $this->queueFactory = $queue_factory;
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    $config = $this->configFactory->get('autotix.settings');

    // Bail early if module is disabled.
    if (!$config->get('enabled')) {
      return;
    }

    // Prevent infinite loops: skip our own internal logging channel.
    $channel = $context['channel'] ?? '';
    if ($channel === 'autotix_internal') {
      return;
    }

    // Convert level to integer if it's a string.
    $severity = is_int($level) ? $level : $this->levelToInt($level);

    // Only forward entries at or above the configured threshold.
    $threshold = (int) $config->get('severity_threshold');
    if ($severity > $threshold) {
      return;
    }

    // Render the message with placeholders.
    $rendered_message = $this->renderMessage($message, $context);

    // Deduplication check — use the raw template so dynamic values
    // (timestamps, IPs, request URIs) don't defeat dedup.
    if ($this->dedup->isDuplicate($channel, (string) $message)) {
      \Drupal::logger('autotix_internal')->info(
        'Autotix skipped duplicate error — channel: @channel | message: @message',
        [
          '@channel' => $channel,
          '@message' => mb_strimwidth($rendered_message, 0, 120, '...'),
        ]
      );
      return;
    }

    // Build the full URL of the page where the error occurred.
    $full_url = $this->buildFullUrl($context);

    // Build the payload in the generic webhook format.
    $payload = [
      'source' => 'drupal',
      'level' => self::LEVEL_MAP[$severity] ?? 'error',
      'message' => $rendered_message,
      'url' => $full_url,
      'details' => [
        'channel' => $channel,
        'severity' => $severity,
        'uid' => $context['uid'] ?? 0,
        'request_uri' => $full_url,
        'referer' => $context['referer'] ?? '',
        'ip' => $context['ip'] ?? '',
        'hostname' => $context['hostname'] ?? '',
        'environment' => $config->get('environment') ?? 'production',
        'timestamp' => $context['timestamp'] ?? time(),
      ],
    ];

    // Optionally include backtrace.
    if ($config->get('include_backtrace')) {
      $backtrace = $context['backtrace'] ?? NULL;
      if ($backtrace) {
        $payload['details']['backtrace'] = $backtrace;
      }
      else {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $payload['details']['backtrace'] = $this->formatBacktrace($trace);
      }
    }

    // Send immediately or enqueue for cron, based on config.
    if ($config->get('send_immediately')) {
      \Drupal::logger('autotix_internal')->info(
        'Autotix capturing error (immediate mode) — channel: @channel | severity: @severity | message: @message',
        [
          '@channel' => $channel,
          '@severity' => $severity,
          '@message' => mb_strimwidth($rendered_message, 0, 120, '...'),
        ]
      );
      try {
        $this->client->send($payload);
      }
      catch (\Exception $e) {
        // Fall back to queue on failure so the error isn't lost.
        \Drupal::logger('autotix_internal')->warning(
          'Immediate send failed, queuing for retry: @error',
          ['@error' => $e->getMessage()]
        );
        $queue = $this->queueFactory->get('autotix');
        $queue->createItem($payload);
      }
    }
    else {
      \Drupal::logger('autotix_internal')->info(
        'Autotix capturing error (queued for cron) — channel: @channel | severity: @severity | message: @message',
        [
          '@channel' => $channel,
          '@severity' => $severity,
          '@message' => mb_strimwidth($rendered_message, 0, 120, '...'),
        ]
      );
      $queue = $this->queueFactory->get('autotix');
      $queue->createItem($payload);
    }
  }

  /**
   * Build the full URL (scheme + host + path) from the log context.
   */
  protected function buildFullUrl(array $context): string {
    $path = $context['request_uri'] ?? '';

    if (empty($path)) {
      try {
        $request = \Drupal::request();
        $path = $request->getRequestUri();
      }
      catch (\Exception $e) {
        return '';
      }
    }

    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
      return $path;
    }

    $base_url = '';
    try {
      $request = \Drupal::request();
      $base_url = $request->getSchemeAndHttpHost();
    }
    catch (\Exception $e) {
      $base_url = $GLOBALS['base_url'] ?? '';
    }

    if (empty($base_url)) {
      return $path;
    }

    return $base_url . $path;
  }

  /**
   * Render a log message by replacing Drupal placeholders with context values.
   *
   * Only keys starting with @, %, or ! are treated as placeholders, following
   * Drupal's logging conventions. Other context keys (uid, ip, timestamp, etc.)
   * are ignored so they don't accidentally replace substrings in the message.
   */
  protected function renderMessage(string|\Stringable $message, array $context): string {
    $message = (string) $message;
    $replacements = [];
    foreach ($context as $key => $value) {
      // Only substitute Drupal-style placeholders (@key, %key, !key).
      if (!is_string($key) || $key === '' || !in_array($key[0], ['@', '%', '!'], TRUE)) {
        continue;
      }
      if (is_string($value) || (is_object($value) && method_exists($value, '__toString'))) {
        $replacements[$key] = (string) $value;
      }
    }
    return strtr($message, $replacements);
  }

  /**
   * Convert a PSR-3 string log level to an RFC 5424 integer.
   */
  protected function levelToInt(string $level): int {
    $map = array_flip(self::LEVEL_MAP);
    return $map[$level] ?? 3;
  }

  /**
   * Format a debug backtrace into a readable string.
   */
  protected function formatBacktrace(array $trace): string {
    $lines = [];
    foreach ($trace as $i => $frame) {
      $file = $frame['file'] ?? '(unknown)';
      $line = $frame['line'] ?? '?';
      $func = $frame['function'] ?? '(unknown)';
      $class = isset($frame['class']) ? $frame['class'] . $frame['type'] : '';
      $lines[] = "#{$i} {$file}:{$line} {$class}{$func}()";
    }
    return implode("\n", $lines);
  }

}
