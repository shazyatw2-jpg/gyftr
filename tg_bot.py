#!/usr/bin/env python3
"""
╔══════════════════════════════════════════════════════════════════╗
║               GYFTR TELEGRAM AUTOMATION BOT                      ║
║   Bot Token: 9140144926:AAF4lZCd-yvbmD8tmexoxMz_Y7BTXqiRgJc     ║
║                                                                  ║
║   Features:                                                      ║
║   • Fresh, isolated session for EVERY Telegram user              ║
║   • Automatic session purge after every GV buy (Flipkart/Amazon) ║
║   • Automatic session purge on Switch Login & Logout             ║
║   • Interactive Telegram UI (Buttons & Commands)                 ║
║   • Mobile OTP Login with zero cross-user session leakage        ║
║   • ePay Points Balance & Profile Checker                        ║
║   • Auto Play Diwali Game (+150 Score ePay Rewards)              ║
║   • Buy / Gift Flipkart Gift Card (₹50) with Recipient Email     ║
║   • Buy Amazon Pay Gift Card (₹100)                              ║
║   • Pure Python QR Code PNG Generator (Zero Extra Dependencies)  ║
║   • UPI Intent Link & Scannable QR Code Photo Delivery           ║
║   • Automatic Background Payment Verification & Push Alert       ║
╚══════════════════════════════════════════════════════════════════╝
"""

import os
import sys
import time
import json
import zlib
import struct
import io
import re
import threading
import logging
from typing import Dict, Any, Optional

import telebot
from telebot import types

# Import core GyFTR client & constants from gyftr.py
from gyftr import (
    GyftrClient,
    FLIPKART_BRAND_ID,
    FLIPKART_MRP,
    AMAZON_PRODUCT_ID,
    AMAZON_MRP,
)

try:
    import qrcode
    HAS_QRCODE = True
except ImportError:
    HAS_QRCODE = False

# ─── Configuration ────────────────────────────────────────────────────────────

BOT_TOKEN = "9140144926:AAF4lZCd-yvbmD8tmexoxMz_Y7BTXqiRgJc"
SESSIONS_DIR = "/storage/emulated/0/gyftr/sessions"
ACCOUNTS_DIR = "/storage/emulated/0/gyftr/sessions/accounts"
os.makedirs(SESSIONS_DIR, exist_ok=True)
os.makedirs(ACCOUNTS_DIR, exist_ok=True)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s"
)
logger = logging.getLogger("GyftrTgBot")

bot = telebot.TeleBot(BOT_TOKEN, parse_mode="Markdown")

# ─── Per-User Session & State Registry ────────────────────────────────────────

clients: Dict[int, GyftrClient] = {}
user_states: Dict[int, str] = {}
active_payments: Dict[str, Dict[str, Any]] = {}  # ext_order_id -> payment_info

# User Conversation States
STATE_IDLE = "IDLE"
STATE_AWAITING_MOBILE = "AWAITING_MOBILE"
STATE_AWAITING_OTP = "AWAITING_OTP"
STATE_AWAITING_GIFT_EMAIL = "AWAITING_GIFT_EMAIL"


def get_user_session_path(chat_id: int) -> str:
    """Return isolated session file path strictly for this chat_id."""
    return os.path.join(SESSIONS_DIR, f"{chat_id}.json")


def get_client_for_chat(chat_id: int) -> GyftrClient:
    """
    Retrieve or initialize an isolated GyftrClient for a specific Telegram chat/user.
    Every user gets their own fresh, isolated session.
    Global session.json is NEVER loaded or shared across users.
    """
    if chat_id in clients:
        return clients[chat_id]

    session_path = get_user_session_path(chat_id)
    client = GyftrClient(session_file=session_path)

    # Only load private session file if it exists for this chat_id
    if os.path.exists(session_path):
        try:
            with open(session_path, "r", encoding="utf-8") as f:
                data = json.load(f)
            client.token = data.get("token")
            client.mobile = data.get("mobile")
            client.user_data = data.get("user_data", {})
            client.epay_balance = float(data.get("epay_balance", 0.0))
            if client.token:
                logger.info(f"Loaded private session for chat {chat_id} ({client.mobile})")
        except Exception as e:
            logger.warning(f"Error loading user session for {chat_id}: {e}")

    clients[chat_id] = client
    return client


def save_user_session(chat_id: int, client: GyftrClient):
    """Save user session to individual file strictly for this chat_id."""
    if not client or not client.token:
        return
    session_path = get_user_session_path(chat_id)
    payload = {
        "mobile": client.mobile,
        "token": client.token,
        "user_data": client.user_data,
        "epay_balance": client.epay_balance,
        "saved_at": int(time.time()),
    }
    try:
        with open(session_path, "w", encoding="utf-8") as f:
            json.dump(payload, f, indent=2)
    except Exception as e:
        logger.error(f"Failed to save user session for {chat_id}: {e}")


