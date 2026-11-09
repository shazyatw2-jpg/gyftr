"""
╔══════════════════════════════════════════════════════════════════╗
║               GYFTR ALL-IN-ONE AUTOMATION BOT                    ║
║   1. Login (Session Cache / Mobile OTP)                         ║
║   2. Auto Play Diwali Game (Earn ePay Points)                   ║
║   3. Buy Rs 100 Amazon Pay Gift Voucher                         ║
║   4. Pay via UPI (Intent URI + Terminal QR + Auto-App Launch)   ║
╚══════════════════════════════════════════════════════════════════╝

Reverse-engineered from gyftr.com & pay.gyftr.com network traffic:
  - api.gyftr.com : CryptoJS AES (OpenSSL EVP_BytesToKey)
                    Key: d909dg78609e47ak47diy4cf87t90c4a
  - pay.gyftr.com : Hybrid RSA (2048-bit PKCS1_OAEP SHA-1) + AES
                    Key: gyftr2024ClientKey123456789012345
                    Public Key: MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8...
  - Device Verification: FingerprintJS Pro on metrics.gyftr.com
"""

import sys
import os
import time
import json
import base64
import hashlib
import random
import string
import subprocess
import argparse
import webbrowser
import http.server
import socketserver
import threading
import urllib.parse
import requests

from Crypto.Cipher import AES, PKCS1_OAEP
from Crypto.PublicKey import RSA
from Crypto.Hash import SHA1
from Crypto.Util.Padding import pad, unpad

try:
    import qrcode
    HAS_QRCODE = True
except ImportError:
    HAS_QRCODE = False

# ─── Configuration & Keys ─────────────────────────────────────────────────────

API_BASE_URL = "https://api.gyftr.com/gyftrapi/api"
PAY_BASE_URL = "https://pay.gyftr.com"
CAMPAIGN     = "DIWALI2026"
GAME_SCORE   = 150

# Voucher Configurations
FLIPKART_BRAND_ID       = 95
FLIPKART_BRAND_NAME     = "Flipkart"
FLIPKART_PRODUCT_ID     = 4860
FLIPKART_CM_PRODUCT_ID  = 5919
FLIPKART_PRODUCT_NAME   = "Flipkart Gift Card"
FLIPKART_MRP            = 50

AMAZON_PRODUCT_ID       = 5527
AMAZON_BRAND_ID         = 89
AMAZON_MRP              = 100

API_SECRET_KEY = "d909dg78609e47ak47diy4cf87t90c4a"
PAY_SECRET_KEY = "gyftr2024ClientKey123456789012345"

SESSION_FILE = "/storage/emulated/0/gyftr/session.json"

PAY_RSA_PUB = """-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAzFOoztxmhFYN4I6HH4mv
3T0bzwwjO7H4k2oX1kl+Tw3IkQjZWKljd0U/FNXURd95JfFoed4wJ40Sjcjy63md
EzpWREg++UfOTArA23keOONvs1JvvkGJkbpPdfFA3cHzMMV4JZru9BNDX+/8uP0R
EDlDxdQK9/UQ9dJhoWnOmED8fL6teQa2HGP+okkEBJ26uiSrPhFLJqaWdtT0txYP
6Gb2sbd7k3dKa0/6aKh653LiJZIMN5G5blarSe9/KGQMpq7jIQsCatKytIIW+gQA
RFAX743nEWtLxu3mV9+0WEQiq8zy9APLoGoz2eXFkIXPU8UIoXQE6ohc83Tiat0Y
FwIDAQAB
-----END PUBLIC KEY-----"""

USER_AGENT = (
    "Mozilla/5.0 (Linux; Android 10; Redmi Note 9 Pro Max Build/QKQ1.191215.002; wv) "
    "AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/156.0.8078.47 Mobile Safari/537.36"
)

HEADERS = {
    "Content-Type": "application/json",
    "Accept": "application/json, text/plain, */*",
    "device": "mobile",
    "platform": "android",
    "Origin": "https://www.gyftr.com",
    "Referer": "https://www.gyftr.com/",
    "User-Agent": USER_AGENT,
    "sec-ch-ua-platform": '"Android"',
    "sec-ch-ua-mobile": "?1",
    "sec-fetch-site": "same-site",
    "sec-fetch-mode": "cors",
    "sec-fetch-dest": "empty",
    "accept-language": "en-IN,en-US;q=0.9,en;q=0.8",
}

# ─── Cryptography Routines ───────────────────────────────────────────────────

def evp_bytes_to_key(password: bytes, salt: bytes, key_len=32, iv_len=16):
    """OpenSSL EVP_BytesToKey MD5 key derivation compatible with CryptoJS."""
    d = b""
    d_i = b""
    while len(d) < key_len + iv_len:
        d_i = hashlib.md5(d_i + password + salt).digest()
        d += d_i
    return d[:key_len], d[key_len : key_len + iv_len]


def aes_encrypt(plaintext: str, passphrase: str = API_SECRET_KEY) -> str:
    """CryptoJS compatible AES-256-CBC encryption with 'Salted__' prefix."""
    salt = os.urandom(8)
    key, iv = evp_bytes_to_key(passphrase.encode("utf-8"), salt)
    cipher = AES.new(key, AES.MODE_CBC, iv)
    ct = cipher.encrypt(pad(plaintext.encode("utf-8"), 16))
    return base64.b64encode(b"Salted__" + salt + ct).decode("utf-8")


def aes_decrypt(ciphertext_b64: str, passphrase: str = API_SECRET_KEY) -> str:
    """CryptoJS compatible AES-256-CBC decryption."""
    raw = base64.b64decode(ciphertext_b64)
    if raw[:8] != b"Salted__":
        raise ValueError("Invalid CryptoJS ciphertext (missing 'Salted__' prefix)")
    salt = raw[8:16]
    ct = raw[16:]
    key, iv = evp_bytes_to_key(passphrase.encode("utf-8"), salt)
    cipher = AES.new(key, AES.MODE_CBC, iv)
    return unpad(cipher.decrypt(ct), 16).decode("utf-8")


