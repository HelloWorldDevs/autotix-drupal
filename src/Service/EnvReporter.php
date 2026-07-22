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
  protected const STATE_LAST_REPORT = 'autotix.env_last_report';
  protected const RECHECK_DAYS = 30;

  /**
   * Upper bound on npm packages reported, to keep the payload sane on
   * sites with a large committed lockfile.
   */
  protected const MAX_NPM_PACKAGES = 750;

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
      // Drupal modules (ecosystem "Drupal") plus any front-end npm
      // dependencies (ecosystem "npm") found in the codebase, so the
      // Vulnerability Scanning feed covers both stacks.
      'packages' => array_merge($this->collectModules(), $this->collectNpmPackages()),
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
   * Enumerate npm packages from lockfiles found in the codebase.
   *
   * Drupal front-end tooling (themes, build pipelines, the project root)
   * commonly ships a package.json + package-lock.json, and those JS
   * dependencies carry their own CVEs.  We surface them to the same
   * Vulnerability Scanning feed as Drupal modules, tagged ecosystem
   * "npm" so the server matches them against the npm advisory database.
   *
   * Resolved versions come from package-lock.json (every lockfile
   * format, direct + transitive).  A package.json with no lockfile is a
   * best-effort fallback: only exact-pinned versions are reported, since
   * a range like "^4.17.0" is not an installed version.  Results are
   * deduplicated by name+version and capped at self::MAX_NPM_PACKAGES.
   *
   * @return array<int, array{name: string, version: string, ecosystem: string}>
   */
  protected function collectNpmPackages(): array {
    $seen = [];
    $packages = [];
    foreach ($this->npmLockfilePaths() as $path) {
      $contents = @file_get_contents($path);
      if ($contents === FALSE || $contents === '') {
        continue;
      }
      $entries = str_ends_with($path, 'package-lock.json')
        ? self::parseNpmLockfile($contents)
        : self::parseNpmManifest($contents);
      foreach ($entries as $entry) {
        $key = $entry['name'] . '@' . $entry['version'];
        if (isset($seen[$key])) {
          continue;
        }
        $seen[$key] = TRUE;
        $packages[] = $entry + ['ecosystem' => 'npm'];
        if (count($packages) >= self::MAX_NPM_PACKAGES) {
          $this->loggerFactory->get('autotix')->warning(
            'Autotix npm scan hit the @max package cap; some dependencies were not reported.',
            ['@max' => self::MAX_NPM_PACKAGES],
          );
          return $packages;
        }
      }
    }
    return $packages;
  }

  /**
   * Candidate npm lockfile/manifest paths, most-authoritative first.
   *
   * We probe a small set of well-known locations rather than walking the
   * tree (which would descend into node_modules): the Composer project
   * root (one level above the Drupal root), the Drupal root itself, and
   * any custom theme/module/profile directory.  For each location a
   * package-lock.json wins; package.json is the fallback.
   *
   * @return array<int, string>
   */
  protected function npmLockfilePaths(): array {
    if (!defined('DRUPAL_ROOT')) {
      return [];
    }
    $roots = [dirname(DRUPAL_ROOT), DRUPAL_ROOT];
    foreach (['themes/custom', 'modules/custom', 'profiles/custom'] as $sub) {
      $roots = array_merge($roots, glob(DRUPAL_ROOT . '/' . $sub . '/*', GLOB_ONLYDIR) ?: []);
    }
    $paths = [];
    foreach (array_unique($roots) as $root) {
      if (is_file($root . '/package-lock.json')) {
        $paths[] = $root . '/package-lock.json';
      }
      elseif (is_file($root . '/package.json')) {
        $paths[] = $root . '/package.json';
      }
    }
    return $paths;
  }

  /**
   * Parse a package-lock.json into {name, version} pairs.
   *
   * Supports every lockfile format: v1 keeps a recursive "dependencies"
   * tree keyed by package name; v2/v3 keep a flat "packages" map keyed
   * by install path ("node_modules/<name>", possibly nested).  Entries
   * without a concrete version (link/workspace stubs, the root package)
   * are skipped.  Static + pure so it is trivially unit-testable.
   *
   * @return array<int, array{name: string, version: string}>
   */
  public static function parseNpmLockfile(string $contents): array {
    $data = json_decode($contents, TRUE);
    if (!is_array($data)) {
      return [];
    }
    $out = [];
    // v2/v3: flat "packages" map keyed by install path.
    if (isset($data['packages']) && is_array($data['packages'])) {
      foreach ($data['packages'] as $path => $info) {
        // "" is the root project; links/workspaces have no version.
        if ($path === '' || !is_array($info) || empty($info['version'])) {
          continue;
        }
        $pos = strrpos((string) $path, 'node_modules/');
        if ($pos === FALSE) {
          continue;
        }
        $name = substr((string) $path, $pos + strlen('node_modules/'));
        if ($name !== '') {
          $out[] = ['name' => $name, 'version' => (string) $info['version']];
        }
      }
    }
    // v1: recursive "dependencies" tree keyed by name.
    if (isset($data['dependencies']) && is_array($data['dependencies'])) {
      self::flattenNpmV1($data['dependencies'], $out);
    }
    return $out;
  }

  /**
   * Recursively flatten a v1 lockfile "dependencies" tree.
   *
   * @param array<string, mixed> $deps
   *   The "dependencies" map from a v1 lockfile (or a nested subtree).
   * @param array<int, array{name: string, version: string}> $out
   *   Accumulator, appended to in place.
   */
  private static function flattenNpmV1(array $deps, array &$out): void {
    foreach ($deps as $name => $info) {
      if (!is_array($info) || empty($info['version'])) {
        continue;
      }
      $out[] = ['name' => (string) $name, 'version' => (string) $info['version']];
      if (isset($info['dependencies']) && is_array($info['dependencies'])) {
        self::flattenNpmV1($info['dependencies'], $out);
      }
    }
  }

  /**
   * Parse a package.json manifest as a fallback when no lockfile exists.
   *
   * Only exact-pinned versions are returned — a range ("^1.2.0", "~1",
   * "*", "latest", a git/url/file spec) is not a resolved install and
   * would produce false advisory matches, so those are skipped.  Static
   * + pure for unit-testability.
   *
   * @return array<int, array{name: string, version: string}>
   */
  public static function parseNpmManifest(string $contents): array {
    $data = json_decode($contents, TRUE);
    if (!is_array($data)) {
      return [];
    }
    $out = [];
    foreach (['dependencies', 'devDependencies', 'optionalDependencies'] as $section) {
      if (!isset($data[$section]) || !is_array($data[$section])) {
        continue;
      }
      foreach ($data[$section] as $name => $constraint) {
        if (!is_string($constraint)) {
          continue;
        }
        $version = ltrim(trim($constraint), '=v');
        // Accept only a bare, exact semver (optionally with a
        // pre-release / build suffix); reject ranges, tags, urls.
        if (preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
          $out[] = ['name' => (string) $name, 'version' => $version];
        }
      }
    }
    return $out;
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
