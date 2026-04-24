<?php

namespace Drupal\autotix\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Triggers a test watchdog error to verify the Autotix pipeline.
 */
class TestController extends ControllerBase {

  /**
   * Generate a test error and redirect back to settings.
   */
  public function trigger() {
    // Log a test error that the WebhookLogger will pick up.
    \Drupal::logger('autotix_test')->error(
      'Test error from Autotix module. If you see this in your webhook log, the module is working correctly. Timestamp: @time',
      ['@time' => date('Y-m-d H:i:s')]
    );

    $config = $this->config('autotix.settings');
    if ($config->get('send_immediately')) {
      $this->messenger()->addStatus(
        $this->t('Test error has been sent to Autotix. Check your dashboard.')
      );
    }
    else {
      $this->messenger()->addStatus(
        $this->t('Test error has been queued. Run cron to deliver it, then check your Autotix dashboard.')
      );
    }

    return new RedirectResponse(
      Url::fromRoute('autotix.settings_form')->toString()
    );
  }

}
