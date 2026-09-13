# HolderBot PHP `v0.6.0`

A high-performance, **zero-dependency** 1:1 PHP rewrite of HolderBot designed to run seamlessly on standard **cPanel shared hosting** or any LAMP/LEMP server using native PHP cURL, `php://input` webhooks, and zero external composer packages.

> **Original Source:** Rewritten from the original Python implementation: [https://github.com/erfjab/holderbot/](https://github.com/erfjab/holderbot/)  
> **Release Version:** `v0.6.0` (matched 1:1 with the upstream release)

---

## 🌟 Features Implemented (1:1 with Python HolderBot v0.6.0)

### 1. Panel & Server Management
- **Multi-Panel Support:** Complete native REST integration with both **Marzban** and **Marzneshin**, including a sudo-privilege check on every login - a non-sudo credential is rejected, same as the original bot.
- **Server Switching:** Manage multiple independent servers from a single Telegram bot.
- **Interactive Server Setup:** Add new servers directly from Telegram; credentials are verified before anything is saved.
- **Server Settings:** Toggle Node Monitoring, Node Auto-Restart, Expired Stats reporting (each behind a confirmation), edit remark/credentials, or remove a server.
- **Server Statistics Dashboard:** Total accounts, active/disabled/expired/limited counts, and remaining-data percentiles, scanning every user on the server (no page cap).

### 2. User & Subscription Operations
- **Status Filter Browsing:** Filter users by `Active`, `Expired`, or `Limited` with responsive pagination.
- **User Search & Deeplinks:**
  - Search by username via `/user <server_id> <username>` (always returns a results list).
  - In-bot interactive search wizard.
  - Deep-link support: `/start user_<server_id>_<username>`.
- **Telegram Inline Queries:** Search users live in any chat via `@BotUsername <server_id> <query>`.
- **Rich User Info Cards:** Real-time traffic breakdown (GB & percentage), remaining expiration duration, status, note, and subscription link.
- **User Modification & Quick Actions:**
  - ❌ / ✅ **Toggle Active/Disabled**
  - 🧪 **Recharge User ("Charge"):** Apply a template to extend an existing account, with optional traffic usage reset.
  - 📊 **Modify Data Limit:** Change total GB limit directly on an existing user.
  - ⏱️ **Modify Expiration Date:** Update remaining days directly.
  - 🗒️ **Edit Note:** Update a user's note.
  - 👤 **Change Owner:** Reassign user ownership to another admin.
  - 🔁 **Reset Traffic Usage:** Reset consumed bandwidth back to 0.
  - ⛓️ **Revoke Subscription Link:** Regenerate token & subscription URL immediately.
  - 🖼️ **QR Code:** Generated fully offline (no third-party service ever sees the subscription link).
  - 🗑️ **Delete User:** Permanently remove user with confirmation prompt.

### 3. Creation Wizard
- Validates username formatting.
- 🎲 **Random Username Generator:** 1-click random username, which then flows into the same count/template steps as a typed name.
- 1-click template selection or custom GB and duration inputs.
- Optional numeric suffix to create several accounts from one base name in a single pass.
- JSON import for bulk creation from a `.json` document (strictly validated).

### 4. Batch Server Actions (`Actions` Menu)
- **Delete Expired Users:** Automatically cleans up all expired accounts under a selected admin or globally (`ALL`), after confirmation.
- **Delete Limited Users:** Automatically cleans up all data-exhausted accounts, after confirmation.
- **Delete Admin Users:** Remove all of one admin's accounts, after confirmation (always scoped to a specific admin).
- **Bulk Enable/Disable:** Enable or disable all accounts belonging to a specific panel administrator, after confirmation.
- **Transfer Users:** Reassign all accounts from Admin A to Admin B in bulk, after confirmation.

### 5. Template Management (CRUD)
- Create new templates (Remark, Data Limit in GB, Date Limit in Days, and Date Type: unlimited / fixed date / after first use) directly inside Telegram.
- Inspect, edit, enable/disable (with confirmation), and delete templates.
- 1-click application in user creation and recharging - date type included.

### 6. Background Tasks on cPanel (`cron.php`)
- **Node Health Monitoring:** Periodically inspects node connectivity and auto-restarts failed nodes; a node an admin deliberately disabled is never treated as a failure.
- **Instant Admin Alerts:** Dispatches high-priority Telegram alerts when any node experiences failure.
- **Daily Expired Summary:** Notification listing accounts that expired in the last 24 hours, with one-click bot deeplinks.

---

## 📁 Directory Structure

```text
holderbot-php/
├── config.sample.php     # Sample configuration file
├── config.php            # Your active configuration
├── version.php           # Upstream version definition (v0.6.0)
├── index.php             # Webhook entry point (reads php://input)
├── tgbot.php             # Native cURL Telegram API wrapper with tgbot()
├── storage.php           # Zero-dependency storage (JSON file or MySQL)
├── cron.php              # Background task runner for cPanel cron jobs
├── set_webhook.php       # Webhook registration / inspection utility
├── panels/
│   ├── marzban.php       # Marzban REST client (auth, users, admins, nodes, system)
│   ├── marzneshin.php    # Marzneshin REST client (auth, users, admins, nodes, services)
│   └── panel_manager.php # Unified normalization layer & batch orchestrator
├── helpers/
│   ├── format.php        # Human-readable bytes, dates, stats, and cards
│   ├── keyboards.php     # Telegram inline keyboard builders
│   └── qrcode.php        # Zero-dependency QR code generator
├── handlers/
│   ├── commands.php      # /start, /help, /user commands & deeplinks
│   ├── callbacks.php     # Inline button actions (menus, user operations, actions)
│   ├── states.php        # Multi-step creation, recharge & modification wizards
│   └── inline.php        # Inline query search handler (@bot srv_id query)
└── data/
    └── .htaccess         # Security protection blocking browser access
```

---

## 🚀 cPanel Deployment Guide

### 1. Upload Files
1. Open **cPanel File Manager**.
2. Navigate to `public_html/` and create a folder named `holderbot` (e.g. `public_html/holderbot`).
3. Upload all files from `holderbot-php/` into that folder.

### 2. Configure Settings
1. Copy `config.sample.php` to `config.php`.
2. Edit `config.php`:
   - Set `'bot_token'` from [@BotFather](https://t.me/BotFather).
   - Add your Telegram User ID to `'admin_ids'` (get it from [@userinfobot](https://t.me/userinfobot)).
   - Set `'webhook_url'` to your full HTTPS URL (e.g. `https://yourdomain.com/holderbot/index.php`).

### 3. File Permissions
Ensure the `data/` directory is writable by the web server:
```bash
chmod 755 data/
```

### 4. Register the Webhook
Open in your browser:
```text
https://yourdomain.com/holderbot/set_webhook.php?action=set
```
Or run via SSH:
```bash
php set_webhook.php set
```

### 5. Setup cPanel Cron Job
In cPanel, search for **Cron Jobs** and add a job running every minute (`* * * * *`) - the shortest interval most hosts allow, and the closest a cron-driven script can get to the original bot's continuous 30-second node monitoring:
```bash
php /home/yourusername/public_html/holderbot/cron.php > /dev/null 2>&1
```

---

## 📄 License & Attribution

Original Python HolderBot repository: [https://github.com/erfjab/holderbot/](https://github.com/erfjab/holderbot/)  
Original Author: [erfjab](https://github.com/erfjab)
