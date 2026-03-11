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

function sendLoginVerificationEmail(mysqli $conn, string $email): array
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

    $token = createToken();
    $tokenHash = hashToken($token);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    $insertStmt = $conn->prepare("INSERT INTO login_verifications (email, token_hash, expires_at) VALUES (?, ?, ?)");
    $insertStmt->bind_param("sss", $email, $tokenHash, $expiresAt);
    $insertStmt->execute();
    $insertStmt->close();

    $displayName = trim(($user['firstName'] ?? '') . ' ' . ($user['lastName'] ?? ''));
    if ($displayName === '') {
        $displayName = 'User';
    }

    $verifyLink = appBaseUrl() . '/verify_login.php?token=' . urlencode($token);
    $subject = 'NBI Clearance Login Verification';
    $body = "
        <p>Hello {$displayName},</p>
        <p>To complete your sign in, click the verification link below.</p>
        <p><a href=\"{$verifyLink}\">Verify Sign In</a></p>
        <p>This link expires in 10 minutes.</p>
    ";

    return sendAppEmail($email, $displayName, $subject, $body);
}

$statusType = 'info';
$statusMessage = 'Check your email for the login verification link.';
$pendingEmail = $_SESSION['pending_login_email'] ?? '';
$devVerifyLink = $_SESSION['pending_verify_debug_link'] ?? '';
$flash = pullFlashMessage();

if ($flash) {
    $statusType = (string) ($flash['type'] ?? $statusType);
    $statusMessage = (string) ($flash['message'] ?? $statusMessage);
}

$token = trim($_GET['token'] ?? '');
if ($token !== '') {
    $tokenHash = hashToken($token);
    $tokenStmt = $conn->prepare("SELECT id, email, expires_at, used_at FROM login_verifications WHERE token_hash=? LIMIT 1");
    $tokenStmt->bind_param("s", $tokenHash);
    $tokenStmt->execute();
    $tokenResult = $tokenStmt->get_result();
    $record = $tokenResult->fetch_assoc();
    $tokenStmt->close();

    if (
        $record &&
        empty($record['used_at']) &&
        strtotime((string) $record['expires_at']) > time()
    ) {
        $consumeStmt = $conn->prepare("UPDATE login_verifications SET used_at=NOW() WHERE id=?");
        $consumeStmt->bind_param("i", $record['id']);
        $consumeStmt->execute();
        $consumeStmt->close();

        session_regenerate_id(true);
        $_SESSION['email'] = $record['email'];
        $_SESSION['role'] = getUserRole($conn, (string) $record['email']);
        unset($_SESSION['pending_login_email']);
        unset($_SESSION['pending_verify_debug_link']);
        if ($_SESSION['role'] === 'admin') {
            redirectTo('backend.php');
        }

        redirectTo('homepage.php');
    }

    setFlashMessage('error', 'The verification link is invalid or expired. Please sign in again.');
    redirectTo('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
    if ($pendingEmail === '') {
        $statusType = 'error';
        $statusMessage = 'No pending login was found. Please sign in again.';
    } else {
        $mailResult = sendLoginVerificationEmail($conn, $pendingEmail);
        if ($mailResult['success']) {
            $statusType = 'success';
            $statusMessage = 'A new verification email has been sent.';
            $devVerifyLink = (string) ($mailResult['debug_link'] ?? '');
            if ($devVerifyLink !== '') {
                $_SESSION['pending_verify_debug_link'] = $devVerifyLink;
            } else {
                unset($_SESSION['pending_verify_debug_link']);
            }
        } else {
            $statusType = 'error';
            $statusMessage = 'Unable to send verification email. ' . $mailResult['error'];
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
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
    <div class="auth-layout auth-layout-single">
        <main class="auth-panel auth-panel-full">
            <div class="container">
                <h2 class="form-title">Verify Sign In</h2>
                <p class="form-subtitle">
                    <?php if ($pendingEmail !== ''): ?>
                        We sent a verification link to <?php echo htmlspecialchars(maskEmail($pendingEmail)); ?>.
                    <?php else: ?>
                        Open your inbox and click the verification link.
                    <?php endif; ?>
                </p>

                <div class="notice notice-<?php echo htmlspecialchars($statusType); ?>">
                    <?php echo htmlspecialchars($statusMessage); ?>
                </div>

                <?php if ($devVerifyLink !== ''): ?>
                    <div class="notice notice-info">
                        Local mail mode is active. Continue sign-in using this link:
                        <a class="inline-link" href="<?php echo htmlspecialchars($devVerifyLink); ?>">Open Verification Link</a>
                    </div>
                <?php endif; ?>

                <form method="post" action="verify_login.php" class="auth-actions">
                    <button type="submit" name="resend" class="btn">Resend Verification Email</button>
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
