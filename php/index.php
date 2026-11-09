<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║              GYFTR AUTOMATION WEB DASHBOARD                      ║
 * ║   Full Web UI for Browser-Based & Server-Based GyFTR Purchases   ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */

session_start();
require_once __DIR__ . '/GyftrClient.php';

$sessionDir = __DIR__ . '/sessions';
if (!is_dir($sessionDir)) @mkdir($sessionDir, 0777, true);

$userSessionFile = $sessionDir . '/web_' . session_id() . '.json';
$client = new GyftrClient($userSessionFile);

if (!empty($_SESSION['login_mobile']) && !$client->mobile) {
    $client->mobile = $_SESSION['login_mobile'];
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'home';
$flash = '';
$flashType = 'info';

if ($action === 'cancel_login') {
    unset($_SESSION['login_mobile']);
    header("Location: index.php");
    exit;
}

// Handle AJAX Payment Check
if (isset($_GET['check_payment_ajax']) && !empty($_SESSION['active_payment'])) {
    header('Content-Type: application/json');
    $pay = $_SESSION['active_payment'];
    $status = $client->checkPaymentStatus($pay);
    if ($status === 'SUCCESS') {
        $client->clearSession();
        unset($_SESSION['active_payment']);
        $_SESSION['flash'] = "🎉 Payment Verified! Voucher order has been completed and dispatched. Session closed.";
        $_SESSION['flash_type'] = "success";
    } elseif ($status === 'FAILED') {
        unset($_SESSION['active_payment']);
        $_SESSION['flash'] = "❌ Payment failed or expired.";
        $_SESSION['flash_type'] = "danger";
    }
    echo json_encode(['status' => $status]);
    exit;
}

// Handle Manual Payment Check GET
if (isset($_GET['view']) && $_GET['view'] === 'check_pay' && !empty($_SESSION['active_payment'])) {
    $pay = $_SESSION['active_payment'];
    $status = $client->checkPaymentStatus($pay);
    if ($status === 'SUCCESS') {
        $client->clearSession();
        unset($_SESSION['active_payment']);
        $flash = "🎉 Payment Verified! Voucher order has been completed and dispatched. Session closed.";
        $flashType = "success";
    } elseif ($status === 'FAILED') {
        unset($_SESSION['active_payment']);
        $flash = "❌ Payment failed or expired.";
        $flashType = "danger";
    } else {
        $flash = "⏳ Payment is still pending. Please complete transaction in your UPI app.";
        $flashType = "info";
        header("Location: index.php?view=payment");
        exit;
    }
}

if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    $flashType = $_SESSION['flash_type'] ?? 'info';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
}

