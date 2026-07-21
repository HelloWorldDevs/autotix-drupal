<?php

namespace Drupal\autotix\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns webhook delivery statistics as JSON.
 */
class StatusController extends ControllerBase {

  /**
   * Return delivery stats + setup health for the status widget.
   */
  public function get(): JsonResponse {
    $state = \Drupal::state();
    $config = $this->config('autotix.settings');

    // Setup health: does the auth token resolve, and how did the last
    // environment report go? A declined env report (stored:false) means
    // Vulnerability Scanning isn't enabled for this org in Autotix.
    $tokenConfigured = FALSE;
    try {
      $tokenConfigured = \Drupal::service('autotix.client')->hasToken();
    }
    catch (\Throwable $e) {
      // Status must never 500 — leave FALSE.
    }
    $envLast = NULL;
    $envNext = NULL;
    try {
      $reporter = \Drupal::service('autotix.env_reporter');
      $envLast = $reporter->lastReport();
      $envNext = $reporter->nextCheckAt();
    }
    catch (\Throwable $e) {
      // Same — status is best-effort.
    }

    // These state values are set by WebhookClient after each delivery.
    // On a fresh install (or if no errors have been sent yet), they'll
    // all be NULL — the JS widget handles that with an "unknown" fallback.
    return new JsonResponse([
      'status' => $state->get('autotix.last_status'),
      'totalDelivered' => $state->get('autotix.total_delivered') ?? 0,
      'totalFailed' => $state->get('autotix.total_failed') ?? 0,
      'lastDeliveryAt' => $state->get('autotix.last_delivery_at'),
      'enabled' => (bool) $config->get('enabled'),
      'sendImmediately' => (bool) $config->get('send_immediately'),
      'tokenConfigured' => $tokenConfigured,
      'envLastReportAt' => $envLast['time'] ?? NULL,
      'envSent' => $envLast['sent'] ?? NULL,
      'envStored' => $envLast['stored'] ?? NULL,
      'envReason' => $envLast['reason'] ?? NULL,
      'envNextCheckAt' => $envNext,
    ]);
  }

}
