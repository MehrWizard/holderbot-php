# HolderBot PHP

PHP webhook implementation of [erfjab/holderbot](https://github.com/erfjab/holderbot), targeting the supplied Python `v0.6.0` source. It supports Marzban and Marzneshin, user and template management, administrator browsing, creation, search and admin-scoped user actions, batch operations, inline search, QR delivery, node monitoring, and expiry reports.

Both sudo and regular panel credentials are accepted. Regular administrators are limited to the users and operations authorized by the panel; HolderBot hides administrator management, ownership transfer, server-wide actions, and node controls for those connections.

## Differences from Python

| Area | Python | PHP |
| --- | --- | --- |
| Runtime | Long-running polling process | HTTPS webhook plus cron worker |
| Storage | Python database layout | Fresh MySQL-only installation; Python database migration is out of scope |
| Callback data | Python callback serialization | PHP callback identifiers; send `/start` after switching |
| Configuration | Settings and `.env` | `config.php`; `.env` is not loaded automatically |
| Node polling | Approximately 30 seconds | One-minute cron, or `cron.php --daemon` for a similar interval |
| Batch work | Some requests run concurrently | Persistent MySQL queue with bounded steps, progress, cancellation, and retry handling |
| Administrator browsing | Administrators appear only in action selectors | Searchable admin details, owned-user search, status totals and filters, and admin-scoped actions |
| QR output | Python/Pillow implementation | Pure PHP byte-mode encoder; pixels may differ while encoded content remains equivalent |

Telegram and panel APIs still require JSON at their HTTP boundaries. The application does not read or write JSON files. Runtime state, cache, server records, templates, and queue jobs are stored in MySQL.

## Patched compatibility issues

- Restored Python menus, cards, prompts, pagination, templates, inline results, and text overrides. Template pickers are paginated, and leaving a running job preserves the navigated menu when its result arrives.
- Fixed callback parsing, long usernames, numeric server IDs, stale confirmations and selectors, and chat-scoped wizard state.
- Fixed Marzban and Marzneshin credential validation, token-cache identity, user creation, date changes, recharge, ownership, and config updates.
- Fixed destructive batch pagination, bounded large selectors, and allowed repeated bulk actions from the same menu without reusing an earlier job.
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
'queue_budget_seconds' => 55,
'queue_cron_budget_seconds' => 300,
'scan_page_size' => 1000,
'scan_request_timeout_seconds' => 25,
'scan_parallel_requests' => 4,
'queue_retention_days' => 7,
'inline_lease_seconds' => 120,
```

Full-panel scans and bulk discovery request up to 1,000 users per page by default and adapt when a panel returns fewer. Statistics fetch up to four independent pages concurrently and checkpoint every processed page in MySQL. A slow first page is retried at the same size with `scan_request_timeout_seconds`; later pages use that extended timeout directly. The cron worker runs for up to five minutes and starts another step while at least 30 seconds remain. Overlapping minute-based cron invocations are skipped by the database lock.

## Queue operations

The queue is reserved for work that can exceed webhook or shared-host limits: multi-user creation and imports, bulk deletion, transfers, configuration and admin-wide status changes, full statistics scans, monitoring, access refresh, expiry reports, and other scheduled work. Single-user edits, recharge, creation, and QR delivery run immediately. Statistics get a five-second immediate attempt; longer scans continue in cron from saved pages. The latest completed statistics summary and its message chunks are cached in MySQL; opening Stats uses that cache, while Refresh Stats starts a new scan. Mutations are journaled before the panel request. If PHP is interrupted, cron compares the saved before-state and intended result with the panel and completes only an exact match; ambiguous results remain held for review and are never replayed. Statistics and expiry scans checkpoint each panel page; full report entries and bulk targets are stored in child MySQL rows. Imports are limited to 8 MiB and 100,000 entries, parsed incrementally, and validated before creation. Credentials are loaded from MySQL when a job runs and are not copied into queue payloads.

```bash
php queue.php health
php queue.php attention
php queue.php inspect JOB_ID
php queue.php issues JOB_ID
php queue.php reconcile JOB_ID
php queue.php cancel JOB_ID
```

`/jobs` in Telegram lists recent jobs for the current chat. Jobs are deduplicated by submission identity, rotate through bounded worker slices, and retain success and uncertain-operation counts. Cancellation stops remaining work after the current step; it cannot undo a panel request already sent. Expired job data is cleaned up incrementally; uncertain-operation evidence is retained. Results and pending notifications commit together in MySQL. Cron retries interrupted or temporary delivery failures without rerunning panel work; permanent Telegram rejections close delivery. A crash after Telegram accepts a message can cause a duplicate on retry. Report delivery advances one recipient per step. Node monitoring checkpoints each node and checks restart results. Remote APIs do not provide a shared transaction, so conflicting or unobservable outcomes require manual review. `issues` shows the last mutation target, saved intent, and up to 50 uncertain users per page.

## Verification

```bash
php tests/run.php
python3 tests/run_all.py /path/to/original/holderbot
python3 tests/mysql_contract.py
```

The checks execute presentation fixtures from the original Python source, pin all 75 upstream router contracts, and cover PHP workflows, QR compatibility, HTTP clients, MySQL migration, recovery, and delivery failures. Live validation requires configured Telegram credentials and supported panel instances.

## TODO

- [X] Test concurrent PHP schema upgrades, 16,000-row import ingestion, and interrupted mutation recovery in isolated MySQL.
- [ ] Validate live webhook delivery, Telegram rendering, panel versions, and large batches.
- [ ] Test backup restoration and external queue/heartbeat monitoring.

Original author: [erfjab](https://github.com/erfjab). PHP repository: [MehrWizard/holderbot-php](https://github.com/MehrWizard/holderbot-php).
