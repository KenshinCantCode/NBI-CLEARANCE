<?php

require_once 'connect.php';
require_once 'auth_helpers.php';
require_once 'mailer.php';

ensureUserRoleColumn($conn);

function maskEmail(string $email): string
{
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return $email;
    }

    $name = $parts[0];
    $domain = $parts[1];
    if (strlen($name) <= 2) {
        return str_repeat('*', strlen($name)) . '@' . $domain;
    }

    return substr($name, 0, 2) . str_repeat('*', max(1, strlen($name) - 2)) . '@' . $domain;
}

function createLoginOtpHash(string $email, string $otp): string
{
    return hashToken(strtolower(trim($email)) . '|' . $otp);
}

function sendLoginOtpEmail(mysqli $conn, string $email): array
{
    $lookupStmt = $conn->prepare("SELECT firstName, lastName FROM users WHERE email=? LIMIT 1");
    $lookupStmt->bind_param("s", $email);
    $lookupStmt->execute();
    $result = $lookupStmt->get_result();
    $user = $result->fetch_assoc();
    $lookupStmt->close();

    if (!$user) {
        return [
            'success' => false,
            'error' => 'User account was not found.'
        ];
    }

    $invalidateStmt = $conn->prepare("UPDATE login_verifications SET used_at=NOW() WHERE email=? AND used_at IS NULL");
    $invalidateStmt->bind_param("s", $email);
    $invalidateStmt->execute();
    $invalidateStmt->close();

    $otp = '';
    $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    $inserted = false;

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash = createLoginOtpHash($email, $otp);

        $insertStmt = $conn->prepare("INSERT INTO login_verifications (email, token_hash, expires_at) VALUES (?, ?, ?)");
        if (!$insertStmt) {
            return [
                'success' => false,
                'error' => 'Unable to prepare verification request.'
            ];
        }

        $insertStmt->bind_param("sss", $email, $otpHash, $expiresAt);
        $inserted = $insertStmt->execute();
        $insertStmt->close();

        if ($inserted) {
            break;
        }
    }

    if (!$inserted) {
        return [
            'success' => false,
            'error' => 'Unable to generate a one-time code right now.'
        ];
    }

    $displayName = trim(($user['firstName'] ?? '') . ' ' . ($user['lastName'] ?? ''));
    if ($displayName === '') {
        $displayName = 'User';
    }

    $subject = 'NBI Clearance Login OTP';
    $body = "
        <p>Hello {$displayName},</p>
        <p>Use this one-time passcode (OTP) to complete your sign in:</p>
        <p style=\"font-size: 28px; font-weight: 700; letter-spacing: 4px; margin: 8px 0;\">{$otp}</p>
        <p>This code expires in 10 minutes.</p>
        <p>If you did not request this sign in, you can ignore this email.</p>
    ";

    $altBody = "Hello {$displayName}, your login OTP is {$otp}. It expires in 10 minutes.";
    $mailResult = sendAppEmail($email, $displayName, $subject, $body, $altBody);

    if (($mailResult['success'] ?? false) && (($mailResult['delivery'] ?? '') === 'file')) {
        $mailResult['debug_otp'] = $otp;
    }

    return $mailResult;
}

$statusType = 'info';
$statusMessage = 'Check your email for the one-time passcode.';
$pendingEmail = (string) ($_SESSION['pending_login_email'] ?? '');
$devVerifyCode = (string) ($_SESSION['pending_verify_debug_code'] ?? '');
$devMailFile = (string) ($_SESSION['pending_verify_debug_file'] ?? '');
$flash = pullFlashMessage();

