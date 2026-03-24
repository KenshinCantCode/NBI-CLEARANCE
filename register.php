<?php

require_once 'connect.php';
require_once 'auth_helpers.php';
require_once 'mailer.php';

ensureUserRoleColumn($conn);

function createLoginOtpHash(string $email, string $otp): string
{
    return hashToken(strtolower(trim($email)) . '|' . $otp);
}

function sendLoginOtpEmail(mysqli $conn, string $email, string $firstName, string $lastName): array
{
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

    $displayName = trim($firstName . ' ' . $lastName);
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

    $mailResult = sendLoginOtpEmail(
        $conn,
        $user['email'],
        (string) ($user['firstName'] ?? ''),
        (string) ($user['lastName'] ?? '')
    );

    if (!$mailResult['success']) {
        setFlashMessage('error', 'Unable to send login OTP email. ' . $mailResult['error']);
        redirectTo('index.php');
    }

    $_SESSION['pending_login_email'] = $user['email'];
    if (!empty($mailResult['debug_otp'])) {
        $_SESSION['pending_verify_debug_code'] = (string) $mailResult['debug_otp'];
    } else {
        unset($_SESSION['pending_verify_debug_code']);
    }
    if (!empty($mailResult['debug_file'])) {
        $_SESSION['pending_verify_debug_file'] = (string) $mailResult['debug_file'];
    } else {
        unset($_SESSION['pending_verify_debug_file']);
    }
    unset($_SESSION['pending_verify_debug_link']);
    setFlashMessage('info', 'A verification OTP has been sent. Enter the code to complete sign in.');
    redirectTo('verify_login.php');
}

setFlashMessage('error', 'Invalid authentication request.');
redirectTo('index.php');
