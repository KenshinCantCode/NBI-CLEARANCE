<?php

require_once 'connect.php';
require_once 'auth_helpers.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$statusType = '';
$statusMessage = '';
$tokenValid = false;
$tokenRecord = null;

if ($token === '') {
    $statusType = 'error';
    $statusMessage = 'The password reset link is invalid.';
} else {
    $tokenHash = hashToken($token);
    $tokenStmt = $conn->prepare("SELECT id, email, expires_at, used_at FROM password_resets WHERE token_hash=? LIMIT 1");
    $tokenStmt->bind_param("s", $tokenHash);
    $tokenStmt->execute();
    $tokenResult = $tokenStmt->get_result();
    $tokenRecord = $tokenResult->fetch_assoc();
    $tokenStmt->close();

    if (!$tokenRecord) {
        $statusType = 'error';
        $statusMessage = 'The password reset link is invalid.';
    } elseif (!empty($tokenRecord['used_at'])) {
        $statusType = 'error';
        $statusMessage = 'This password reset link has already been used.';
    } elseif (strtotime((string) $tokenRecord['expires_at']) <= time()) {
        $statusType = 'error';
        $statusMessage = 'This password reset link has expired.';
    } else {
        $tokenValid = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid && $tokenRecord) {
    $newPassword = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < 8) {
        $statusType = 'error';
        $statusMessage = 'Password must be at least 8 characters long.';
    } elseif ($newPassword !== $confirmPassword) {
        $statusType = 'error';
        $statusMessage = 'Passwords do not match.';
    } else {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $email = $tokenRecord['email'];

        $updatePassStmt = $conn->prepare("UPDATE users SET password=? WHERE email=?");
        $updatePassStmt->bind_param("ss", $newHash, $email);
        $updatePassStmt->execute();
        $updatePassStmt->close();

        $consumeStmt = $conn->prepare("UPDATE password_resets SET used_at=NOW() WHERE id=?");
        $consumeStmt->bind_param("i", $tokenRecord['id']);
        $consumeStmt->execute();
        $consumeStmt->close();

        $revokeLoginStmt = $conn->prepare("UPDATE login_verifications SET used_at=NOW() WHERE email=? AND used_at IS NULL");
        $revokeLoginStmt->bind_param("s", $email);
        $revokeLoginStmt->execute();
        $revokeLoginStmt->close();

        setFlashMessage('success', 'Password reset successful. Please sign in with your new password.');
        redirectTo('index.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | NBI Clearance Portal</title>
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
                <h2 class="form-title">Reset Password</h2>
                <p class="form-subtitle">Create a new password for your account.</p>

                <?php if ($statusMessage !== ''): ?>
                    <div class="notice notice-<?php echo htmlspecialchars($statusType); ?>">
                        <?php echo htmlspecialchars($statusMessage); ?>
                    </div>
                <?php endif; ?>

                <?php if ($tokenValid): ?>
                    <form method="post" action="reset_password.php">
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                        <div class="input-group">
                            <i class="fas fa-lock"></i>
                            <input type="password" name="password" id="newPassword" placeholder="New Password" required>
                            <label for="newPassword">New Password</label>
                        </div>
                        <div class="input-group">
                            <i class="fas fa-lock"></i>
                            <input type="password" name="confirm_password" id="confirmPassword" placeholder="Confirm Password" required>
                            <label for="confirmPassword">Confirm Password</label>
                        </div>
                        <input type="submit" class="btn" value="Update Password">
                    </form>
                <?php else: ?>
                    <div class="auth-actions">
                        <a class="btn btn-secondary" href="forgot_password.php">Request New Link</a>
                    </div>
                <?php endif; ?>

                <div class="links">
                    <p>Back to account access</p>
                    <a class="text-link" href="index.php">Sign In</a>
                </div>
            </div>
        </main>
    </div>
</body>
</html>

