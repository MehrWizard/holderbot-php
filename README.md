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
| Node monitoring | 30-second scheduled interval | One-minute cron schedules monitoring jobs; the optional daemon schedules at approximately 30-second intervals. Actual execution depends on backlog |
| Daily reports | Scheduled report at 06:00 | Queued at or after 06:00 in the configured timezone; delivery follows paginated processing and outbox progress |
| Node alerts | Single aggregate alert | Individual node alerts record confirmed or unconfirmed restart outcomes |
| Batch execution | Some panel requests run concurrently | Creation, recharge, bulk actions, imports, statistics, and scheduled work use a persistent cron-driven queue; progress and cancellation are available through `/jobs`. Short edits and menu responses remain synchronous |
| QR appearance | Can optimize numeric/alphanumeric segments; Pillow background resizing | Byte-mode QR encoding and GD resizing; subscription content is preserved, but module patterns and image pixels can differ |
| Invalid input | Some malformed inputs fail during processing | Invalid JSON and unsafe numeric values are rejected; imports are capped at 8 MiB/10,000 users and generated batches at 10,000 users; HTML values are escaped |
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

The same cron entry schedules periodic jobs and processes persistent work within a bounded worker slice. Configure `queue_path` outside the web root where possible. See [Persistent queue](#persistent-queue) for implemented workloads, configuration, recovery rules, and production operations.

The background runner refreshes panel access every eight hours and sends expiry reports at or after 06:00 in the configured timezone. `php cron.php --expired` explicitly queues a fresh daily report; delivery follows worker progress.

For an optional QR background, set `qr_background` to a local image path. Text overrides use upstream names, for example:

```php
'messages' => ['START' => 'Welcome!'],
'keyboards' => ['HOMES' => 'Home'],
```

The corresponding process environment variables also work. PHP does not automatically read Python's `.env` file.

## Persistent queue

### Scope

One local persistent queue handles user creation, JSON imports, recharge, bulk deletion, owner transfers, config changes, admin-wide enable/disable, QR delivery, server statistics, access refresh, node monitoring, and daily expiry reports. Scheduled reports and statistics scan one page at a time. Cron schedules work and runs a bounded worker slice; it no longer performs an unbounded panel scan afterward.

Ordinary short edits, searches, menu responses, and callback acknowledgements remain synchronous. This is a single-host design using local files and OS locks for both JSON and MySQL installations. No Redis, Composer dependency, or additional cron entry is required.

### Deployment

Webhook and CLI processes must share a writable, persistent spool. Put it outside the web root:

```php
'queue_path' => '/home/account/private/holderbot-queue',
'queue_budget_seconds' => 15,
'queue_steps' => 10,
'queue_retention_days' => 7,
```

```cron
* * * * * /usr/bin/php /absolute/path/holderbot-php/cron.php
```

Use the PHP executable that has the required extensions and access to the same configuration. The alternative `php cron.php --daemon` schedules a slice approximately every 30 seconds. Use one mode. Cron and worker locks prevent overlapping execution. `php queue.php work` also runs one bounded slice, without scheduling additional periodic jobs.

The default spool is `data/queue`. New directories and files use restrictive permissions and an Apache deny rule. Nginx does not read `.htaccess`; use a private path or an explicit server deny rule. Queue payloads can contain subscription links and imported account parameters. Panel passwords and bot tokens are not copied into jobs. Back up queue files with application storage.

The spool is not a distributed queue. Do not run workers on independent hosts or storage where `flock` and atomic rename do not have local-filesystem semantics. Files are flushed before atomic replacement; interruption recovery assumes persistent local storage, not loss of the filesystem or a stale backup restore.

### Workload behavior

| Work | Checkpoint | Completion and recovery |
| --- | --- | --- |
| User creation | One account per mutation; owner assignment and QR delivery are separate steps | Failed delivery never recreates an account; uncertain creation or ownership writes are recorded for inspection |
| JSON import | Download in the worker, then validate up to 50 entries per step into immutable pages | No accounts are created until the entire array validates; malformed later entries reject the whole import |
| Bulk deletion and transfer | Discover 50 targets per step before starting mutations | Saved target pages avoid the queue's own changing-pagination problem; per-user receipts suppress duplicate targets |
| Config changes | Read the user's current config list, then write the updated list | Preserve unrelated selections; no-op users are counted separately |
| Admin enable/disable | One panel bulk-endpoint request | Moves the request out of the webhook; cannot subdivide the panel's internal operation |
| Recharge | Save absolute desired quota/expiry and preconditions; reset, write, and reconciliation are separate steps | Changed preconditions stop work. An uncertain quota write is compared with saved intent; increments are not replayed. An uncertain reset stops further work |
| Statistics | Fixed reference time, page cursor, counters, and qualifying-user fragments | Failed reads do not advance counters; output is published only after the complete scan |
| Daily expiry report | One scheduled job per server/date, with paginated aggregation | Failed reads retry; reports are generated before their outbox deliveries. Explicitly retry a failed same-day job through the CLI |
| Access refresh | One job per server per eight-hour period | Read/authentication failures back off; successful validation updates the online marker |
| Node monitoring | Read node status, checkpoint each optional restart, then publish alerts | Uncertain restarts are not repeated within the job. Alerts identify the server/node and confirmed or unconfirmed restart result |
| Message outbox | One message per job with recipient, next eligible time, and attempt count | Explicit Telegram 429 rejections honor `retry_after`, with a bot-wide delivery cooldown and at most five rejected attempts. Ambiguous sends are not blindly repeated |
| Retention | Incremental removal of old payloads | Preserve compact metadata tombstones and submission identities; remove large imports, target pages, and issue files after the retention period |

Statistics and report fragments are split into complete HTML units below 3,900 bytes. Output order is preserved within each chat's message outbox, including when the first message is waiting for a rate-limit retry. Node alerts are sent per node rather than as the original bot's single aggregate message. Access alerts and completion summaries also use the outbox. Created QR delivery uses a separate checkpoint in its creation job and observes the same explicit rate-limit cooldown.

Scheduled jobs coalesce pending periods: a slow server does not accumulate a job for every missed monitoring tick. Mutation jobs for one server execute in submission order. Read-only jobs and deliveries can progress alongside those mutations. This avoids delaying monitoring behind a large creation batch, but reports are observational scans, not transactionally consistent database snapshots.

### Time and size limits

- The default worker slice starts at most ten steps within a 15-second network budget. Configured values are capped at 100 steps and 45 seconds. Panel and Telegram HTTP waits, including nested authentication, use the remaining budget.
- The deadline cannot preempt filesystem operations or image encoding. QR backgrounds are limited to 8 MiB and eight million pixels before decoding. Local processing and hosting termination still require deployment validation.
- Imports accept at most 8 MiB and 10,000 users, with an individual JSON entry limited to approximately 64 KiB. Downloads have a ten-second HTTP budget. The raw download is bounded in memory; decoded validation is incremental.
- Generated batches and discovered mutation targets are capped at 10,000 users. Larger work must be split by administrator or input file. Statistics and expiry scans are paginated without that mutation cap.
- Admission stops at 1,500 runnable jobs for new work, reserving space up to 2,000 for outbox messages. Full queues defer internal publishing; new submissions receive a failure response. Monitor backlog rather than raising limits without load testing.
- Three consecutive read failures cause a job to fail, with increasing backoff before the final failure. Permanent invalid input fails immediately.
- Admin bulk endpoints, offset-based panel pagination, and changes made directly on the panel remain outside the queue's control. External changes can shift target pages during discovery.

### Durability and duplicate policy

Submission identity includes the chat, actor, source message, operation, server ID, and parameters. Repeated submissions with the same identity reuse the same job. The worker persists an in-flight marker before mutations and sends. A process killed between a remote operation and its local checkpoint cannot cause an automatic replay of that operation.

A completed job means its work has been accounted for; it does not imply every operation succeeded. Inspect confirmed, unconfirmed, owner-assignment, and delivery counts. Issue files identify affected users and recovery reasons. Account creation can be confirmed while ownership or QR delivery remains unconfirmed.

Telegram and panel APIs do not provide a shared transaction with the queue. Exactly-once remote execution is therefore not guaranteed. Explicit 429 rejection is retryable; a connection timeout, malformed response, interrupted send, or other failed mutation remains uncertain. No general automatic mutation replay is provided. Manual resend deliberately creates a new delivery and can duplicate a message if the earlier outcome was uncertain.

Jobs recheck administrator authorization and server identity before work. Changed type, URL, or panel-admin username stops the job; password rotation is allowed. System outbox deliveries recheck that their recipient is still a configured administrator. Cancellation stops future steps and does not undo completed changes or recall already published outbox messages.

### Status and operator commands

In Telegram, `/jobs` shows the submitting user's recent work in the current chat, with refresh and cancellation controls. Completion status includes a summary-delivery job ID for inspection. A failed summary can be inspected independently of the original operation.

Run these commands on the host:

```bash
php queue.php health
php queue.php attention
php queue.php inspect JOB_ID
php queue.php issues JOB_ID
php queue.php cancel JOB_ID
php queue.php retry-read JOB_ID
php queue.php resend OUTBOX_JOB_ID
```

`health` reports the worker heartbeat, backlog, and jobs needing attention. It exits nonzero if the worker has not run within three minutes or its last run reported an error. Monitor that command externally: the bot cannot reliably alert you through its own queue when cron is stopped. `attention` lists up to 100 retained failed or uncertain jobs. `inspect` omits raw subscription/import payloads; `issues` contains account identifiers and should be handled as private operational data.

`retry-read` accepts failed, unarchived statistics, expiry, access, monitoring, or import jobs with no in-flight operation. It resumes their checkpoint rather than repeating completed mutations. Invalid import files must be corrected and submitted as new jobs. `resend` requires an unarchived outbox job and creates a distinct manual delivery. There is no command that blindly retries account creation, reset, deletion, or recharge mutations.

Corrupt runnable metadata fails the worker closed and appears in health output. Repair it from a trusted backup before resuming. Do not simply remove in-flight markers: doing so can repeat remote operations. Restoring an old spool backup also requires reconciling panel state before starting the worker.

### Retention and upgrades

Ready markers index runnable work; routine worker steps do not scan completed history. Recent history is bounded per chat/user. After seven days by default, maintenance removes up to 50 payload files within a separate half-second allowance per run. Summary tombstones are partitioned by ID prefix and retained permanently so old button submissions cannot recreate jobs. Their small disk footprint still grows over time; monitor it. Deleting tombstones removes duplicate-submission protection.

Issue files are removed with retained payloads. Export issue details before the retention window if a longer audit trail is required. Archived summary counters remain inspectable, but archived payloads cannot be resent or retried.

On upgrade from the original spool layout, the worker builds the runnable index incrementally before processing jobs. Allow cron to complete that migration and avoid manually rearranging the spool while it runs. Existing job files and callback IDs remain usable.


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
php tests/production_queue.php
python3 tests/queue_processes.py
```

The optional MySQL upgrade test requires local MariaDB server tools and the PHP `pdo_mysql` extension. It creates and removes an isolated temporary database instance:

```bash
python3 tests/mysql_contract.py
```

## Validation and TODO

Checked items indicate completed implementation or validation. The queue components are implemented; unchecked items below are deployment validation and broader compatibility work.

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
- [X] Implement paginated reports, scheduled monitoring, a durable message outbox, operator commands, and incremental retention.
- [X] Pass 30 additional production queue assertions covering reports, delivery retries, imports, recharge recovery, retention, and corruption handling.
- [ ] Verify webhook and cron permissions against the private spool on the deployment host.
- [ ] Configure external heartbeat/backlog monitoring and an issue-retention policy.
- [ ] Exercise backup restoration and reconciliation procedures on staging.
- [ ] Validate live Telegram rendering, webhook delivery/retries, and supported panel-version behavior.
- [ ] Validate large batches against actual hosting execution limits.
- [ ] Test QR background compositing with GD; current runtime verification covers plain QR generation.
- [ ] Expand MySQL validation beyond schema upgrades to storage operations and supported database versions.
- [ ] Provide and test Python database migration tooling if migration of an existing installation is required.

Original author: [erfjab](https://github.com/erfjab). PHP repository: [MehrWizard/holderbot-php](https://github.com/MehrWizard/holderbot-php).