def get_account_session_path(mobile: str) -> str:
    clean = re.sub(r"\D", "", mobile)
    if len(clean) == 12 and clean.startswith("91"):
        clean = clean[2:]
    return os.path.join(ACCOUNTS_DIR, f"{clean}.json")


def try_auto_login(client: GyftrClient, mobile: str) -> bool:
    account_file = get_account_session_path(mobile)
    if not os.path.exists(account_file):
        return False
    try:
        with open(account_file, "r", encoding="utf-8") as f:
            data = json.load(f)
        token = data.get("token")
        if not token:
            return False
        client.token = token
        clean = re.sub(r"\D", "", mobile)
        if len(clean) == 12 and clean.startswith("91"):
            clean = clean[2:]
        client.mobile = clean
        client.user_data = data.get("user_data", {})
        client.epay_balance = float(data.get("epay_balance", 0.0))
        if client.refresh_profile():
            save_account_session(client)
            return True
        else:
            client.token = None
            return False
    except Exception:
        return False


def save_account_session(client: GyftrClient):
    if not client.token or not client.mobile:
        return
    account_file = get_account_session_path(client.mobile)
    payload = {
        "mobile": client.mobile,
        "token": client.token,
        "user_data": client.user_data,
        "epay_balance": client.epay_balance,
        "saved_at": int(time.time()),
    }
    try:
        with open(account_file, "w", encoding="utf-8") as f:
            json.dump(payload, f, indent=2)
    except Exception:
        pass


def clear_user_session(chat_id: int):
    """
    Completely purge user session from memory and disk.
    Ensures the user gets a 100% fresh, clean session.
    """
    session_path = get_user_session_path(chat_id)
    if os.path.exists(session_path):
        try:
            os.remove(session_path)
            logger.info(f"Removed session file for chat {chat_id}")
        except Exception as e:
            logger.warning(f"Error removing session file for {chat_id}: {e}")

    # Reinitialize a completely clean, fresh client instance
    clients[chat_id] = GyftrClient(session_file=session_path)
    user_states[chat_id] = STATE_IDLE
    logger.info(f"Chat {chat_id} session completely purged & reset to fresh state.")


# ─── Pure Python QR Code PNG Generator ────────────────────────────────────────

def generate_qr_png_bytes(data: str, box_size: int = 10, border: int = 4) -> bytes:
    """
    Generates a valid standard PNG image of a QR code in pure Python
    using standard library zlib and struct (No Pillow / libpng needed).
    """
    if not HAS_QRCODE:
        return b""

    qr = qrcode.QRCode(box_size=box_size, border=border)
    qr.add_data(data)
    qr.make(fit=True)
    matrix = qr.get_matrix()
    grid_h = len(matrix)
    grid_w = len(matrix[0])

    img_w = grid_w * box_size
    img_h = grid_h * box_size

    # 8-bit grayscale: 0 = black pixel, 255 = white pixel
    raw_scanlines = bytearray()
    for row in matrix:
        raw_scanlines.append(0)  # Filter byte 0 (None)
        for cell in row:
            val = 0 if cell else 255
            raw_scanlines.extend([val] * box_size)

        # Replicate row for vertical scaling
        single_row = raw_scanlines[-(img_w + 1):]
        for _ in range(box_size - 1):
            raw_scanlines.extend(single_row)

    def png_chunk(tag: bytes, payload: bytes) -> bytes:
        crc = zlib.crc32(tag + payload) & 0xFFFFFFFF
        return struct.pack(">I", len(payload)) + tag + payload + struct.pack(">I", crc)

    # IHDR: Width, Height, BitDepth=8, ColorType=0 (Grayscale), Compression=0, Filter=0, Interlace=0
    ihdr = struct.pack(">IIBBBBB", img_w, img_h, 8, 0, 0, 0, 0)
    compressed = zlib.compress(bytes(raw_scanlines), level=9)
    png_data = (
        b"\x89PNG\r\n\x1a\n"
        + png_chunk(b"IHDR", ihdr)
        + png_chunk(b"IDAT", compressed)
        + png_chunk(b"IEND", b"")
    )
    return png_data


# ─── Keyboards & UI Helpers ───────────────────────────────────────────────────

