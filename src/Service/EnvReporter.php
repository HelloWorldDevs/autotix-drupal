<?php

namespace Drupal\autotix\Service;

use Autotix\PhpSdk\EnvReporter as CoreReporter;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\HttpFactory;

/**
 * Drupal-side adapter around \Autotix\PhpSdk\EnvReporter.
 *
 * Collects the site's environment fingerprint (Drupal version, PHP
 * version, installed modules + versions) and reports it to Autotix for
 * the Vulnerability Scanning feature.  Mirrors the WebhookClient
 * adapter: transport + auth + fingerprint live in the shared SDK; this
 * class only handles the Drupal-specific bits — enumerating modules,
 * resolving the token from Key/env, and the "report once, recheck
 * monthly, send only if changed" cadence stored in Drupal state.
 *
 * If you find yourself adding non-Drupal logic here, push it down into
 * autotix/php-sdk so WordPress and Laravel inherit it for free.
 */
class EnvReporter {

  use DependencySerializationTrait;

  protected const STATE_FINGERPRINT = 'autotix.env_fingerprint';
  protected const STATE_NEXT_CHECK = 'autotix.env_next_check';
  protected const RECHECK_DAYS = 30;

  protected ClientInterface $httpClient;
  protected ConfigFactoryInterface $configFactory;
  protected KeyRepositoryInterface $keyRepository;
  protected StateInterface $state;
  protected LoggerChannelFactoryInterface $loggerFactory;
  protected ModuleExtensionList $moduleList;

  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    KeyRepositoryInterface $key_repository,
    StateInterface $state,
    LoggerChannelFactoryInterface $logger_factory,
    ModuleExtensionList $module_list,
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->keyRepository = $key_repository;
    $this->state = $state;
    $this->loggerFactory = $logger_factory;
    $this->moduleList = $module_list;
  }

  /**
   * Collect + report the environment when due.  Called from hook_cron().
   * No-ops (without a network call) when the fingerprint is unchanged and
   * the recheck window hasn't elapsed.
   *
   * @return bool
   *   TRUE when a report was sent and accepted.
   */
  public function maybeReport(): bool {
    $env = $this->collect();
    $fingerprint = CoreReporter::fingerprint(
      $env['platform'],
      $env['platformVersion'],
      $env['runtime'],
      $env['packages'],
    );

    $last = (string) $this->state->get(self::STATE_FINGERPRINT, '');
    $next = (int) $this->state->get(self::STATE_NEXT_CHECK, 0);

    if ($fingerprint === $last && time() < $next) {
      return FALSE;
    }

    $config = $this->configFactory->get('autotix.settings');
    $token = getenv('AUTOTIX_AUTH_TOKEN') ?: $this->resolveKey($config->get('auth_token_key'));

    $reporter = new CoreReporter(
      $this->httpClient,
      new HttpFactory(),
      new HttpFactory(),
      [
        'auth_token' => $token,
        'debug' => (bool) $config->get('debug'),
        'user_agent' => 'Autotix-Drupal/1.0',
      ],
      $this->loggerFactory->get('autotix_internal'),
    );

    $sent = FALSE;
    try {
      $sent = $reporter->report($env);
    }
    catch (\Throwable $e) {
      // Telemetry must never break cron — log + let the next run retry.
      $this->loggerFactory->get('autotix')->warning(
        'Autotix env report failed: @message',
        ['@message' => $e->getMessage()],
      );
    }

    // Persist regardless of outcome so a transient failure doesn't hammer
    // the endpoint every cron tick.
    $this->state->set(self::STATE_FINGERPRINT, $fingerprint);
    $this->state->set(self::STATE_NEXT_CHECK, time() + self::RECHECK_DAYS * 86400);

    return $sent;
  }

  /**
   * Build the env report payload from Drupal state.
   *
   * @return array{
   *   siteUrl: string,
   *   platform: string,
   *   platformVersion: string,
   *   runtime: string,
   *   packages: array<int, array{name: string, version: string, ecosystem: string}>,
   *   projectKey: ?string,
   * }
   */
  public function collect(): array {
    $config = $this->configFactory->get('autotix.settings');
    return [
      'siteUrl' => \Drupal::request()->getSchemeAndHttpHost(),
      'platform' => 'drupal',
      'platformVersion' => \Drupal::VERSION,
      'runtime' => 'php ' . PHP_VERSION,
      'packages' => $this->collectModules(),
      'projectKey' => $config->get('project_key') ?: NULL,
    ];
  }

  /**
   * Enumerate installed modules as {name, version} pairs.  Drupal
   * modules are tracked on drupal.org (ecosystem "Drupal"); the module
   * machine name is the stable identifier a Drupal.org advisory feed
   * matches against.  Modules with no version (dev checkouts, core
   * pseudo-modules) are skipped.
   *
   * @return array<int, array{name: string, version: string, ecosystem: string}>
   */
  protected function collectModules(): array {
    $packages = [];
    foreach ($this->moduleList->getAllInstalledInfo() as $name => $info) {
      $version = $info['version'] ?? '';
      if ($version === '') {
        continue;
      }
      $packages[] = [
        'name' => $name,
        'version' => $version,
        'ecosystem' => 'Drupal',
      ];
    }
    return $packages;
  }

  /**
   * Resolve a Key entity ID to its raw value, or NULL if missing.
   * Mirrors WebhookClient::resolveKey().
   */
  protected function resolveKey(?string $key_id): ?string {
    if (empty($key_id)) {
      return NULL;
    }
    $key = $this->keyRepository->getKey($key_id);
    if (!$key) {
      return NULL;
    }
    $value = $key->getKeyValue();
    return is_string($value) && $value !== '' ? $value : NULL;
  }

}
