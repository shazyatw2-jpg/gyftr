<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║               GYFTR TELEGRAM BOT IN PHP                          ║
 * ║   Token: 9140144926:AAF4lZCd-yvbmD8tmexoxMz_Y7BTXqiRgJc         ║
 * ║                                                                  ║
 * ║   Modes:                                                         ║
 * ║   1. CLI Long Polling : php bot.php                              ║
 * ║   2. Webhook Mode     : Include in webhook.php                   ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */

require_once __DIR__ . '/GyftrClient.php';

define('BOT_TOKEN', getenv('BOT_TOKEN') ?: '9140144926:AAF4lZCd-yvbmD8tmexoxMz_Y7BTXqiRgJc');
define('TG_API', 'https://api.telegram.org/bot' . BOT_TOKEN);
define('SESSIONS_DIR', __DIR__ . '/sessions');

if (!is_dir(SESSIONS_DIR)) {
    @mkdir(SESSIONS_DIR, 0777, true);
}

// ─── Telegram API Wrapper ─────────────────────────────────────────────────────

class TelegramBot {
    public static function request(string $method, array $params = []): array {
        $url = TG_API . '/' . $method;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res, true) ?: [];
    }

    public static function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): array {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ];
        if ($replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }
        return self::request('sendMessage', $params);
    }

    public static function editMessageText(int $chatId, int $messageId, string $text, ?array $replyMarkup = null): array {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ];
        if ($replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }
        return self::request('editMessageText', $params);
    }

    public static function sendPhoto(int $chatId, string $photoUrl, string $caption = '', ?array $replyMarkup = null): array {
        $params = [
            'chat_id' => $chatId,
            'photo' => $photoUrl,
            'caption' => $caption,
            'parse_mode' => 'Markdown'
        ];
        if ($replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }
        return self::request('sendPhoto', $params);
    }

    public static function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): array {
        return self::request('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $showAlert
        ]);
    }

    public static function deleteMessage(int $chatId, int $messageId): array {
        return self::request('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId
        ]);
    }
}

// ─── Per-User Session & State Management ──────────────────────────────────────

function getUserSessionFile(int $chatId): string {
    return SESSIONS_DIR . "/{$chatId}.json";
}

function getUserStateFile(int $chatId): string {
    return SESSIONS_DIR . "/{$chatId}_state.json";
}

function getUserPaymentFile(int $chatId): string {
    return SESSIONS_DIR . "/{$chatId}_payment.json";
}

function getUserFpFile(int $chatId): string {
    return SESSIONS_DIR . "/{$chatId}_fp.json";
}

function getClientForChat(int $chatId): GyftrClient {
    $sessionFile = getUserSessionFile($chatId);
    return new GyftrClient($sessionFile);
}

function getUserState(int $chatId): string {
    $f = getUserStateFile($chatId);
    if (file_exists($f)) {
        return trim(file_get_contents($f));
    }
    return 'IDLE';
}

function setUserState(int $chatId, string $state): void {
    file_put_contents(getUserStateFile($chatId), $state);
}

function clearUserSession(int $chatId): void {
    @unlink(getUserSessionFile($chatId));
    @unlink(getUserStateFile($chatId));
    @unlink(getUserPaymentFile($chatId));
    @unlink(getUserFpFile($chatId));
}

// ─── Keyboards & UI Helpers ───────────────────────────────────────────────────