def get_main_menu_markup(is_logged_in: bool = False) -> types.InlineKeyboardMarkup:
    markup = types.InlineKeyboardMarkup(row_width=2)
    b_gift_fk = types.InlineKeyboardButton("🎁 Gift Flipkart ₹50", callback_data="act_gift_fk")
    b_buy_fk = types.InlineKeyboardButton("🛍️ Buy Flipkart ₹50", callback_data="act_buy_fk")
    b_buy_amz = types.InlineKeyboardButton("🛒 Buy Amazon ₹100", callback_data="act_buy_amz")
    b_game = types.InlineKeyboardButton("🎮 Auto-Play Game", callback_data="act_play_game")
    b_bal = types.InlineKeyboardButton("💰 Check Balance", callback_data="act_check_bal")

    markup.add(b_gift_fk, b_buy_fk)
    markup.add(b_buy_amz, b_game)

    if is_logged_in:
        b_switch = types.InlineKeyboardButton("🔄 Switch Login", callback_data="act_login")
        b_logout = types.InlineKeyboardButton("🚪 Logout", callback_data="act_logout")
        markup.add(b_bal)
        markup.add(b_switch, b_logout)
    else:
        b_login = types.InlineKeyboardButton("📱 Login", callback_data="act_login")
        markup.add(b_login, b_bal)

    b_help = types.InlineKeyboardButton("ℹ️ Help & Info", callback_data="act_help")
    markup.add(b_help)
    return markup


def build_status_text(client: GyftrClient) -> str:
    if client.token and client.mobile:
        name = (client.user_data or {}).get("full_name", "Customer")
        return (
            f"⚡ *GyFTR Automation Control Panel*\n"
            f"━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
            f"👤 *Account*: `{client.mobile}` ({name})\n"
            f"🪙 *ePay Balance*: *₹{client.epay_balance:.2f}*\n"
            f"🟢 *Session Status*: *Active*\n"
            f"━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
            f"Select an operation below:"
        )
    else:
        return (
            f"⚡ *GyFTR Automation Control Panel*\n"
            f"━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
            f"⚠️ *Session Status*: *Not Logged In (Fresh Session)*\n"
            f"Please tap *Login* to enter your mobile number and start."
        )


# ─── Command Handlers ─────────────────────────────────────────────────────────

@bot.message_handler(commands=["start", "menu"])
def handle_start(message: types.Message):
    chat_id = message.chat.id
    user_states[chat_id] = STATE_IDLE
    client = get_client_for_chat(chat_id)
    is_logged = bool(client.token and client.mobile)
    text = build_status_text(client)
    bot.send_message(chat_id, text, reply_markup=get_main_menu_markup(is_logged))


@bot.message_handler(commands=["cancel"])
def handle_cancel(message: types.Message):
    chat_id = message.chat.id
    user_states[chat_id] = STATE_IDLE
    client = get_client_for_chat(chat_id)
    is_logged = bool(client.token and client.mobile)
    bot.send_message(chat_id, "❌ Action cancelled. Returning to main menu.", reply_markup=get_main_menu_markup(is_logged))


@bot.message_handler(commands=["logout"])
def handle_logout_command(message: types.Message):
    chat_id = message.chat.id
    clear_user_session(chat_id)
    bot.send_message(
        chat_id,
        "🚪 *Logged Out Successfully!*\n"
        "Your session has been removed and reset to a fresh state.",
        reply_markup=get_main_menu_markup(is_logged_in=False)
    )


@bot.message_handler(commands=["balance", "bal"])
def handle_balance_command(message: types.Message):
    chat_id = message.chat.id
    client = get_client_for_chat(chat_id)
    do_check_balance(chat_id, client)


@bot.message_handler(commands=["game"])
def handle_game_command(message: types.Message):
    chat_id = message.chat.id
    client = get_client_for_chat(chat_id)
    do_play_game(chat_id, client)


@bot.message_handler(commands=["giftfk"])
def handle_giftfk_command(message: types.Message):
    chat_id = message.chat.id
    client = get_client_for_chat(chat_id)
    if not client.token:
        bot.send_message(chat_id, "⚠️ Please login first using /login.")
        return

    args = message.text.split()[1:]
    if args:
        email = args[0].strip()
        start_flipkart_purchase(chat_id, client, gift_email=email)
    else:
        user_states[chat_id] = STATE_AWAITING_GIFT_EMAIL
        bot.send_message(
            chat_id,
            "🎁 *Gift Flipkart ₹50 Gift Voucher*\n\n"
            "Please enter the recipient's *Email address*:\n"
            "_(Type /cancel to abort)_"
        )


@bot.message_handler(commands=["buyfk"])
def handle_buyfk_command(message: types.Message):
    chat_id = message.chat.id
    client = get_client_for_chat(chat_id)
    start_flipkart_purchase(chat_id, client, gift_email=None)