// Handle AJAX / Form Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'send_otp') {
        $mobile = preg_replace('/\D/', '', $_POST['mobile'] ?? '');
        if (strlen($mobile) === 12 && substr($mobile, 0, 2) === '91') {
            $mobile = substr($mobile, 2);
        }
        if (strlen($mobile) !== 10) {
            $flash = "Please enter a valid 10-digit mobile number.";
            $flashType = "danger";
        } elseif ($client->tryAutoLogin($mobile)) {
            // Instant auto-login using saved session!
            $name = $client->userData['full_name'] ?? 'Customer';
            $flash = "🎉 Welcome back, {$name}! Automatically restored saved session for {$client->mobile}!";
            $flashType = "success";
            unset($_SESSION['login_mobile'], $_SESSION['is_registered']);
        } else {
            try {
                if ($client->sendOtp($mobile)) {
                    $_SESSION['login_mobile'] = $mobile;
                    $_SESSION['is_registered'] = $client->isRegistered;
                    $flash = "OTP sent to $mobile!";
                    $flashType = "success";
                } else {
                    $flash = "Failed to send OTP.";
                    $flashType = "danger";
                }
            } catch (Exception $e) {
                $flash = $e->getMessage();
                $flashType = "danger";
            }
        }
    } elseif ($action === 'verify_otp') {
        $otp = trim($_POST['otp'] ?? '');
        $mobile = trim($_POST['mobile'] ?? $_SESSION['login_mobile'] ?? '');
        if (isset($_POST['is_registered']) && $_POST['is_registered'] !== '') {
            $client->isRegistered = (bool)$_POST['is_registered'];
        } elseif (isset($_SESSION['is_registered'])) {
            $client->isRegistered = $_SESSION['is_registered'];
        }
        try {
            if ($client->verifyOtp($otp, $mobile)) {
                $name = $client->userData['full_name'] ?? 'User';
                $flash = "Login successful! Welcome {$name} (Session saved for auto-login).";
                $flashType = "success";
                unset($_SESSION['login_mobile'], $_SESSION['is_registered']);
            } else {
                $flash = "Invalid OTP.";
                $flashType = "danger";
            }
        } catch (Exception $e) {
            $flash = $e->getMessage();
            $flashType = "danger";
        }
    } elseif ($action === 'logout') {
        $client->clearSession();
        unset($_SESSION['login_mobile'], $_SESSION['is_registered'], $_SESSION['active_payment']);
        $flash = "Logged out. (Session saved — enter your mobile number anytime to auto-login).";
        $flashType = "info";
    } elseif ($action === 'play_game') {
        try {
            $res = $client->playGame(2);
            $flash = $res['message'] ?? "Diwali Game finished! +150 points claimed. Balance: ₹" . number_format($client->epayBalance, 2);
            $flashType = !empty($res['already_played']) ? "info" : "success";
        } catch (Exception $e) {
            $flash = "Game Notice: " . $e->getMessage();
            $flashType = "warning";
        }
    } elseif ($action === 'buy_fk') {
        $giftEmail = trim($_POST['gift_email'] ?? '');
        $visitorId = trim($_POST['visitor_id'] ?? '');
        $requestId = trim($_POST['request_id'] ?? '');

        try {
            $cid = $client->addFlipkartVoucherToCart();
            if ($giftEmail) {
                $client->setCartDelivery($giftEmail);
            }
            $fp = [];
            if ($visitorId && $requestId) {
                $fp = ['visitor_id' => $visitorId, 'request_id' => $requestId];
            }
            $co = $client->checkout($cid, (bool)$giftEmail, $fp);
            $pay = $client->initiateUpiPayment($co);

            if (!empty($pay['is_completed'])) {
                $client->clearSession();
                $flash = "🎉 Order completed using ePay points! Voucher sent to " . ($giftEmail ?: $client->mobile);
                $flashType = "success";
            } else {
                $_SESSION['active_payment'] = $pay;
                $_SESSION['payment_product'] = "Flipkart Gift Card ₹50";
                $_SESSION['payment_target'] = $giftEmail ?: $client->mobile;
                header("Location: index.php?view=payment");
                exit;
            }
        } catch (Exception $e) {
            $flash = "Order Failed: " . $e->getMessage();
            $flashType = "danger";
        }
    } elseif ($action === 'buy_amz') {
        $visitorId = trim($_POST['visitor_id'] ?? '');
        $requestId = trim($_POST['request_id'] ?? '');

        try {
            $cid = $client->addAmazonVoucherToCart();
            $fp = [];
            if ($visitorId && $requestId) {
                $fp = ['visitor_id' => $visitorId, 'request_id' => $requestId];
            }
            $co = $client->checkout($cid, false, $fp);
            $pay = $client->initiateUpiPayment($co);

            if (!empty($pay['is_completed'])) {
                $client->clearSession();
                $flash = "🎉 Order completed using ePay points! Voucher sent to {$client->mobile}";
                $flashType = "success";
            } else {
                $_SESSION['active_payment'] = $pay;
                $_SESSION['payment_product'] = "Amazon Pay Gift Card ₹100";
                $_SESSION['payment_target'] = $client->mobile;
                header("Location: index.php?view=payment");
                exit;
            }
        } catch (Exception $e) {
            $flash = "Order Failed: " . $e->getMessage();
            $flashType = "danger";
        }
    }
}

