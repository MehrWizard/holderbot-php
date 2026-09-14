# HolderBot PHP

PHP webhook implementation of [erfjab/holderbot](https://github.com/erfjab/holderbot), targeting the supplied Python `v0.6.0` source. It supports Marzban and Marzneshin, user and template management, batch operations, inline search, QR delivery, node monitoring, and expiry reports.

## Differences from Python

| Area | Python | PHP |
| --- | --- | --- |
| Runtime | Long-running polling process | HTTPS webhook plus cron worker |
| Storage | Python database layout | MySQL-only schema; migrate existing data before switching |
| Callback data | Python callback serialization | PHP callback identifiers; send `/start` after switching |
| Configuration | Settings and `.env` | `config.php`; `.env` is not loaded automatically |
| Node polling | Approximately 30 seconds | One-minute cron, or `cron.php --daemon` for a similar interval |
| Batch work | Some requests run concurrently | Persistent MySQL queue with bounded steps, progress, cancellation, and retry handling |
| QR output | Python/Pillow implementation | Pure PHP byte-mode encoder; pixels may differ while encoded content remains equivalent |

Telegram and panel APIs still require JSON at their HTTP boundaries. The application does not read or write JSON files. Runtime state, cache, server records, templates, and queue jobs are stored in MySQL.

## Patched compatibility issues

- Restored Python menus, cards, prompts, pagination, templates, inline results, and text overrides.
- Fixed callback parsing, long usernames, numeric server IDs, stale wizard actions, and chat-scoped wizard state.
- Fixed Marzban and Marzneshin credential validation, token-cache identity, user creation, date changes, recharge, ownership, and config updates.
- Fixed destructive batch pagination so users are not skipped while the panel changes.
- Added Unicode digit handling, HTML escaping, bounded input validation, and correct empty API responses.
- Added full QR versions 1–40, in-memory QR uploads, optional backgrounds, MySQL migrations, and persistent queue processing.
- Repaired known upstream defects in additive recharge, template updates, inline owner handling, and credential-edit validation.

## Deployment

Requirements: PHP 8.1+, cURL, PDO MySQL, JSON support, ctype, zlib, and `CURLStringFile`. GD is optional for QR backgrounds.

1. Copy `config.sample.php` to `config.php`.
2. Set the bot token, admin IDs, HTTPS webhook URL, timezone, and MySQL credentials in `config.php`.
3. Create the database and grant table creation and migration privileges.
4. Register the webhook with Telegram using your deployment tooling.
5. Run the worker every minute:

```cron
* * * * * /usr/bin/php /absolute/path/holderbot-php/cron.php
```

For process-supervised hosting, use `php cron.php --daemon`. Do not run both modes at once. The worker uses MySQL advisory locks to prevent overlap.

Optional queue settings in `config.php`:

```php
'queue_budget_seconds' => 15,
'queue_steps' => 10,
'queue_retention_days' => 7,
```

## Queue operations

The queue is reserved for work that can exceed webhook or shared-host limits: multi-user creation and imports, bulk deletion, transfers, configuration and admin-wide status changes, full statistics scans, monitoring, access refresh, expiry reports, and other scheduled work. Single-user edits, recharge, creation, and QR delivery run immediately. Credentials are loaded from MySQL when a job runs and are not copied into queue payloads.

```bash
php queue.php health
php queue.php attention
php queue.php inspect JOB_ID
php queue.php cancel JOB_ID
```

`/jobs` in Telegram lists recent jobs for the current chat. Jobs are deduplicated by submission identity, run in bounded worker slices, and retain success and uncertain-operation counts. Remote APIs do not provide a shared transaction, so uncertain mutations require manual review.

## Verification

```bash
php tests/run.php
python3 tests/run_all.py /path/to/original/holderbot
```

The available checks cover PHP syntax, Python presentation fixtures, QR compatibility, and optional MySQL migration/HTTP contracts. Live validation still requires the PDO MySQL extension, configured Telegram credentials, and supported panel instances.

## TODO

- [ ] Run the MySQL migration and queue tests on the deployment host.
- [ ] Validate live webhook delivery, Telegram rendering, panel versions, and large batches.
- [ ] Test backup restoration and external queue/heartbeat monitoring.
- [ ] Provide a migration tool for existing Python installations if required.

Original author: [erfjab](https://github.com/erfjab). PHP repository: [MehrWizard/holderbot-php](https://github.com/MehrWizard/holderbot-php).
