<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║              GYFTR BROWSER DEVICE VERIFICATION                   ║
 * ║   Executes FingerprintJS Pro on user browser and saves tokens    ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */

require_once __DIR__ . '/GyftrClient.php';

$sessionsDir = __DIR__ . '/sessions';
if (!is_dir($sessionsDir)) {
    @mkdir($sessionsDir, 0777, true);
}

// 1. Handle API Callback from JS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $chatId = preg_replace('/\D/', '', $input['chat_id'] ?? '');
    $visitorId = trim($input['visitor_id'] ?? '');
    $requestId = trim($input['request_id'] ?? '');

    if ($chatId && $visitorId && $requestId) {
        $fpFile = $sessionsDir . "/{$chatId}_fp.json";
        $data = [
            'visitor_id' => $visitorId,
            'request_id' => $requestId,
            'timestamp' => time()
        ];
        file_put_contents($fpFile, json_encode($data));
        echo json_encode(['status' => 'success', 'message' => 'Device verified!']);
        exit;
    }
    echo json_encode(['status' => 'error', 'message' => 'Missing required tokens']);
    exit;
}

// 2. Render Verification Webpage
$chatId = htmlspecialchars($_GET['chat_id'] ?? 'default');
$mobile = htmlspecialchars($_GET['mobile'] ?? '');
$encMobile = $mobile ? GyftrCrypto::aesEncrypt($mobile) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>GyFTR Device Verification</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; text-align: center; padding: 40px 20px; background: #0f172a; color: #f8fafc; }
    .card { max-width: 400px; margin: 40px auto; background: #1e293b; padding: 32px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); border: 1px solid #334155; }
    .spinner { border: 4px solid #334155; border-top: 4px solid #38bdf8; border-radius: 50%; width: 48px; height: 48px; animation: spin 0.8s linear infinite; margin: 24px auto; }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    .success { color: #4ade80; font-size: 24px; font-weight: bold; margin: 15px 0; }
    p { color: #94a3b8; font-size: 15px; line-height: 1.5; }
    .btn { display: inline-block; margin-top: 20px; padding: 12px 24px; background: #2563eb; color: #fff; text-decoration: none; border-radius: 12px; font-weight: bold; }
  </style>
</head>
<body>
  <div class="card">
    <h3 style="margin-top:0; color:#e2e8f0;">🔒 GyFTR Device Verification</h3>
    <div id="status" class="spinner"></div>
    <p id="msg">Verifying browser fingerprint with security gateway...</p>
    <div id="return-btn" style="display:none;">
      <a class="btn" href="tg://resolve?domain=Shazyfk_bot">Return to Telegram Bot</a>
    </div>
  </div>

  <script type="module">
    try {
      const { start } = await import("https://metrics.gyftr.com/web/v4/Bwihr8E7Qva6zV1aeuAt?ci=jsl/4.1.6");
      const client = start({
        apiKey: "Bwihr8E7Qva6zV1aeuAt",
        region: "ap",
        endpoints: ["https://metrics.gyftr.com"]
      });
      const resp = await client.get({
        linkedId: "<?= $encMobile ?>",
        tag: { page: "checkout" }
      });
      const vid = resp.visitor_id || resp.visitorId || "";
      const rid = resp.event_id || resp.requestId || "";

      if (vid && rid) {
        await fetch("verify.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            chat_id: "<?= $chatId ?>",
            visitor_id: vid,
            request_id: rid
          })
        });

        document.getElementById("status").className = "";
        document.getElementById("status").innerHTML = "<div class='success'>✅ Verified!</div>";
        document.getElementById("msg").innerText = "Device verification successful! You can now return to Telegram.";
        document.getElementById("return-btn").style.display = "block";
      } else {
        document.getElementById("msg").innerText = "Fingerprint check returned empty response.";
      }
    } catch (err) {
      document.getElementById("status").className = "";
      document.getElementById("msg").innerText = "Notice: " + (err.message || err);
    }
  </script>
</body>
</html>
