# ⚡ GyFTR Automation Suite & Telegram Bot

A complete, dual-engine (Python & PHP) reverse-engineered automation suite for GyFTR and Pay.GyFTR platforms. Features Telegram bot integrations, browser web dashboard, automated campaign claim bots, device verification bridges, cart gifting checkout, and persistent per-account session pools.

---

## 🌟 Key Features

* **Multi-Platform Support**: Available in both **Python 3** (CLI & Telegram Daemon) and native **PHP 7.4 / 8.0+** (cPanel webhooks, long-polling, and Web Dashboard).
* **Automatic Fresh & Existing Account Authentication**: Seamlessly authenticates existing users via `/v1/user/login` and automatically detects and provisions fresh mobile numbers via `/v1/user/register` with automated Terms of Service consent.
* **Persistent Session Pool (`sessions/accounts/`)**: Caches and restores user authentication tokens automatically. Entering a previously registered number bypasses SMS OTP entirely.
* **Diwali 2026 Game Auto-Play**: 1-click automated score submission (+150 points) with optimized routing and instant ePay credit.
* **Gift Card Checkout**: Direct purchase and gifting of Flipkart ₹50 GV and Amazon Pay ₹100 GV using ePay balances and UPI dynamic QR code generation.
* **Device Verification Bridge**: Integrated with FingerprintJS Pro mobile browser bridge for instant request validation.

---

## 📁 Repository Structure

```
gyftr/
├── gyftr.py             # Python CLI engine & full GyFTR API implementation
├── tg_bot.py            # Python Telegram bot daemon with isolated user sessions
├── php/
│   ├── GyftrClient.php  # Native PHP GyFTR engine & OpenSSL encryption
│   ├── bot.php          # Standalone PHP Telegram Bot (CLI or Webhook)
│   ├── webhook.php      # 1-click Webhook registrar for cPanel / shared hosting
│   ├── verify.php       # Mobile browser bridge for FingerprintJS Pro tokens
│   ├── index.php        # Responsive browser Web Dashboard
│   ├── .htaccess        # Apache / cPanel configuration rules
│   └── README.md        # Comprehensive PHP deployment guide
└── README.md            # Main documentation
```

---

## 🚀 Quick Start

### 1. Python Telegram Bot
```bash
pip install requests pycryptodome telebot qrcode pillow
python3 tg_bot.py
```

### 2. PHP Web Dashboard & Control Panel
Serve directly on any Apache/Nginx web server or local testing:
```bash
php -S 0.0.0.0:8080 -t php
```
Navigate to `http://localhost:8080/index.php` in your browser.

### 3. Shared Hosting / cPanel Deployment
1. Upload the files inside `php/` to your `public_html/gyftr/` folder.
2. Visit `https://your-domain.com/gyftr/webhook.php?set=1` in your browser to bind your Telegram bot webhook.
3. Access the web control dashboard at `https://your-domain.com/gyftr/`.

---

## 🔒 Security Note
All sensitive user sessions, account tokens, and network captures are ignored via `.gitignore` to maintain credentials security.
