# Python / PHP parity audit

Baseline: the original Python tree supplied alongside this PHP repository, reporting `v0.6.0`. `tests/upstream_manifest.json` records the SHA-256 of each Python source file. This audit does not assert that the supplied tree equals the current GitHub default branch.

The previous README's unconditional “100% / 1:1” claims were not accurate. This revision repairs the differences below and adds executable comparisons. Passing these checks is evidence for the covered behavior, not proof of equivalence for every Telegram update, panel version, or deployment.

## Side-by-side findings

| Area | Original Python | Previous PHP gap | Current implementation / evidence |
| --- | --- | --- | --- |
| Home and server menus | Specific labels, order, two-column rows | Different welcome, icons, ordering, and extra prose | Restored; differential fixtures execute original keyboard methods |
| User/template/server cards | Panel-specific fields; timestamps; exact byte/date formatting | Simplified cards omitted owner, flags, reset strategy, activity, timestamps | Restored fields and formatting; user cards and captions compared to Python |
| User lists | Ten items per page, two columns, emoji filters | Requested eleven, displayed ten; page offsets skipped records | Ten-item requests; original labels and pagination |
| Search and inline | `/user` preserves the entire search; integer server ID; short share card | Search truncation, non-integer IDs, different article IDs and captions | Fixed; workflow and inline assertions |
| Server creation | Remark → panel-type buttons → three credentials in one message | Five separate typed steps | Original sequence; invalid credentials remain retryable |
| Server/template callbacks | IDs remain intact | Several hard-coded substring offsets removed the first ID digit | Prefix-derived lengths; callback round-trip tests |
| Credentials | Validate sudo credentials before saving | Cached token could validate a different password for the same host | Cache bound to host, username, password; local HTTP test rejects changed bad credentials |
| User creation | Select admin, even when only one exists; optional templates; numeric suffix; configs required | Skipped single-admin selection; date callback mismatch; JSON could bypass empty-config check | Repaired; single, JSON, date and config workflows tested |
| Marzneshin creation | POST `/api/users` | Called a private PHP request method and failed at runtime | Callable request boundary; tested through native cURL |
| User configs | All configs initially selected; toggle/all/none/done | Generated callbacks did not match parser; failed empty save discarded state | Unified callback protocol, valid IDs, original initial selection |
| User edits | Report actual panel result | Data/date/note edits could announce success after failure | Checked responses and original success/failure screens |
| Recharge | Normal, reset usage, additive; preserve selected date strategy | Failed writes masked by rereading user; additive hold duration replaced; extra Marzneshin enable call | Checked mutation response, additive duration, reset-before-write, panel-specific payloads |
| Date edits | Marzban status changes with date strategy | Previously omitted status, leaving users on hold/disabled | Shared date payload builder; native HTTP assertions |
| Batch operations | Delete/transfer selected admin's users; report counts | Mutating a paginated result could skip later users | Snapshot targets before mutations; 61-user deletion and transfer tests |
| Batch config counts | Count users actually requiring changes | Counted unaffected users too | Corrected denominator |
| User identity | Full username, actual owner, distinct active/enabled flags | Truncated usernames; owner array used as string; active conflated with activated | Fixed; long callback values stored behind references |
| Templates | Empty initial database; editable date type | Invented default plans; different button captions and result screens | Removed implicit seed plans, restored presentation, zero-day edit covered |
| Numeric input | Unicode decimal digits accepted by Python integer conversion | ASCII-only input | Persian, Arabic and other Unicode decimal digits normalized in numeric states |
| Wizard persistence | Separate state per user and chat | Same admin's groups/private chat shared one state | `(user_id, chat_id)` scope with private-state compatibility |
| JSON storage | Database mutations do not overwrite unrelated writes | Read/modify/write races could discard other updates | Entire mutation locked; 360 concurrent mutations tested |
| Chat cleanup | Tracks and removes wizard prompts | Mostly only `/start` menu cleanup | Prompt tracking added; created QR photos remain in chat |
| Text settings | MessageTexts / KeyboardTexts overrides | Fixed strings | Matching setting names supported through config arrays or process environment |
| Access refresh | On startup and every eight hours | Missing independent job; reports could remain offline indefinitely | Restored refresh task, independent of monitoring being enabled |
| Node monitoring | Panel-specific failure states, one aggregate alert, optional restart | Different alert format; extra failure statuses | Original alert fields and status rules; disabled nodes never restarted |
| Expiry report/stats | Integer-hour buckets and original links/counters | Different thresholds, header, flags and link formatting | Matched supplied source, including its exclusion of zero-hour buckets |
| QR encoding | ECC M, modules 8 px, border 2, versions up to 40 | Only versions 1–10; border 4; reversed secondary format bits | Fixed format bits and full version table; all 40 versions match reference byte-mode matrices |
| QR background | Optional image, max 1000 px, centered QR at 60% | Missing feature | Optional local background via PHP GD |

