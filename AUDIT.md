# Compatibility and production audit

Reference: local Python v0.6.0. Python database migration is out of scope.

## Implemented fixes

- [X] Hold interrupted mutations for review instead of automatically replaying them.
- [X] Persist absolute recharge values before reset and recharge writes, identifying the last write phase. Record the target username and previous subscription hash before revocation.
- [X] Reload jobs under MySQL locks, retain cancellation requests, and rotate worker scheduling.
- [X] Reject null mutation results as unconfirmed; checkpoint confirmed results before notifications.
- [X] Retain bulk loading message IDs, track edit replacements, restore bulk creation QR output, and fix confirmation markup.
- [X] Edit queue Home and Back navigation in place, detach its loading message from result delivery, and return Unicode-safe status alerts.
- [X] Store bulk targets, import entries, and complete report chunks in child MySQL rows. Parse imports incrementally and validate before creating users.
- [X] Commit notifications with job results in a separate MySQL outbox. Deliver reports one recipient per step, retry transient failures, and suppress permanent Telegram rejections without replaying mutations.
- [X] Preserve the expiry scan time, report real worker heartbeat age, and clean expired data in bounded passes while retaining uncertain-operation evidence.
- [X] Require matching template date wizard state, reject invalid date types, and preserve creation state after a database-save failure.
- [X] Bind configuration and ownership callbacks to their target username so an older editor cannot alter another user on the same server. Old selector buttons must be reopened.
- [X] Restore fresh Home menus and reject malformed command server IDs before panel lookup.
- [X] Provide paginated CLI inspection of uncertain users and persisted mutation intent.

- [X] Run small statistics requests immediately with a five-second budget; resume longer scans in cron without restarting saved pages.

- [X] Preserve user deep links in paginated expiry reports.
- [X] Checkpoint node monitoring one node at a time, report unconfirmed restarts accurately, and deliver reports through bounded recipient steps.
- [X] Treat failed ownership assignment after creation as a partial operation requiring review.
- [X] Index pending queue items by job, status, and position.
- [X] Journal every single-user and bulk mutation with its before-state and intended result, then reconcile interrupted work through read-only panel checks.
- [X] Bind destructive confirmations and selectors to the current server, user, template, admin, action, and wizard state.
- [X] Paginate server, template, admin, service, configuration, and user selectors without skipping exact page boundaries.
- [X] Invalidate cached statistics after user mutations and server changes.
- [X] Bound creation and recharge template pickers to 20 entries per page with state-checked navigation.
- [X] Use callback identity for bulk submission deduplication so another action from the same edited message creates a new job.
- [X] Return template cards to their source list page and remove duplicate confirmation navigation.

The source contract executes presentation logic from the original Python tree and pins all 75 router handlers, including decorators, messages, keyboards, state transitions, CRUD calls, and panel calls. PHP workflow tests exercise the corresponding command, callback, wizard, validation, and failure surfaces.

## Remaining work and limits

- [X] Pin every Python router handler and verify the corresponding PHP command, keyboard, wizard, and panel-workflow surfaces.
- [ ] Exercise both panel types with isolated users, supported panel versions, and 16,000-user mutation workloads. Local import ingestion and a 16,000-target worker test per adapter are covered; remote mutation throughput remains untested.
- [ ] Validate live Telegram navigation, rendering, QR delivery, and final results.
- [ ] Test backup restoration and external stopped-cron monitoring.

Creation, recharge, reset, revoke, user edits, deletion, transfer, configuration changes, and bulk status changes persist their adapter-specific before-state and intended result before the remote write. `queue.php reconcile ID` only reads the panel; cron checks at most one eligible uncertain request per slice, up to three times. A job completes automatically only when every required observable field matches. Conflicts and insufficient evidence remain held for review, and reconciliation never replays a mutation.

Notifications remain pending until a send response is recorded. A crash before sending is retried by cron, including for completed jobs. A crash after Telegram accepts a message but before local acknowledgement can cause a duplicate on retry; exactly-once sending is not guaranteed. Completed mutations are never replayed to recover notifications. Imports remain limited to 8 MiB and 100,000 entries.

## Local verification

Passed: PHP syntax; 36 differential presentation fixtures executed from Python; contracts for all 75 Python router handlers; QR matrices for all 40 versions; 36 local HTTP requests across both clients; isolated MySQL upgrades; 16,000-row import ingestion; mutation recovery; cancellation, cleanup, delivery, and notification recovery. Telegram and panel transport are stubbed in local tests.
