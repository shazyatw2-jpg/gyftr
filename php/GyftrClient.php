<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║                  GYFTR CLIENT PHP ENGINE                         ║
 * ║   Full Reverse-Engineered GyFTR & Pay.GyFTR API Implementation   ║
 * ║   Compatible with PHP 7.4 / 8.0 / 8.1 / 8.2 / 8.3 / 8.4+         ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */

class GyftrCrypto {
    public const API_SECRET_KEY = "d909dg78609e47ak47diy4cf87t90c4a";
    public const PAY_SECRET_KEY = "gyftr2024ClientKey123456789012345";
    public const PAY_RSA_PUB = "-----BEGIN PUBLIC KEY-----\n"
        . "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAzFOoztxmhFYN4I6HH4mv\n"
        . "3T0bzwwjO7H4k2oX1kl+Tw3IkQjZWKljd0U/FNXURd95JfFoed4wJ40Sjcjy63md\n"
        . "EzpWREg++UfOTArA23keOONvs1JvvkGJkbpPdfFA3cHzMMV4JZru9BNDX+/8uP0R\n"
        . "EDlDxdQK9/UQ9dJhoWnOmED8fL6teQa2HGP+okkEBJ26uiSrPhFLJqaWdtT0txYP\n"
        . "6Gb2sbd7k3dKa0/6aKh653LiJZIMN5G5blarSe9/KGQMpq7jIQsCatKytIIW+gQA\n"
        . "RFAX743nEWtLxu3mV9+0WEQiq8zy9APLoGoz2eXFkIXPU8UIoXQE6ohc83Tiat0Y\n"
        . "FwIDAQAB\n"
        . "-----END PUBLIC KEY-----";

    /**
     * OpenSSL EVP_BytesToKey MD5 key derivation compatible with CryptoJS.
     */
    public static function evpBytesToKey(string $password, string $salt, int $keyLen = 32, int $ivLen = 16): array {
        $d = '';
        $di = '';
        while (strlen($d) < $keyLen + $ivLen) {
            $di = md5($di . $password . $salt, true);
            $d .= $di;
        }
        return [
            substr($d, 0, $keyLen),
            substr($d, $keyLen, $ivLen)
        ];
    }

    /**
     * CryptoJS compatible AES-256-CBC encryption with 'Salted__' prefix.
     */
    public static function aesEncrypt(string $plaintext, string $passphrase = self::API_SECRET_KEY): string {
        $salt = random_bytes(8);
        list($key, $iv) = self::evpBytesToKey($passphrase, $salt, 32, 16);
        $ct = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode("Salted__" . $salt . $ct);
    }

    /**
     * CryptoJS compatible AES-256-CBC decryption.
     */
    public static function aesDecrypt(string $ciphertextB64, string $passphrase = self::API_SECRET_KEY): string {
        $raw = base64_decode($ciphertextB64);
        if (substr($raw, 0, 8) !== "Salted__") {
            throw new Exception("Invalid CryptoJS ciphertext (missing 'Salted__' prefix)");
        }
        $salt = substr($raw, 8, 8);
        $ct = substr($raw, 16);
        list($key, $iv) = self::evpBytesToKey($passphrase, $salt, 32, 16);
        $dec = openssl_decrypt($ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($dec === false) {
            throw new Exception("AES decryption error: " . openssl_error_string());
        }
        return $dec;
    }

    /**
     * Hybrid RSA-2048 PKCS1_OAEP + AES encryption for pay.gyftr.com.
     */
    public static function hybridEncrypt(array $payload): array {
        $randomAesKey = bin2hex(random_bytes(32));
        $payloadStr = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $encryptedPayload = self::aesEncrypt($payloadStr, $randomAesKey);

        $encryptedKeyRaw = '';
        $ok = openssl_public_encrypt($randomAesKey, $encryptedKeyRaw, self::PAY_RSA_PUB, OPENSSL_PKCS1_OAEP_PADDING);
        if (!$ok) {
            throw new Exception("RSA OAEP encryption failed: " . openssl_error_string());
        }
        $encryptedKey = base64_encode($encryptedKeyRaw);

        return [
            'encryptedPayload' => $encryptedPayload,
            'encryptedKey' => $encryptedKey
        ];
    }

    /**
     * Decrypt API response if returned in 'Salted__' base64 format.
     */
    public static function tryDecryptApiData($raw, string $key = self::API_SECRET_KEY) {
        if (is_string($raw) && strpos($raw, 'U2FsdGVk') === 0) {
            try {
                $dec = self::aesDecrypt($raw, $key);
                $json = json_decode($dec, true);
                return is_array($json) ? $json : $dec;
            } catch (Exception $e) {
                return $raw;
            }
        }
        return $raw;
    }
}

class GyftrClient {
    public const API_BASE_URL = "https://api.gyftr.com/gyftrapi/api";
    public const PAY_BASE_URL = "https://pay.gyftr.com";
    public const CAMPAIGN     = "DIWALI2026";

