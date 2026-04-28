<?php

namespace Drupal\autotix\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns webhook delivery statistics as JSON.
 */
class StatusController extends ControllerBase {

  /**
   * Return delivery stats for the status widget.
   */
  public function get(): JsonResponse {
    $config = $this->config('autotix.settings');
    $state = \Drupal::state();

    // These state values are set by WebhookClient after each delivery.
    // On a fresh install (or if no errors have been sent yet), they'll
    // all be NULL — the JS widget should handle that gracefully.
    return new JsonResponse([
      'status' => $state->get('autotix.last_status'),
      'totalDelivered' => $state->get('autotix.total_delivered') ?? 0,
      'totalFailed' => $state->get('autotix.total_failed') ?? 0,
      'lastDeliveryAt' => $state->get('autotix.last_delivery_at'),
    ]);
  }

}
