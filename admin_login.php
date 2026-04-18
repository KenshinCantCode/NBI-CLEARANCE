<?php
require_once 'connect.php';
require_once 'auth_helpers.php';

function ensureDefaultAdminAccount(mysqli $conn): array
{
    $result = [
        'created' => false,
        'email' => '',
        'password' => '',
        'warning' => ''
    ];

    $adminCheck = $conn->query("SELECT email FROM users WHERE role='admin' LIMIT 1");
    if ($adminCheck && $adminCheck->num_rows > 0) {
        return $result;
    }

    $defaultEmail = 'admin@kenshin.local';
    $defaultPassword = 'kenshin091306';

    $existingStmt = $conn->prepare("SELECT email FROM users WHERE email=? LIMIT 1");
    if ($existingStmt) {
        $existingStmt->bind_param("s", $defaultEmail);
        $existingStmt->execute();
        $existingResult = $existingStmt->get_result();
        $existingUser = $existingResult->fetch_assoc();
        $existingStmt->close();

        if ($existingUser) {
            $result['warning'] = 'No admin account exists yet, but default admin email already exists as a normal user. Set role=admin in the users table.';
            return $result;
        }
    }

    $firstName = 'System';
    $lastName = 'Admin';
    $role = 'admin';
    $passwordHash = password_hash($defaultPassword, PASSWORD_DEFAULT);

    $insertStmt = $conn->prepare("INSERT INTO users (firstName, lastName, email, password, role) VALUES (?, ?, ?, ?, ?)");
    if (!$insertStmt) {
        $result['warning'] = 'Unable to create default admin account automatically.';
        return $result;
    }

    $insertStmt->bind_param("sssss", $firstName, $lastName, $defaultEmail, $passwordHash, $role);
    if ($insertStmt->execute()) {
        $result['created'] = true;
        $result['email'] = $defaultEmail;
        $result['password'] = $defaultPassword;
    } else {
        $result['warning'] = 'Unable to create default admin account automatically.';
    }
    $insertStmt->close();

    return $result;
}

ensureUserRoleColumn($conn);
$seedInfo = ensureDefaultAdminAccount($conn);

if (isset($_SESSION['email']) && isAdminUser($conn, (string) $_SESSION['email'])) {
    $_SESSION['role'] = 'admin';
    redirectTo('backend.php');
}

$statusType = '';
$statusMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $statusType = 'error';
        $statusMessage = 'Please enter a valid admin email and password.';
    } else {
        $stmt = $conn->prepare("SELECT email, password, role FROM users WHERE email=? LIMIT 1");
        if (!$stmt) {
            $statusType = 'error';
            $statusMessage = 'Unable to process admin sign in right now.';
        } else {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();

            $role = strtolower(trim((string) ($user['role'] ?? 'user')));
            $isValid = $user && $role === 'admin' && verifyPasswordAndUpgrade($conn, (string) $user['email'], $password, (string) $user['password']);

            if (!$isValid) {
                $statusType = 'error';
                $statusMessage = 'Access denied. Invalid admin credentials.';
            } else {
                session_regenerate_id(true);
                $_SESSION['email'] = (string) $user['email'];
                $_SESSION['role'] = 'admin';
                clearPendingLoginVerificationState();

                setFlashMessage('success', 'Admin sign in successful.');
                redirectTo('backend.php');
            }
        }
    }
}

$flash = pullFlashMessage();
if ($flash) {
    $statusType = (string) ($flash['type'] ?? $statusType);
    $statusMessage = (string) ($flash['message'] ?? $statusMessage);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | NBI Clearance Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?php echo (int) (@filemtime(__DIR__ . '/style.css') ?: time()); ?>">
</head>
<body class="auth-page">

        <main class="auth-panel">
            <div class="container">
                <div class="auth-card-brand">
                    <img src="assets/nbi.png" alt="" class="card-logo">
                    <div>
                        <p>NBI Clearance Portal</p>
                    </div>
                </div>
                <h2 class="form-title">Admin Backend Login</h2>

                <?php if ($statusMessage !== ''): ?>
                    <div class="notice notice-<?php echo htmlspecialchars($statusType); ?>">
                        <?php echo htmlspecialchars($statusMessage); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($seedInfo['warning'])): ?>
                    <div class="notice notice-error">
                        <?php echo htmlspecialchars((string) $seedInfo['warning']); ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="admin_login.php">
                    <div class="input-group">
                        <i class="fas fa-envelope"></i>
                        <input type="email" name="email" id="adminEmail" placeholder="Admin Email" required>
                        <label for="adminEmail">Admin Email</label>
                    </div>
                    <div class="input-group">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" id="adminPassword" placeholder="Password" required>
                        <label for="adminPassword">Password</label>
                    </div>
                    <input type="submit" class="btn" value="Sign In as Admin">
                </form>

                <div class="links">
                    <p>Back to user portal?</p>
                    <a class="text-link" href="index.php">User Sign In</a>
                </div>
            </div>
        </main>
    </div>
    <?php include 'footer.php'; ?>
</body>
</html>