function getMainMenuMarkup(bool $isLoggedIn): array {
    $buttons = [
        [
            ['text' => '🎁 Gift Flipkart ₹50', 'callback_data' => 'act_gift_fk'],
            ['text' => '🛍️ Buy Flipkart ₹50', 'callback_data' => 'act_buy_fk']
        ],
        [
            ['text' => '🛒 Buy Amazon ₹100', 'callback_data' => 'act_buy_amz'],
            ['text' => '🎮 Auto-Play Game', 'callback_data' => 'act_play_game']
        ]
    ];

    if ($isLoggedIn) {
        $buttons[] = [
            ['text' => '💰 Check Balance', 'callback_data' => 'act_check_bal']
        ];
        $buttons[] = [
            ['text' => '🔄 Switch Login', 'callback_data' => 'act_login'],
            ['text' => '🚪 Logout', 'callback_data' => 'act_logout']
        ];
    } else {
        $buttons[] = [
            ['text' => '📱 Login', 'callback_data' => 'act_login'],
            ['text' => '💰 Check Balance', 'callback_data' => 'act_check_bal']
        ];
    }

    $buttons[] = [
        ['text' => 'ℹ️ Help & Info', 'callback_data' => 'act_help']
    ];

    return ['inline_keyboard' => $buttons];
}

function buildStatusText(GyftrClient $client): string {
    if ($client->token && $client->mobile) {
        $name = $client->userData['full_name'] ?? 'Customer';
        return "⚡ *GyFTR PHP Automation Control Panel*\n"
            . "━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
            . "👤 *Account*: `{$client->mobile}` ({$name})\n"
            . "🪙 *ePay Balance*: *₹" . number_format($client->epayBalance, 2) . "*\n"
            . "🟢 *Session Status*: *Active*\n"
            . "━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
            . "Select an operation below:";
    } else {
        return "⚡ *GyFTR PHP Automation Control Panel*\n"
            . "━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
            . "⚠️ *Session Status*: *Not Logged In (Fresh Session)*\n"
            . "Please tap *Login* to enter your mobile number and start.";
    }
}

// ─── Update Processor ─────────────────────────────────────────────────────────

function processUpdate(array $update): void {
    if (isset($update['message'])) {
        handleMessage($update['message']);
    } elseif (isset($update['callback_query'])) {
        handleCallbackQuery($update['callback_query']);
    }
}

