# Compatibility and production audit

Scope: local Python v0.6.0 reference versus PHP main at `69c47e7`. Static review and available local tests; no live panel or Telegram mutations. Python database migration is explicitly out of scope.

Verdict: core workflows exist, but production readiness and complete behavioral parity are not established. These are implementation gaps, not necessary consequences of using PHP.

## Remediation in progress

The findings below describe the audited baseline. Local changes now prevent replay of interrupted mutations by holding them for review, reload worker state under the job lock, persist cancellation requests during an executing step, retain bulk message IDs, repair confirmation markup, track replacement messages, persist bulk targets and report entries in child rows, checkpoint bulk QR delivery separately, and use a real worker heartbeat. Imports are downloaded once with an 8 MiB bound, then parsed from MySQL in 64 KiB reads and committed in pages of 50 entries. Validation must finish before any imported user is created.

Verification now includes the repaired HTTP harness (36 local requests across both clients) and isolated MySQL schema tests with four concurrent workers. Additional MySQL assertions cover null mutation results, failed-job replay prevention, and cancellation. The isolated suite also ingests 16,000 users with a reload after each parsing page, tests malformed/chunked input, and verifies recovery from a child-process exit during a mutation. Full 16,000-user remote-panel mutation throughput remains untested. Delivery phases intentionally do not retry Telegram sends after an ambiguous interruption, so a crash immediately before sending may lose a notification; backend mutations are not replayed to recover it.

Scheduling now rotates eligible jobs by last service time, so a locked or long-running job does not monopolize worker attempts. Cancellation is checked before the next step and preserves uncertain-mutation evidence after a crash. Retention removes child records in batches of at most 1,000 per table per maintenance pass before compacting parent payloads. Isolated MySQL tests cover these cases.

## Baseline blocking findings

- **Uncertain mutations can replay.** `helpers/queue.php` marks an interrupted inline request runnable and later invokes recharge/revoke again. The intended absolute recharge result and revoke baseline are not persisted. Worker mutation phase is not saved before the remote call, so process death can repeat a mutation despite the exception handler. Reconcile persisted intent before retrying, or retain an explicit uncertain item requiring review.
- **Worker claims use stale state.** `BatchQueue::run()` fetches a row before acquiring its job lock and does not reload it afterward. Inline completion between those actions can be overwritten and executed again. Cancellation can also be overwritten by `save()`. Reload and validate under the lock and enforce conditional state transitions.
- **Failure can be reported as success.** `executeInline()` sets success to one even when a panel operation returns null. Creation recovery treats any existing username as successful without verifying that this job created it or that its configuration matches.
- **Bulk result delivery is incomplete.** `CallbackHandlers::queueBatch()` does not attach `message_id` for most bulk operations. The finalizer therefore has no result destination. Bulk creation also lacks the per-user QR output present in Python `app/routers/users/create.py`.

## Interface and functional findings

- Confirmation prompts for toggle/reset/revoke/delete concatenate a username with `</code>` but no opening tag in `handlers/callbacks.php`. Telegram can reject these messages; edit-to-send fallback does not repair invalid HTML.
- Edit fallback does not persist the replacement message ID into the job. Completion can leave the replacement loading message behind. Queue navigation provides Back but no dedicated Home button.
- Refresh alert truncation uses byte-oriented `substr()`, which can split UTF-8. Completed descriptions contain a literal single-quoted `\\n`. Stage labels derive from previously saved phases rather than consistently persisted current execution stages.
- Statistics still always enter the cron queue. The promised inline-first execution was added only for selected single-user operations.
- Statistics and expiry output discard users after the first 25. The expiry report does not clearly identify the omitted names. This is reduced output compared with the reference; complete paginated results are needed.

## Large-panel findings

- Bulk targets and imports remain arrays inside the parent job payload; a per-user item table was never implemented. Statistics also accumulate the full expiry-link array across pages. Earlier claims of constant memory use were incorrect.
- Raising the target count to 100,000 does not remove the 8 MiB import download limit or whole-document decoding.
- Expiry scan time changes between pages because the saved `scan.now` is not reused by the queue caller. Results can describe different time windows within one report.
- The worker selects the oldest job repeatedly. A long scan can delay all newer interactive jobs; a locked oldest row consumes attempts without advancing to another job.
- Health returns the current time as `last_run` and always returns `stale=false`; it cannot detect a stopped cron worker.

## Verification

- Passed: PHP syntax, 36 differential presentation/formatting fixtures, QR matrices for all 40 versions.
- Skipped: MySQL integration checks because local PHP lacks PDO MySQL.
- Failed: `tests/http_contract.py` exits with `Class "Storage" not found` during token lookup. Fix the harness before relying on its panel-contract coverage.
- The default test runner does not run the HTTP or MySQL contracts. Existing presentation fixtures do not prove all keyboard routes, state transitions, panel mutations, cancellation, or crash recovery.
- Python exposes `/start` and `/user`; PHP includes both and adds `/jobs`. Presence alone is not an end-to-end command compatibility test.

Required signoff: resolve the findings above, add workflow and crash-recovery tests, then exercise both panel types with isolated test users, Telegram navigation and results, and a 16,000-user dataset. Deployment success on ordinary requests does not test these failure paths.