    public const FLIPKART_BRAND_ID      = 95;
    public const FLIPKART_PRODUCT_ID    = 4860;
    public const FLIPKART_CM_PRODUCT_ID = 5919;
    public const FLIPKART_MRP           = 50;

    public const AMAZON_BRAND_ID        = 89;
    public const AMAZON_PRODUCT_ID      = 5527;
    public const AMAZON_CM_PRODUCT_ID   = 6942;
    public const AMAZON_MRP             = 100;

    public const USER_AGENT = "Mozilla/5.0 (Linux; Android 10; Redmi Note 9 Pro Max Build/QKQ1.191215.002; wv) "
        . "AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/156.0.8078.47 Mobile Safari/537.36";

    public static function getProxy(): ?string {
        $env = getenv('GYFTR_PROXY');
        if ($env !== false && $env !== '') {
            return $env;
        }
        if (defined('GYFTR_PROXY') && GYFTR_PROXY) {
            return GYFTR_PROXY;
        }
        return null;
    }

    public static function applyProxy(array &$curlOpts): void {
        $proxy = self::getProxy();
        if ($proxy) {
            $curlOpts[CURLOPT_PROXY] = $proxy;
        }
    }

    public ?string $token = null;
    public ?string $mobile = null;
    public array $userData = [];
    public float $epayBalance = 0.0;
    public ?string $sessionFile = null;
    public ?bool $isRegistered = null;

    private array $cookies = [];
    private ?string $cookieJarFile = null;

    public function __construct(?string $sessionFile = null) {
        $this->sessionFile = $sessionFile;
        if ($this->sessionFile) {
            $this->loadSession();
        }
    }

    public function loadSession(): bool {
        if (!$this->sessionFile || !file_exists($this->sessionFile)) {
            return false;
        }
        try {
            $raw = file_get_contents($this->sessionFile);
            $data = json_decode($raw, true);
            if (!is_array($data)) return false;

            $this->token = $data['token'] ?? null;
            $this->mobile = $data['mobile'] ?? null;
            $this->userData = $data['user_data'] ?? [];
            $this->epayBalance = floatval($data['epay_balance'] ?? 0.0);

            if ($this->token) {
                return $this->refreshProfile();
            }
        } catch (Exception $e) {
            return false;
        }
        return false;
    }

