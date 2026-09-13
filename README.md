# HolderBot PHP

PHP implementation of [erfjab/holderbot](https://github.com/erfjab/holderbot), targeting the supplied Python `v0.6.0` behavior while supporting webhook-based hosting.

The bot supports Marzban and Marzneshin servers, user creation/search/modification, templates, admin batch operations, inline search, offline QR codes, node monitoring, and expiry reports. Telegram menus, prompts and cards follow the original implementation.

See [PARITY.md](PARITY.md) for the side-by-side audit, repaired gaps, intentional fixes to upstream bugs, test coverage, and remaining validation limits. Complete equivalence across all deployments has not been certified.

## Deploy

1. Use PHP 8.1+ with cURL, JSON, ctype and zlib. MySQL storage additionally requires PDO MySQL; optional QR backgrounds require GD.
2. Copy `config.sample.php` to `config.php`. Set the bot token, authorized admin IDs, HTTPS webhook URL, timezone and storage settings.
3. Ensure the storage directory is writable. JSON defaults to `data/storage.json`; `storage_path` can place it outside the web root. Existing PHP data is preserved; new installs start without templates.
4. Register the webhook with `php set_webhook.php set` from the deployment directory.
5. Schedule `php /absolute/path/holderbot-php/cron.php` every minute, or run `php cron.php --daemon` under a process supervisor for the original 30-second node-monitoring interval. Use one mode at a time. A lock prevents overlapping task runners.

The background runner refreshes panel access every eight hours and sends expiry reports at or after 06:00 in the configured timezone. `php cron.php --expired` explicitly runs the daily report immediately.

For an optional QR background, set `qr_background` to a local image path. Text overrides use upstream names, for example:

```php
'messages' => ['START' => 'Welcome!'],
'keyboards' => ['HOMES' => 'Home'],
```

The corresponding process environment variables also work. PHP does not automatically read Python's `.env` file.

## Verify

Tests use temporary storage and local fake services; they do not load your production configuration.

```bash
python3 -m pip install -r tests/requirements.txt
python3 tests/run_all.py /path/to/original/holderbot
```

For PHP workflow tests alone:

```bash
php tests/run.php
```

The Python source is only a test oracle. The deployed bot has no Python or Composer dependency.

Original author: [erfjab](https://github.com/erfjab). PHP repository: [MehrWizard/holderbot-php](https://github.com/MehrWizard/holderbot-php).