function handleMessage(array $message): void {
    $chatId = $message['chat']['id'] ?? 0;
    if (!$chatId) return;

    $text = trim($message['text'] ?? '');
    $client = getClientForChat($chatId);
    $state = getUserState($chatId);
    $isLoggedIn = !empty($client->token && $client->mobile);

    // 1. Slash Commands
    if (strpos($text, '/') === 0) {
        $parts = explode(' ', $text);
        $cmd = strtolower($parts[0]);

        if ($cmd === '/start' || $cmd === '/menu') {
            setUserState($chatId, 'IDLE');
            TelegramBot::sendMessage($chatId, buildStatusText($client), getMainMenuMarkup($isLoggedIn));
            return;
        }

        if ($cmd === '/cancel') {
            setUserState($chatId, 'IDLE');
            TelegramBot::sendMessage($chatId, "❌ Action cancelled. Returning to main menu.", getMainMenuMarkup($isLoggedIn));
            return;
        }

        if ($cmd === '/logout') {
            clearUserSession($chatId);
            TelegramBot::sendMessage($chatId, "🚪 *Logged Out Successfully!*\nSession has been removed.", getMainMenuMarkup(false));
            return;
        }

        if ($cmd === '/login') {
            clearUserSession($chatId);
            setUserState($chatId, 'AWAITING_MOBILE');
            TelegramBot::sendMessage(
                $chatId,
                "📱 *GyFTR Fresh Mobile Login*\n\nPlease enter your *10-digit registered mobile number*:\n_(Type /cancel to abort)_"
            );
            return;
        }

        if ($cmd === '/balance' || $cmd === '/bal') {
            doCheckBalance($chatId, $client);
            return;
        }

        if ($cmd === '/game') {
            doPlayGame($chatId, $client);
            return;
        }

        if ($cmd === '/giftfk') {
            if (!$isLoggedIn) {
                TelegramBot::sendMessage($chatId, "⚠️ Please login first using /login.");
                return;
            }
            $email = trim($parts[1] ?? '');
            if ($email) {
                doFlipkartPurchase($chatId, $client, $email);
            } else {
                setUserState($chatId, 'AWAITING_GIFT_EMAIL');
                TelegramBot::sendMessage(
                    $chatId,
                    "🎁 *Gift Flipkart ₹50 Gift Voucher*\n\nPlease enter the recipient's *Email address*:\n_(Type /cancel to abort)_"
                );
            }
            return;
        }

        if ($cmd === '/buyfk') {
            doFlipkartPurchase($chatId, $client, null);
            return;
        }

        if ($cmd === '/buyamazon' || $cmd === '/buyamz') {
            doAmazonPurchase($chatId, $client);
            return;
        }

        if ($cmd === '/help') {
            $helpText = "📖 *GyFTR PHP Bot Commands*\n"
                . "━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
                . "• `/start` or `/menu` - Open interactive menu\n"
                . "• `/login` - Fresh login with mobile OTP\n"
                . "• `/logout` - Purge current session\n"
                . "• `/balance` - Check live ePay points balance\n"
                . "• `/game` - Auto-play Diwali game (+150 Score)\n"
                . "• `/giftfk <email>` - Gift Flipkart ₹50 GV to email\n"
                . "• `/buyfk` - Buy Flipkart ₹50 GV for self\n"
                . "• `/buyamazon` - Buy Amazon ₹100 GV\n"
                . "• `/cancel` - Cancel active prompt\n"
                . "━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
                . "🔒 *Security*: Fresh sessions per user. Automatically cleared after every GV buy!";
            TelegramBot::sendMessage($chatId, $helpText);
            return;
        }
    }

    // 2. State: Awaiting Mobile
    if ($state === 'AWAITING_MOBILE') {
        $cleanMobile = preg_replace('/\D/', '', $text);
        if (strlen($cleanMobile) === 12 && substr($cleanMobile, 0, 2) === '91') {
            $cleanMobile = substr($cleanMobile, 2);
        }
        if (strlen($cleanMobile) !== 10) {
            TelegramBot::sendMessage($chatId, "⚠️ Invalid mobile number. Please send a 10-digit Indian mobile number:");
            return;
        }

        // Check for saved session first!
        if ($client->tryAutoLogin($cleanMobile)) {
            setUserState($chatId, 'IDLE');
            $name = $client->userData['full_name'] ?? 'Customer';
            TelegramBot::sendMessage(
                $chatId,
                "🎉 *Welcome Back, {$name}!*\n"
                . "Automatically restored saved session for `{$client->mobile}`!\n\n"
                . "🪙 *ePay Balance*: *₹" . number_format($client->epayBalance, 2) . "*\n"
                . "🟢 *Status*: Ready for orders",
                getMainMenuMarkup(true)
            );
            return;
        }

        $wait = TelegramBot::sendMessage($chatId, "⏳ Sending OTP to *{$cleanMobile}* ...");
        $waitId = $wait['result']['message_id'] ?? 0;

        try {
            $ok = $client->sendOtp($cleanMobile);
            if ($ok) {
                setUserState($chatId, 'AWAITING_OTP:' . $cleanMobile);
                TelegramBot::editMessageText(
                    $chatId,
                    $waitId,
                    "✅ *OTP Sent!* Sent to *{$cleanMobile}*.\n\nPlease enter the *6-digit OTP* received:\n_(Type /cancel to abort)_"
                );
            } else {
                TelegramBot::editMessageText($chatId, $waitId, "❌ Failed to send OTP. Please try again with /login.");
                setUserState($chatId, 'IDLE');
            }
        } catch (Exception $e) {
            TelegramBot::editMessageText($chatId, $waitId, "❌ Error sending OTP: " . $e->getMessage());
            setUserState($chatId, 'IDLE');
        }
        return;
    }

    // 3. State: Awaiting OTP
    if (strpos($state, 'AWAITING_OTP') === 0) {
        $cleanOtp = preg_replace('/\D/', '', $text);
        if (strlen($cleanOtp) !== 6) {
            TelegramBot::sendMessage($chatId, "⚠️ Please enter a 6-digit numeric OTP:");
            return;
        }

        $parts = explode(':', $state);
        $pendingMobile = $parts[1] ?? $client->mobile;

        $wait = TelegramBot::sendMessage($chatId, "⏳ Verifying OTP ...");
        $waitId = $wait['result']['message_id'] ?? 0;

        try {
            $ok = $client->verifyOtp($cleanOtp, $pendingMobile);
            if ($ok) {
                setUserState($chatId, 'IDLE');
                $name = $client->userData['full_name'] ?? 'Customer';
                TelegramBot::editMessageText(
                    $chatId,
                    $waitId,
                    "🎉 *Login Successful!*\n\nWelcome, *{$name}*!\n📱 Mobile: `{$client->mobile}`\n🪙 ePay Balance: *₹" . number_format($client->epayBalance, 2) . "*",
                    getMainMenuMarkup(true)
                );
            } else {
                TelegramBot::editMessageText($chatId, $waitId, "❌ Verification failed. Invalid OTP. Try again with /login.");
                clearUserSession($chatId);
            }
        } catch (Exception $e) {
            TelegramBot::editMessageText($chatId, $waitId, "❌ Verification error: " . $e->getMessage());
            clearUserSession($chatId);
        }
        return;
    }

    // 4. State: Awaiting Gift Email
    if ($state === 'AWAITING_GIFT_EMAIL') {
        if (!filter_var($text, FILTER_VALIDATE_EMAIL)) {
            TelegramBot::sendMessage($chatId, "⚠️ Invalid email format. Please provide a valid email (e.g. `recipient@example.com`):");
            return;
        }
        setUserState($chatId, 'IDLE');
        doFlipkartPurchase($chatId, $client, $text);
        return;
    }

    // Default Fallback
    TelegramBot::sendMessage($chatId, buildStatusText($client), getMainMenuMarkup($isLoggedIn));
}