def hybrid_encrypt(payload: dict) -> dict:
    """
    Hybrid RSA + AES encryption used by pay.gyftr.com/api-proxy.
    Generates a 32-byte hex random key, encrypts payload with AES,
    and encrypts the key with RSA-2048 PKCS1_OAEP (SHA-1).
    """
    random_aes_key = os.urandom(32).hex()
    payload_str = json.dumps(payload, separators=(",", ":"))
    encrypted_payload = aes_encrypt(payload_str, random_aes_key)

    rsa_key = RSA.import_key(PAY_RSA_PUB)
    cipher = PKCS1_OAEP.new(rsa_key, hashAlgo=SHA1)
    encrypted_key = base64.b64encode(cipher.encrypt(random_aes_key.encode("utf-8"))).decode("utf-8")

    return {
        "encryptedPayload": encrypted_payload,
        "encryptedKey": encrypted_key
    }


def try_decrypt_api_data(raw, key=API_SECRET_KEY):
    """Safely decrypt API response data string if encrypted."""
    if isinstance(raw, str) and raw.startswith("U2FsdGVk"):
        try:
            dec = aes_decrypt(raw, key)
            return json.loads(dec)
        except Exception:
            return raw
    return raw


def random_string(length=6):
    """Random alphanumeric string."""
    return "".join(random.choices(string.ascii_letters + string.digits, k=length))


# ─── Device Verification via FingerprintJS Pro ───────────────────────────────

def get_device_verification(encrypted_mobile: str, port: int = 8765, timeout: int = 15) -> dict:
    """
    Launches a 1-second local browser bridge to execute Gyftr's official
    FingerprintJS Pro agent and capture a genuine request_id & visitor_id.
    """
    captured = {}
    done_event = threading.Event()

    html_content = f"""<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gyftr Device Verification</title>
  <style>
    body {{ font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; text-align: center; padding: 40px 20px; background: #0f172a; color: #f8fafc; }}
    .card {{ max-width: 380px; margin: 30px auto; background: #1e293b; padding: 30px; border-radius: 16px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.5); border: 1px solid #334155; }}
    .spinner {{ border: 4px solid #334155; border-top: 4px solid #38bdf8; border-radius: 50%; width: 44px; height: 44px; animation: spin 0.8s linear infinite; margin: 20px auto; }}
    @keyframes spin {{ 0% {{ transform: rotate(0deg); }} 100% {{ transform: rotate(360deg); }} }}
    .success {{ color: #4ade80; font-size: 20px; font-weight: bold; margin-top: 10px; }}
    p {{ color: #94a3b8; font-size: 14px; line-height: 1.5; }}
  </style>
</head>
<body>
  <div class="card">
    <h3 style="margin-top:0; color:#e2e8f0;">🔒 GyFTR Device Verification</h3>
    <div id="status" class="spinner"></div>
    <p id="msg">Verifying browser fingerprint with security gateway...</p>
  </div>
  <script type="module">
    try {{
      const {{ start }} = await import("https://metrics.gyftr.com/web/v4/Bwihr8E7Qva6zV1aeuAt?ci=jsl/4.1.6");
      const client = start({{
        apiKey: "Bwihr8E7Qva6zV1aeuAt",
        region: "ap",
        endpoints: ["https://metrics.gyftr.com"]
      }});
      const resp = await client.get({{
        linkedId: "{encrypted_mobile}",
        tag: {{ page: "checkout" }}
      }});
      const vid = resp.visitor_id || resp.visitorId || "";
      const rid = resp.event_id || resp.requestId || "";
      if (vid && rid) {{
        await fetch("/callback?visitor_id=" + encodeURIComponent(vid) + "&request_id=" + encodeURIComponent(rid));
        document.getElementById("status").className = "";
        document.getElementById("status").innerHTML = "<div class='success'>✅ Verified!</div>";
        document.getElementById("msg").innerText = "Device verification successful! You can return to Termux.";
        setTimeout(() => {{ window.close(); }}, 1500);
      }} else {{
        document.getElementById("msg").innerText = "Fingerprint check returned empty result.";
      }}
    }} catch (err) {{
      document.getElementById("status").className = "";
      document.getElementById("msg").innerText = "Notice: " + (err.message || err);
      fetch("/callback?error=" + encodeURIComponent(err.message || err));
    }}
  </script>
</body>
</html>"""

    class VHandler(http.server.BaseHTTPRequestHandler):
        def do_GET(self):
            parsed = urllib.parse.urlparse(self.path)
            if parsed.path == "/fp":
                self.send_response(200)
                self.send_header("Content-Type", "text/html; charset=utf-8")
                self.end_headers()
                self.wfile.write(html_content.encode("utf-8"))
            elif parsed.path == "/callback":
                qs = urllib.parse.parse_qs(parsed.query)
                captured["visitor_id"] = qs.get("visitor_id", [""])[0]
                captured["request_id"] = qs.get("request_id", [""])[0]
                captured["error"] = qs.get("error", [""])[0]
                self.send_response(200)
                self.send_header("Access-Control-Allow-Origin", "*")
                self.end_headers()
                self.wfile.write(b"OK")
                done_event.set()
            else:
                self.send_response(404)
                self.end_headers()

        def log_message(self, format, *args):
            pass

    server = None
    try:
        socketserver.TCPServer.allow_reuse_address = True
        for p in range(port, port + 15):
            try:
                server = socketserver.TCPServer(("127.0.0.1", p), VHandler)
                port = p
                break
            except OSError:
                continue

        if not server:
            return {}

        server_thread = threading.Thread(target=server.serve_forever)
        server_thread.daemon = True
        server_thread.start()

        verification_url = f"http://127.0.0.1:{port}/fp"
        print(f"\n      🌐 Launching 1-second Device Verification in browser ...")
        print(f"         ↳ Link: {verification_url}")

        # Open in Android browser or system default browser
        opened = False
        if os.path.exists("/data/data/com.termux/files/usr/bin/am"):
            try:
                res = subprocess.run(
                    ["am", "start", "-a", "android.intent.action.VIEW", "-d", verification_url],
                    stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True
                )
                if res.returncode == 0:
                    opened = True
            except Exception:
                pass

        if not opened:
            try:
                webbrowser.open(verification_url)
            except Exception:
                pass

        # Wait for callback
        done_event.wait(timeout=timeout)

    except Exception as ex:
        print(f"      ⚠️ Verification bridge note: {ex}")
    finally:
        if server:
            try:
                server.shutdown()
                server.server_close()
            except Exception:
                pass

    vid = captured.get("visitor_id")
    rid = captured.get("request_id")
    if vid and rid:
        print(f"      ✅ Device verified! Request ID: {rid[:18]}... | Visitor ID: {vid}")
        return {"visitor_id": vid, "request_id": rid}
    else:
        err = captured.get("error", "timeout")
        print(f"      ℹ️ Device verification: {err}")
        return {}


