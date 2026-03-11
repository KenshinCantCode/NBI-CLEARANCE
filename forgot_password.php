<?php

require_once 'connect.php';
require_once 'auth_helpers.php';
require_once 'mailer.php';

$statusType = '';
$statusMessage = '';
$devResetLink = '';
$devMailFile = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $statusType = 'error';
        $statusMessage = 'Please enter a valid email address.';
    } else {
        $lookupStmt = $conn->prepare("SELECT firstName, lastName FROM users WHERE email=? LIMIT 1");
        $lookupStmt->bind_param("s", $email);
        $lookupStmt->execute();
        $userResult = $lookupStmt->get_result();
        $user = $userResult->fetch_assoc();
        $lookupStmt->close();

        if ($user) {
            $invalidateStmt = $conn->prepare("UPDATE password_resets SET used_at=NOW() WHERE email=? AND used_at IS NULL");
            $invalidateStmt->bind_param("s", $email);
            $invalidateStmt->execute();
            $invalidateStmt->close();

            $token = createToken();
            $tokenHash = hashToken($token);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));

            $insertStmt = $conn->prepare("INSERT INTO password_resets (email, token_hash, expires_at) VALUES (?, ?, ?)");
            $insertStmt->bind_param("sss", $email, $tokenHash, $expiresAt);
            $insertStmt->execute();
            $insertStmt->close();

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
        } else {
            $statusType = 'success';
            $statusMessage = 'If the email exists in our system, a reset link has been sent.';
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
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
    <div class="auth-layout auth-layout-single">
        <main class="auth-panel auth-panel-full">
            <div class="container">
                <h2 class="form-title">Recover Password</h2>
                <p class="form-subtitle">Enter your account email and we will send a reset link.</p>

                <?php if ($statusMessage !== ''): ?>
                    <div class="notice notice-<?php echo htmlspecialchars($statusType); ?>">
                        <?php echo htmlspecialchars($statusMessage); ?>
                    </div>
                <?php endif; ?>

                <?php if ($devResetLink !== ''): ?>
                    <div class="notice notice-info">
                        Local mail mode is active. Use this reset link:
                        <a class="inline-link" href="<?php echo htmlspecialchars($devResetLink); ?>">Open Reset Link</a>
                        <?php if ($devMailFile !== ''): ?>
                            <br>
                            Saved email file: <code><?php echo htmlspecialchars($devMailFile); ?></code>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="forgot_password.php">
                    <div class="input-group">
                        <i class="fas fa-envelope"></i>
                        <input type="email" name="email" id="recoverEmail" placeholder="Email" required>
                        <label for="recoverEmail">Email</label>
                    </div>
                    <input type="submit" class="btn" value="Send Reset Link">
                </form>

                <div class="links">
                    <p>Remembered your password?</p>
                    <a class="text-link" href="index.php">Back to Sign In</a>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