function handleCallbackQuery(array $call): void {
    $callId = $call['id'] ?? '';
    $chatId = $call['message']['chat']['id'] ?? 0;
    $data = $call['data'] ?? '';
    $client = getClientForChat($chatId);

    TelegramBot::answerCallbackQuery($callId);

    if ($data === 'act_login') {
        clearUserSession($chatId);
        setUserState($chatId, 'AWAITING_MOBILE');
        TelegramBot::sendMessage($chatId, "📱 *GyFTR Fresh Mobile Login*\n\nPlease enter your *10-digit mobile number*:");
    } elseif ($data === 'act_logout') {
        clearUserSession($chatId);
        TelegramBot::sendMessage($chatId, "🚪 *Logged Out Successfully!*\nSession cleared.", getMainMenuMarkup(false));
    } elseif ($data === 'act_check_bal') {
        doCheckBalance($chatId, $client);
    } elseif ($data === 'act_play_game') {
        doPlayGame($chatId, $client);
    } elseif ($data === 'act_gift_fk') {
        if (!$client->token) {
            TelegramBot::sendMessage($chatId, "⚠️ Please login first using /login.");
            return;
        }
        setUserState($chatId, 'AWAITING_GIFT_EMAIL');
        TelegramBot::sendMessage($chatId, "🎁 *Gift Flipkart ₹50 GV*\n\nPlease enter recipient's *Email address*:");
    } elseif ($data === 'act_buy_fk') {
        doFlipkartPurchase($chatId, $client, null);
    } elseif ($data === 'act_buy_amz') {
        doAmazonPurchase($chatId, $client);
    } elseif ($data === 'act_help') {
        handleMessage(['chat' => ['id' => $chatId], 'text' => '/help']);
    } elseif (strpos($data, 'chk_status_') === 0) {
        $extOrderId = substr($data, strlen('chk_status_'));
        doCheckManualPayment($chatId, $client, $extOrderId, $callId);
    }
}

