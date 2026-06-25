<?php

namespace Drupal\autotix_error\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Controller that deliberately triggers a PHP error for pipeline testing.
 */
class TestErrorController extends ControllerBase {

  /**
   * Triggers a fatal error so watchdog + Autotix can capture it end-to-end.
   */
  public function trigger() {
    // Deliberate bug: call an undefined function to generate a real
    // PHP Fatal/Error that watchdog will capture and Autotix will process.
    autotix_error_nonexistent_function_call();
  }

}