@bot.message_handler(commands=["buyamazon", "buyamz"])
def handle_buyamz_command(message: types.Message):
    chat_id = message.chat.id
    client = get_client_for_chat(chat_id)
    start_amazon_purchase(chat_id, client)


@bot.message_handler(commands=["login"])
def handle_login_command(message: types.Message):
    chat_id = message.chat.id
    # Always clear existing session on login/switch login
    clear_user_session(chat_id)
    user_states[chat_id] = STATE_AWAITING_MOBILE
    bot.send_message(
        chat_id,
        "📱 *GyFTR Fresh Mobile Login*\n\n"
        "Please send your *10-digit registered mobile number*:\n"
        "_(Type /cancel to abort)_"
    )


@bot.message_handler(commands=["help"])
def handle_help_command(message: types.Message):
    help_text = (
        "📖 *GyFTR Bot Command Reference*\n"
        "━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
        "• `/start` or `/menu` - Open interactive control menu\n"
        "• `/login` - Fresh login or switch mobile number with OTP\n"
        "• `/logout` - Purge current session and reset\n"
        "• `/balance` - Check live ePay points balance & account details\n"
        "• `/game` - Auto-play Diwali game & claim +150 score reward\n"
        "• `/giftfk <email>` - Buy ₹50 Flipkart GV gifted to recipient email\n"
        "• `/buyfk` - Buy ₹50 Flipkart GV for yourself\n"
        "• `/buyamazon` - Buy ₹100 Amazon Pay GV\n"
        "• `/cancel` - Cancel active input prompt\n"
        "━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
        "🔒 *Security Note*:\n"
        "Every Telegram user has an isolated fresh session. "
        "Sessions are automatically removed after every GV purchase for security!"
    )
    bot.send_message(message.chat.id, help_text)


# ─── Interactive Message Text Handler (State Machine) ─────────────────────────

@bot.message_handler(func=lambda msg: True, content_types=["text"])
def handle_text_inputs(message: types.Message):
    chat_id = message.chat.id
    text = message.text.strip()
    state = user_states.get(chat_id, STATE_IDLE)
    client = get_client_for_chat(chat_id)

    if text.startswith("/"):
        return

    # 1. State: Awaiting Mobile Number
    if state == STATE_AWAITING_MOBILE:
        clean_mobile = re.sub(r"\D", "", text)
        if len(clean_mobile) == 12 and clean_mobile.startswith("91"):
            clean_mobile = clean_mobile[2:]
        if len(clean_mobile) != 10:
            bot.send_message(chat_id, "⚠️ Invalid mobile number. Please send a valid 10-digit Indian mobile number:")
            return

        # Check if saved session exists for instant auto-login!
        if try_auto_login(client, clean_mobile):
            save_user_session(chat_id, client)
            user_states[chat_id] = STATE_IDLE
            name = (client.user_data or {}).get("full_name", "Customer")
            bot.send_message(
                chat_id,
                f"🎉 *Welcome Back, {name}!*\n"
                f"Automatically restored saved session for `{client.mobile}`!\n\n"
                f"🪙 *ePay Balance*: *₹{client.epay_balance:.2f}*\n"
                f"🟢 *Status*: Ready for orders",
                reply_markup=get_main_menu_markup(is_logged_in=True)
            )
            return

        wait_msg = bot.send_message(chat_id, f"⏳ Sending OTP to *{clean_mobile}* ...")
        try:
            ok = client.send_otp(clean_mobile)
            if ok:
                user_states[chat_id] = STATE_AWAITING_OTP
                bot.edit_message_text(
                    f"✅ *OTP Sent!* Sent to *{clean_mobile}*.\n\n"
                    f"Please enter the *6-digit OTP* received via SMS/WhatsApp:\n"
                    f"_(Type /cancel to abort)_",
                    chat_id,
                    wait_msg.message_id
                )
            else:
                bot.edit_message_text("❌ Failed to send OTP. Please try again with /login.", chat_id, wait_msg.message_id)
                user_states[chat_id] = STATE_IDLE
        except Exception as e:
            bot.edit_message_text(f"❌ Error sending OTP: {e}", chat_id, wait_msg.message_id)
            user_states[chat_id] = STATE_IDLE
        return

    # 2. State: Awaiting OTP
    if state == STATE_AWAITING_OTP:
        clean_otp = re.sub(r"\D", "", text)
        if len(clean_otp) != 6:
            bot.send_message(chat_id, "⚠️ Please enter a 6-digit numeric OTP:")
            return

        wait_msg = bot.send_message(chat_id, "⏳ Verifying OTP ...")
        try:
            ok = client.verify_otp(clean_otp)
            if ok:
                save_user_session(chat_id, client)
                save_account_session(client)
                user_states[chat_id] = STATE_IDLE
                name = (client.user_data or {}).get("full_name", "Customer")
                bot.edit_message_text(
                    f"🎉 *Login Successful!*\n\n"
                    f"Welcome, *{name}*!\n"
                    f"📱 Mobile: `{client.mobile}`\n"
                    f"🪙 ePay Balance: *₹{client.epay_balance:.2f}*",
                    chat_id,
                    wait_msg.message_id,
                    reply_markup=get_main_menu_markup(is_logged_in=True)
                )
            else:
                bot.edit_message_text("❌ Verification failed. Invalid OTP. Please try /login again.", chat_id, wait_msg.message_id)
                clear_user_session(chat_id)
        except Exception as e:
            bot.edit_message_text(f"❌ Verification error: {e}", chat_id, wait_msg.message_id)
            clear_user_session(chat_id)
        return

    # 3. State: Awaiting Gift Email
    if state == STATE_AWAITING_GIFT_EMAIL:
        email = text.strip()
        email_regex = r"^[\w\.-]+@[\w\.-]+\.\w+$"
        if not re.match(email_regex, email):
            bot.send_message(chat_id, "⚠️ Invalid email format. Please provide a valid email (e.g. `recipient@example.com`):")
            return

        user_states[chat_id] = STATE_IDLE
        start_flipkart_purchase(chat_id, client, gift_email=email)
        return

    # Default fallback: show main dashboard
    is_logged = bool(client.token and client.mobile)
    bot.send_message(chat_id, build_status_text(client), reply_markup=get_main_menu_markup(is_logged))