// ─── Core Logic Functions ─────────────────────────────────────────────────────

function doCheckBalance(int $chatId, GyftrClient $client): void {
    if (!$client->token) {
        TelegramBot::sendMessage($chatId, "⚠️ Not logged in. Please use /login first.");
        return;
    }
    $wait = TelegramBot::sendMessage($chatId, "⏳ Fetching live account balance ...");
    $waitId = $wait['result']['message_id'] ?? 0;

    if ($client->refreshProfile()) {
        $name = $client->userData['full_name'] ?? 'Customer';
        TelegramBot::editMessageText(
            $chatId,
            $waitId,
            "💰 *GyFTR Account Balance*\n━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
            . "👤 *Name*: {$name}\n📱 *Mobile*: `{$client->mobile}`\n"
            . "🪙 *ePay Balance*: *₹" . number_format($client->epayBalance, 2) . "*\n"
            . "━━━━━━━━━━━━━━━━━━━━━━━━━━",
            getMainMenuMarkup(true)
        );
    } else {
        TelegramBot::editMessageText($chatId, $waitId, "❌ Failed to fetch balance. Token might be expired.");
    }
}

function doPlayGame(int $chatId, GyftrClient $client): void {
    if (!$client->token) {
        TelegramBot::sendMessage($chatId, "⚠️ Please login first using /login.");
        return;
    }
    $wait = TelegramBot::sendMessage($chatId, "🎮 *Diwali Game Auto-Bot*\nStarting game and claiming +150 score ...");
    $waitId = $wait['result']['message_id'] ?? 0;

    try {
        $res = $client->playGame(0);
        $msg = $res['message'] ?? "Diwali Game finished!";
        $isPlayed = !empty($res['already_played']);
        TelegramBot::editMessageText(
            $chatId,
            $waitId,
            ($isPlayed ? "ℹ️ *Diwali Game Status*\n" : "🎉 *Diwali Game Completed!*\n")
            . "━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
            . "🎯 *Status*: " . $msg . "\n"
            . "🪙 *ePay Balance*: *₹" . number_format($client->epayBalance, 2) . "*\n"
            . "━━━━━━━━━━━━━━━━━━━━━━━━━━",
            getMainMenuMarkup(true)
        );
    } catch (Exception $e) {
        TelegramBot::editMessageText(
            $chatId,
            $waitId,
            "ℹ️ *Game Notice*\n━━━━━━━━━━━━━━━━━━━━━━━━━━\n" . $e->getMessage() . "\n\n🪙 *Balance*: *₹" . number_format($client->epayBalance, 2) . "*",
            getMainMenuMarkup(true)
        );
    }
}

function doFlipkartPurchase(int $chatId, GyftrClient $client, ?string $giftEmail): void {
    if (!$client->token) {
        TelegramBot::sendMessage($chatId, "⚠️ Please login first using /login.");
        return;
    }
    $isGift = (bool)$giftEmail;
    $target = $isGift ? "recipient `{$giftEmail}`" : "your mobile `{$client->mobile}`";

    $wait = TelegramBot::sendMessage($chatId, "🛍️ *Initiating Flipkart ₹50 Order ...*\nDelivering to: {$target}\n⏳ Adding to cart ...");
    $waitId = $wait['result']['message_id'] ?? 0;

    try {
        // 1. Add Flipkart GV
        $cartItemId = $client->addFlipkartVoucherToCart();

        // 2. Set Delivery if gifting
        if ($isGift) {
            TelegramBot::editMessageText($chatId, $waitId, "🛍️ Setting delivery recipient email `{$giftEmail}` ...");
            $client->setCartDelivery($giftEmail);
        }

        // 3. Load device verification tokens if captured
        $fpTokens = [];
        $fpFile = getUserFpFile($chatId);
        if (file_exists($fpFile)) {
            $fpTokens = json_decode(file_get_contents($fpFile), true) ?: [];
        }

        TelegramBot::editMessageText($chatId, $waitId, "🛍️ Creating secure checkout order ...");
        $checkoutData = $client->checkout($cartItemId, $isGift, $fpTokens);

        TelegramBot::editMessageText($chatId, $waitId, "⚡ Processing payment options & ePay balance ...");
        $paymentInfo = $client->initiateUpiPayment($checkoutData);

        handlePaymentPresentation($chatId, $client, $paymentInfo, "Flipkart Gift Card ₹50", $giftEmail ?: $client->mobile, $waitId);

    } catch (Exception $e) {
        TelegramBot::editMessageText(
            $chatId,
            $waitId,
            "❌ *Flipkart Order Failed*\n━━━━━━━━━━━━━━━━━━━━━━━━━━\n`" . $e->getMessage() . "`",
            getMainMenuMarkup(true)
        );
    }
}

