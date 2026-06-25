# Autotix for Drupal

Captures watchdog errors from your Drupal 10/11 site and forwards them to [Autotix](https://autotix.io), which turns them into tickets (Jira or GitHub Issues) and AI-generated fix PRs automatically.

> This repository is a read-only mirror, split from the Autotix monorepo. Issues and PRs are welcome here and will be applied upstream.

## Requirements

- Drupal `^10.3 || ^11`
- PHP >= 8.1
- [Key](https://www.drupal.org/project/key) module (secure credential storage)

## Install

```bash
composer require autotix/drupal-module
drush en autotix
```

(The shared `autotix/php-sdk` core is bundled in `lib/php-sdk/` as a fallback, so the module also works when copied into `modules/custom` without Composer.)

## Configure

1. Create a Key (Administration → Configuration → System → Keys) holding your org webhook token from app.autotix.io → Settings.
2. Go to **Administration → Configuration → Web services → Autotix** (`/admin/config/services/autotix`).
3. Select the key, set your severity threshold and environment, and enable capture.

Settings include:

| Setting | Default | Purpose |
| --- | --- | --- |
| `enabled` | `false` | Master switch. |
| `auth_method` | `token` | `token` (X-Webhook-Token) or `hmac` (HMAC-SHA256). |
| `severity_threshold` | `3` (Error) | Minimum RFC 5424 severity to forward. |
| `dedup_window` | `86400` | Seconds to suppress repeat sends of the same error. |
| `send_immediately` | `false` | Send inline instead of on destruct. |
| `include_backtrace` | `false` | Attach a formatted backtrace. |

## Testing the pipeline

Two options, from safest to most thorough:

1. **Logged-error test (built in).** Visit
   `/admin/config/services/autotix/test` to send a deliberate *logged* error
   end-to-end and confirm a ticket shows up in Autotix.
2. **Fatal-error test (optional submodule).** This module bundles a companion
   submodule, **Autotix Test Error** (`autotix_error`), that exercises the
   genuine uncaught-PHP-error path. Enable it, then visit `/autotix-test-error`
   (administrators only) to trigger a real fatal error:

   ```bash
   drush en autotix_error
   ```

   Leave it disabled in production — it only exists to verify capture.

## Development

```bash
composer install
vendor/bin/phpunit
```

The bundled SDK copy is kept in sync with the canonical source by `bin/sync-sdk.sh` and guarded by `BundledSdkSyncTest`.

## License

GPL-2.0-or-later