# ─── Inline Callback Query Handlers ───────────────────────────────────────────

@bot.callback_query_handler(func=lambda call: True)
def handle_callback_queries(call: types.CallbackQuery):
    chat_id = call.message.chat.id
    data = call.data
    client = get_client_for_chat(chat_id)

    try:
        bot.answer_callback_query(call.id)
    except Exception:
        pass

    if data == "act_login":
        # Always clear and start fresh session on switch login
        clear_user_session(chat_id)
        user_states[chat_id] = STATE_AWAITING_MOBILE
        bot.send_message(chat_id, "📱 *GyFTR Fresh Mobile Login*\n\nPlease enter your *10-digit mobile number*:")

    elif data == "act_logout":
        clear_user_session(chat_id)
        bot.send_message(
            chat_id,
            "🚪 *Logged Out Successfully!*\nSession cleared.",
            reply_markup=get_main_menu_markup(is_logged_in=False)
        )

    elif data == "act_check_bal":
        do_check_balance(chat_id, client)

    elif data == "act_play_game":
        do_play_game(chat_id, client)

    elif data == "act_gift_fk":
        if not client.token:
            bot.send_message(chat_id, "⚠️ Please login first using /login.")
            return
        user_states[chat_id] = STATE_AWAITING_GIFT_EMAIL
        bot.send_message(chat_id, "🎁 *Gift Flipkart ₹50 GV*\n\nPlease enter the recipient's *Email address*:")

    elif data == "act_buy_fk":
        start_flipkart_purchase(chat_id, client, gift_email=None)

    elif data == "act_buy_amz":
        start_amazon_purchase(chat_id, client)

    elif data == "act_help":
        handle_help_command(call.message)

    elif data.startswith("chk_status_"):
        ext_order_id = data.replace("chk_status_", "")
        check_manual_payment_status(chat_id, ext_order_id, call)


# ─── Core Action Implementations ──────────────────────────────────────────────

def do_check_balance(chat_id: int, client: GyftrClient):
    if not client.token:
        bot.send_message(chat_id, "⚠️ Not logged in. Please use /login first.")
        return

    status_msg = bot.send_message(chat_id, "⏳ Fetching live account details ...")
    try:
        client.refresh_profile()
        save_user_session(chat_id, client)
        name = (client.user_data or {}).get("full_name", "Customer")
        bot.edit_message_text(
            f"💰 *GyFTR Account Balance*\n"
            f"━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
            f"👤 *Name*: {name}\n"
            f"📱 *Mobile*: `{client.mobile}`\n"
            f"🪙 *ePay Balance*: *₹{client.epay_balance:.2f}*\n"
            f"🎯 *Campaign*: {(client.user_data or {}).get('campaign', 'DIWALI2026')}\n"
            f"━━━━━━━━━━━━━━━━━━━━━━━━━━",
            chat_id,
            status_msg.message_id,
            reply_markup=get_main_menu_markup(is_logged_in=True)
        )
    except Exception as e:
        bot.edit_message_text(f"❌ Failed to fetch balance: {e}", chat_id, status_msg.message_id)


