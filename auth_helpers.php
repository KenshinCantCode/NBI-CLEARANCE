<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function setFlashMessage(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function pullFlashMessage(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function redirectTo(string $location): void
{
    header("Location: {$location}");
    exit();
}

function ensureAuthSupportTables(mysqli $conn): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $checked = true;
    $queries = [
        "CREATE TABLE IF NOT EXISTS login_verifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_login_verifications_token_hash (token_hash),
            INDEX idx_login_verifications_email_used_created (email, used_at, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS password_resets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_password_resets_token_hash (token_hash),
            INDEX idx_password_resets_email_used_created (email, used_at, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];

    foreach ($queries as $sql) {
        if (!$conn->query($sql)) {
            throw new RuntimeException('Unable to initialize authentication tables: ' . $conn->error);
        }
    }
}

function loadAppConfig(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = [];
    $configPath = __DIR__ . '/app_config.php';
    if (is_file($configPath)) {
        $loaded = require $configPath;
        if (is_array($loaded)) {
            $config = $loaded;
        }
    }

    return $config;
}

function configuredAppBaseUrl(): string
{
    static $baseUrl = null;
    if ($baseUrl !== null) {
        return $baseUrl;
    }

    $config = loadAppConfig();
    $candidate = trim((string) (
        getenv('PUBLIC_BASE_URL')
        ?: getenv('APP_BASE_URL')
        ?: getenv('APP_URL')
        ?: ($config['public_base_url'] ?? '')
    ));

    if ($candidate === '') {
        $baseUrl = '';
        return $baseUrl;
    }

    if (!preg_match('/^https?:\/\//i', $candidate)) {
        $candidate = 'https://' . ltrim($candidate, '/');
    }

    $baseUrl = rtrim($candidate, '/');
    return $baseUrl;
}

function appBaseUrl(): string
{
    $configured = configuredAppBaseUrl();
    if ($configured !== '') {
        return $configured;
    }

    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    $https = (
        $forwardedProto === 'https'
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
    );
    $scheme = $https ? 'https' : 'http';

    $rawHost = (string) (
        $_SERVER['HTTP_X_FORWARDED_HOST']
        ?? $_SERVER['HTTP_HOST']
        ?? $_SERVER['SERVER_NAME']
        ?? 'localhost'
    );
    $host = trim(explode(',', $rawHost)[0] ?? 'localhost');

    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/NBICLEARANCE/index.php';
    $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    if ($basePath === '' || $basePath === '.' || $basePath === '/') {
        return "{$scheme}://{$host}";
    }

    return "{$scheme}://{$host}{$basePath}";
}

function isLegacyMd5Hash(string $storedHash): bool
{
    return (bool) preg_match('/^[a-f0-9]{32}$/i', $storedHash);
}

function verifyPasswordAndUpgrade(mysqli $conn, string $email, string $plainPassword, string $storedHash): bool
{
    if (password_verify($plainPassword, $storedHash)) {
        return true;
    }

    if (!isLegacyMd5Hash($storedHash)) {
        return false;
    }

    if (!hash_equals(strtolower($storedHash), md5($plainPassword))) {
        return false;
    }

    $newHash = password_hash($plainPassword, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password=? WHERE email=?");
    if ($stmt) {
        $stmt->bind_param("ss", $newHash, $email);
        $stmt->execute();
        $stmt->close();
    }

    return true;
}

function createToken(int $lengthBytes = 32): string
{
    return bin2hex(random_bytes($lengthBytes));
}

function hashToken(string $token): string
{
    return hash('sha256', $token);
}

function createLoginOtpHash(string $email, string $otp): string
{
    return hashToken(strtolower(trim($email)) . '|' . $otp);
}

function sendLoginOtpEmail(mysqli $conn, string $email, string $firstName = '', string $lastName = ''): array
{
    try {
        ensureAuthSupportTables($conn);
    } catch (Throwable $exception) {
        return [
            'success' => false,
            'error' => $exception->getMessage()
        ];
    }

    $resolvedFirstName = trim($firstName);
    $resolvedLastName = trim($lastName);

    if ($resolvedFirstName === '' && $resolvedLastName === '') {
        $lookupStmt = $conn->prepare("SELECT firstName, lastName FROM users WHERE email=? LIMIT 1");
        if (!$lookupStmt) {
            return [
                'success' => false,
                'error' => 'Unable to look up the user account.'
            ];
        }

        $lookupStmt->bind_param("s", $email);
        $lookupStmt->execute();
        $lookupResult = $lookupStmt->get_result();
        $user = $lookupResult->fetch_assoc();
        $lookupStmt->close();

        if (!$user) {
            return [
                'success' => false,
                'error' => 'User account was not found.'
            ];
        }

        $resolvedFirstName = (string) ($user['firstName'] ?? '');
        $resolvedLastName = (string) ($user['lastName'] ?? '');
    }

    $invalidateStmt = $conn->prepare("UPDATE login_verifications SET used_at=NOW() WHERE email=? AND used_at IS NULL");
    if (!$invalidateStmt) {
        return [
            'success' => false,
            'error' => 'Unable to prepare verification request.'
        ];
    }

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

    $displayName = trim($resolvedFirstName . ' ' . $resolvedLastName);
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

function syncPendingLoginDebugState(array $mailResult): void
{
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
}

function clearPendingLoginVerificationState(): void
{
    unset(
        $_SESSION['pending_login_email'],
        $_SESSION['pending_verify_debug_code'],
        $_SESSION['pending_verify_debug_file']
    );
}

function ensureUsersTableColumn(mysqli $conn, string $columnName, string $definitionSql): void
{
    $safeColumnName = $conn->real_escape_string($columnName);
    $columnCheck = $conn->query("SHOW COLUMNS FROM users LIKE '{$safeColumnName}'");
    if ($columnCheck && $columnCheck->num_rows > 0) {
        return;
    }

    $conn->query("ALTER TABLE users ADD COLUMN {$definitionSql}");
}

function ensureUserRoleColumn(mysqli $conn): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $checked = true;
    ensureUsersTableColumn($conn, 'role', "role VARCHAR(20) NOT NULL DEFAULT 'user' AFTER password");
    ensureUsersTableColumn($conn, 'updated_at', "updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER role");
}

function getUserRole(mysqli $conn, string $email): string
{
    ensureUserRoleColumn($conn);

    $stmt = $conn->prepare("SELECT role FROM users WHERE email=? LIMIT 1");
    if (!$stmt) {
        return 'user';
    }

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    $role = strtolower(trim((string) ($row['role'] ?? 'user')));
    return $role === 'admin' ? 'admin' : 'user';
}

function isAdminUser(mysqli $conn, string $email): bool
{
    return getUserRole($conn, $email) === 'admin';
}