# ─── API Client Class ─────────────────────────────────────────────────────────

class GyftrClient:
    def __init__(self, session_file: str = SESSION_FILE):
        self.session = requests.Session()
        self.session.headers.update(HEADERS)
        self.session_file = session_file
        self.token = None
        self.mobile = None
        self.user_data = {}
        self.epay_balance = 0.0
        self.is_registered = None

    def load_cached_session(self, target_mobile: str = None) -> bool:
        """Attempt to load saved token and profile from session_file."""
        if not self.session_file or not os.path.exists(self.session_file):
            return False
        try:
            with open(self.session_file, "r") as f:
                saved = json.load(f)
            token = saved.get("token")
            mobile = saved.get("mobile")
            if not token or not mobile:
                return False
            if target_mobile and mobile != target_mobile:
                return False

            self.token = token
            self.mobile = mobile
            self.user_data = saved.get("user_data", {})
            self.epay_balance = float(saved.get("epay_balance") or 0.0)

            # Test token validity
            if self.refresh_profile():
                name = self.user_data.get("full_name") or "User"
                print(f"\n🔄 Restored active login session for {name} ({self.mobile})!")
                print(f"   💰 Current ePay Balance: ₹{self.epay_balance:.2f}")
                return True
            else:
                self.token = None
                return False
        except Exception:
            return False

    def save_session(self):
        """Save active session to configured session_file."""
        if not self.session_file or not self.token or not self.mobile:
            return
        try:
            data = {
                "mobile": self.mobile,
                "token": self.token,
                "user_data": self.user_data,
                "epay_balance": self.epay_balance,
                "saved_at": int(time.time())
            }
            with open(self.session_file, "w") as f:
                json.dump(data, f, indent=2)
        except Exception:
            pass

    def clear_session(self):
        """Clear active session from memory and delete session_file if present."""
        self.token = None
        self.mobile = None
        self.user_data = {}
        self.epay_balance = 0.0
        if self.session_file and os.path.exists(self.session_file):
            try:
                os.remove(self.session_file)
            except Exception:
                pass

    def enc_post(self, url: str, payload: dict, extra_headers: dict = None) -> dict:
        """Helper to POST encrypted payload to api.gyftr.com safely without throwing on HTTP 400."""
        hdrs = dict(self.session.headers)
        if extra_headers:
            hdrs.update(extra_headers)
        if self.token:
            hdrs["token"] = self.token

        body = {"encryptedBody": aes_encrypt(json.dumps(payload, separators=(",", ":")))}
        try:
            r = self.session.post(url, json=body, headers=hdrs, timeout=35)
            try:
                return r.json()
            except Exception:
                return {"code": r.status_code, "message": r.text, "status": "ERROR"}
        except requests.RequestException as e:
            return {"code": 500, "message": str(e), "status": "ERROR"}

    # ── Step 1 & 2: Login ──────────────────────────────────────────────────────

    def send_otp(self, mobile: str) -> bool:
        """Send login OTP to mobile number."""
        self.mobile = mobile
        print(f"\n[1/4] 📱 Sending OTP to {mobile} ...")
        url = f"{API_BASE_URL}/v1/user/checkmobile"
        res = self.enc_post(url, {"mobile": mobile})
        msg = res.get("message", "")
        print(f"      ↳ Server: {msg}")

        if res.get("code") == 200 or res.get("status") == "SUCCESS":
            data = try_decrypt_api_data(res.get("data"))
            if isinstance(data, dict) and "registered" in data:
                self.is_registered = bool(data["registered"])
            print("      ✅ OTP sent successfully!")
            return True
        else:
            print(f"      ❌ Failed to send OTP: {res}")
            return False

    def verify_otp(self, otp: str, full_name: str = "Customer") -> bool:
        """Verify OTP and authenticate (supports both existing and fresh user accounts)."""
        print(f"\n      🔐 Verifying OTP {otp} ...")
        otp = str(otp).strip()

        # If explicitly fresh/unregistered, go directly to register
        if self.is_registered is False:
            return self.register_user(otp, full_name=full_name)

        url = f"{API_BASE_URL}/v1/user/login"
        payload = {"mobile": self.mobile, "password": str(otp), "mode": "O"}
        res = self.enc_post(url, payload)

        if res.get("code") == 200 or res.get("status") == "SUCCESS":
            self.token = res.get("token")
            data = try_decrypt_api_data(res.get("data"))
            if isinstance(data, dict):
                self.user_data = data
                self.epay_balance = float(data.get("epay_balance") or 0.0)
                name = data.get("full_name") or "User"
                print(f"      ✅ Login successful! Welcome, {name}.")
                print(f"      💰 Current ePay Balance: ₹{self.epay_balance:.2f}")
            else:
                print("      ✅ Login successful!")
            self.accept_consent()
            self.save_session()
            return True
        else:
            msg = res.get("message") or res.get("error") or str(res)
            all_err = f"{msg} {res.get('error', '')}".lower()
            # If GyFTR reports account inactive or not registered, fallback to register seamlessly
            if "inactive" in all_err or "not registered" in all_err or "not found" in all_err:
                print("      ℹ️ Fresh GyFTR mobile detected, registering new account ...")
                return self.register_user(otp, full_name=full_name)

            print(f"      ❌ Login failed: {msg}")
            return False

    def register_user(self, otp: str, full_name: str = "Customer") -> bool:
        """Register a new / fresh user account via /v1/user/register."""
        print(f"      📝 Registering new account for {self.mobile} ...")
        url = f"{API_BASE_URL}/v1/user/register"
        payload = {
            "mobile": self.mobile,
            "otp": str(otp).strip(),
            "full_name": full_name or "Customer",
            "email": ""
        }
        res = self.enc_post(url, payload)
        if res.get("code") == 200 or res.get("status") == "SUCCESS":
            self.token = res.get("token") or (res.get("data", {}) if isinstance(res.get("data"), dict) else {}).get("token")
            data = try_decrypt_api_data(res.get("data"))
            if isinstance(data, dict):
                self.user_data = data
                self.epay_balance = float(data.get("epay_balance") or 0.0)
                if not self.token and data.get("token"):
                    self.token = data["token"]
            print(f"      🎉 Account registered successfully! Welcome, {self.user_data.get('full_name', 'Customer')}.")
            self.accept_consent()
            self.refresh_profile()
            self.save_session()
            return True
        else:
            msg = res.get("message") or res.get("error") or str(res)
            print(f"      ❌ Registration failed: {msg}")
            return False

    def accept_consent(self) -> bool:
        """Accept Terms & Conditions and Privacy Policy for user account."""
        if not self.token:
            return False
        try:
            url = f"{API_BASE_URL}/v1/user/consent"
            payload = {
                "consent_type": [
                    {"consent_type": "Terms & Conditions", "status": "ACCEPTED"},
                    {"consent_type": "Privacy & Policy", "status": "ACCEPTED"}
                ]
            }
            res = self.enc_post(url, payload)
            return res.get("code") == 200
        except Exception:
            return False

    def refresh_profile(self) -> bool:
        """Fetch updated user profile and ePay balance."""
        if not self.token:
            return False
        ts = int(time.time() * 1000)
        url = f"{API_BASE_URL}/v1/user/profile?_={ts}"
        try:
            r = self.session.get(url, headers={"token": self.token}, timeout=15)
            if r.status_code == 200:
                rj = r.json()
                data = try_decrypt_api_data(rj.get("data"))
                if isinstance(data, dict):
                    self.user_data.update(data)
                    self.epay_balance = float(data.get("epay_balance") or 0.0)
                    self.save_session()
                    return True
            return False
        except Exception:
            return False

    # ── Step 3: Auto Play Game ────────────────────────────────────────────────

    def play_game(self, simulate_sec: int = 5) -> dict:
        """
        Diwali 2026 Game Auto-Play:
        Simulate game play, submit score (150 points), and claim ePay reward.
        """
        print(f"\n[2/4] 🎮 Playing Diwali Game (Auto Bot) ...")
        steps = 15
        for i in range(steps + 1):
            frac = i / steps
            bar = "█" * int(frac * 25) + "░" * (25 - int(frac * 25))
            sc = int(GAME_SCORE * frac)
            print(f"\r      [{bar}] Score: {sc:3d}/{GAME_SCORE}", end="", flush=True)
            if i < steps and simulate_sec > 0:
                time.sleep(simulate_sec / steps)
        print()

        print("      📤 Submitting score to claim ePay points ...")
        url = f"{API_BASE_URL}/v1/caimpaign/diwali2026"
        payload = {"user_type": "B2C", "points": GAME_SCORE}

        res = self.enc_post(url, payload, extra_headers={"Referer": "https://www.gyftr.com/nov26"})
        msg = res.get("message") or res.get("error") or str(res)
        code = res.get("code")
        already = False

        if code == 200:
            result = try_decrypt_api_data(res.get("data"))
            epay_credited = result.get("epay") if isinstance(result, dict) else "?"
            lucky = result.get("luckuser", False) if isinstance(result, dict) else False
            print(f"      🎉 Score submitted! ePay reward credited: ₹{epay_credited}")
            if lucky:
                print("      🌟 Lucky User Bonus received!")
            display_msg = f"ePay credited: ₹{epay_credited}"
        else:
            all_err = f"{msg} {res.get('error', '')}".lower()
            if "already" in all_err or "played" in all_err or "claimed" in all_err:
                already = True
                display_msg = "Today's game was already played for this account."
                print(f"      ℹ️  {display_msg}")
            else:
                display_msg = msg
                print(f"      ℹ️  Server message: {msg}")

        self.refresh_profile()
        print(f"      💰 Current ePay Balance: ₹{self.epay_balance:.2f}")
        return {"success": True, "message": display_msg, "already_played": already}

    # ── Step 4: Buy Rs 100 Amazon Pay Gift Voucher ─────────────────────────────

    def clear_conflicting_cart(self, target_product_id: int = FLIPKART_PRODUCT_ID, target_mrp: float = FLIPKART_MRP) -> int | None:
        """Clear cart items that don't match the target voucher, returning existing cart_id if already present."""
        ts = int(time.time() * 1000)
        url = f"{API_BASE_URL}/v1/cart/items?_={ts}"
        try:
            r = self.session.get(url, headers={"token": self.token}, timeout=15)
            if r.status_code != 200:
                return None
            data = try_decrypt_api_data(r.json().get("data"))
            if not isinstance(data, list):
                return None

            existing_cart_id = None
            for item in data:
                cid = item.get("cart_id")
                pid = item.get("product_id")
                mrp = float(item.get("mrp") or 0)
                if pid == target_product_id and mrp == target_mrp:
                    existing_cart_id = cid
                else:
                    print(f"      🧹 Removing old cart item: {item.get('product_name')} (Cart ID: {cid})")
                    self.enc_post(f"{API_BASE_URL}/v1/cart/remove", {"cart_item_id": cid})

            return existing_cart_id
        except Exception as e:
            print(f"      ⚠️ Cart check warning: {e}")
            return None

    def add_flipkart_voucher_to_cart(self) -> int:
        """Add Rs 50 Flipkart Gift Card to cart and return cart_item_id (from buyfk.har)."""
        existing_cid = self.clear_conflicting_cart(FLIPKART_PRODUCT_ID, FLIPKART_MRP)
        if existing_cid:
            print(f"      🛒 Flipkart Rs 50 voucher already in cart (Cart ID: {existing_cid})")
            return existing_cid

        print(f"\n[3/4] 🛒 Adding ₹50 Flipkart Gift Card to Cart ...")
        url = f"{API_BASE_URL}/v1/cart/add"
        payload = {
            "brand_name": FLIPKART_BRAND_NAME,
            "brand_id": FLIPKART_BRAND_ID,
            "product_name": FLIPKART_PRODUCT_NAME,
            "product_id": FLIPKART_PRODUCT_ID,
            "cm_product_id": FLIPKART_CM_PRODUCT_ID,
            "mrp": FLIPKART_MRP,
            "stock_left": 0,
            "is_ott_product": "N",
            "discount": 0,
            "discount_type": None,
            "offer_type": None,
            "coupon_code": None,
            "free_product_name": None,
            "free_brand_name": None,
            "is_free_promo": None,
            "earn_reward": 0,
            "service_type": 3,
            "max_value": 10000,
            "isEditPrice": True,
            "quantity": 1,
            "isQntyNotUpdate": False,
            "customPrice": 0,
            "disValue": 1.5
        }

        res = self.enc_post(url, payload)
        data = try_decrypt_api_data(res.get("data"))
        cart_item_id = None

        if isinstance(data, dict):
            cart_item_id = data.get("cart_item_id")

        if not cart_item_id:
            ts = int(time.time() * 1000)
            r = self.session.get(f"{API_BASE_URL}/v1/cart/items?_={ts}", headers={"token": self.token})
            cart_list = try_decrypt_api_data(r.json().get("data"))
            if isinstance(cart_list, list) and len(cart_list) > 0:
                cart_item_id = cart_list[-1].get("cart_id")

        if not cart_item_id:
            raise RuntimeError(f"Failed to add Flipkart Voucher to cart: {res}")

        print(f"      ✅ Item added to cart! Cart Item ID: {cart_item_id}")
        return cart_item_id

    def add_amazon_voucher_to_cart(self) -> int:
        """Add Rs 100 Amazon Gift Voucher to cart and return cart_item_id."""
        existing_cid = self.clear_conflicting_cart(AMAZON_PRODUCT_ID, AMAZON_MRP)
        if existing_cid:
            print(f"      🛒 Amazon Rs 100 voucher already in cart (Cart ID: {existing_cid})")
            return existing_cid

        print(f"\n[3/4] 🛒 Adding ₹100 Amazon Pay Gift Voucher to Cart ...")
        url = f"{API_BASE_URL}/v1/cart/add"
        payload = {
            "brand_name": "Amazon",
            "brand_id": AMAZON_BRAND_ID,
            "product_name": "Amazon",
            "product_id": AMAZON_PRODUCT_ID,
            "cm_product_id": 6970,
            "mrp": AMAZON_MRP,
            "stock_left": 0,
            "is_ott_product": "N",
            "discount": 0,
            "discount_type": None,
            "offer_type": None,
            "coupon_code": None,
            "free_product_name": None,
            "free_brand_name": None,
            "is_free_promo": None,
            "earn_reward": 0,
            "service_type": 3,
            "max_value": 10000,
            "isEditPrice": True,
            "quantity": 1,
            "isQntyNotUpdate": False,
            "customPrice": 0,
            "disValue": 0.5
        }

        res = self.enc_post(url, payload)
        data = try_decrypt_api_data(res.get("data"))
        cart_item_id = None

        if isinstance(data, dict):
            cart_item_id = data.get("cart_item_id")

        if not cart_item_id:
            # Fallback: fetch cart items
            ts = int(time.time() * 1000)
            r = self.session.get(f"{API_BASE_URL}/v1/cart/items?_={ts}", headers={"token": self.token})
            cart_list = try_decrypt_api_data(r.json().get("data"))
            if isinstance(cart_list, list) and len(cart_list) > 0:
                cart_item_id = cart_list[-1].get("cart_id")

        if not cart_item_id:
            raise RuntimeError(f"Failed to add Amazon Voucher to cart: {res}")

        print(f"      ✅ Item added to cart! Cart Item ID: {cart_item_id}")
        return cart_item_id

    def set_cart_delivery(self, recipient_email: str, recipient_name: str = None, recipient_mobile: str = None, template_id: int = 33) -> bool:
        """
        Configure gift delivery details (email/name) for vouchers in the cart.
        From buyfk.har Entry 178: POST /v1/cart/delivery
        """
        print(f"      🎁 Configuring Gift Delivery to email: {recipient_email} ...")
        url = f"{API_BASE_URL}/v1/cart/delivery"
        payload = {
            "delivery_name": recipient_name or (self.user_data.get("full_name") or "Customer"),
            "delivery_mobile": recipient_mobile or self.mobile,
            "delivery_email": recipient_email.strip(),
            "template_id": template_id,
        }

        res = self.enc_post(url, payload)
        if res.get("code") == 200 or res.get("status") == "SUCCESS":
            print(f"      ✅ Gift delivery details set! Recipient: {recipient_email}")
            return True
        else:
            print(f"      ⚠️ Could not set delivery email: {res.get('message', res)}")
            return False

    def checkout(self, cart_item_id: int, is_gift: bool = False) -> dict:
        """Create order checkout session and return payment form parameters."""
        print(f"      💳 Creating checkout order for Cart ID {cart_item_id} ...")

        # 1. Obtain genuine device fingerprint tokens
        enc_mobile = aes_encrypt(self.mobile, API_SECRET_KEY)
        fp_tokens = get_device_verification(enc_mobile)

        url = f"{API_BASE_URL}/v1/order/checkout"
        payload = {
            "cart_items": str(cart_item_id),
            "pg": "WALLET3.O",
            "whatsapp_send": "N" if is_gift else "Y",
            "affiliate_code": "",
            "utmsource": "",
        }

        # Include device verification parameters if captured
        if fp_tokens.get("request_id") and fp_tokens.get("visitor_id"):
            payload["request_id"] = fp_tokens["request_id"]
            payload["visitor_id"] = fp_tokens["visitor_id"]

        res = self.enc_post(url, payload)
        if res.get("code") != 200 and res.get("status") != "SUCCESS":
            raise RuntimeError(f"Checkout failed: {res.get('message', res)}")

        checkout_data = try_decrypt_api_data(res.get("data"))
        if not isinstance(checkout_data, dict) or "formData" not in checkout_data:
            raise RuntimeError(f"Invalid checkout response data: {checkout_data}")

        form_data = checkout_data["formData"]
        order_ref = checkout_data.get("orderRef")
        print(f"      ✅ Order created! Ref: {order_ref} | POrderID: {form_data.get('porderid')}")
        print(f"         Total Amount: ₹{form_data.get('txnamount')}")

        return checkout_data

    # ── Step 5: Pay using UPI ──────────────────────────────────────────────────

    def initiate_upi_payment(self, checkout_data: dict) -> dict:
        """
        Connect to pay.gyftr.com, send hybrid-encrypted payment request,
        and receive UPI Intent URI / QR code.
        """
        print(f"\n[4/4] ⚡ Processing Payment via UPI on pay.gyftr.com ...")
        form_action = checkout_data.get("formAction", PAY_BASE_URL)
        form_data = checkout_data["formData"]
        txn_amount = float(form_data.get("txnamount", 100))
        ext_order_id = form_data.get("porderid")

        # 1. Post formData to pay.gyftr.com
        pay_session = requests.Session()
        post_headers = {
            "Origin": "https://www.gyftr.com",
            "Referer": "https://www.gyftr.com/",
            "User-Agent": USER_AGENT,
        }

        r_post = pay_session.post(form_action, data=form_data, headers=post_headers, allow_redirects=False)
        seamless_token = pay_session.cookies.get("seamless_token")

        if not seamless_token:
            for cookie_header in r_post.headers.get("Set-Cookie", "").split(","):
                if "seamless_token=" in cookie_header:
                    seamless_token = cookie_header.split("seamless_token=")[1].split(";")[0].strip()

        if not seamless_token:
            raise RuntimeError("Could not obtain seamless_token from pay.gyftr.com")

        # 2. Extract checkout_trans_seed and parse ePay balance/token
        raw_seed = pay_session.cookies.get("checkout_trans_seed")
        if not raw_seed:
            for h in r_post.headers.get("Set-Cookie", "").split(","):
                if "checkout_trans_seed=" in h:
                    raw_seed = h.split("checkout_trans_seed=")[1].split(";")[0].strip()

        seed_epay_token = None
        seed_epay_balance = 0.0
        if raw_seed:
            try:
                dec_seed = aes_decrypt(urllib.parse.unquote(raw_seed), PAY_SECRET_KEY)
                seed_json = json.loads(dec_seed)
                seed_resp = seed_json.get("response", {})
                seed_epay_token = seed_resp.get("ePayToken")
                seed_epay_balance = float(seed_resp.get("ePayBalance") or 0.0)
            except Exception:
                pass

        if seed_epay_token:
            pay_session.cookies.set("seamless__pgcontainer_epay_token", seed_epay_token, domain="pay.gyftr.com", path="/")

        pay_session.cookies.set("pg_version", "pg-2", domain="pay.gyftr.com", path="/")
        pay_session.cookies.set("login_required", "false", domain="pay.gyftr.com", path="/")

        # Emulate page load
        try:
            pay_session.get(PAY_BASE_URL, headers={"User-Agent": USER_AGENT, "Referer": "https://www.gyftr.com/"}, timeout=10)
        except Exception:
            pass

        def log_activity(action, desc):
            try:
                pay_session.post(f"{PAY_BASE_URL}/user-activity", json={
                    "externalOrderId": ext_order_id,
                    "action": action,
                    "description": desc,
                    "callerId": 0,
                    "browser": "Chrome",
                    "os": "Android",
                    "mobile": "android",
                    "network": "4g"
                }, headers={
                    "Origin": PAY_BASE_URL,
                    "Referer": f"{PAY_BASE_URL}/",
                    "User-Agent": USER_AGENT,
                    "Content-Type": "application/json"
                }, timeout=5)
            except Exception:
                pass

        log_activity("PAGE_LOAD", "PAGE_LOAD")
        log_activity("PAYMENT_OPTION_SELECTED", "PAYMENT_OPTION_SELECTED:UPIQR")

        print("      ✅ Payment gateway session established!")

        # 3. Calculate ePay deduction and UPI payable amount
        available_epay = max(self.epay_balance, seed_epay_balance)
        epay_to_use = min(available_epay, txn_amount)
        pg_amount = max(0.0, txn_amount - epay_to_use)

        print(f"         Total Amount  : ₹{txn_amount:.2f}")
        print(f"         ePay Used     : ₹{epay_to_use:.2f}")
        print(f"         UPI Payable   : ₹{pg_amount:.2f}")

        def execute_payment_request(use_epay_amt: float, use_pg_amt: float):
            if use_epay_amt > 0:
                used_payload = {
                    "usedEPayBalance": int(use_epay_amt) if use_epay_amt.is_integer() else use_epay_amt,
                    "externalOrderId": ext_order_id
                }
                used_enc = aes_encrypt(json.dumps(used_payload, separators=(",", ":")), PAY_SECRET_KEY)
                pay_session.cookies.set("usedEPayData", used_enc, domain="pay.gyftr.com", path="/")
                log_activity("EPAY_APPLY", f"EPAY_APPLY:partial:{int(use_epay_amt)}")
            else:
                try:
                    pay_session.cookies.set("usedEPayData", "", domain="pay.gyftr.com", path="/", expires=0)
                except Exception:
                    pass

            log_activity("PAY_BUTTON_CLICK", "PAY_BUTTON_CLICK:UPIQR")

            if use_epay_amt >= txn_amount and use_pg_amt <= 0:
                payload = {
                    "amount": txn_amount,
                    "totalAmount": txn_amount,
                    "epayAmount": use_epay_amt,
                    "pgAmount": 0,
                    "paymentType": "epay",
                    "browserInfo": {"browser": "Google Chrome", "version": "156"}
                }
            else:
                payload = {
                    "paymentType": "QR",
                    "deviceType": "Android",
                    "userAgent": USER_AGENT,
                    "upiAppName": "genericintent",
                    "amount": txn_amount,
                    "totalAmount": txn_amount,
                    "epayAmount": use_epay_amt,
                    "pgAmount": use_pg_amt,
                    "browserInfo": {"browser": "Google Chrome", "version": "156"}
                }

            enc_res = hybrid_encrypt(payload)
            req_hdrs = {
                "Authorization": f"Bearer {seamless_token}",
                "Content-Type": "application/json",
                "Origin": PAY_BASE_URL,
                "Referer": f"{PAY_BASE_URL}/",
                "User-Agent": USER_AGENT,
            }
            body = {
                "apiName": "paymentProcessApi",
                "data": enc_res["encryptedPayload"],
                "encryptedKey": enc_res["encryptedKey"]
            }
            r = pay_session.post(f"{PAY_BASE_URL}/api-proxy", json=body, headers=req_hdrs, timeout=20)
            try:
                rj = r.json()
            except Exception:
                rj = {"status": "failed", "message": f"HTTP {r.status_code}: {r.text}"}
            return rj, req_hdrs

        resp_json, req_headers = execute_payment_request(epay_to_use, pg_amount)

        # 4. Check if full payment completed via ePay redirection (status: redirection with TXN_SUCCESS)
        if resp_json.get("status") == "redirection":
            f_data = resp_json.get("formData", {})
            input_d = f_data.get("inputData", "")
            if "TXN_SUCCESS" in input_d or 'status_code";s:2:"00"' in input_d:
                redirect_action = resp_json.get("formAction", f"{API_BASE_URL}/v1/order/pgsuccess")
                try:
                    pay_session.post(redirect_action, data=f_data, headers={"Origin": PAY_BASE_URL, "Referer": f"{PAY_BASE_URL}/", "User-Agent": USER_AGENT}, timeout=10)
                except Exception:
                    pass
                print(f"      🎉 ePay full payment successful! Covered bill of ₹{txn_amount:.2f}")
                return {
                    "pay_session": pay_session,
                    "req_headers": req_headers,
                    "intent_uri": None,
                    "ext_order_id": ext_order_id,
                    "pg_identifier": "ePay",
                    "pg_amount": 0.0,
                    "epay_used": epay_to_use,
                    "is_completed": True
                }

        # Fallback: if ePay was applied and gateway returned redirection error, retry with full amount via UPI
        if resp_json.get("status") != "success" and epay_to_use > 0:
            print(f"      ⚠️ ePay deduction not accepted by gateway ({resp_json.get('status')}). Retrying with full UPI payment (₹{txn_amount:.2f}) ...")
            epay_to_use = 0.0
            pg_amount = txn_amount
            resp_json, req_headers = execute_payment_request(0.0, txn_amount)

        if resp_json.get("status") != "success" or not resp_json.get("data"):
            raise RuntimeError(f"paymentProcessApi failed: {resp_json.get('message', resp_json)}")

        # 4. Decrypt response using PAY_SECRET_KEY
        decrypted_text = aes_decrypt(resp_json["data"], PAY_SECRET_KEY)
        dec_data = json.loads(decrypted_text)
        resp_obj = dec_data.get("response", {})

        intent_uri = resp_obj.get("intentURIData")
        res_ext_id = resp_obj.get("externalOrderId", ext_order_id)
        pg_identifier = resp_obj.get("pg_identifier", "PayU")

        print("      ✅ Payment Intent generated successfully!")
        return {
            "pay_session": pay_session,
            "req_headers": req_headers,
            "intent_uri": intent_uri,
            "ext_order_id": res_ext_id,
            "pg_identifier": pg_identifier,
            "pg_amount": pg_amount,
            "epay_used": epay_to_use
        }

    def check_payment_status(self, payment_info: dict) -> str:
        """
        Check payment status once via verifyPaymentIntentApi.
        Returns: "SUCCESS", "FAILED", or "PENDING".
        """
        if payment_info.get("is_completed"):
            return "SUCCESS"
        pay_session = payment_info.get("pay_session")
        req_headers = payment_info.get("req_headers")
        if not pay_session or not req_headers:
            return "UNKNOWN"
        api_proxy_url = f"{PAY_BASE_URL}/api-proxy"
        try:
            v_enc = hybrid_encrypt({})
            v_body = {
                "apiName": "verifyPaymentIntentApi",
                "data": v_enc["encryptedPayload"],
                "encryptedKey": v_enc["encryptedKey"]
            }
            r_v = pay_session.post(api_proxy_url, json=v_body, headers=req_headers, timeout=10)
            if r_v.status_code == 200:
                v_json = r_v.json()
                if v_json.get("status") == "success" and v_json.get("data"):
                    v_dec = aes_decrypt(v_json["data"], PAY_SECRET_KEY)
                    v_obj = json.loads(v_dec)
                    v_status = v_obj.get("response", {}).get("status", "").upper()
                    if v_status == "SUCCESS":
                        return "SUCCESS"
                    elif v_status == "FAILED":
                        return "FAILED"
        except Exception:
            pass
        return "PENDING"

    def display_upi_and_wait(self, payment_info: dict, poll_timeout_secs: int = 300) -> bool:
        """
        Display UPI payment link, print ASCII QR code,
        attempt to launch UPI app on Android (Termux),
        and poll for payment completion.
        """
        intent_uri = payment_info.get("intent_uri")
        pg_amount = payment_info.get("pg_amount", 0.0)
        ext_order_id = payment_info.get("ext_order_id")

        if payment_info.get("is_completed") or (not intent_uri and pg_amount <= 0):
            print("\n" + "═" * 60)
            print("  🎉 PAYMENT COMPLETE VIA EPAY POINTS!")
            print(f"  Voucher will be delivered to SMS & Email ({self.mobile})")
            print("═" * 60)
            return True

        if not intent_uri:
            print(f"      ⚠️ No UPI Intent URI returned in response: {payment_info}")
            return False

        print("\n" + "═" * 62)
        print("               📲  UPI PAYMENT DETAILS")
        print("═" * 62)
        print(f"  Amount Payable : ₹{pg_amount:.2f}")
        print(f"  Order ID       : {ext_order_id}")
        print(f"\n  🔗 UPI Intent URL:")
        print(f"  {intent_uri}")
        print("═" * 62)

        # 1. Print QR Code if qrcode library is available
        if HAS_QRCODE:
            print("\n  📷 SCAN QR CODE WITH ANY UPI APP (GPay / PhonePe / Paytm):\n")
            try:
                qr = qrcode.QRCode(border=1)
                qr.add_data(intent_uri)
                qr.make(fit=True)
                qr.print_ascii(invert=True)
            except Exception as e:
                print(f"  (Could not render QR: {e})")

        # 2. Attempt to launch UPI App on Android (Termux)
        if os.path.exists("/data/data/com.termux/files/usr/bin/am"):
            print("\n  🚀 Attempting to open UPI App directly on Android ...")
            try:
                cmd = ["am", "start", "-a", "android.intent.action.VIEW", "-d", intent_uri]
                res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                if res.returncode == 0:
                    print("  ✅ UPI App opened! Please complete payment in your UPI app.")
            except Exception:
                pass

        # 3. Poll payment status
        print(f"\n  ⏳ Waiting for payment confirmation (timeout: {poll_timeout_secs}s) ...")
        start_time = time.time()
        while time.time() - start_time < poll_timeout_secs:
            time.sleep(5)
            elapsed = int(time.time() - start_time)
            print(f"\r     Checking payment status ... ({elapsed}s elapsed)", end="", flush=True)

            status = self.check_payment_status(payment_info)
            if status == "SUCCESS":
                print(f"\n\n{'═' * 62}")
                print("  🎉 PAYMENT SUCCESSFUL! VOUCHER ORDER COMPLETED!")
                print(f"  Gift Voucher has been sent to your registered mobile ({self.mobile})")
                print("═" * 62)
                return True
            elif status == "FAILED":
                print(f"\n\n  ❌ Payment Failed")
                return False

        print("\n\n  ⌛ Polling timed out. If you paid, the voucher will arrive on SMS shortly.")
        return False


