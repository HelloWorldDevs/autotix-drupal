<?php

namespace Drupal\autotix\Service;

use Autotix\PhpSdk\EnvReporter as CoreReporter;
use Drupal\autotix\EnvVarTrait;
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
  use EnvVarTrait;

  protected const STATE_FINGERPRINT = 'autotix.env_fingerprint';
  protected const STATE_NEXT_CHECK = 'autotix.env_next_check';
  protected const STATE_LAST_REPORT = 'autotix.env_last_report';
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
  public function maybeReport(bool $force = FALSE): bool {
    $env = $this->collect();
    $fingerprint = CoreReporter::fingerprint(
      $env['platform'],
      $env['platformVersion'],
      $env['runtime'],
      $env['packages'],
    );

    $last = (string) $this->state->get(self::STATE_FINGERPRINT, '');
    $next = (int) $this->state->get(self::STATE_NEXT_CHECK, 0);

    // $force bypasses the send-only-if-changed cadence — used by the
    // "Report environment now" button and drush autotix:report-env, e.g.
    // right after enabling Vulnerability Scanning server-side (a declined
    // report also advances the recheck window, so without force the site
    // would stay silent for up to a month).
    if (!$force && $fingerprint === $last && time() < $next) {
      return FALSE;
    }

    $config = $this->configFactory->get('autotix.settings');
    // envVar() (not getenv()) so a platform-set token still resolves under
    // PHP-FPM's clear_env — the env report can run in a web request too.
    $token = $this->envVar('AUTOTIX_AUTH_TOKEN')
      ?? $this->resolveKey($config->get('auth_token_key'));

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

    // Record the server's verdict for the settings-page status widget:
    // sent+stored = accepted; sent+stored:false = declined (reason says
    // Vulnerability Scanning is off server-side); !sent = network failure.
    $result = $reporter->getLastResult();
    $this->state->set(self::STATE_LAST_REPORT, [
      'time' => time(),
      'sent' => $sent,
      'stored' => $result['stored'] ?? NULL,
      'reason' => $result['reason'] ?? NULL,
    ]);

    return $sent;
  }

  /**
   * Last report outcome for the status widget, or NULL if never reported.
   *
   * @return array{time: int, sent: bool, stored: ?bool, reason: ?string}|null
   */
  public function lastReport(): ?array {
    $value = $this->state->get(self::STATE_LAST_REPORT);
    return is_array($value) ? $value : NULL;
  }

  /**
   * Next scheduled env re-check (Unix timestamp), or NULL if none stored.
   */
  public function nextCheckAt(): ?int {
    $next = (int) $this->state->get(self::STATE_NEXT_CHECK, 0);
    return $next > 0 ? $next : NULL;
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
