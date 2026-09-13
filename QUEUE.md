# Persistent work queue

## Implemented scope

The PHP bot queues bulk deletion, owner transfers, config additions/removals, admin-wide enable/disable requests, multi-user creation, and JSON imports. Single-user creation and ordinary edits remain synchronous. Queued actions acknowledge submission, expose progress through `/jobs` and refresh buttons, and send a completion summary. Cancellation stops remaining work; it does not undo completed changes.

The existing cron entry processes the queue before scheduled monitoring and reports. No Redis, Composer package, additional daemon, or second cron entry is required.

```cron
* * * * * /usr/bin/php /absolute/path/holderbot-php/cron.php
```

Configure a writable local spool outside the web root:

```php
'queue_path' => '/home/account/private/holderbot-queue',
'queue_budget_seconds' => 15,
'queue_steps' => 10,
```

The default spool is `data/queue`. It is filesystem-backed for both JSON and MySQL installations. The directory and files are created with restrictive permissions and an Apache deny rule. Nginx does not interpret `.htaccess`; keep the spool outside the web root or configure a deny rule. Back up the spool along with the application's storage. It can contain subscription links and imported user parameters, but jobs do not copy panel passwords or bot tokens.

## Processing and recovery

| Stage | Work per step | Durable progress and failure handling |
| --- | --- | --- |
| Submit | Save operation parameters; split validated imports into pages | Atomic publication after input files are written; repeated submissions with the same message, actor, operation, and parameters reuse the existing job |
| Discover | Read at most 50 users from the panel | Save target pages before any mutation; failed reads retry up to three times with increasing delays and then fail the job |
| Mutate | Process one target | Save an in-flight marker before contacting the panel; save the result and advance the cursor afterward |
| Assign owner | Assign the owner of one created account | Separate from creation; an uncertain assignment does not repeat account creation |
| Deliver QR | Send one created subscription | Separate from panel mutations; a failed or interrupted send is recorded without recreating the account |
| Complete | Send one summary | Delivery is attempted separately; `/jobs` remains available if the summary send fails |

One worker holds an OS file lock; process termination releases it. Queue jobs for the same server execute in submission order to prevent one job from changing another job's discovery pages. Different servers share worker time. Per-user attempt receipts suppress duplicate targets within a job. A removed administrator or a changed server identity stops further work; password rotation is allowed.

The worker stops starting steps when its configured step count or time budget is exhausted. Panel and Telegram HTTP timeouts are capped by the remaining worker budget, including nested token requests. This bounds network waits; filesystem latency and QR image processing are not preempted by that deadline. Hosting can still terminate a process unexpectedly, so recovery uses persisted markers.

An interrupted or unsuccessful mutation is **not automatically retried**. A timeout can occur after the panel has applied the change. Such results appear as “Not confirmed” and have an issue record in the job directory. Inspect the panel before starting replacement work. A “completed” job means all targets have been accounted for, not that every mutation succeeded. Confirmed creation counts do not imply successful owner assignment or QR delivery; those failures have separate counters.

Notification delivery also avoids blind replay after an ambiguous send. This favors avoiding duplicates over guaranteed delivery. Use `/jobs` to inspect results and the existing user QR action to resend a subscription when needed.

## Limits

- Target discovery and generated creation are capped at 10,000 users per job. Split larger operations by administrator. Discovery that exceeds the cap stops before making mutations.
- JSON imports are limited to 1 MiB and 1,000 users. Downloading has a ten-second overall HTTP budget, and parsing rejects malformed, non-finite, negative, or overflowing quota values. Split larger files before uploading.
- Admin enable/disable uses the panel's existing bulk endpoint. Queuing removes the wait from the webhook but cannot subdivide or guarantee the completion of the panel's internal operation.
- Target snapshots prevent the queue's own delete/transfer pagination problem. External panel changes during discovery can still shift offset-based pages; the panel API provides no atomic snapshot here.
- A job identifies the submitted server by type, URL, and admin username. Changing those values fails the job rather than applying it to another server.
- The spool and `flock` design targets a single host with local persistent storage. Independent web and cron containers must share the same storage; multi-host distributed workers require a different locking backend.
- Job records and deduplication receipts are retained. Monitor disk usage and archive completed job payloads as needed. Deleting a whole job removes its duplicate-submission protection. Automatic retention and compact tombstones remain follow-up work.
- One-minute cron introduces queue latency. A large batch may need many runs, especially when QR uploads or panel requests are slow.

## Designs for other expensive work

These are proposed extensions, not implemented features. They should reuse the queue infrastructure rather than introduce a separate broker for each operation.

| Workload | Proposed job and checkpoint | Retry and output policy | Current exposure |
| --- | --- | --- | --- |
| Server statistics | Save page cursor, counters, reference time, and qualifying usernames; fetch one page per step | Retry read-only pages with backoff; publish only after successful completion; invalidate cached results when server identity changes | Statistics currently fetch all pages in the webhook and can time out |
| Daily expiry reports | Schedule one job per server and report date; save reference time, page cursor, and report fragments | Retry reads; mark the report generated only after successful aggregation; split output into Telegram-sized messages | Current scheduled report scans all users synchronously and can exceed cron limits or message size limits |
| Access refresh and node monitoring | Schedule one read job per server and interval; put optional node restarts in separate mutation steps | Back off failing servers; deduplicate each scheduled period; do not blindly replay an uncertain restart | Queue processing runs first, but the existing background task runner can still overrun afterward |
| General message delivery | Durable outbox with recipient, payload, delivery state, attempts, and next eligible time | Retry explicit rate-limit responses using `retry_after`; cap attempts; flag ambiguous timeouts rather than assume exactly-once delivery | Queued creation has separate QR delivery and summaries; general alerts and messages have no outbox |
| Larger imports | Stream validation into immutable target pages using a dedicated import job | Reject the entire import before mutations if any entry is invalid; cap bytes and rows; report progress | Current bounded imports are downloaded and parsed in the webhook before queuing |
| Additive recharge batches | Persist each user's desired final quota/date and its precondition before mutation | Reconcile panel state before retrying; do not repeat increment/reset operations after uncertain outcomes | No additive recharge batch job is implemented; ordinary recharge remains synchronous |
| Queue retention and scale | Compact completed jobs into deduplication tombstones; index runnable jobs and recent history | Retain issue records for a defined audit period; preserve submission identities while removing large payloads | Directory scanning and retained payloads grow with queue history |

## Validation

```bash
php tests/queue.php
python3 tests/queue_processes.py
python3 tests/run_all.py /path/to/original/holderbot
```

The queue tests cover deferred submissions, page-by-page discovery, resumption, cancellation, read failures, unconfirmed mutations, separate owner/QR delivery, revoked administrators, changed servers, config updates, and transfers. Process tests exercise concurrent submissions, worker exclusion, and actual `SIGKILL` recovery without repeating a mutation. These tests use temporary storage and fake panels; live hosting and panel behavior still require deployment validation.