    public function saveSession(): bool {
        if (!$this->sessionFile || !$this->token || !$this->mobile) {
            return false;
        }
        $data = [
            'mobile' => $this->mobile,
            'token' => $this->token,
            'user_data' => $this->userData,
            'epay_balance' => $this->epayBalance,
            'saved_at' => time()
        ];
        $dir = dirname($this->sessionFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return (bool)file_put_contents($this->sessionFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    public function clearSession(): void {
        $this->token = null;
        $this->mobile = null;
        $this->userData = [];
        $this->epayBalance = 0.0;
        if ($this->sessionFile && file_exists($this->sessionFile)) {
            @unlink($this->sessionFile);
        }
    }

    public static function getAccountSessionFile(string $mobile): string {
        $clean = preg_replace('/\D/', '', $mobile);
        if (strlen($clean) === 12 && substr($clean, 0, 2) === '91') {
            $clean = substr($clean, 2);
        }
        $dir = is_dir('/storage/emulated/0/gyftr/sessions') ? '/storage/emulated/0/gyftr/sessions/accounts' : __DIR__ . '/sessions/accounts';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir . "/{$clean}.json";
    }

    /**
     * Check if an authenticated saved session exists for this mobile number.
     * If valid, loads and restores it into this client instance.
     */
    public function tryAutoLogin(string $mobile): bool {
        $file = self::getAccountSessionFile($mobile);
        if (!file_exists($file)) {
            return false;
        }
        try {
            $raw = file_get_contents($file);
            $data = json_decode($raw, true);
            if (!is_array($data) || empty($data['token'])) {
                return false;
            }

            $this->token = $data['token'];
            $clean = preg_replace('/\D/', '', $mobile);
            if (strlen($clean) === 12 && substr($clean, 0, 2) === '91') {
                $clean = substr($clean, 2);
            }
            $this->mobile = $clean;
            $this->userData = $data['user_data'] ?? [];
            $this->epayBalance = floatval($data['epay_balance'] ?? 0.0);

            // Test if token is still valid with GyFTR server
            if ($this->refreshProfile()) {
                $this->saveAccountSession();
                $this->saveSession();
                return true;
            } else {
                // Token expired
                @unlink($file);
                $this->clearSession();
                return false;
            }
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Save session permanently to the mobile account storage pool.
     */
    public function saveAccountSession(): bool {
        if (!$this->token || !$this->mobile) {
            return false;
        }
        $file = self::getAccountSessionFile($this->mobile);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $data = [
            'mobile' => $this->mobile,
            'token' => $this->token,
            'user_data' => $this->userData,
            'epay_balance' => $this->epayBalance,
            'saved_at' => time()
        ];
        return (bool)file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Send encrypted POST request to api.gyftr.com.
     */
    public function encPost(string $url, array $payload, array $extraHeaders = []): array {
        $headerMap = [
            "Content-Type" => "application/json",
            "Accept" => "application/json, text/plain, */*",
            "Expect" => "", // Suppress 100-continue which triggers WAF 403
            "device" => "mobile",
            "platform" => "android",
            "Origin" => "https://www.gyftr.com",
            "Referer" => "https://www.gyftr.com/",
            "User-Agent" => self::USER_AGENT,
            "sec-ch-ua-platform" => "\"Android\"",
            "sec-ch-ua-mobile" => "?1",
            "sec-fetch-site" => "same-site",
            "sec-fetch-mode" => "cors",
            "sec-fetch-dest" => "empty",
            "accept-language" => "en-IN,en-US;q=0.9,en;q=0.8"
        ];

        if ($this->token) {
            $headerMap["token"] = $this->token;
        }
        foreach ($extraHeaders as $k => $v) {
            $headerMap[$k] = $v;
        }
        $headers = [];
        foreach ($headerMap as $k => $v) {
            $headers[] = "$k: $v";
        }

        $bodyJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $encryptedBody = GyftrCrypto::aesEncrypt($bodyJson, GyftrCrypto::API_SECRET_KEY);
        $reqData = json_encode(["encryptedBody" => $encryptedBody], JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        $curlOpts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $reqData,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_SSL_VERIFYPEER => true
        ];

        self::applyProxy($curlOpts);

        curl_setopt_array($ch, $curlOpts);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        @curl_close($ch);

        if ($err) {
            return ["code" => 500, "message" => "cURL Error: $err", "status" => "ERROR"];
        }

        $json = json_decode($res, true);
        if (is_array($json)) {
            return $json;
        }
        return ["code" => $httpCode, "message" => $res, "status" => "RAW"];
    }

    /**
     * Step 1: Send OTP to mobile via /v1/user/checkmobile.
     */
    public function sendOtp(string $mobile): bool {
        $this->mobile = preg_replace('/\D/', '', $mobile);
        if (strlen($this->mobile) === 12 && substr($this->mobile, 0, 2) === '91') {
            $this->mobile = substr($this->mobile, 2);
        }
        $url = self::API_BASE_URL . "/v1/user/checkmobile";
        $payload = [
            "mobile" => $this->mobile
        ];
        $res = $this->encPost($url, $payload);
        if (($res['code'] ?? 0) === 200 || ($res['status'] ?? '') === 'SUCCESS') {
            $data = GyftrCrypto::tryDecryptApiData($res['data'] ?? []);
            if (is_array($data) && isset($data['registered'])) {
                $this->isRegistered = (bool)$data['registered'];
            }
            return true;
        }
        $msg = $res['message'] ?? json_encode($res);
        throw new Exception($msg);
    }

    /**
     * Step 2: Verify received OTP via /v1/user/login or /v1/user/register.
     */
    public function verifyOtp(string $otp, ?string $mobile = null, string $fullName = 'Customer'): bool {
        if ($mobile) {
            $clean = preg_replace('/\D/', '', $mobile);
            if (strlen($clean) === 12 && substr($clean, 0, 2) === '91') {
                $clean = substr($clean, 2);
            }
            $this->mobile = $clean;
        }
        if (!$this->mobile) {
            throw new Exception("Mobile number not set. Call sendOtp() first.");
        }
        $otp = trim(preg_replace('/\D/', '', $otp));

        // If explicitly detected as fresh/unregistered account, go directly to register
        if ($this->isRegistered === false) {
            return $this->registerUser($otp, $fullName);
        }

        $url = self::API_BASE_URL . "/v1/user/login";
        $payload = [
            "mobile" => $this->mobile,
            "password" => (string)$otp,
            "mode" => "O"
        ];
        $res = $this->encPost($url, $payload);

        if (($res['code'] ?? 0) === 200 || ($res['status'] ?? '') === 'SUCCESS') {
            $this->token = $res['token'] ?? null;
            $data = GyftrCrypto::tryDecryptApiData($res['data'] ?? []);
            if (is_array($data)) {
                $this->userData = $data;
                $this->epayBalance = floatval($data['epay_balance'] ?? 0.0);
            }
            $this->acceptConsent();
            $this->saveSession();
            $this->saveAccountSession();
            return true;
        }

        $msg = $res['message'] ?? $res['error'] ?? json_encode($res);
        $allErr = strtolower($msg . ' ' . ($res['error'] ?? ''));

        // Fallback for fresh account: GyFTR returns "Your account is inactive, please contact support!" when fresh number hits login
        if (strpos($allErr, 'inactive') !== false || strpos($allErr, 'not registered') !== false || strpos($allErr, 'not found') !== false) {
            return $this->registerUser($otp, $fullName);
        }

        throw new Exception($msg);
    }

    /**
     * Register a new / fresh user via /v1/user/register.
     */
    public function registerUser(string $otp, string $fullName = 'Customer'): bool {
        $url = self::API_BASE_URL . "/v1/user/register";
        $payload = [
            "mobile" => $this->mobile,
            "otp" => (string)$otp,
            "full_name" => $fullName ?: "Customer",
            "email" => ""
        ];
        $res = $this->encPost($url, $payload);

        if (($res['code'] ?? 0) === 200 || ($res['status'] ?? '') === 'SUCCESS') {
            $this->token = $res['token'] ?? null;
            $data = GyftrCrypto::tryDecryptApiData($res['data'] ?? []);
            if (is_array($data)) {
                $this->userData = $data;
                $this->epayBalance = floatval($data['epay_balance'] ?? 0.0);
                if (empty($this->token) && !empty($data['token'])) {
                    $this->token = $data['token'];
                }
            }
            $this->acceptConsent();
            $this->refreshProfile();
            $this->saveSession();
            $this->saveAccountSession();
            return true;
        }

        $msg = $res['message'] ?? $res['error'] ?? json_encode($res);
        throw new Exception($msg);
    }

    /**
     * Accept Terms & Conditions and Privacy Policy.
     */
    public function acceptConsent(): bool {
        if (!$this->token) return false;
        try {
            $url = self::API_BASE_URL . "/v1/user/consent";
            $payload = [
                "consent_type" => [
                    ["consent_type" => "Terms & Conditions", "status" => "ACCEPTED"],
                    ["consent_type" => "Privacy & Policy", "status" => "ACCEPTED"]
                ]
            ];
            $res = $this->encPost($url, $payload);
            return (($res['code'] ?? 0) === 200);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Step 3: Refresh live profile & balance.
     */
    public function refreshProfile(): bool {
        if (!$this->token) return false;
        $ts = round(microtime(true) * 1000);
        $url = self::API_BASE_URL . "/v1/user/profile?_=" . $ts;

        $headers = [
            "token: " . $this->token,
            "User-Agent: " . self::USER_AGENT,
            "device: mobile",
            "platform: android",
            "Origin: https://www.gyftr.com",
            "Referer: https://www.gyftr.com/"
        ];

        $ch = curl_init($url);
        $curlOpts = [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25
        ];
        self::applyProxy($curlOpts);
        curl_setopt_array($ch, $curlOpts);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        @curl_close($ch);

        if ($code === 200) {
            $json = json_decode($res, true);
            if (is_array($json)) {
                if (!empty($json['token'])) {
                    $this->token = $json['token'];
                }
                $data = GyftrCrypto::tryDecryptApiData($json['data'] ?? []);
                if (is_array($data)) {
                    $this->userData = array_merge($this->userData, $data);
                    $this->epayBalance = floatval($data['epay_balance'] ?? 0.0);
                    $this->saveSession();
                    $this->saveAccountSession();
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Step 4: Auto-Play Diwali 2026 Game (+150 Score).
     */
    public function playGame(int $simulateSec = 0): array {
        if (!$this->token) {
            throw new Exception("Must be logged in to play game.");
        }
        if ($simulateSec > 0) {
            sleep($simulateSec);
        }
        $url = self::API_BASE_URL . "/v1/caimpaign/diwali2026";
        $userType = $this->userData['user_type'] ?? 'B2C';
        $payload = [
            "user_type" => $userType ?: "B2C",
            "points" => 150
        ];
        $extraHeaders = [
            "Referer" => "https://www.gyftr.com/nov26"
        ];
        $res = $this->encPost($url, $payload, $extraHeaders);
        $code = $res['code'] ?? 0;
        $errCode = $res['error'] ?? '';
        $msg = $res['message'] ?? $errCode ?? json_encode($res);

        if ($code === 200 || ($res['status'] ?? '') === 'SUCCESS') {
            $this->refreshProfile();
            $data = GyftrCrypto::tryDecryptApiData($res['data'] ?? []);
            $epay = is_array($data) ? ($data['epay'] ?? '?') : '?';
            return [
                "success" => true,
                "message" => "Diwali Game finished! ePay credited: ₹{$epay}. New Balance: ₹" . number_format($this->epayBalance, 2),
                "epay" => $epay
            ];
        }

        $allErrText = strtolower($msg . ' ' . $errCode);
        if (strpos($allErrText, 'already') !== false || strpos($allErrText, 'played') !== false || strpos($allErrText, 'claimed') !== false) {
            $this->refreshProfile();
            return [
                "success" => true,
                "message" => "Today's Diwali Game was already played for this account. Current Balance: ₹" . number_format($this->epayBalance, 2),
                "already_played" => true
            ];
        }

        if ($code === 429 || strpos($allErrText, 'shortly') !== false || strpos($allErrText, 'try again') !== false) {
            $this->refreshProfile();
            return [
                "success" => false,
                "message" => "GyFTR Notice: " . $msg . " (Wait 30 seconds and retry).",
                "already_played" => true
            ];
        }

        if (strpos($allErrText, 'timed out') !== false || strpos($allErrText, 'curl error') !== false) {
            $this->refreshProfile();
            return [
                "success" => false,
                "message" => "Game server is busy (response timed out). Your score may already be counted. Balance: ₹" . number_format($this->epayBalance, 2),
                "already_played" => false
            ];
        }

        throw new Exception($msg);
    }

    /**
     * Cart: Clear conflicting items or return existing item ID.
     */
    public function clearConflictingCart(int $targetProductId = self::FLIPKART_PRODUCT_ID, float $targetMrp = self::FLIPKART_MRP): ?int {
        $ts = round(microtime(true) * 1000);
        $url = self::API_BASE_URL . "/v1/cart/mycart?_=" . $ts;

        $headers = [
            "token: " . $this->token,
            "User-Agent: " . self::USER_AGENT,
            "device: mobile",
            "platform: android",
            "Origin: https://www.gyftr.com",
            "Referer: https://www.gyftr.com/"
        ];

        $ch = curl_init($url);
        $curlOpts = [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25
        ];
        self::applyProxy($curlOpts);
        curl_setopt_array($ch, $curlOpts);
        $res = curl_exec($ch);
        @curl_close($ch);

        $json = json_decode($res, true);
        if (!is_array($json) || empty($json['data'])) {
            return null;
        }

        $cartData = GyftrCrypto::tryDecryptApiData($json['data']);
        $cartItems = $cartData['cart_items'] ?? $cartData;
        if (!is_array($cartItems)) return null;

        $existingTargetCartId = null;
        foreach ($cartItems as $item) {
            $cid = $item['cart_id'] ?? $item['cart_item_id'] ?? $item['id'] ?? null;
            $pid = intval($item['product_id'] ?? 0);
            $mrp = floatval($item['mrp'] ?? 0);

            if ($pid === $targetProductId && abs($mrp - $targetMrp) < 0.01 && $existingTargetCartId === null) {
                $existingTargetCartId = intval($cid);
            } else if ($cid) {
                // Remove conflicting item
                $this->encPost(self::API_BASE_URL . "/v1/cart/remove", ["cart_item_id" => intval($cid)]);
            }
        }
        return $existingTargetCartId;
    }

    /**
     * Cart: Add Flipkart ₹50 Gift Card.
     */
    public function addFlipkartVoucherToCart(): int {
        if (!$this->token) throw new Exception("Must be logged in.");

        $existing = $this->clearConflictingCart(self::FLIPKART_PRODUCT_ID, self::FLIPKART_MRP);
        if ($existing) return $existing;

        $url = self::API_BASE_URL . "/v1/cart/add";
        $payload = [
            "brand_name" => "Flipkart",
            "brand_id" => self::FLIPKART_BRAND_ID,
            "product_name" => "Flipkart Gift Card",
            "product_id" => self::FLIPKART_PRODUCT_ID,
            "cm_product_id" => self::FLIPKART_CM_PRODUCT_ID,
            "mrp" => self::FLIPKART_MRP,
            "stock_left" => 0,
            "is_ott_product" => "N",
            "discount" => 0,
            "discount_type" => null,
            "offer_type" => null,
            "coupon_code" => null,
            "free_product_name" => null,
            "free_brand_name" => null,
            "is_free_promo" => null,
            "earn_reward" => 0,
            "service_type" => 3,
            "max_value" => 10000,
            "isEditPrice" => true,
            "quantity" => 1,
            "isQntyNotUpdate" => false,
            "customPrice" => 0,
            "disValue" => 1.5
        ];

        $res = $this->encPost($url, $payload);
        if (isset($res['code']) && $res['code'] === 200) {
            $data = GyftrCrypto::tryDecryptApiData($res['data'] ?? []);
            $cartItemId = $data['cart_id'] ?? $data['cart_item_id'] ?? $data['id'] ?? null;
            if ($cartItemId) return intval($cartItemId);
        }
        throw new Exception("Failed to add Flipkart GV to cart: " . ($res['message'] ?? json_encode($res)));
    }

    /**
     * Cart: Add Amazon ₹100 Gift Card.
     */
    public function addAmazonVoucherToCart(): int {
        if (!$this->token) throw new Exception("Must be logged in.");

        $existing = $this->clearConflictingCart(self::AMAZON_PRODUCT_ID, self::AMAZON_MRP);
        if ($existing) return $existing;

        $url = self::API_BASE_URL . "/v1/cart/add";
        $payload = [
            "brand_name" => "Amazon Shopping Voucher",
            "brand_id" => self::AMAZON_BRAND_ID,
            "product_name" => "Amazon Shopping Voucher",
            "product_id" => self::AMAZON_PRODUCT_ID,
            "cm_product_id" => self::AMAZON_CM_PRODUCT_ID,
            "mrp" => self::AMAZON_MRP,
            "stock_left" => 0,
            "is_ott_product" => "N",
            "discount" => 0,
            "discount_type" => null,
            "offer_type" => null,
            "coupon_code" => null,
            "free_product_name" => null,
            "free_brand_name" => null,
            "is_free_promo" => null,
            "earn_reward" => 0,
            "service_type" => 3,
            "max_value" => 10000,
            "isEditPrice" => false,
            "quantity" => 1,
            "isQntyNotUpdate" => false,
            "customPrice" => 0,
            "disValue" => 0
        ];

        $res = $this->encPost($url, $payload);
        if (isset($res['code']) && $res['code'] === 200) {
            $data = GyftrCrypto::tryDecryptApiData($res['data'] ?? []);
            $cartItemId = $data['cart_id'] ?? $data['cart_item_id'] ?? $data['id'] ?? null;
            if ($cartItemId) return intval($cartItemId);
        }
        throw new Exception("Failed to add Amazon GV to cart: " . ($res['message'] ?? json_encode($res)));
    }

    /**
     * Delivery: Set recipient email for gifting.
     */
    public function setCartDelivery(string $recipientEmail, ?string $recipientName = null, ?string $recipientMobile = null, int $templateId = 33): bool {
        if (!$this->token) throw new Exception("Must be logged in.");

        $url = self::API_BASE_URL . "/v1/cart/delivery";
        $payload = [
            "delivery_name" => $recipientName ?: "Friend",
            "delivery_mobile" => $recipientMobile ?: $this->mobile,
            "delivery_email" => $recipientEmail,
            "template_id" => $templateId
        ];
        $res = $this->encPost($url, $payload);
        return isset($res['code']) && $res['code'] === 200;
    }

    /**
     * Checkout: Generate order checkout session & payment form parameters.
     */
    public function checkout(int $cartItemId, bool $isGift = false, array $fpTokens = []): array {
        if (!$this->token) throw new Exception("Must be logged in.");

        $url = self::API_BASE_URL . "/v1/order/checkout";
        $payload = [
            "cart_items" => (string)$cartItemId,
            "pg" => "WALLET3.O",
            "whatsapp_send" => $isGift ? "N" : "Y",
            "affiliate_code" => "",
            "utmsource" => ""
        ];

        if (!empty($fpTokens['request_id']) && !empty($fpTokens['visitor_id'])) {
            $payload['request_id'] = $fpTokens['request_id'];
            $payload['visitor_id'] = $fpTokens['visitor_id'];
        }

        $res = $this->encPost($url, $payload);
        if (($res['code'] ?? 0) !== 200 && ($res['status'] ?? '') !== 'SUCCESS') {
            throw new Exception("Checkout failed: " . ($res['message'] ?? json_encode($res)));
        }

        $checkoutData = GyftrCrypto::tryDecryptApiData($res['data'] ?? []);
        if (!is_array($checkoutData) || empty($checkoutData['formData'])) {
            throw new Exception("Invalid checkout response structure: " . json_encode($checkoutData));
        }

        return $checkoutData;
    }

    /**
     * Payment: Connect to pay.gyftr.com and initiate UPI Intent / ePay deduction.
     */
    public function initiateUpiPayment(array $checkoutData): array {
        $formAction = $checkoutData['formAction'] ?? self::PAY_BASE_URL;
        $formData = $checkoutData['formData'];
        $txnAmount = floatval($formData['txnamount'] ?? 100);
        $extOrderId = $formData['porderid'] ?? '';

        $cookieFile = tempnam(sys_get_temp_dir(), 'gyftr_cookie_');

        // 1. POST formData to pay.gyftr.com
        $ch = curl_init($formAction);
        $curlOpts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($formData),
            CURLOPT_HTTPHEADER => [
                "Origin: https://www.gyftr.com",
                "Referer: https://www.gyftr.com/",
                "User-Agent: " . self::USER_AGENT,
                "Content-Type: application/x-www-form-urlencoded"
            ],
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false
        ];
        self::applyProxy($curlOpts);
        curl_setopt_array($ch, $curlOpts);
        $rawResp = curl_exec($ch);
        @curl_close($ch);

        // Read cookies
        $cookies = [];
        if (file_exists($cookieFile)) {
            $lines = file($cookieFile);
            foreach ($lines as $line) {
                if (strpos($line, '#') === 0 || trim($line) === '') continue;
                $parts = explode("\t", trim($line));
                if (count($parts) >= 7) {
                    $cookies[$parts[5]] = $parts[6];
                }
            }
        }

        $seamlessToken = $cookies['seamless_token'] ?? null;
        if (!$seamlessToken && preg_match('/seamless_token=([^;]+)/', $rawResp, $m)) {
            $seamlessToken = trim($m[1]);
        }
        if (!$seamlessToken) {
            @unlink($cookieFile);
            throw new Exception("Could not obtain seamless_token from pay.gyftr.com");
        }

        // 2. Extract seed and ePay tokens
        $seedEpayBalance = 0.0;
        $seedEpayToken = null;
        $rawSeed = $cookies['checkout_trans_seed'] ?? null;
        if ($rawSeed) {
            try {
                $decSeed = GyftrCrypto::aesDecrypt(urldecode($rawSeed), GyftrCrypto::PAY_SECRET_KEY);
                $seedJson = json_decode($decSeed, true);
                $respObj = $seedJson['response'] ?? [];
                $seedEpayToken = $respObj['ePayToken'] ?? null;
                $seedEpayBalance = floatval($respObj['ePayBalance'] ?? 0.0);
            } catch (Exception $e) {}
        }

        // 3. Calculate ePay deduction
        $availableEpay = max($this->epayBalance, $seedEpayBalance);
        $epayToUse = min($availableEpay, $txnAmount);
        $pgAmount = max(0.0, $txnAmount - $epayToUse);

        // Function to execute payment request
        $executePayment = function(float $useEpay, float $usePg) use ($cookieFile, $seamlessToken, $extOrderId, $txnAmount, $seedEpayToken) {
            // Update usedEPayData cookie
            $cookieStr = "seamless_token=$seamlessToken; pg_version=pg-2; login_required=false";
            if ($seedEpayToken) {
                $cookieStr .= "; seamless__pgcontainer_epay_token=$seedEpayToken";
            }
            if ($useEpay > 0) {
                $usedPayload = [
                    "usedEPayBalance" => ($useEpay == floor($useEpay)) ? (int)$useEpay : $useEpay,
                    "externalOrderId" => $extOrderId
                ];
                $encUsed = GyftrCrypto::aesEncrypt(json_encode($usedPayload, JSON_UNESCAPED_SLASHES), GyftrCrypto::PAY_SECRET_KEY);
                $cookieStr .= "; usedEPayData=$encUsed";
            }

            if ($useEpay >= $txnAmount && $usePg <= 0) {
                $payload = [
                    "amount" => $txnAmount,
                    "totalAmount" => $txnAmount,
                    "epayAmount" => $useEpay,
                    "pgAmount" => 0,
                    "paymentType" => "epay",
                    "browserInfo" => ["browser" => "Google Chrome", "version" => "156"]
                ];
            } else {
                $payload = [
                    "paymentType" => "QR",
                    "deviceType" => "Android",
                    "userAgent" => self::USER_AGENT,
                    "upiAppName" => "genericintent",
                    "amount" => $txnAmount,
                    "totalAmount" => $txnAmount,
                    "epayAmount" => $useEpay,
                    "pgAmount" => $usePg,
                    "browserInfo" => ["browser" => "Google Chrome", "version" => "156"]
                ];
            }

            $encRes = GyftrCrypto::hybridEncrypt($payload);
            $reqBody = json_encode([
                "apiName" => "paymentProcessApi",
                "data" => $encRes['encryptedPayload'],
                "encryptedKey" => $encRes['encryptedKey']
            ], JSON_UNESCAPED_SLASHES);

            $ch = curl_init(self::PAY_BASE_URL . "/api-proxy");
            $curlOpts = [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $reqBody,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer $seamlessToken",
                    "Content-Type: application/json",
                    "Origin: " . self::PAY_BASE_URL,
                    "Referer: " . self::PAY_BASE_URL . "/",
                    "User-Agent: " . self::USER_AGENT,
                    "Cookie: $cookieStr"
                ],
                CURLOPT_COOKIEFILE => $cookieFile,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 25
            ];
            self::applyProxy($curlOpts);
            curl_setopt_array($ch, $curlOpts);
            $res = curl_exec($ch);
            @curl_close($ch);

            $rj = json_decode($res, true);
            return [is_array($rj) ? $rj : ["status" => "failed", "message" => $res], $cookieStr];
        };

        list($respJson, $cookieStr) = $executePayment($epayToUse, $pgAmount);

        // Check if full payment completed via ePay
        if (($respJson['status'] ?? '') === 'redirection') {
            $fData = $respJson['formData'] ?? [];
            $inputD = $fData['inputData'] ?? '';
            if (strpos($inputD, 'TXN_SUCCESS') !== false || strpos($inputD, 'status_code";s:2:"00"') !== false) {
                $redirectUrl = $respJson['formAction'] ?? (self::API_BASE_URL . "/v1/order/pgsuccess");
                $ch = curl_init($redirectUrl);
                $curlOpts = [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query($fData),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15
                ];
                self::applyProxy($curlOpts);
                curl_setopt_array($ch, $curlOpts);
                curl_exec($ch);
                @curl_close($ch);
                @unlink($cookieFile);

                return [
                    "intent_uri" => null,
                    "ext_order_id" => $extOrderId,
                    "pg_identifier" => "ePay",
                    "pg_amount" => 0.0,
                    "epay_used" => $epayToUse,
                    "is_completed" => true,
                    "seamless_token" => $seamlessToken,
                    "cookie_str" => $cookieStr
                ];
            }
        }

        // Fallback: if ePay was rejected, retry full amount via UPI
        if (($respJson['status'] ?? '') !== 'success' && $epayToUse > 0) {
            $epayToUse = 0.0;
            $pgAmount = $txnAmount;
            list($respJson, $cookieStr) = $executePayment(0.0, $txnAmount);
        }

        if (($respJson['status'] ?? '') !== 'success' || empty($respJson['data'])) {
            @unlink($cookieFile);
            throw new Exception("paymentProcessApi failed: " . ($respJson['message'] ?? json_encode($respJson)));
        }

        // Decrypt response
        $decryptedText = GyftrCrypto::aesDecrypt($respJson['data'], GyftrCrypto::PAY_SECRET_KEY);
        $decData = json_decode($decryptedText, true);
        $respObj = $decData['response'] ?? [];

        @unlink($cookieFile);

        return [
            "intent_uri" => $respObj['intentURIData'] ?? null,
            "ext_order_id" => $respObj['externalOrderId'] ?? $extOrderId,
            "pg_identifier" => $respObj['pg_identifier'] ?? 'PayU',
            "pg_amount" => $pgAmount,
            "epay_used" => $epayToUse,
            "is_completed" => false,
            "seamless_token" => $seamlessToken,
            "cookie_str" => $cookieStr
        ];
    }

    /**
     * Verify payment status via verifyPaymentIntentApi.
     * Returns: "SUCCESS", "FAILED", or "PENDING".
     */
    public function checkPaymentStatus(array $paymentInfo): string {
        if (!empty($paymentInfo['is_completed'])) {
            return "SUCCESS";
        }
        $seamlessToken = $paymentInfo['seamless_token'] ?? null;
        $cookieStr = $paymentInfo['cookie_str'] ?? '';
        if (!$seamlessToken) return "UNKNOWN";

        try {
            $vEnc = GyftrCrypto::hybridEncrypt([]);
            $reqBody = json_encode([
                "apiName" => "verifyPaymentIntentApi",
                "data" => $vEnc['encryptedPayload'],
                "encryptedKey" => $vEnc['encryptedKey']
            ], JSON_UNESCAPED_SLASHES);

            $ch = curl_init(self::PAY_BASE_URL . "/api-proxy");
            $curlOpts = [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $reqBody,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer $seamlessToken",
                    "Content-Type: application/json",
                    "Origin: " . self::PAY_BASE_URL,
                    "Referer: " . self::PAY_BASE_URL . "/",
                    "User-Agent: " . self::USER_AGENT,
                    "Cookie: $cookieStr"
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15
            ];
            self::applyProxy($curlOpts);
            curl_setopt_array($ch, $curlOpts);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            @curl_close($ch);

            if ($code === 200) {
                $vJson = json_decode($res, true);
                if (($vJson['status'] ?? '') === 'success' && !empty($vJson['data'])) {
                    $vDec = GyftrCrypto::aesDecrypt($vJson['data'], GyftrCrypto::PAY_SECRET_KEY);
                    $vObj = json_decode($vDec, true);
                    $status = strtoupper($vObj['response']['status'] ?? '');
                    if ($status === 'SUCCESS') return 'SUCCESS';
                    if ($status === 'FAILED') return 'FAILED';
                }
            }
        } catch (Exception $e) {}

        return "PENDING";
    }
}
