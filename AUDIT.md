# Compatibility and production audit

Reference: local Python v0.6.0. Python database migration is out of scope. Core workflows exist; complete behavioral parity and production readiness are not yet established.

## Implemented fixes

- [X] Hold interrupted mutations for review instead of automatically replaying them.
- [X] Persist absolute recharge values before reset and recharge writes, identifying the last write phase. Record the target username and previous subscription hash before revocation.
- [X] Reload jobs under MySQL locks, retain cancellation requests, and rotate worker scheduling.
- [X] Reject null mutation results as unconfirmed; checkpoint confirmed results before notifications.
- [X] Retain bulk loading message IDs, track edit replacements, restore bulk creation QR output, and fix confirmation markup.
- [X] Send queue Home navigation as a fresh menu and return Unicode-safe status alerts.
- [X] Store bulk targets, import entries, and complete report chunks in child MySQL rows. Parse imports incrementally and validate before creating users.
- [X] Deliver reports one recipient per step and terminate failed notification attempts without replaying panel mutations.
- [X] Preserve the expiry scan time, report real worker heartbeat age, and clean expired data in bounded passes while retaining uncertain-operation evidence.
- [X] Require matching template date wizard state, reject invalid date types, and preserve creation state after a database-save failure.
- [X] Bind configuration and ownership callbacks to their target username so an older editor cannot alter another user on the same server. Old selector buttons must be reopened.
- [X] Restore fresh Home menus and reject malformed command server IDs before panel lookup.
- [X] Provide paginated CLI inspection of uncertain users and persisted mutation intent.

- [X] Run small statistics requests immediately with a five-second budget; resume longer scans in cron without restarting saved pages.

## Remaining work and limits

- [ ] Complete command, keyboard, wizard, and panel-workflow regression coverage against Python. Initial handler tests cover entry commands, deep links, Home, search, filtered pagination, stale owner callbacks, creation-wizard validation, template date workflows, failed quota edits, Unicode note limits, configuration selection and ownership failures; remaining workflows still need coverage.
- [ ] Exercise both panel types with isolated users, supported panel versions, and 16,000-user mutation workloads. Large import ingestion has been tested; remote mutation throughput has not.
- [ ] Validate live Telegram navigation, rendering, QR delivery, and final results.
- [ ] Test backup restoration and external stopped-cron monitoring.

Ambiguous recharge, reset, revoke, and other mutations still require operator review. Stored intent helps that review; matching remote values alone cannot prove which request applied them. No automatic mutation replay or operator resolution command is provided.

Notification delivery is best effort. A process crash between saving a delivery checkpoint and sending can lose a notification. Completed mutations are not replayed to recover notifications. Imports remain limited to 8 MiB and 100,000 entries.

## Local verification

Passed: PHP syntax; 36 differential presentation fixtures; QR matrices for all 40 versions; 36 local HTTP requests across both clients; isolated MySQL fresh and interrupted upgrades with four concurrent workers; 16,000-row import ingestion; mutation process-exit recovery; cancellation and scheduling; bounded cleanup and delivery; persisted mutation intent and paginated issue inspection. Telegram calls are stubbed in database tests.
