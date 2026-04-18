<?php

require_once 'connect.php';
require_once 'auth_helpers.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$statusType = '';
$statusMessage = '';
$tokenValid = false;
$tokenRecord = null;
$authReady = true;

try {
    ensureAuthSupportTables($conn);
} catch (Throwable $exception) {
    $authReady = false;
    $statusType = 'error';
    $statusMessage = 'Unable to initialize password reset right now. ' . $exception->getMessage();
}

if ($authReady && $token === '') {
    $statusType = 'error';
    $statusMessage = 'The password reset link is invalid.';
} elseif ($authReady) {
    $tokenHash = hashToken($token);
    $tokenStmt = $conn->prepare("SELECT id, email, expires_at, used_at FROM password_resets WHERE token_hash=? LIMIT 1");
    if (!$tokenStmt) {
        $statusType = 'error';
        $statusMessage = 'Unable to verify the password reset link right now.';
    } else {
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
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $authReady && $tokenValid && $tokenRecord) {
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
        $consumeStmt = $conn->prepare("UPDATE password_resets SET used_at=NOW() WHERE id=?");
        $revokeLoginStmt = $conn->prepare("UPDATE login_verifications SET used_at=NOW() WHERE email=? AND used_at IS NULL");

        if (!$updatePassStmt || !$consumeStmt || !$revokeLoginStmt) {
            $statusType = 'error';
            $statusMessage = 'Unable to update your password right now.';
            if ($updatePassStmt) {
                $updatePassStmt->close();
            }
            if ($consumeStmt) {
                $consumeStmt->close();
            }
            if ($revokeLoginStmt) {
                $revokeLoginStmt->close();
            }
        } else {
            $tokenId = (int) $tokenRecord['id'];
            $resetApplied = false;
            $conn->begin_transaction();

            $updatePassStmt->bind_param("ss", $newHash, $email);
            $updated = $updatePassStmt->execute();
            $passwordChanged = $updated && $updatePassStmt->affected_rows === 1;
            $updatePassStmt->close();

            if ($passwordChanged) {
                $consumeStmt->bind_param("i", $tokenId);
                $tokenConsumed = $consumeStmt->execute() && $consumeStmt->affected_rows === 1;
                $consumeStmt->close();

                $revokeLoginStmt->bind_param("s", $email);
                $verificationsRevoked = $revokeLoginStmt->execute();
                $revokeLoginStmt->close();

                if ($tokenConsumed && $verificationsRevoked) {
                    $conn->commit();
                    $resetApplied = true;
                } else {
                    $conn->rollback();
                }
            } else {
                $consumeStmt->close();
                $revokeLoginStmt->close();
                $conn->rollback();
            }

            if (!$resetApplied) {
                $statusType = 'error';
                $statusMessage = 'Unable to update your password right now.';
            } else {
                setFlashMessage('success', 'Password reset successful. Please sign in with your new password.');
                redirectTo('index.php');
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
    <title>Reset Password | NBI Clearance Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?php echo (int) (@filemtime(__DIR__ . '/style.css') ?: time()); ?>">
</head>
<body class="auth-page">
    <div class="auth-layout">
        <aside class="auth-visual">
            <div class="auth-brand">
                <img src="assets/nbi.png" alt="NBI Clearance Portal logo" class="brand-logo">
                <div>
                    <strong>National Bureau of Investigation</strong>
                </div>
            </div>
            <p class="eyebrow">Reset Credentials</p>
            <h1>Create a new password and restore secure access to your clearance portal.</h1>
        </aside>

        <main class="index-panel">
            <div class="container">
                <div class="auth-card-brand">
                    <img src="assets/nbi.png" alt="" class="card-logo">
                    <div>
                        <p>NBI Clearance Portal</p>
                        <span>Secure password reset</span>
                    </div>
                </div>
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
    <?php include 'footer.php'; ?>
</body>
</html>