def do_play_game(chat_id: int, client: GyftrClient):
    if not client.token:
        bot.send_message(chat_id, "⚠️ Please login first using /login.")
        return

    status_msg = bot.send_message(chat_id, "🎮 *Diwali Game Auto-Bot*\nStarting game and simulating play (Score: 150/150) ...")

    def run_game():
        try:
            res = client.play_game(simulate_sec=4)
            save_user_session(chat_id, client)
            msg = res.get("message", "Points claimed!")
            is_already = res.get("already_played", False)
            title = "ℹ️ *Diwali Game Status*" if is_already else "🎉 *Diwali Game Completed!*"
            bot.edit_message_text(
                f"{title}\n"
                f"━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
                f"🎯 *Status*: {msg}\n"
                f"🪙 *Current ePay Balance*: *₹{client.epay_balance:.2f}*\n"
                f"━━━━━━━━━━━━━━━━━━━━━━━━━━",
                chat_id,
                status_msg.message_id,
                reply_markup=get_main_menu_markup(is_logged_in=True)
            )
        except Exception as e:
            bot.edit_message_text(
                f"ℹ️ *Game Notice*\n"
                f"━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
                f"{e}\n\n"
                f"🪙 *Current ePay Balance*: *₹{client.epay_balance:.2f}*",
                chat_id,
                status_msg.message_id,
                reply_markup=get_main_menu_markup(is_logged_in=True)
            )

    threading.Thread(target=run_game, daemon=True).start()


# ─── Purchase Flow: Flipkart ₹50 GV ──────────────────────────────────────────

def start_flipkart_purchase(chat_id: int, client: GyftrClient, gift_email: Optional[str] = None):
    if not client.token:
        bot.send_message(chat_id, "⚠️ Please login first using /login.")
        return

    is_gift = bool(gift_email)
    dest_str = f"recipient email `{gift_email}`" if is_gift else f"your mobile `{client.mobile}`"

    status_msg = bot.send_message(
        chat_id,
        f"🛍️ *Initiating Flipkart ₹50 Order ...*\n"
        f"Delivering to: {dest_str}\n"
        f"⏳ Clearing conflicting items & adding to cart ..."
    )

    def execute_flow():
        try:
            # 1. Clear cart & add Flipkart ₹50 GV
            cart_item_id = client.add_flipkart_voucher_to_cart()

            # 2. If gift, configure delivery
            if is_gift:
                bot.edit_message_text(
                    f"🛍️ *Cart Updated (ID: {cart_item_id})*\n"
                    f"⏳ Setting delivery recipient email `{gift_email}` ...",
                    chat_id,
                    status_msg.message_id
                )
                client.set_cart_delivery(recipient_email=gift_email)

            # 3. Checkout with device verification
            bot.edit_message_text(
                f"🛍️ *Creating Order ...*\n"
                f"⏳ Performing device verification gateway check ...",
                chat_id,
                status_msg.message_id
            )
            checkout_data = client.checkout(cart_item_id, is_gift=is_gift)

            # 4. Initiate payment
            bot.edit_message_text(
                f"⚡ *Order Created (Ref: {checkout_data.get('orderRef')})*\n"
                f"⏳ Processing payment options & ePay point redemption ...",
                chat_id,
                status_msg.message_id
            )
            payment_info = client.initiate_upi_payment(checkout_data)

            # Handle payment outcome
            handle_payment_presentation(
                chat_id=chat_id,
                client=client,
                payment_info=payment_info,
                product_name="Flipkart Gift Card ₹50",
                recipient=gift_email if is_gift else client.mobile,
                status_msg_id=status_msg.message_id
            )

        except Exception as e:
            logger.exception("Flipkart purchase error")
            bot.edit_message_text(
                f"❌ *Flipkart Order Failed*\n━━━━━━━━━━━━━━━━━━━━━━━━━━\n`{e}`",
                chat_id,
                status_msg.message_id,
                reply_markup=get_main_menu_markup(is_logged_in=bool(client.token))
            )

    threading.Thread(target=execute_flow, daemon=True).start()


# ─── Purchase Flow: Amazon ₹100 GV ───────────────────────────────────────────