$isLoggedIn = !empty($client->token && $client->mobile);
$view = $_GET['view'] ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>GyFTR Automation Control Panel</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    body { background: #0b0f19; color: #f1f5f9; min-height: 100vh; padding: 20px 0; }
    .card { background: #151e2e; border: 1px solid #1e293b; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.4); }
    .btn-primary { background: #3b82f6; border-color: #3b82f6; border-radius: 10px; }
    .btn-success { background: #10b981; border-color: #10b981; border-radius: 10px; }
    .btn-warning { background: #f59e0b; border-color: #f59e0b; border-radius: 10px; }
    .badge-epay { font-size: 1.25rem; background: #047857; padding: 8px 16px; border-radius: 12px; }
  </style>
</head>
<body>
<div class="container" style="max-width: 620px;">

  <div class="text-center mb-4">
    <h2 class="fw-bold text-white">⚡ GyFTR Automation Suite</h2>
    <p class="text-secondary mb-0">PHP Backend Control Panel</p>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flashType ?> alert-dismissible fade show" role="alert">
      <?= htmlspecialchars($flash) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if ($view === 'payment' && !empty($_SESSION['active_payment'])): ?>
    <?php
      $pay = $_SESSION['active_payment'];
      $intentUri = $pay['intent_uri'] ?? '';
      $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($intentUri);
    ?>
    <div class="card p-4 text-center">
      <h4 class="text-warning mb-3">📲 Complete UPI Payment</h4>
      <p class="text-secondary"><?= htmlspecialchars($_SESSION['payment_product'] ?? '') ?> for <b><?= htmlspecialchars($_SESSION['payment_target'] ?? '') ?></b></p>
      
      <div class="my-3">
        <img src="<?= $qrUrl ?>" class="img-fluid rounded border border-secondary" style="max-width: 250px;" alt="QR Code">
      </div>

      <h3 class="text-success fw-bold">₹<?= number_format($pay['pg_amount'], 2) ?></h3>
      <p class="small text-secondary">Order ID: <code><?= htmlspecialchars($pay['ext_order_id']) ?></code></p>

      <div id="pollingStatus" class="text-info small mt-3">⏳ Listening for payment confirmation...</div>
      <div class="d-grid gap-2 mt-3">
        <a href="<?= htmlspecialchars($intentUri) ?>" class="btn btn-primary btn-lg">Open in UPI App</a>
        <a href="index.php?view=check_pay" class="btn btn-outline-info">Check Payment Status</a>
        <a href="index.php" class="btn btn-outline-secondary">Return to Dashboard</a>
      </div>
    </div>

    <script>
      const pollTimer = setInterval(async () => {
        try {
          const res = await fetch("index.php?check_payment_ajax=1");
          const data = await res.json();
          if (data.status === "SUCCESS") {
            clearInterval(pollTimer);
            document.getElementById("pollingStatus").innerHTML = "<b class='text-success'>🎉 Payment Confirmed! Dispatched!</b>";
            setTimeout(() => { window.location.href = "index.php"; }, 1500);
          } else if (data.status === "FAILED") {
            clearInterval(pollTimer);
            document.getElementById("pollingStatus").innerHTML = "<b class='text-danger'>❌ Payment Failed or Expired</b>";
            setTimeout(() => { window.location.href = "index.php"; }, 1500);
          }
        } catch (e) {}
      }, 4000);
    </script>

  <?php elseif (!$isLoggedIn): ?>
    <div class="card p-4">
      <h4 class="text-white mb-3">📱 Connect GyFTR Account</h4>

      <?php if (empty($_SESSION['login_mobile'])): ?>
        <form method="POST">
          <input type="hidden" name="action" value="send_otp">
          <div class="mb-3">
            <label class="form-label text-secondary">10-Digit Mobile Number</label>
            <input type="tel" name="mobile" class="form-control form-control-lg bg-dark text-white border-secondary" placeholder="9876543210" required autofocus>
          </div>
          <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">Send OTP</button>
        </form>
      <?php else: ?>
        <form method="POST">
          <input type="hidden" name="action" value="verify_otp">
          <input type="hidden" name="mobile" value="<?= htmlspecialchars($_SESSION['login_mobile'] ?? '') ?>">
          <input type="hidden" name="is_registered" value="<?= isset($_SESSION['is_registered']) ? (int)$_SESSION['is_registered'] : '' ?>">
          <p class="text-info small">OTP was sent to <b><?= htmlspecialchars($_SESSION['login_mobile'] ?? '') ?></b></p>
          <div class="mb-3">
            <label class="form-label text-secondary">6-Digit OTP</label>
            <input type="text" name="otp" class="form-control form-control-lg bg-dark text-white border-secondary" placeholder="123456" required autofocus>
          </div>
          <button type="submit" class="btn btn-success w-100 py-2 fw-bold mb-2">Verify & Login</button>
          <a href="index.php?action=cancel_login" class="btn btn-outline-secondary w-100">Cancel</a>
        </form>
      <?php endif; ?>
    </div>

  <?php else: ?>
    <!-- Active User Dashboard -->
    <div class="card p-4 mb-3">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <h5 class="text-white mb-0"><?= htmlspecialchars($client->userData['full_name'] ?? 'Customer') ?></h5>
          <small class="text-secondary"><?= htmlspecialchars($client->mobile) ?></small>
        </div>
        <div>
          <span class="badge badge-epay">₹<?= number_format($client->epayBalance, 2) ?></span>
        </div>
      </div>

      <div class="d-flex gap-2">
        <form method="POST" class="flex-grow-1">
          <input type="hidden" name="action" value="play_game">
          <button type="submit" class="btn btn-warning w-100 fw-bold">🎮 Play Diwali Game</button>
        </form>
        <form method="POST">
          <input type="hidden" name="action" value="logout">
          <button type="submit" class="btn btn-outline-danger">Logout</button>
        </form>
      </div>
    </div>

    <!-- Purchase Options -->
    <div class="card p-4 mb-3">
      <h5 class="text-white mb-3">🛍️ Flipkart Gift Card (₹50)</h5>
      <form method="POST" id="fkForm">
        <input type="hidden" name="action" value="buy_fk">
        <input type="hidden" name="visitor_id" id="fk_vid">
        <input type="hidden" name="request_id" id="fk_rid">
        <div class="mb-3">
          <label class="form-label text-secondary">Gift to Email (Leave blank to send to self)</label>
          <input type="email" name="gift_email" class="form-control bg-dark text-white border-secondary" placeholder="friend@example.com">
        </div>
        <button type="submit" class="btn btn-success w-100 py-2 fw-bold">Buy ₹50 Flipkart GV (Pay with ePay / UPI)</button>
      </form>
    </div>

    <div class="card p-4">
      <h5 class="text-white mb-3">🛒 Amazon Pay Voucher (₹100)</h5>
      <form method="POST" id="amzForm">
        <input type="hidden" name="action" value="buy_amz">
        <input type="hidden" name="visitor_id" id="amz_vid">
        <input type="hidden" name="request_id" id="amz_rid">
        <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">Buy ₹100 Amazon GV</button>
      </form>
    </div>

    <!-- Silent Browser FingerprintJS Bridge -->
    <script type="module">
      try {
        const { start } = await import("https://metrics.gyftr.com/web/v4/Bwihr8E7Qva6zV1aeuAt?ci=jsl/4.1.6");
        const fp = start({ apiKey: "Bwihr8E7Qva6zV1aeuAt", region: "ap", endpoints: ["https://metrics.gyftr.com"] });
        const resp = await fp.get({ tag: { page: "dashboard" } });
        const vid = resp.visitor_id || resp.visitorId || "";
        const rid = resp.event_id || resp.requestId || "";
        if (vid && rid) {
          document.getElementById("fk_vid").value = vid;
          document.getElementById("fk_rid").value = rid;
          document.getElementById("amz_vid").value = vid;
          document.getElementById("amz_rid").value = rid;
        }
      } catch (e) {}
    </script>

  <?php endif; ?>

  <div class="text-center mt-4">
    <p class="text-secondary small">Telegram Bot: <a href="tg://resolve?domain=Shazyfk_bot" class="text-info text-decoration-none">@Shazyfk_bot</a></p>
  </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
