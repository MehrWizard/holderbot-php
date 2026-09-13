# HolderBot PHP `v0.6.0`

A high-performance, **zero-dependency** 1:1 PHP rewrite of HolderBot designed to run seamlessly on standard **cPanel shared hosting** or any LAMP/LEMP server using native PHP cURL, `php://input` webhooks, and zero external composer packages.

> **Original Source:** Rewritten from the original Python implementation: [https://github.com/erfjab/holderbot/](https://github.com/erfjab/holderbot/)  
> **Release Version:** `v0.6.0` (matched 1:1 with the upstream release)

---

## 🌟 Features Implemented (1:1 with Python HolderBot v0.6.0)

### 1. Panel & Server Management
- **Multi-Panel Support:** Complete native REST integration with both **Marzban** and **Marzneshin**.
- **Server Switching:** Manage multiple independent servers from a single Telegram bot.
- **Interactive Server Setup:** Add new servers directly from Telegram through a 5-step wizard.
- **Server Settings:** Toggle Node Monitoring, Node Auto-Restart, Active/Disabled status, or remove servers.
- **Server Statistics Dashboard:** Real-time metrics including total accounts, active/disabled/expired/limited counts, total traffic used, and host CPU/RAM usage.

### 2. User & Subscription Operations
- **Status Filter Browsing:** Filter users by `All`, `Active`, `Expired`, or `Limited` with responsive pagination.
- **User Search & Deeplinks:**
  - Search by username via `/user <server_id> <username>`.
  - In-bot interactive search wizard.
  - Deep-link support: `/start user_<server_id>_<username>`.
- **Telegram Inline Queries:** Search users live in any chat via `@BotUsername <server_id> <query>`.
- **Rich User Info Cards:** Real-time traffic breakdown (GB & percentage), remaining expiration duration, status, note, and subscription link.
- **User Modification & Quick Actions:**
  - ❌ / ✅ **Toggle Active/Disabled**
  - 🧪 **Recharge User ("Charge"):** Apply a template or custom GB/days to extend an existing account, with optional traffic usage reset.
  - 📊 **Modify Data Limit:** Change total GB limit directly on an existing user.
  - ⏱️ **Modify Expiration Date:** Update remaining days directly.
  - 🗒️ **Edit Note:** Update or clear user note.
  - 👤 **Change Owner:** Reassign user ownership to another admin.
  - 🔁 **Reset Traffic Usage:** Reset consumed bandwidth back to 0.
  - ⛓️ **Revoke Subscription Link:** Regenerate token & subscription URL immediately.
  - 🖼️ **QR Code:** Send subscription link QR code (offline SVG generator + online fallback).
  - 🗑️ **Delete User:** Permanently remove user with confirmation prompt.

### 3. Creation Wizards
- **Single User Creation:**
  - Validates username formatting and checks for duplicates on the panel.
  - 🎲 **Random Username Generator:** 1-click random username creation.
  - 1-click template selection or custom GB and duration inputs.
- **Bulk User Creation:**
  - Generate $N$ users at once (up to 50 per batch) with custom prefix (e.g. `client_01`, `client_02`, ...).
  - Automatically applies selected template.
  - Outputs summary list of all created usernames and subscription links.

### 4. Batch Server Actions (`Actions` Menu)
- **Delete Expired Users:** Automatically cleans up all expired accounts under a selected admin or globally (`ALL`).
- **Delete Limited Users:** Automatically cleans up all data-exhausted accounts.
- **Bulk Enable/Disable:** Enable or disable all accounts belonging to a specific panel administrator.
- **Transfer Users:** Reassign all accounts from Admin A to Admin B in bulk.

### 5. Template Management (CRUD)
- Create new templates (Remark, Data Limit in GB, Date Limit in Days) directly inside Telegram.
- Inspect and delete templates.
- 1-click application in user creation and recharging.

### 6. Background Tasks on cPanel (`cron.php`)
- **Node Health Monitoring:** Periodically inspects node connectivity; automatically resyncs/reconnects failed nodes.
- **Instant Admin Alerts:** Dispatches high-priority Telegram alerts when any node experiences failure.
- **Daily Expired Summary:** Daily morning notification listing accounts expired in the last 24 hours with one-click bot deeplinks.

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
In cPanel, search for **Cron Jobs** and add a job running every 5 minutes (`*/5 * * * *`):
```bash
php /home/yourusername/public_html/holderbot/cron.php > /dev/null 2>&1
```

---

## 📄 License & Attribution

Original Python HolderBot repository: [https://github.com/erfjab/holderbot/](https://github.com/erfjab/holderbot/)  
Original Author: [erfjab](https://github.com/erfjab)
