# HolderBot PHP

PHP implementation of [erfjab/holderbot](https://github.com/erfjab/holderbot), targeting the supplied Python `v0.6.0` source and supporting webhook-based hosting.

The bot supports Marzban and Marzneshin servers, user creation, search and modification, templates, admin batch operations, inline search, local QR generation, node monitoring, and expiry reports. Telegram menus, prompts, and cards follow the original implementation.

The audited feature set is implemented. Complete behavioral equivalence and production compatibility have not been established. The comparison baseline is the supplied Python source, not necessarily the latest upstream branch. [tests/upstream_manifest.json](tests/upstream_manifest.json) records the SHA-256 hashes of the reference Python files.

## Differences from Python

| Area | Original Python | PHP implementation and impact |
| --- | --- | --- |
| Deployment | Long-running polling process | HTTPS webhook endpoint with CLI cron jobs; different hosting and installation requirements |
| Storage | Python database layout | Separate JSON/MySQL layout; existing Python data requires migration and cannot be copied directly |
| Existing Telegram buttons | Python callback serialization | Different callback identifiers; send `/start` after switching to obtain compatible menus |
| Configuration | Python settings and `.env` | `config.php` and process environment text overrides; Python's `.env` is not loaded automatically |
| Node monitoring | 30-second scheduled interval | One-minute cron is supported and accepted for this deployment; optional daemon supports 30-second intervals |
| Daily reports | Scheduled report at 06:00 | Runs at or after 06:00 in the configured timezone; delayed cron runs can send a catch-up report |
| Batch execution | Some panel requests run concurrently | Bulk actions, multi-user creation, and JSON imports use a persistent cron-driven queue; progress and cancellation are available through `/jobs`. Single-user operations remain synchronous |
| QR appearance | Can optimize numeric/alphanumeric segments; Pillow background resizing | Byte-mode QR encoding and GD resizing; subscription content is preserved, but module patterns and image pixels can differ |
| Invalid input | Some malformed inputs fail during processing | Invalid JSON and unsafe numeric values are rejected; imports are capped at 1 MiB/1,000 users and generated batches at 10,000 users; HTML values are escaped |
| Stale creation buttons | Different callback/state handling | Checks the active wizard step and server; rejects buttons that would consume another session's state |
| Upstream defects | Several source paths fail or skip requested work | Intentional repairs listed below; PHP does not reproduce those defects |

## Patched PHP issues

The following changes close gaps in earlier PHP revisions. Regression coverage establishes the behavior exercised by the tests, not equivalence for every possible input or deployment.

| Area | Previous PHP issue | Resolution and evidence |
| --- | --- | --- |
| Home and server menus | Different welcome, icons, ordering, and extra prose | Restored; differential fixtures execute original keyboard methods |
| User/template/server cards | Simplified cards omitted owner, flags, reset strategy, activity, timestamps | Restored fields and formatting; user cards and captions compared to Python |
| User lists | Requested eleven, displayed ten; page offsets skipped records | Ten-item requests; original labels and pagination |
| Search and inline | Search truncation, non-integer IDs, different article IDs and captions | Fixed; workflow and inline assertions |
| Server creation | Five separate typed steps | Original sequence; invalid credentials remain retryable |
| Server/template callbacks | Several hard-coded substring offsets removed the first ID digit | Prefix-derived lengths; callback round-trip tests |
| Credentials | Cached token could validate a different password for the same host | Cache bound to host, username, password; local HTTP test rejects changed bad credentials |
| User creation | Skipped single-admin selection; date callback mismatch; JSON could bypass empty-config check | Repaired; single, JSON, date and config workflows tested |
| Marzneshin creation | Called a private PHP request method and failed at runtime | Callable request boundary; tested through native cURL |
| User configs | Generated callbacks did not match parser; failed empty save discarded state | Unified callback protocol, valid IDs, original initial selection |
| User edits | Data/date/note edits could announce success after failure | Checked responses and original success/failure screens |
| Recharge | Failed writes masked by rereading user; additive hold duration replaced; extra Marzneshin enable call | Checked mutation response, additive duration, reset-before-write, panel-specific payloads |
| Date edits | Previously omitted status, leaving users on hold/disabled | Shared date payload builder; native HTTP assertions |
| Batch operations | Mutating a paginated result could skip later users | Snapshot targets before mutations; 61-user deletion and transfer tests |
| Batch config counts | Counted unaffected users too | Corrected denominator |
| User identity | Truncated usernames; owner array used as string; active conflated with activated | Fixed; long callback values stored behind references |
| Templates | Invented default plans; different button captions and result screens | Removed implicit seed plans, restored presentation, zero-day edit covered |
| Numeric input | ASCII-only input | Persian, Arabic and other Unicode decimal digits normalized in numeric states |
| Wizard persistence | Same admin's groups/private chat shared one state | `(user_id, chat_id)` scope with private-state compatibility |
| JSON storage | Read/modify/write races could discard other updates | Entire mutation locked; 360 concurrent mutations tested |
| Chat cleanup | Mostly only `/start` menu cleanup | Prompt tracking added; created QR photos remain in chat |
| Text settings | Fixed strings | Matching setting names supported through config arrays or process environment |
| Access refresh | Missing independent job; reports could remain offline indefinitely | Restored refresh task, independent of monitoring being enabled |
| Node monitoring | Different alert format; extra failure statuses | Original alert fields and status rules; disabled nodes never restarted |
| Expiry report/stats | Different thresholds, header, flags and link formatting | Matched supplied source, including its exclusion of zero-hour buckets |
| QR encoding | Only versions 1–10; border 4; reversed secondary format bits | Fixed format bits and full version table; all 40 versions match reference byte-mode matrices |
| QR background | Missing feature | Optional local background via PHP GD |
| HTTP responses | Empty JSON lists became synthetic success records | Both clients preserve empty lists, including node/admin results |
| Creation callbacks | Stale buttons could reuse another wizard's state | Reject callbacks with mismatched steps or servers; covered by workflow tests |
| Config identifiers | Numeric-looking Marzban tags could be converted to integers | Preserve tag strings and validate selected IDs |
| MySQL upgrades | Concurrent or interrupted migrations could leave a partial schema | Serialize migrations and resume partial upgrades; tested against isolated MariaDB |

## Upstream bugs repaired

These are intentional behavior changes relative to defects found in the supplied Python source.

| Source defect | PHP repair |
| --- | --- |
| Additive Marzneshin recharge passes a `timedelta` to a model expecting `datetime` | Produces the resulting UTC expiration date |
| Additive Marzban recharge can combine incompatible date types when no expiry exists | Uses the current timestamp as a fallback; existing expiries, including past ones, remain the additive base |
| Template date-edit handlers omit the template ID in CRUD calls | Updates the selected template |
| Marzban inline handling reads `owner_username`, which is absent from its response model | Reads the admin username |
| Credential-edit type mapping omits Marzban | Validates credential edits for both supported panels |
| Destructive batch pagination can skip users as the result set changes | Snapshots the targets before deleting or transferring users |
| Certain invalid inputs crash handlers or break HTML messages | Validates input and escapes interpolated HTML values |

## Deployment

1. Use PHP 8.1+ with cURL, JSON, ctype and zlib. MySQL storage additionally requires PDO MySQL; optional QR backgrounds require GD.
2. Copy `config.sample.php` to `config.php`. Set the bot token, authorized admin IDs, HTTPS webhook URL, timezone and storage settings.
3. Ensure the storage directory is writable. JSON defaults to `data/storage.json`; `storage_path` can place it outside the web root. Existing PHP data is preserved; new installs start without templates.
4. Register the webhook with `php set_webhook.php set` from the deployment directory.
5. Schedule `php /absolute/path/holderbot-php/cron.php` every minute, or run `php cron.php --daemon` under a process supervisor for the original 30-second node-monitoring interval. Use one mode at a time. A lock prevents overlapping task runners.

The same cron entry processes persistent batch jobs before scheduled tasks. Configure `queue_path` outside the web root where possible. See [QUEUE.md](QUEUE.md) for operation coverage, recovery rules, configuration, and designs for other expensive requests.

The background runner refreshes panel access every eight hours and sends expiry reports at or after 06:00 in the configured timezone. `php cron.php --expired` explicitly runs the daily report immediately.

For an optional QR background, set `qr_background` to a local image path. Text overrides use upstream names, for example:

```php
'messages' => ['START' => 'Welcome!'],
'keyboards' => ['HOMES' => 'Home'],
```

The corresponding process environment variables also work. PHP does not automatically read Python's `.env` file.

## Batch queue

Bulk deletion, owner transfers, config changes, admin-wide enable/disable requests, multi-user creation, and JSON imports run through the persistent queue. Submitting an action saves a job and returns its status; the existing one-minute cron processes it in subsequent runs. No additional service or cron entry is required.

Use `/jobs` to view your ten most recent batches in the current chat. Each job provides status refresh and cancellation controls. Cancellation stops remaining work and preserves completed changes. The bot sends a summary when processing finishes.

```php
'queue_path' => '/home/account/private/holderbot-queue',
'queue_budget_seconds' => 15,
'queue_steps' => 10,
```

The queue uses local files for both JSON and MySQL installations. Webhook and cron processes must share a writable spool. Each cron run processes up to the configured step count or time budget; network timeouts are capped by the remaining budget. Large jobs can take several runs. Back up the spool alongside application storage and monitor its disk usage; automatic retention is not implemented.

Account creation, owner assignment, and QR delivery have separate checkpoints. A failed QR send cannot trigger another account creation. Interrupted or unsuccessful mutations are recorded as unconfirmed and are not automatically repeated: inspect the panel before submitting replacement work. A completed job can contain unconfirmed operations or delivery failures; check its counters.

Imports are limited to 1 MiB and 1,000 users. Generated batches and discovered target lists are capped at 10,000 users per job. Split larger work into smaller batches.

Single-user operations, server statistics, and scheduled reports remain synchronous. The queue does not eliminate their hosting limits or bound local image-processing time. [QUEUE.md](QUEUE.md) documents recovery behavior, single-host requirements, and proposed extensions for other expensive workloads.

## Verification

Tests use temporary storage and local fake services. They do not load production configuration, send Telegram messages, or modify live panel accounts. Python is required only for reference tests; the deployed bot has no Python or Composer dependency.

```bash
python3 -m pip install -r tests/requirements.txt
python3 tests/run_all.py /path/to/original/holderbot
```

For the PHP workflow tests alone:

```bash
php tests/run.php
```

For queue behavior and process recovery tests:

```bash
php tests/queue.php
python3 tests/queue_processes.py
```

The optional MySQL upgrade test requires local MariaDB server tools and the PHP `pdo_mysql` extension. It creates and removes an isolated temporary database instance:

```bash
python3 tests/mysql_contract.py
```

## Validation and TODO

Checked items indicate completed implementation or validation. Unchecked items remain open; proposed work is not an existing feature.

- [X] Restore audited menus, cards, prompts, user workflows, templates, text overrides, and scheduled jobs.
- [X] Implement optional QR backgrounds through GD, with a maximum background dimension of 1000 pixels and a centered QR sized to 60% of the resized background's smaller dimension.
- [X] Pass PHP syntax checks and 63 workflow assertions.
- [X] Pass 36 differential fixtures evaluated against the supplied Python source.
- [X] Pass 36 local HTTP requests covering both native panel clients.
- [X] Verify 360 concurrent JSON mutations across six processes without lost records.
- [X] Compare all 40 QR versions against reference byte-mode matrices.
- [X] Test fresh, legacy, and interrupted MySQL upgrades with four concurrent workers per scenario.
- [X] Add a persistent batch queue with bounded network waits, saved progress, status/cancel controls, and separate completion delivery.
- [X] Pass 28 queue assertions and process tests for concurrent submissions, worker exclusion, and termination without replaying mutations.
- [ ] Implement the report, monitoring, general outbox, and retention extensions designed in [QUEUE.md](QUEUE.md).
- [ ] Validate live Telegram rendering, webhook delivery/retries, and supported panel-version behavior.
- [ ] Validate large batches against actual hosting execution limits.
- [ ] Test QR background compositing with GD; current runtime verification covers plain QR generation.
- [ ] Expand MySQL validation beyond schema upgrades to storage operations and supported database versions.
- [ ] Provide and test Python database migration tooling if migration of an existing installation is required.

Original author: [erfjab](https://github.com/erfjab). PHP repository: [MehrWizard/holderbot-php](https://github.com/MehrWizard/holderbot-php).
