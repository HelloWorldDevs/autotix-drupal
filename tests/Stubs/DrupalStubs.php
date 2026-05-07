<?php

/**
 * @file
 * Minimal stubs for Drupal interfaces/classes needed by unit tests.
 *
 * These allow the module's source files to be loaded and tested without
 * a full Drupal installation.
 */

// Only declare if not already defined (e.g. when running inside Drupal).

// --- Drupal\Core\Config ---

namespace Drupal\Core\Config {

  if (!interface_exists('Drupal\Core\Config\ConfigFactoryInterface')) {
    interface ConfigFactoryInterface {
      public function get($name);
      public function getEditable($name);
    }
  }

  if (!class_exists('Drupal\Core\Config\ImmutableConfig')) {
    class ImmutableConfig {
      public function get($key) {}
    }
  }
}

// --- Drupal\Core\Cache ---

namespace Drupal\Core\Cache {

  if (!interface_exists('Drupal\Core\Cache\CacheBackendInterface')) {
    interface CacheBackendInterface {
      public function get($cid, $allow_invalid = FALSE);
      public function set($cid, $data, $expire = -1, array $tags = []);
      public function delete($cid);
      public function deleteMultiple(array $cids);
      public function deleteAll();
      public function invalidate($cid);
      public function invalidateMultiple(array $cids);
      public function invalidateAll();
      public function garbageCollection();
      public function removeBin();
    }
  }
}

// --- Drupal\Core\Queue ---

namespace Drupal\Core\Queue {

  if (!class_exists('Drupal\Core\Queue\QueueFactory')) {
    class QueueFactory {
      public function get($name, $reliable = FALSE) {}
    }
  }

  if (!interface_exists('Drupal\Core\Queue\QueueInterface')) {
    interface QueueInterface {
      public function createItem($data);
      public function numberOfItems();
      public function claimItem($lease_time = 3600);
      public function deleteItem($item);
      public function releaseItem($item);
      public function createQueue();
      public function deleteQueue();
    }
  }

  if (!class_exists('Drupal\Core\Queue\QueueWorkerBase')) {
    abstract class QueueWorkerBase {
      public function __construct(array $configuration, $plugin_id, $plugin_definition) {}
      abstract public function processItem($data);
    }
  }
}

// --- Drupal\Core\Plugin ---

namespace Drupal\Core\Plugin {

  if (!interface_exists('Drupal\Core\Plugin\ContainerFactoryPluginInterface')) {
    interface ContainerFactoryPluginInterface {
      public static function create(\Symfony\Component\DependencyInjection\ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition);
    }
  }
}

// --- Drupal\Core\Logger ---

namespace Drupal\Core\Logger {

  if (!trait_exists('Drupal\Core\Logger\RfcLoggerTrait')) {
    trait RfcLoggerTrait {
      public function emergency(string|\Stringable $message, array $context = []): void {
        $this->log(0, $message, $context);
      }
      public function alert(string|\Stringable $message, array $context = []): void {
        $this->log(1, $message, $context);
      }
      public function critical(string|\Stringable $message, array $context = []): void {
        $this->log(2, $message, $context);
      }
      public function error(string|\Stringable $message, array $context = []): void {
        $this->log(3, $message, $context);
      }
      public function warning(string|\Stringable $message, array $context = []): void {
        $this->log(4, $message, $context);
      }
      public function notice(string|\Stringable $message, array $context = []): void {
        $this->log(5, $message, $context);
      }
      public function info(string|\Stringable $message, array $context = []): void {
        $this->log(6, $message, $context);
      }
      public function debug(string|\Stringable $message, array $context = []): void {
        $this->log(7, $message, $context);
      }
    }
  }
}

// --- Drupal\Core\Session ---

namespace Drupal\Core\Session {

  if (!interface_exists('Drupal\Core\Session\AccountProxyInterface')) {
    interface AccountProxyInterface {
      public function id();
    }
  }
}

// --- Drupal\Core\Form ---

namespace Drupal\Core\Form {

  if (!class_exists('Drupal\Core\Form\FormBase')) {
    abstract class FormBase {
      protected $configFactory;
      protected $requestStack;

      public function t($string, array $args = [], array $options = []) {
        return strtr($string, $args);
      }

      public function messenger() {
        return new class {
          public function addStatus($message) {}
          public function addError($message) {}
        };
      }

      abstract public function getFormId();
      abstract public function buildForm(array $form, FormStateInterface $form_state);
      abstract public function submitForm(array &$form, FormStateInterface $form_state);
    }
  }

  if (!interface_exists('Drupal\Core\Form\FormStateInterface')) {
    interface FormStateInterface {
      public function getValue($key, $default = NULL);
      public function setErrorByName($name, $message = '');
      public function setRedirect($route_name, array $route_parameters = [], array $options = []);
    }
  }
}

// --- Drupal\Core\Url ---

namespace Drupal\Core {

  if (!class_exists('Drupal\Core\Url')) {
    class Url {
      public static function fromRoute($route_name, array $route_parameters = [], array $options = []) {
        return new static();
      }
      public function toString() {
        return '/mocked-url';
      }
    }
  }
}

// --- Drupal global class ---

namespace {

  if (!class_exists('Drupal')) {
    class Drupal {
      /** @var array */
      public static $loggerEntries = [];

      public static function logger($channel) {
        return new class($channel) {
          private string $channel;
          public function __construct(string $channel) { $this->channel = $channel; }
          public function __call($name, $args) {
            \Drupal::$loggerEntries[] = [
              'channel' => $this->channel,
              'level' => $name,
              'message' => $args[0] ?? '',
              'context' => $args[1] ?? [],
            ];
          }
        };
      }

      public static function request() {
        throw new \RuntimeException('No request in unit test context');
      }

      public static function currentUser() {
        return new class {
          public function id() { return 1; }
        };
      }

      public static function resetLoggerEntries(): void {
        static::$loggerEntries = [];
      }

      public static function getLoggerEntries(): array {
        return static::$loggerEntries;
      }
    }
  }
}

namespace Drupal\key {

  if (!interface_exists('Drupal\key\KeyRepositoryInterface')) {
    interface KeyRepositoryInterface {
      public function getKey($key_id);
    }
  }
}

namespace Drupal\Core\State {

  if (!interface_exists('Drupal\Core\State\StateInterface')) {
    interface StateInterface {
      public function get($key, $default = NULL);
      public function set($key, $value);
    }
  }
}

namespace Drupal\Core\Logger {

  if (!interface_exists('Drupal\Core\Logger\LoggerChannelInterface')) {
    // Minimal stub — the Drupal adapter only needs a PSR-3 logger interface
    // for the SDK; the channel itself just needs to be passable around.
    interface LoggerChannelInterface extends \Psr\Log\LoggerInterface {}
  }

  if (!interface_exists('Drupal\Core\Logger\LoggerChannelFactoryInterface')) {
    interface LoggerChannelFactoryInterface {
      public function get($channel);
    }
  }
}