function doAmazonPurchase(int $chatId, GyftrClient $client): void {
    if (!$client->token) {
        TelegramBot::sendMessage($chatId, "⚠️ Please login first using /login.");
        return;
    }
    $wait = TelegramBot::sendMessage($chatId, "🛒 *Initiating Amazon Pay ₹100 Order ...*\nDelivering to: `{$client->mobile}`\n⏳ Adding to cart ...");
    $waitId = $wait['result']['message_id'] ?? 0;

    try {
        $cartItemId = $client->addAmazonVoucherToCart();

        $fpTokens = [];
        $fpFile = getUserFpFile($chatId);
        if (file_exists($fpFile)) {
            $fpTokens = json_decode(file_get_contents($fpFile), true) ?: [];
        }

        TelegramBot::editMessageText($chatId, $waitId, "🛒 Creating order ...");
        $checkoutData = $client->checkout($cartItemId, false, $fpTokens);

        TelegramBot::editMessageText($chatId, $waitId, "⚡ Processing payment ...");
        $paymentInfo = $client->initiateUpiPayment($checkoutData);

        handlePaymentPresentation($chatId, $client, $paymentInfo, "Amazon Pay Gift Card ₹100", $client->mobile, $waitId);

    } catch (Exception $e) {
        TelegramBot::editMessageText(
            $chatId,
            $waitId,
            "❌ *Amazon Order Failed*\n━━━━━━━━━━━━━━━━━━━━━━━━━━\n`" . $e->getMessage() . "`",
            getMainMenuMarkup(true)
        );
    }
}

function handlePaymentPresentation(
    int $chatId,
    GyftrClient $client,
    array $paymentInfo,
    string $productName,
    string $recipient,
    int $waitMsgId
): void {
    $extOrderId = $paymentInfo['ext_order_id'] ?? 'N/A';
    $pgAmount = floatval($paymentInfo['pg_amount'] ?? 0.0);
    $epayUsed = floatval($paymentInfo['epay_used'] ?? 0.0);
    $intentUri = $paymentInfo['intent_uri'] ?? null;
    $isCompleted = !empty($paymentInfo['is_completed']);

    // Case 1: Fully Paid by ePay
    if ($isCompleted || (!$intentUri && $pgAmount <= 0)) {
        clearUserSession($chatId);
        TelegramBot::editMessageText(
            $chatId,
            $waitMsgId,
            "🎉 *ORDER COMPLETED SUCCESSFULLY!*\n━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
            . "🛍️ *Product*: {$productName}\n📬 *Delivered To*: `{$recipient}`\n"
            . "🪙 *Paid with ePay*: ₹" . number_format($epayUsed, 2) . "\n⚡ *UPI Paid*: ₹0.00\n"
            . "🆔 *Order ID*: `{$extOrderId}`\n\n✅ Voucher details dispatched!\n"
            . "🔒 *Session Removed*: Account logged out for next fresh purchase.",
            getMainMenuMarkup(false)
        );
        return;
    }

    if (!$intentUri) {
        TelegramBot::editMessageText($chatId, $waitMsgId, "⚠️ *Payment Error*\nNo UPI Intent URL received from gateway.", getMainMenuMarkup(true));
        return;
    }

    // Save active payment state for manual checking
    file_put_contents(getUserPaymentFile($chatId), json_encode([
        'payment_info' => $paymentInfo,
        'product_name' => $productName,
        'recipient' => $recipient,
        'start_time' => time()
    ]));

    TelegramBot::deleteMessage($chatId, $waitMsgId);

    $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($intentUri);

    $markup = [
        'inline_keyboard' => [
            [['text' => '📲 Pay via UPI App', 'url' => $intentUri]],
            [['text' => '🔄 Check Payment Status', 'callback_data' => 'chk_status_' . $extOrderId]],
            [['text' => '❌ Return to Menu', 'callback_data' => 'act_help']]
        ]
    ];

    $caption = "💳 *UPI Payment Required*\n━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
        . "🛍️ *Item*: {$productName}\n📬 *Recipient*: `{$recipient}`\n"
        . "🪙 *ePay Discount*: ₹" . number_format($epayUsed, 2) . "\n"
        . "⚡ *Amount to Pay*: *₹" . number_format($pgAmount, 2) . "*\n"
        . "🆔 *Order Ref*: `{$extOrderId}`\n━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
        . "📲 *Direct UPI Link*:\n`{$intentUri}`\n\n"
        . "👇 Tap button to pay or scan QR code with GPay/PhonePe/Paytm:";

    TelegramBot::sendPhoto($chatId, $qrUrl, $caption, $markup);
}