# ─── Main Execution ───────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(
        description="Gyftr Auto Bot: Login → Diwali Game → ₹50 Flipkart GV (with Gifting) → UPI Payment"
    )
    parser.add_argument("--mobile", "-m", help="10-digit registered mobile number", default=None)
    parser.add_argument("--otp", "-o", help="OTP received on SMS/WhatsApp", default=None)
    parser.add_argument("--email", "-e", help="Recipient email address for gifting (optional)", default=None)
    parser.add_argument("--brand", "-b", choices=["flipkart", "amazon"], default="flipkart", help="Voucher brand: flipkart (₹50) or amazon (₹100) (default: flipkart)")
    parser.add_argument("--skip-game", action="store_true", help="Skip Diwali game play")
    parser.add_argument("--fast", action="store_true", help="Fast game play simulation without delay")
    parser.add_argument("--fresh", action="store_true", help="Ignore saved session and force fresh OTP login")
    parser.add_argument("--timeout", type=int, default=300, help="Payment polling timeout in seconds (default: 300)")

    args, unknown = parser.parse_known_args()

    # Support positional arguments if passed: python gyftr.py <mobile> [otp]
    mobile = args.mobile
    otp = args.otp

    if not mobile and len(unknown) > 0 and unknown[0].isdigit() and len(unknown[0]) == 10:
        mobile = unknown[0]
    if not otp and len(unknown) > 1 and unknown[1].isdigit():
        otp = unknown[1]

    brand_name = "Flipkart (₹50)" if args.brand == "flipkart" else "Amazon (₹100)"
    print()
    print("╔" + "═" * 60 + "╗")
    print("║        🪔  GYFTR AUTO BOT: GAME + FLIPKART/AMAZON GV + UPI 🪔║")
    print(f"║   1. Login → 2. Play Game → 3. Add {brand_name} → 4. Pay UPI ║")
    print("╚" + "═" * 60 + "╝")

    client = GyftrClient()

    # Try loading cached session if not forced fresh
    logged_in = False
    if not args.fresh and not otp:
        if client.load_cached_session(target_mobile=mobile):
            logged_in = True

    if not logged_in:
        # Prompt for mobile if not provided
        if not mobile:
            print("\n📌 Enter your Gyftr-registered 10-digit mobile number:")
            mobile = input("   ➤ Mobile: ").strip()

        if not mobile.isdigit() or len(mobile) != 10:
            print("❌ Invalid mobile number! Must be 10 digits.")
            sys.exit(1)

        # Step 1: Send OTP
        if not client.send_otp(mobile):
            sys.exit(1)

        # Prompt for OTP if not provided
        if not otp:
            print("\n📌 Enter OTP received on SMS/WhatsApp:")
            otp = input("   ➤ OTP: ").strip()

        if not otp.isdigit() or len(otp) < 4:
            print("❌ Invalid OTP!")
            sys.exit(1)

        # Step 2: Login
        if not client.verify_otp(otp):
            sys.exit(1)

    # Step 3: Play Diwali Game (Earn ePay points)
    if not args.skip_game:
        sim_secs = 0 if args.fast else 5
        client.play_game(simulate_sec=sim_secs)
    else:
        print("\n[2/4] ⏭️ Skipping game play as requested.")

    # Gifting Email Configuration
    gift_email = args.email
    if gift_email is None:
        print("\n🎁 Gifting Options:")
        email_in = input("   ➤ Enter recipient email for gifting (press Enter to deliver to yourself): ").strip()
        if email_in and "@" in email_in and "." in email_in:
            gift_email = email_in
        elif email_in:
            print("   ⚠️ Invalid email format, proceeding with self delivery.")

    # Step 4: Add Voucher to Cart (Flipkart ₹50 or Amazon ₹100)
    if args.brand == "flipkart":
        cart_item_id = client.add_flipkart_voucher_to_cart()
    else:
        cart_item_id = client.add_amazon_voucher_to_cart()

    if gift_email:
        client.set_cart_delivery(recipient_email=gift_email)

    checkout_data = client.checkout(cart_item_id, is_gift=bool(gift_email))

    # Step 5: Pay using UPI
    payment_info = client.initiate_upi_payment(checkout_data)
    client.display_upi_and_wait(payment_info, poll_timeout_secs=args.timeout)

    print("\n[DONE] Bot workflow complete! Thank you.")


if __name__ == "__main__":
    main()