if ($flash) {
    $statusType = (string) ($flash['type'] ?? $statusType);
    $statusMessage = (string) ($flash['message'] ?? $statusMessage);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify'])) {
    if ($pendingEmail === '') {
        $statusType = 'error';
        $statusMessage = 'No pending login was found. Please sign in again.';
    } else {
        $otp = preg_replace('/\D+/', '', (string) ($_POST['otp'] ?? ''));

        if (!preg_match('/^\d{6}$/', $otp)) {
            $statusType = 'error';
            $statusMessage = 'Enter the 6-digit OTP code from your email.';
        } else {
            $otpStmt = $conn->prepare(
                "SELECT id, email, token_hash, expires_at
                 FROM login_verifications
                 WHERE email=? AND used_at IS NULL
                 ORDER BY id DESC
                 LIMIT 1"
            );

            if (!$otpStmt) {
                $statusType = 'error';
                $statusMessage = 'Unable to verify OTP right now.';
            } else {
                $otpStmt->bind_param("s", $pendingEmail);
                $otpStmt->execute();
                $otpResult = $otpStmt->get_result();
                $record = $otpResult->fetch_assoc();
                $otpStmt->close();

                if (!$record) {
                    $statusType = 'error';
                    $statusMessage = 'No active OTP was found. Please resend a new code.';
                } elseif (strtotime((string) $record['expires_at']) <= time()) {
                    $expireStmt = $conn->prepare("UPDATE login_verifications SET used_at=NOW() WHERE id=? AND used_at IS NULL");
                    if ($expireStmt) {
                        $expireStmt->bind_param("i", $record['id']);
                        $expireStmt->execute();
                        $expireStmt->close();
                    }
                    $statusType = 'error';
                    $statusMessage = 'Your OTP has expired. Please request a new code.';
                } else {
                    $expectedHash = createLoginOtpHash($pendingEmail, $otp);
                    if (!hash_equals((string) $record['token_hash'], $expectedHash)) {
                        $statusType = 'error';
                        $statusMessage = 'The OTP code is incorrect. Try again.';
                    } else {
                        $consumeStmt = $conn->prepare("UPDATE login_verifications SET used_at=NOW() WHERE id=? AND used_at IS NULL");
                        $consumeStmt->bind_param("i", $record['id']);
                        $consumeStmt->execute();
                        $consumeStmt->close();

                        session_regenerate_id(true);
                        $_SESSION['email'] = (string) $record['email'];
                        $_SESSION['role'] = getUserRole($conn, (string) $record['email']);
                        unset($_SESSION['pending_login_email']);
                        unset($_SESSION['pending_verify_debug_code']);
                        unset($_SESSION['pending_verify_debug_file']);
                        unset($_SESSION['pending_verify_debug_link']);
                        if ($_SESSION['role'] === 'admin') {
                            redirectTo('backend.php');
                        }

                        redirectTo('homepage.php');
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
    if ($pendingEmail === '') {
        $statusType = 'error';
        $statusMessage = 'No pending login was found. Please sign in again.';
    } else {
        $mailResult = sendLoginOtpEmail($conn, $pendingEmail);
        if ($mailResult['success']) {
            $statusType = 'success';
            $statusMessage = 'A new OTP has been sent to your email.';
            $devVerifyCode = (string) ($mailResult['debug_otp'] ?? '');
            $devMailFile = (string) ($mailResult['debug_file'] ?? '');
            if ($devVerifyCode !== '') {
                $_SESSION['pending_verify_debug_code'] = $devVerifyCode;
            } else {
                unset($_SESSION['pending_verify_debug_code']);
            }
            if ($devMailFile !== '') {
                $_SESSION['pending_verify_debug_file'] = $devMailFile;
            } else {
                unset($_SESSION['pending_verify_debug_file']);
            }
            unset($_SESSION['pending_verify_debug_link']);
        } else {
            $statusType = 'error';
            $statusMessage = 'Unable to send OTP email. ' . $mailResult['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Login | NBI Clearance Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?php echo (int) (@filemtime(__DIR__ . '/style.css') ?: time()); ?>">
</head>
<body class="auth-page">
    <div class="auth-layout auth-layout-single">
        <main class="auth-panel auth-panel-full">
            <div class="container">
                <h2 class="form-title">Verify Sign In</h2>
                <p class="form-subtitle">
                    <?php if ($pendingEmail !== ''): ?>
                        We sent a 6-digit OTP to <?php echo htmlspecialchars(maskEmail($pendingEmail)); ?>.
                    <?php else: ?>
                        Open your inbox and enter the OTP code.
                    <?php endif; ?>
                </p>

                <div class="notice notice-<?php echo htmlspecialchars($statusType); ?>">
                    <?php echo htmlspecialchars($statusMessage); ?>
                </div>

                <?php if ($devVerifyCode !== ''): ?>
                    <div class="notice notice-info">
                        Local mail mode is active. OTP code:
                        <strong><?php echo htmlspecialchars($devVerifyCode); ?></strong>
                        <?php if ($devMailFile !== ''): ?>
                            <br>
                            Saved email file: <code><?php echo htmlspecialchars($devMailFile); ?></code>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="verify_login.php">
                    <div class="input-group">
                        <i class="fas fa-key"></i>
                        <input
                            type="text"
                            name="otp"
                            id="otpCode"
                            placeholder="6-digit OTP"
                            maxlength="6"
                            inputmode="numeric"
                            pattern="[0-9]{6}"
                            autocomplete="one-time-code"
                            required
                        >
                        <label for="otpCode">6-digit OTP</label>
                    </div>
                    <input type="submit" name="verify" class="btn" value="Verify OTP">
                </form>

                <form method="post" action="verify_login.php" class="auth-actions">
                    <button type="submit" name="resend" class="btn btn-secondary">Resend OTP</button>
                </form>

                <div class="links">
                    <p>Need to try again?</p>
                    <a class="text-link" href="index.php">Back to Sign In</a>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