function doCheckManualPayment(int $chatId, GyftrClient $client, string $extOrderId, string $callId): void {
    $payFile = getUserPaymentFile($chatId);
    if (!file_exists($payFile)) {
        TelegramBot::answerCallbackQuery($callId, "No pending payment found for this order.", true);
        return;
    }

    $payData = json_decode(file_get_contents($payFile), true) ?: [];
    $paymentInfo = $payData['payment_info'] ?? [];
    $status = $client->checkPaymentStatus($paymentInfo);

    if ($status === 'SUCCESS') {
        clearUserSession($chatId);
        TelegramBot::answerCallbackQuery($callId, "🎉 Payment Successful!", true);
        TelegramBot::sendMessage(
            $chatId,
            "🎉 *Payment Verified!*\n━━━━━━━━━━━━━━━━━━━━━━━━━━\nOrder `{$extOrderId}` has been completed!\n"
            . "Delivered to: `{$payData['recipient']}`\n"
            . "🔒 *Session Removed*: Account logged out for next fresh purchase.",
            getMainMenuMarkup(false)
        );
    } elseif ($status === 'FAILED') {
        @unlink($payFile);
        TelegramBot::answerCallbackQuery($callId, "❌ Payment Failed or Expired", true);
    } else {
        TelegramBot::answerCallbackQuery($callId, "⏳ Payment is still pending. Please complete in UPI app.", false);
    }
}

// ─── CLI Long Polling Runner ─────────────────────────────────────────────────

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    echo "\n════════════════════════════════════════════════════════════\n";
    echo "  🤖 GyFTR PHP Telegram Bot Started\n";
    echo "  📱 Token: " . substr(BOT_TOKEN, 0, 15) . "...\n";
    echo "  ⚡ Running Long-Polling Loop ...\n";
    echo "════════════════════════════════════════════════════════════\n\n";

    $lastUpdateId = 0;
    while (true) {
        $updates = TelegramBot::request('getUpdates', [
            'offset' => $lastUpdateId + 1,
            'timeout' => 20
        ]);

        if (!empty($updates['ok']) && !empty($updates['result'])) {
            foreach ($updates['result'] as $u) {
                $lastUpdateId = $u['update_id'];
                try {
                    processUpdate($u);
                } catch (Exception $e) {
                    echo "Update error: " . $e->getMessage() . "\n";
                }
            }
        }
        usleep(500000); // 0.5s pause
    }
}
