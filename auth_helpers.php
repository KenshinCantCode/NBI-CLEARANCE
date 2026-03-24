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

function ensureUserRoleColumn(mysqli $conn): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $checked = true;
    $columnCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
    if ($columnCheck && $columnCheck->num_rows > 0) {
        return;
    }

    $conn->query("ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'user' AFTER password");
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