def start_amazon_purchase(chat_id: int, client: GyftrClient):
    if not client.token:
        bot.send_message(chat_id, "⚠️ Please login first using /login.")
        return

    status_msg = bot.send_message(
        chat_id,
        f"🛒 *Initiating Amazon Pay ₹100 Order ...*\n"
        f"Delivering to: `{client.mobile}`\n"
        f"⏳ Adding voucher to cart ..."
    )

    def execute_flow():
        try:
            # 1. Add Amazon voucher
            cart_item_id = client.add_amazon_voucher_to_cart()

            # 2. Checkout
            bot.edit_message_text(
                f"🛒 *Cart Updated (ID: {cart_item_id})*\n"
                f"⏳ Running device verification check ...",
                chat_id,
                status_msg.message_id
            )
            checkout_data = client.checkout(cart_item_id, is_gift=False)

            # 3. Initiate payment
            bot.edit_message_text(
                f"⚡ *Order Created (Ref: {checkout_data.get('orderRef')})*\n"
                f"⏳ Setting up payment gateway ...",
                chat_id,
                status_msg.message_id
            )
            payment_info = client.initiate_upi_payment(checkout_data)

            # Handle presentation
            handle_payment_presentation(
                chat_id=chat_id,
                client=client,
                payment_info=payment_info,
                product_name="Amazon Pay Gift Card ₹100",
                recipient=client.mobile,
                status_msg_id=status_msg.message_id
            )

        except Exception as e:
            logger.exception("Amazon purchase error")
            bot.edit_message_text(
                f"❌ *Amazon Order Failed*\n━━━━━━━━━━━━━━━━━━━━━━━━━━\n`{e}`",
                chat_id,
                status_msg.message_id,
                reply_markup=get_main_menu_markup(is_logged_in=bool(client.token))
            )

    threading.Thread(target=execute_flow, daemon=True).start()


# ─── Payment Presentation & Polling ───────────────────────────────────────────

def handle_payment_presentation(
    chat_id: int,
    client: GyftrClient,
    payment_info: dict,
    product_name: str,
    recipient: str,
    status_msg_id: int,
):
    ext_order_id = payment_info.get("ext_order_id", "N/A")
    pg_amount = payment_info.get("pg_amount", 0.0)
    epay_used = payment_info.get("epay_used", 0.0)
    intent_uri = payment_info.get("intent_uri")
    is_completed = payment_info.get("is_completed", False)

    # 1. Full Payment via ePay Points -> Completed immediately!
    if is_completed or (not intent_uri and pg_amount <= 0):
        # Remove session after every GV buy as requested
        clear_user_session(chat_id)

        bot.edit_message_text(
            f"🎉 *ORDER COMPLETED SUCCESSFULLY!*\n"
            f"━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
            f"🛍️ *Product*: {product_name}\n"
            f"📬 *Delivered To*: `{recipient}`\n"
            f"🪙 *Paid with ePay*: ₹{epay_used:.2f}\n"
            f"⚡ *UPI Paid*: ₹0.00\n"
            f"🆔 *Order ID*: `{ext_order_id}`\n\n"
            f"✅ Voucher details sent to recipient!\n"
            f"🔒 *Session Removed*: Account logged out for next purchase.",
            chat_id,
            status_msg_id,
            reply_markup=get_main_menu_markup(is_logged_in=False)
        )
        return

    # 2. UPI Payment Required
    if not intent_uri:
        bot.edit_message_text(
            f"⚠️ *Payment Error*\nNo UPI Intent URL received from gateway.\nOrder ID: `{ext_order_id}`",
            chat_id,
            status_msg_id,
            reply_markup=get_main_menu_markup(is_logged_in=bool(client.token))
        )
        return

    # Store active payment info
    active_payments[ext_order_id] = {
        "payment_info": payment_info,
        "chat_id": chat_id,
        "client": client,
        "product_name": product_name,
        "recipient": recipient,
        "start_time": time.time(),
    }

    # Delete progress message
    try:
        bot.delete_message(chat_id, status_msg_id)
    except Exception:
        pass

    # Create Payment Markup
    markup = types.InlineKeyboardMarkup(row_width=1)
    markup.add(
        types.InlineKeyboardButton("📲 Pay via UPI App", url=intent_uri),
        types.InlineKeyboardButton("🔄 Check Payment Status", callback_data=f"chk_status_{ext_order_id}"),
        types.InlineKeyboardButton("❌ Return to Main Menu", callback_data="act_help")
    )

    summary_text = (
        f"💳 *UPI Payment Required*\n"
        f"━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
        f"🛍️ *Item*: {product_name}\n"
        f"📬 *Recipient*: `{recipient}`\n"
        f"🪙 *ePay Discount*: ₹{epay_used:.2f}\n"
        f"⚡ *Amount to Pay*: *₹{pg_amount:.2f}*\n"
        f"🆔 *Order Ref*: `{ext_order_id}`\n"
        f"━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
        f"📲 *Direct UPI Link*:\n`{intent_uri}`\n\n"
        f"👇 Tap below to pay or scan the QR code:"
    )

    # Generate QR Code PNG
    png_bytes = generate_qr_png_bytes(intent_uri, box_size=8, border=3)

    if png_bytes:
        photo_io = io.BytesIO(png_bytes)
        photo_io.name = "upi_qr.png"
        bot.send_photo(
            chat_id,
            photo=photo_io,
            caption=summary_text,
            reply_markup=markup
        )
    else:
        bot.send_message(chat_id, summary_text, reply_markup=markup)

    # Launch background polling thread
    threading.Thread(
        target=poll_payment_completion_background,
        args=(chat_id, client, ext_order_id, product_name, recipient),
        daemon=True
    ).start()


