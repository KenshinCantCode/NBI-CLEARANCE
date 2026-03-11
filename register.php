<?php

require_once 'connect.php';
require_once 'auth_helpers.php';
require_once 'mailer.php';

ensureUserRoleColumn($conn);

function sendLoginVerificationEmail(mysqli $conn, string $email, string $firstName, string $lastName): array
{
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

    $displayName = trim($firstName . ' ' . $lastName);
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('index.php');
}

if (isset($_POST['signUp'])) {
    $firstName = trim($_POST['fName'] ?? '');
    $lastName = trim($_POST['lName'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($firstName === '' || $lastName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        setFlashMessage('error', 'Please complete all sign up fields.');
        redirectTo('index.php?form=signup');
    }

    if (strlen($password) < 8) {
        setFlashMessage('error', 'Password must be at least 8 characters long.');
        redirectTo('index.php?form=signup');
    }

    $checkStmt = $conn->prepare("SELECT email FROM users WHERE email=? LIMIT 1");
    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $existingUser = $checkResult->fetch_assoc();
    $checkStmt->close();

    if ($existingUser) {
        setFlashMessage('error', 'Email address already exists.');
        redirectTo('index.php?form=signup');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $role = 'user';
    $insertStmt = $conn->prepare("INSERT INTO users (firstName, lastName, email, password, role) VALUES (?, ?, ?, ?, ?)");
    $insertStmt->bind_param("sssss", $firstName, $lastName, $email, $passwordHash, $role);

    if ($insertStmt->execute()) {
        $insertStmt->close();
        setFlashMessage('success', 'Account created successfully. Sign in to continue.');
        redirectTo('index.php');
    }

    $error = $conn->error;
    $insertStmt->close();
    setFlashMessage('error', 'Unable to create account. ' . $error);
    redirectTo('index.php?form=signup');
}

if (isset($_POST['signIn'])) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        setFlashMessage('error', 'Please enter your email and password.');
        redirectTo('index.php');
    }

    $stmt = $conn->prepare("SELECT email, password, firstName, lastName, role FROM users WHERE email=? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user || !verifyPasswordAndUpgrade($conn, $user['email'], $password, $user['password'])) {
        setFlashMessage('error', 'Not found, incorrect email or password.');
        redirectTo('index.php');
    }

    $mailResult = sendLoginVerificationEmail(
        $conn,
        $user['email'],
        (string) ($user['firstName'] ?? ''),
        (string) ($user['lastName'] ?? '')
    );

    if (!$mailResult['success']) {
        setFlashMessage('error', 'Unable to send login verification email. ' . $mailResult['error']);
        redirectTo('index.php');
    }

    $_SESSION['pending_login_email'] = $user['email'];
    if (!empty($mailResult['debug_link'])) {
        $_SESSION['pending_verify_debug_link'] = $mailResult['debug_link'];
    } else {
        unset($_SESSION['pending_verify_debug_link']);
    }
    setFlashMessage('info', 'Verification email sent. Open your inbox to complete sign in.');
    redirectTo('verify_login.php');
}

setFlashMessage('error', 'Invalid authentication request.');
redirectTo('index.php');
