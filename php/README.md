# ⚡ GyFTR Automation Suite in PHP

A complete PHP port of the GyFTR & Pay.GyFTR automation system, supporting Telegram Bot hosting (CLI long-polling & Webhooks) and a modern Web Dashboard.

---

## 📁 Package Structure

```
php/
├── GyftrClient.php     # Core GyFTR API engine & cryptography
├── bot.php             # Standalone Telegram Bot (CLI polling or Webhook)
├── webhook.php         # 1-click Webhook endpoint for cPanel / Apache / Nginx
├── verify.php          # 1-second Device Verification web bridge (FingerprintJS Pro)
├── index.php           # Modern responsive Web Dashboard for browsers
├── sessions/           # Isolated per-user session storage (auto-managed)
└── README.md           # Deployment & configuration instructions
```

---

## 🛠️ Server Requirements

- **PHP**: `7.4` or `8.0+` (PHP 8.1, 8.2, 8.3, 8.4 recommended)
- **Extensions**:
  - `curl` (for API communications)
  - `openssl` (for AES-256-CBC and RSA OAEP cryptographic routines)
  - `json` (standard JSON handling)

*(All standard cPanel and VPS distributions have these extensions enabled by default).*

---

## 🚀 Deployment Options

### Option 1: Hosting on VPS via CLI Long-Polling (Recommended)

1. Upload the `php/` folder to your server (e.g., `/var/www/gyftr`).
2. Run the bot:
   ```bash
   php /var/www/gyftr/bot.php
   ```
3. To run it continuously 24/7 in background:
   - **Using Screen**:
     ```bash
     screen -S gyftr
     php /var/www/gyftr/bot.php
     # Press Ctrl+A then D to detach
     ```
   - **Using Systemd** (`/etc/systemd/system/gyftr.service`):
     ```ini
     [Unit]
     Description=GyFTR Telegram Bot
     After=network.target

     [Service]
     Type=simple
     User=www-data
     WorkingDirectory=/var/www/gyftr
     ExecStart=/usr/bin/php /var/www/gyftr/bot.php
     Restart=always
     RestartSec=5

     [Install]
     WantedBy=multi-user.target
     ```
     Enable and start:
     ```bash
     sudo systemctl enable --now gyftr
     ```

---

### Option 2: Shared Hosting / cPanel via Webhook (Zero Background Processes)

1. Upload the `php/` files to your web root (e.g. `public_html/gyftr/`).
2. Ensure your domain has an active SSL certificate (`https://`).
3. Set your webhook with 1 click by visiting in your browser:
   ```
   https://your-domain.com/gyftr/webhook.php?set=1
   ```
4. Telegram will reply with `{"ok": true, "result": true, "description": "Webhook was set"}`.
5. Done! Incoming messages to [@Shazyfk_bot](https://t.me/Shazyfk_bot) will now trigger `webhook.php` instantly.

*To delete webhook and switch back to long-polling*:
Visit: `https://your-domain.com/gyftr/webhook.php?delete=1`

---

### Option 3: Browser Web Dashboard (`index.php`)

You can also use GyFTR directly in any web browser without opening Telegram:
1. Open `https://your-domain.com/gyftr/index.php`.
2. Connect mobile number & verify OTP.
3. 1-click Auto Play Diwali Game (+150 ePay score).
4. Buy / Gift Flipkart ₹50 GV or Amazon ₹100 GV.
5. The page automatically executes FingerprintJS Pro in the browser, calculates ePay discounts, and presents the UPI QR code & Intent link on screen!

---

## 🔒 Security & Session Handling

- **Per-User Isolation**: Every Telegram user receives an isolated, independent session.
- **Auto-Purge**: All session data is immediately wiped from memory and disk after every successful voucher purchase or switch login.
