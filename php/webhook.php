<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║              TELEGRAM WEBHOOK RECEIVER IN PHP                    ║
 * ║   Host on Apache / Nginx / cPanel                                ║
 * ║   Setup: Visit https://your-domain.com/webhook.php?set=1         ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */

require_once __DIR__ . '/bot.php';

// 1. One-Click Webhook Registration
if (isset($_GET['set'])) {
    header('Content-Type: application/json');
    $currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http")
        . "://" . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');

    $res = TelegramBot::request('setWebhook', ['url' => $currentUrl]);
    echo json_encode([
        'notice' => 'Webhook registration request sent to Telegram',
        'registered_url' => $currentUrl,
        'telegram_response' => $res
    ], JSON_PRETTY_PRINT);
    exit;
}

// 2. One-Click Webhook Deletion (Switch back to polling)
if (isset($_GET['delete'])) {
    header('Content-Type: application/json');
    $res = TelegramBot::request('deleteWebhook');
    echo json_encode(['notice' => 'Webhook deleted', 'telegram_response' => $res], JSON_PRETTY_PRINT);
    exit;
}

// 3. Receive Incoming Telegram Webhook Update
$input = file_get_contents('php://input');
if ($input) {
    $update = json_decode($input, true);
    if (is_array($update)) {
        try {
            processUpdate($update);
        } catch (Exception $e) {
            error_log("Webhook process error: " . $e->getMessage());
        }
    }
}

echo "OK";