def poll_payment_completion_background(chat_id: int, client: GyftrClient, ext_order_id: str, product_name: str, recipient: str):
    """Polls payment status in background and pushes instant alert on completion."""
    start_time = time.time()
    timeout = 300  # 5 minutes

    while time.time() - start_time < timeout:
        time.sleep(5)
        entry = active_payments.get(ext_order_id)
        if not entry:
            break

        payment_info = entry["payment_info"]
        status = client.check_payment_status(payment_info)

        if status == "SUCCESS":
            active_payments.pop(ext_order_id, None)
            # Remove session after every GV buy as requested
            clear_user_session(chat_id)

            success_text = (
                f"🎉 *PAYMENT CONFIRMED!*\n"
                f"━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
                f"Order *{ext_order_id}* is verified and completed!\n"
                f"🛍️ *Product*: {product_name}\n"
                f"📬 *Delivered to*: `{recipient}`\n"
                f"━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
                f"🔒 *Session Removed*: Account logged out for next purchase.\n"
                f"Use /login to login with a fresh number."
            )
            bot.send_message(chat_id, success_text, reply_markup=get_main_menu_markup(is_logged_in=False))
            return

        elif status == "FAILED":
            active_payments.pop(ext_order_id, None)
            bot.send_message(
                chat_id,
                f"❌ *Payment Failed*\nOrder `{ext_order_id}` was rejected or expired.",
                reply_markup=get_main_menu_markup(is_logged_in=bool(client.token))
            )
            return

    # Timeout
    if ext_order_id in active_payments:
        active_payments.pop(ext_order_id, None)
        logger.info(f"Background payment polling timed out for {ext_order_id}")


def check_manual_payment_status(chat_id: int, ext_order_id: str, call: types.CallbackQuery):
    """Handles manual 'Check Payment Status' button clicks."""
    entry = active_payments.get(ext_order_id)
    if not entry:
        bot.answer_callback_query(call.id, "No active pending session for this order.", show_alert=True)
        return

    client = entry["client"]
    payment_info = entry["payment_info"]
    status = client.check_payment_status(payment_info)

    if status == "SUCCESS":
        active_payments.pop(ext_order_id, None)
        # Remove session after every GV buy as requested
        clear_user_session(chat_id)

        bot.answer_callback_query(call.id, "🎉 Payment Successful!", show_alert=True)
        bot.send_message(
            chat_id,
            f"🎉 *Payment Verified!*\n"
            f"Order `{ext_order_id}` was paid successfully!\n"
            f"Delivered to: `{entry['recipient']}`\n"
            f"🔒 *Session Removed*: Account logged out for next purchase.",
            reply_markup=get_main_menu_markup(is_logged_in=False)
        )
    elif status == "FAILED":
        active_payments.pop(ext_order_id, None)
        bot.answer_callback_query(call.id, "❌ Payment Failed", show_alert=True)
    else:
        bot.answer_callback_query(call.id, "⏳ Payment is still pending. Please complete in UPI app.", show_alert=False)


# ─── Main Bot Execution ───────────────────────────────────────────────────────

def main():
    logger.info("Initializing GyFTR Telegram Bot ...")
    try:
        bot_info = bot.get_me()
        logger.info(f"Bot connected: @{bot_info.username} (ID: {bot_info.id})")
        print("\n" + "═" * 60)
        print(f"  🤖 GyFTR Telegram Bot Started: @{bot_info.username}")
        print(f"  📱 Bot ID: {bot_info.id}")
        print(f"  ⚡ Running with Per-User Fresh Sessions & Post-Purchase Purge")
        print("═" * 60 + "\n")
    except Exception as e:
        logger.error(f"Failed to connect to Telegram API: {e}")
        sys.exit(1)

    while True:
        try:
            bot.infinity_polling(timeout=20, long_polling_timeout=20)
        except Exception as e:
            logger.error(f"Polling exception: {e}")
            time.sleep(3)


if __name__ == "__main__":
    main()
