<?php

declare(strict_types=1);

namespace Drupal\autotix\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the Autotix module (auto-discovered by Drush >= 12).
 */
final class AutotixCommands extends DrushCommands {

  /**
   * Report the site environment to Autotix (Vulnerability Scanning feed).
   *
   * Without --force this honors the send-only-if-changed cadence and may
   * no-op. Use --force right after enabling Vulnerability Scanning in the
   * Autotix dashboard — a previously declined report also advances the
   * recheck window, so an unforced run can stay silent for up to a month.
   */
  #[CLI\Command(name: 'autotix:report-env', aliases: ['atx-env'])]
  #[CLI\Option(name: 'force', description: 'Send even if the fingerprint is unchanged and the recheck window has not elapsed.')]
  #[CLI\Usage(name: 'drush autotix:report-env --force', description: 'Force an immediate environment report.')]
  public function reportEnv(array $options = ['force' => FALSE]): void {
    /** @var \Drupal\autotix\Service\EnvReporter $reporter */
    $reporter = \Drupal::service('autotix.env_reporter');

    $before = $reporter->lastReport()['time'] ?? 0;
    $sent = $reporter->maybeReport((bool) $options['force']);
    $last = $reporter->lastReport();

    if (($last['time'] ?? 0) === $before) {
      $this->io()->text(
        'Skipped — fingerprint unchanged and recheck window not elapsed. Re-run with --force.',
      );
      return;
    }

    if (!$sent) {
      throw new \RuntimeException(
        'Environment report failed — check connectivity and the auth token.',
      );
    }

    if (($last['stored'] ?? NULL) === FALSE) {
      $this->io()->warning(
        'Autotix received the report but declined to store it: '
        . ($last['reason'] ?? 'Vulnerability Scanning is not enabled for your organization.'),
      );
      return;
    }

    $this->io()->success('Environment reported to Autotix.');
  }

}
