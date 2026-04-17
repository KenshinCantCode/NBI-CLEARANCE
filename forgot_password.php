<?php

require_once 'connect.php';
require_once 'auth_helpers.php';
require_once 'mailer.php';

$statusType = '';
$statusMessage = '';
$devResetLink = '';
$devMailFile = '';
$authReady = true;

try {
    ensureAuthSupportTables($conn);
} catch (Throwable $exception) {
    $authReady = false;
    $statusType = 'error';
    $statusMessage = 'Unable to initialize password recovery right now. ' . $exception->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $authReady) {
    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $statusType = 'error';
        $statusMessage = 'Please enter a valid email address.';
    } else {
        $lookupStmt = $conn->prepare("SELECT firstName, lastName FROM users WHERE email=? LIMIT 1");
        if (!$lookupStmt) {
            $statusType = 'error';
            $statusMessage = 'Unable to process password recovery right now.';
        } else {
            $lookupStmt->bind_param("s", $email);
            $lookupStmt->execute();
            $userResult = $lookupStmt->get_result();
            $user = $userResult->fetch_assoc();
            $lookupStmt->close();

            if ($user) {
                $invalidateStmt = $conn->prepare("UPDATE password_resets SET used_at=NOW() WHERE email=? AND used_at IS NULL");
                $insertStmt = $conn->prepare("INSERT INTO password_resets (email, token_hash, expires_at) VALUES (?, ?, ?)");

                if (!$invalidateStmt || !$insertStmt) {
                    $statusType = 'error';
                    $statusMessage = 'Unable to process password recovery right now.';
                    if ($invalidateStmt) {
                        $invalidateStmt->close();
                    }
                    if ($insertStmt) {
                        $insertStmt->close();
                    }
                } else {
                    $invalidateStmt->bind_param("s", $email);
                    $invalidated = $invalidateStmt->execute();
                    $invalidateStmt->close();

                    $token = createToken();
                    $tokenHash = hashToken($token);
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));

                    $insertStmt->bind_param("sss", $email, $tokenHash, $expiresAt);
                    $stored = $insertStmt->execute();
                    $insertStmt->close();

                    if (!$invalidated || !$stored) {
                        $statusType = 'error';
                        $statusMessage = 'Unable to generate a password reset link right now.';
                    } else {
                        $displayName = trim(($user['firstName'] ?? '') . ' ' . ($user['lastName'] ?? ''));
                        if ($displayName === '') {
                            $displayName = 'User';
                        }

                        $resetLink = appBaseUrl() . '/reset_password.php?token=' . urlencode($token);
                        $subject = 'NBI Clearance Password Reset';
                        $body = "
                            <p>Hello {$displayName},</p>
                            <p>We received a request to reset your password.</p>
                            <p>Click the link below to continue (valid for 30 minutes):</p>
                            <p><a href=\"{$resetLink}\">Reset Password</a></p>
                            <p>If you did not request this, you can ignore this email.</p>
                        ";

                        $mailResult = sendAppEmail($email, $displayName, $subject, $body);
                        if (!$mailResult['success']) {
                            $statusType = 'error';
                            $statusMessage = 'Unable to send reset email right now. ' . $mailResult['error'];
                        } else {
                            $statusType = 'success';
                            $statusMessage = 'If the email exists in our system, a reset link has been sent.';
                            $devResetLink = (string) ($mailResult['debug_link'] ?? '');
                            $devMailFile = (string) ($mailResult['debug_file'] ?? '');
                        }
                    }
                }
            } else {
                $statusType = 'success';
                $statusMessage = 'If the email exists in our system, a reset link has been sent.';
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recover Password | NBI Clearance Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?php echo (int) (@filemtime(__DIR__ . '/style.css') ?: time()); ?>">
</head>
<body class="auth-page index-page">
    <div class="auth-layout">
        <aside class="auth-visual">
            <div class="auth-brand">
                <img src="assets/nbi.png" alt="NBI Clearance Portal logo" class="brand-logo">
                <div>
                    <p class="auth-brand-kicker">Official Account Recovery</p>
                    <strong>National Bureau of Investigation</strong>
                    <span>Password reset assistance</span>
                </div>
            </div>
            <p class="eyebrow">Recover Access</p>
            <h1>Reset or Recover Your Password</h1>
        </aside>

        <main class="index-panel">
            <div class="container">
                <div class="auth-card-brand">
                    <img src="assets/nbi.png" alt="" class="card-logo">
                    <div>
                        <p>NBI Clearance Portal</p>
                        <span>Password recovery</span>
                    </div>
                </div>
                <h2 class="form-title">Forgot Password</h2>
                <p class="form-subtitle">Enter your registered email and we will send a reset link.</p>

                <?php if ($statusMessage !== ''): ?>
                    <div class="notice notice-<?php echo htmlspecialchars($statusType); ?>">
                        <?php echo htmlspecialchars($statusMessage); ?>
                    </div>
                <?php endif; ?>

                <?php if ($devResetLink !== ''): ?>
                    <div class="notice notice-info">
                        Local mail mode is active.
                        <a class="inline-link" href="<?php echo htmlspecialchars($devResetLink); ?>">Open reset link</a>
                        <?php if ($devMailFile !== ''): ?>
                            <p class="notice-meta">Saved email file: <code><?php echo htmlspecialchars($devMailFile); ?></code></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="forgot_password.php">
                    <div class="input-group">
                        <i class="fas fa-envelope"></i>
                        <input
                            type="email"
                            name="email"
                            id="recoverEmail"
                            placeholder="Email Address"
                            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                            required
                        >
                        <label for="recoverEmail">Email Address</label>
                    </div>

                    <button type="submit" class="btn">Send Reset Link</button>
                </form>

                <div class="links">
                    <p>Remembered your password?</p>
                    <a class="text-link" href="index.php">Back to Sign In</a>
                </div>
            </div>
        </main>
    </div>
</body>
<div class="footer">
    <div class="footer-container">
        <p>@2026 NBI Clearance. All Right Reserved</p>
        <p>Contact Us</p>
    </div>
</html>