## Deliberate differences from broken upstream paths

Reproducing source bugs would prevent the rewrite from completing the intended actions:

- Python's additive Marzneshin recharge passes a `timedelta` to a model expecting `datetime`. PHP produces the resulting UTC expiration date.
- Python's additive Marzban recharge can add a `datetime` to an integer when no expiry exists. PHP uses the current timestamp as that fallback. Existing expiries, including past expiries, remain the additive base.
- Python's template date-edit handlers omit the template ID when invoking CRUD. PHP updates the selected template.
- The Python Marzban inline handler reads `owner_username`, absent from its response model. PHP reads the admin username.
- The Python credential-edit type map omits Marzban. PHP validates edits for both supported panels.
- Python's destructive batch loops also have the changing-page issue. PHP snapshots before mutation so the requested operation reaches every selected user.
- Invalid input that crashes a Python handler is rejected without crashing PHP. HTML values are escaped rather than allowing user-supplied markup to break Telegram requests.

These are intentional functional repairs, not literal bug-for-bug equivalence.

## Operational and visual limits

- PHP uses webhooks; Python uses polling. Their storage formats and callback serialization are different. Existing Python callback messages and Python databases are not directly interchangeable with PHP; start a new menu after switching implementations.
- `php cron.php --daemon` provides a 30-second node interval. A once-per-minute cPanel cron provides only a 60-second interval. Both run the daily report at or after 06:00 in the configured timezone. A delayed cron run can send a catch-up report.
- PHP QR generation uses byte mode. Python can optimize numeric/alphanumeric segments and choose a different mask. Both encode the same subscription, but PNG bytes and module patterns are not guaranteed identical. GD background resampling also differs from Pillow's Lanczos filter.
- PHP executes panel mutations sequentially; Python batches some requests concurrently. This affects latency, especially with many users. Hosting request timeouts still apply to large webhook batches.
- Empty JSON imports and malformed numeric JSON values are rejected rather than following Python paths that can fail after parsing.
- QR-background compositing remains untested because GD is unavailable. MySQL schema upgrades were tested against isolated MariaDB with PDO MySQL loaded explicitly: fresh, legacy, and interrupted migrations each passed with four concurrent workers. This does not cover every MySQL version or storage operation.
- No production bot token or panel credentials were used. No Telegram messages were sent, live accounts changed, webhook registered, or deployment performed. Live Telegram rendering, panel-version compatibility, webhook retries and hosting limits remain deployment validation work.

## Reproduce the checks

PHP 8.1+ with cURL, JSON, ctype and zlib is required. Python is only needed for reference tests, not to run the PHP bot. Install `tests/requirements.txt` in a test environment for the QR comparison.

```bash
python3 tests/run_all.py /path/to/original/holderbot
```

The suite lints PHP, exercises mocked Telegram workflows, executes original Python presentation methods with framework plumbing stubbed, runs actual PHP cURL against a local fake panel, checks concurrent JSON writes, and compares all 40 QR versions with python-qrcode. None of these tests loads the production `config.php`.

## Combined-diff regression review

The pre-commit review repaired empty JSON array handling in both HTTP clients (empty node/admin lists must stay empty), rejected stale creation callbacks whose server or wizard step differs from the active session, preserved numeric-looking Marzban config tags, and made MySQL schema upgrades serialized and resumable. The full suite passes with 62 workflow assertions, 36 Python differential fixtures, 34 local HTTP requests, 360 concurrent JSON mutations, and all 40 QR versions.

The optional database test requires local MariaDB server tools and the PHP `pdo_mysql` extension. It creates and removes its own temporary database instance:

```sh
python3 tests/mysql_contract.py
```
