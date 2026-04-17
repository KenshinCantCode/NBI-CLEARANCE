<?php
require_once 'auth_helpers.php';
require_once 'connect.php';

if (!isset($_SESSION['email'])) {
    setFlashMessage('error', 'Please sign in with an admin account.');
    redirectTo('admin_login.php');
}

ensureUserRoleColumn($conn);
$sessionEmail = (string) $_SESSION['email'];
$sessionRole = getUserRole($conn, $sessionEmail);
$_SESSION['role'] = $sessionRole;
if ($sessionRole !== 'admin') {
    setFlashMessage('error', 'Access denied. Admin account required.');
    redirectTo('admin_login.php');
}

function ensureBackendTables(mysqli $conn): void
{
    $queries = [
        "CREATE TABLE IF NOT EXISTS nbi_applications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            first_name VARCHAR(80) NOT NULL,
            middle_name VARCHAR(80) NOT NULL DEFAULT '',
            last_name VARCHAR(80) NOT NULL,
            birthdate DATE NOT NULL,
            gender VARCHAR(20) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            address_line VARCHAR(255) NOT NULL,
            purpose VARCHAR(255) NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'Submitted',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS nbi_appointments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            appointment_date DATE NOT NULL,
            appointment_time TIME NOT NULL,
            location VARCHAR(160) NOT NULL,
            onsite_required TINYINT(1) NOT NULL DEFAULT 1,
            notes VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(40) NOT NULL DEFAULT 'Scheduled',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS nbi_notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            title VARCHAR(160) NOT NULL,
            message TEXT NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'info',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notifications_email_read_created (email, is_read, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS nbi_user_settings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            email_notifications TINYINT(1) NOT NULL DEFAULT 1,
            onsite_notifications TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];

    foreach ($queries as $sql) {
        if (!$conn->query($sql)) {
            throw new RuntimeException('Database setup failed: ' . $conn->error);
        }
    }
}

function backendTab(string $tab): string
{
    $tabs = ['overview', 'applications', 'appointments', 'notifications', 'users'];
    return in_array($tab, $tabs, true) ? $tab : 'overview';
}

function statusClass(string $status): string
{
    $value = strtolower(trim($status));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value ?? '');
    return trim((string) $value, '-');
}

function formatDateTimeValue(string $value, string $pattern): string
{
    $timestamp = strtotime($value);
    if (!$timestamp) {
        return $value;
    }

    return date($pattern, $timestamp);
}

function createBackendNotification(mysqli $conn, string $email, string $title, string $message, string $type = 'info'): void
{
    $stmt = $conn->prepare("INSERT INTO nbi_notifications (email, title, message, type) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param("ssss", $email, $title, $message, $type);
    $stmt->execute();
    $stmt->close();
}

function countRows(mysqli $conn, string $sql): int
{
    $result = $conn->query($sql);
    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();
    return (int) ($row['total'] ?? 0);
}

$viewerEmail = (string) $_SESSION['email'];
$activeTab = backendTab((string) ($_GET['tab'] ?? 'overview'));
$tabTitles = [
    'overview' => 'Backend Overview',
    'applications' => 'Applications Queue',
    'appointments' => 'Appointments Queue',
    'notifications' => 'Notifications Center',
    'users' => 'User Records'
];
$tabDescriptions = [
    'overview' => 'Manage records, updates, and system status.',
    'applications' => 'Review applicant forms and update application status.',
    'appointments' => 'Monitor schedules and update appointment outcomes.',
    'notifications' => 'Create and monitor user notifications.',
    'users' => 'View user-level account and preference data.'
];

$dbReady = true;
$dbSetupError = '';
try {
    ensureBackendTables($conn);
} catch (Throwable $exception) {
    $dbReady = false;
    $dbSetupError = $exception->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbReady) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update_application_status') {
        $applicationId = (int) ($_POST['application_id'] ?? 0);
        $status = trim((string) ($_POST['status'] ?? ''));
        $allowedStatuses = ['Submitted', 'Under Review', 'For Biometrics', 'Approved', 'Rejected'];

        if ($applicationId <= 0 || !in_array($status, $allowedStatuses, true)) {
            setFlashMessage('error', 'Invalid application update request.');
            redirectTo('backend.php?tab=applications');
        }

        $targetEmail = '';
        $emailLookupStmt = $conn->prepare("SELECT email FROM nbi_applications WHERE id=? LIMIT 1");
        if ($emailLookupStmt) {
            $emailLookupStmt->bind_param("i", $applicationId);
            $emailLookupStmt->execute();
            $emailResult = $emailLookupStmt->get_result();
            $targetRow = $emailResult->fetch_assoc();
            if ($targetRow) {
                $targetEmail = (string) $targetRow['email'];
            }
            $emailLookupStmt->close();
        }

        $updateStmt = $conn->prepare("UPDATE nbi_applications SET status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
        if (!$updateStmt) {
            setFlashMessage('error', 'Unable to update application status right now.');
            redirectTo('backend.php?tab=applications');
        }

        $updateStmt->bind_param("si", $status, $applicationId);
        $updateStmt->execute();
        $updateStmt->close();

        if ($targetEmail !== '') {
            createBackendNotification(
                $conn,
                $targetEmail,
                'Application status update',
                'Your application status was updated to "' . $status . '".',
                'info'
            );
        }

        setFlashMessage('success', 'Application status updated.');
        redirectTo('backend.php?tab=applications');
    }

    if ($action === 'update_appointment_status') {
        $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
        $status = trim((string) ($_POST['status'] ?? ''));
        $allowedStatuses = ['Scheduled', 'Completed', 'Missed', 'Rescheduled', 'Cancelled'];

        if ($appointmentId <= 0 || !in_array($status, $allowedStatuses, true)) {
            setFlashMessage('error', 'Invalid appointment update request.');
            redirectTo('backend.php?tab=appointments');
        }

        $targetEmail = '';
        $emailLookupStmt = $conn->prepare("SELECT email FROM nbi_appointments WHERE id=? LIMIT 1");
        if ($emailLookupStmt) {
            $emailLookupStmt->bind_param("i", $appointmentId);
            $emailLookupStmt->execute();
            $emailResult = $emailLookupStmt->get_result();
            $targetRow = $emailResult->fetch_assoc();
            if ($targetRow) {
                $targetEmail = (string) $targetRow['email'];
            }
            $emailLookupStmt->close();
        }

        $updateStmt = $conn->prepare("UPDATE nbi_appointments SET status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
        if (!$updateStmt) {
            setFlashMessage('error', 'Unable to update appointment status right now.');
            redirectTo('backend.php?tab=appointments');
        }

        $updateStmt->bind_param("si", $status, $appointmentId);
        $updateStmt->execute();
        $updateStmt->close();

        if ($targetEmail !== '') {
            createBackendNotification(
                $conn,
                $targetEmail,
                'Appointment status update',
                'Your appointment status is now "' . $status . '".',
                'info'
            );
        }

        setFlashMessage('success', 'Appointment status updated.');
        redirectTo('backend.php?tab=appointments');
    }

    if ($action === 'toggle_notification_read') {
        $notificationId = (int) ($_POST['notification_id'] ?? 0);
        $markRead = (int) ($_POST['mark_read'] ?? 0) === 1 ? 1 : 0;

        if ($notificationId <= 0) {
            setFlashMessage('error', 'Invalid notification request.');
            redirectTo('backend.php?tab=notifications');
        }

        $updateStmt = $conn->prepare("UPDATE nbi_notifications SET is_read=? WHERE id=?");
        if (!$updateStmt) {
            setFlashMessage('error', 'Unable to update notification state.');
            redirectTo('backend.php?tab=notifications');
        }

        $updateStmt->bind_param("ii", $markRead, $notificationId);
        $updateStmt->execute();
        $updateStmt->close();

        setFlashMessage('success', 'Notification state updated.');
        redirectTo('backend.php?tab=notifications');
    }

    if ($action === 'create_notification') {
        $targetEmail = trim((string) ($_POST['target_email'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        $type = trim((string) ($_POST['type'] ?? 'info'));
        $allowedTypes = ['info', 'success', 'warning', 'error'];

        if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL) || $title === '' || $message === '' || !in_array($type, $allowedTypes, true)) {
            setFlashMessage('error', 'Please enter valid notification details.');
            redirectTo('backend.php?tab=notifications');
        }

        createBackendNotification($conn, $targetEmail, $title, $message, $type);
        setFlashMessage('success', 'Notification created successfully.');
        redirectTo('backend.php?tab=notifications');
    }
}

$viewerName = 'Admin';

$viewerStmt = $conn->prepare("SELECT firstName, lastName FROM users WHERE email=? LIMIT 1");
if ($viewerStmt) {
    $viewerStmt->bind_param("s", $viewerEmail);
    $viewerStmt->execute();
    $viewerResult = $viewerStmt->get_result();
    $viewerRow = $viewerResult->fetch_assoc();
    if ($viewerRow) {
        $candidateName = trim((string) ($viewerRow['firstName'] ?? '') . ' ' . (string) ($viewerRow['lastName'] ?? ''));
        if ($candidateName !== '') {
            $viewerName = $candidateName;
        }
    }
    $viewerStmt->close();
}

$flash = pullFlashMessage();
$bodyClass = 'home-page backend-page';

$stats = [
    'users_total' => 0,
    'applications_total' => 0,
    'applications_pending' => 0,
    'appointments_upcoming' => 0,
    'notifications_unread' => 0
];

$recentApplications = [];
$recentAppointments = [];
$recentNotifications = [];
$applicationRows = [];
$appointmentRows = [];
$notificationRows = [];
$userRows = [];
$recipientOptions = [];

if ($dbReady) {
    $stats['users_total'] = countRows($conn, "SELECT COUNT(*) AS total FROM users");
    $stats['applications_total'] = countRows($conn, "SELECT COUNT(*) AS total FROM nbi_applications");
    $stats['applications_pending'] = countRows($conn, "SELECT COUNT(*) AS total FROM nbi_applications WHERE status IN ('Submitted', 'Under Review', 'For Biometrics')");
    $stats['appointments_upcoming'] = countRows($conn, "SELECT COUNT(*) AS total FROM nbi_appointments WHERE appointment_date >= CURDATE() AND status IN ('Scheduled', 'Rescheduled')");
    $stats['notifications_unread'] = countRows($conn, "SELECT COUNT(*) AS total FROM nbi_notifications WHERE is_read=0");

    if ($activeTab === 'overview') {
        $recentAppQuery = $conn->query(
            "SELECT id, email, first_name, last_name, purpose, status, updated_at
             FROM nbi_applications
             ORDER BY updated_at DESC
             LIMIT 8"
        );
        if ($recentAppQuery) {
            while ($row = $recentAppQuery->fetch_assoc()) {
                $recentApplications[] = $row;
            }
        }

        $recentAppointmentQuery = $conn->query(
            "SELECT id, email, appointment_date, appointment_time, location, status
             FROM nbi_appointments
             ORDER BY appointment_date ASC, appointment_time ASC
             LIMIT 8"
        );
        if ($recentAppointmentQuery) {
            while ($row = $recentAppointmentQuery->fetch_assoc()) {
                $recentAppointments[] = $row;
            }
        }

        $recentNotificationQuery = $conn->query(
            "SELECT id, email, title, type, is_read, created_at
             FROM nbi_notifications
             ORDER BY created_at DESC
             LIMIT 8"
        );
        if ($recentNotificationQuery) {
            while ($row = $recentNotificationQuery->fetch_assoc()) {
                $recentNotifications[] = $row;
            }
        }
    }

    if ($activeTab === 'applications') {
        $applicationQuery = $conn->query(
            "SELECT id, email, first_name, middle_name, last_name, purpose, status, updated_at
             FROM nbi_applications
             ORDER BY updated_at DESC
             LIMIT 200"
        );
        if ($applicationQuery) {
            while ($row = $applicationQuery->fetch_assoc()) {
                $applicationRows[] = $row;
            }
        }
    }

    if ($activeTab === 'appointments') {
        $appointmentQuery = $conn->query(
            "SELECT id, email, appointment_date, appointment_time, location, onsite_required, notes, status, updated_at
             FROM nbi_appointments
             ORDER BY appointment_date DESC, appointment_time DESC
             LIMIT 200"
        );
        if ($appointmentQuery) {
            while ($row = $appointmentQuery->fetch_assoc()) {
                $appointmentRows[] = $row;
            }
        }
    }

    if ($activeTab === 'notifications') {
        $notificationQuery = $conn->query(
            "SELECT id, email, title, message, type, is_read, created_at
             FROM nbi_notifications
             ORDER BY created_at DESC
             LIMIT 300"
        );
        if ($notificationQuery) {
            while ($row = $notificationQuery->fetch_assoc()) {
                $notificationRows[] = $row;
            }
        }

        $recipientQuery = $conn->query("SELECT email, firstName, lastName FROM users ORDER BY firstName ASC, lastName ASC");
        if ($recipientQuery) {
            while ($row = $recipientQuery->fetch_assoc()) {
                $recipientOptions[] = $row;
            }
        }
    }

    if ($activeTab === 'users') {
        $userQuery = $conn->query(
            "SELECT
                u.email,
                u.firstName,
                u.lastName,
                COALESCE(a.status, 'No Application') AS application_status,
                COALESCE(ap.status, 'No Appointment') AS appointment_status,
                COALESCE(s.email_notifications, 1) AS email_notifications,
                COALESCE(s.onsite_notifications, 1) AS onsite_notifications
             FROM users u
             LEFT JOIN nbi_applications a ON a.email=u.email
             LEFT JOIN nbi_appointments ap ON ap.email=u.email
             LEFT JOIN nbi_user_settings s ON s.email=u.email
             ORDER BY u.firstName ASC, u.lastName ASC
             LIMIT 300"
        );
        if ($userQuery) {
            while ($row = $userQuery->fetch_assoc()) {
                $userRows[] = $row;
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
    <title>Backend Operations | NBI Clearance</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?php echo (int) (@filemtime(__DIR__ . '/style.css') ?: time()); ?>">
</head>
<body class="<?php echo htmlspecialchars($bodyClass); ?>">
    <div class="home-layout">
        <aside class="sidebar" id="sidebar" aria-hidden="true">
            <div class="sidebar-brand">
                <img src="assets/nbi.png" alt="NBI Clearance Portal logo" class="sidebar-brand-mark">
                <div class="sidebar-brand-copy">
                    <p>NBI Clearance</p>
                    <span>Backend Operations Center</span>
                </div>
            </div>

            <nav class="sidebar-nav">
                <a href="homepage.php?tab=dashboard" data-nav-link><i class="fa-solid fa-house"></i> Portal Home</a>
                <a href="backend.php?tab=overview" data-nav-link class="<?php echo $activeTab === 'overview' ? 'active' : ''; ?>"><i class="fa-solid fa-chart-line"></i> Overview</a>
                <a href="backend.php?tab=applications" data-nav-link class="<?php echo $activeTab === 'applications' ? 'active' : ''; ?>"><i class="fa-solid fa-file-signature"></i> Applications</a>
                <a href="backend.php?tab=appointments" data-nav-link class="<?php echo $activeTab === 'appointments' ? 'active' : ''; ?>"><i class="fa-solid fa-calendar-check"></i> Appointments</a>
                <a href="backend.php?tab=notifications" data-nav-link class="<?php echo $activeTab === 'notifications' ? 'active' : ''; ?>"><i class="fa-solid fa-bell"></i> Notifications</a>
                <a href="backend.php?tab=users" data-nav-link class="<?php echo $activeTab === 'users' ? 'active' : ''; ?>"><i class="fa-solid fa-users"></i> Users</a>
            </nav>

            <a class="logout-link" href="logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
        </aside>

        <div class="overlay" id="overlay"></div>

        <main class="main-content">
            <header class="topbar">
                <button id="menuToggle" class="menu-toggle" type="button" aria-label="Open sidebar" aria-controls="sidebar" aria-expanded="false">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <div class="topbar-title">
                    <h1><?php echo htmlspecialchars($tabTitles[$activeTab]); ?></h1>
                    <p><?php echo htmlspecialchars($tabDescriptions[$activeTab]); ?> Signed in as <?php echo htmlspecialchars($viewerName); ?>.</p>
                </div>
                <div class="topbar-right">
                    <span class="status-pill"><i class="fa-solid fa-circle"></i> Official Backend</span>
                    <div class="user-chip"><?php echo strtoupper(substr($viewerName, 0, 1)); ?></div>
                </div>
            </header>

            <?php if ($flash): ?>
                <div class="notice notice-<?php echo htmlspecialchars((string) $flash['type']); ?>">
                    <?php echo htmlspecialchars((string) $flash['message']); ?>
                </div>
            <?php endif; ?>

            <?php if (!$dbReady): ?>
                <div class="notice notice-error">
                    Unable to initialize backend tables. <?php echo htmlspecialchars($dbSetupError); ?>
                </div>
            <?php endif; ?>

            <?php if ($activeTab === 'overview'): ?>
                <section class="cards-grid backend-metrics">
                    <article class="stat-card">
                        <i class="fa-solid fa-users card-icon"></i>
                        <p class="card-label">Total Users</p>
                        <h3><?php echo (int) $stats['users_total']; ?></h3>
                        <span class="card-meta">Registered accounts</span>
                    </article>
                    <article class="stat-card">
                        <i class="fa-solid fa-file-lines card-icon"></i>
                        <p class="card-label">Applications</p>
                        <h3><?php echo (int) $stats['applications_total']; ?></h3>
                        <span class="card-meta"><?php echo (int) $stats['applications_pending']; ?> pending review</span>
                    </article>
                    <article class="stat-card">
                        <i class="fa-solid fa-calendar-check card-icon"></i>
                        <p class="card-label">Upcoming Appointments</p>
                        <h3><?php echo (int) $stats['appointments_upcoming']; ?></h3>
                        <span class="card-meta">Scheduled or rescheduled</span>
                    </article>
                    <article class="stat-card">
                        <i class="fa-solid fa-bell card-icon"></i>
                        <p class="card-label">Unread Notifications</p>
                        <h3><?php echo (int) $stats['notifications_unread']; ?></h3>
                        <span class="card-meta">Pending user alerts that still need attention</span>
                    </article>
                </section>

                <section class="backend-panels">
                    <article class="table-card">
                        <div class="table-head">
                            <h2>Recent Applications</h2>
                            <a href="backend.php?tab=applications">Open queue</a>
                        </div>
                        <?php if (empty($recentApplications)): ?>
                            <p class="empty-note">No application records available.</p>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="data-table data-table-compact">
                                    <thead>
                                        <tr>
                                            <th>Applicant</th>
                                            <th>Status</th>
                                            <th>Updated</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentApplications as $row): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars(trim((string) $row['first_name'] . ' ' . (string) $row['last_name'])); ?><br><span class="cell-meta"><?php echo htmlspecialchars((string) $row['email']); ?></span></td>
                                                <td><span class="status-chip status-<?php echo htmlspecialchars(statusClass((string) $row['status'])); ?>"><?php echo htmlspecialchars((string) $row['status']); ?></span></td>
                                                <td><?php echo htmlspecialchars(formatDateTimeValue((string) $row['updated_at'], 'M d, Y h:i A')); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </article>

                    <article class="table-card">
                        <div class="table-head">
                            <h2>Recent Appointments</h2>
                            <a href="backend.php?tab=appointments">Open queue</a>
                        </div>
                        <?php if (empty($recentAppointments)): ?>
                            <p class="empty-note">No appointment records available.</p>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="data-table data-table-compact">
                                    <thead>
                                        <tr>
                                            <th>Email</th>
                                            <th>Schedule</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentAppointments as $row): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string) $row['email']); ?></td>
                                                <td><?php echo htmlspecialchars(formatDateTimeValue((string) $row['appointment_date'], 'M d, Y')); ?> at <?php echo htmlspecialchars(formatDateTimeValue((string) $row['appointment_time'], 'h:i A')); ?></td>
                                                <td><span class="status-chip status-<?php echo htmlspecialchars(statusClass((string) $row['status'])); ?>"><?php echo htmlspecialchars((string) $row['status']); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </article>
                </section>

                <section class="table-card">
                    <div class="table-head">
                        <h2>Latest Notifications</h2>
                        <a href="backend.php?tab=notifications">Open center</a>
                    </div>
                    <?php if (empty($recentNotifications)): ?>
                        <p class="empty-note">No notifications yet.</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table data-table-compact">
                                <thead>
                                    <tr>
                                        <th>Email</th>
                                        <th>Title</th>
                                        <th>Type</th>
                                        <th>Read</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentNotifications as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string) $row['email']); ?></td>
                                            <td><?php echo htmlspecialchars((string) $row['title']); ?></td>
                                            <td><span class="status-chip status-<?php echo htmlspecialchars(statusClass((string) $row['type'])); ?>"><?php echo htmlspecialchars((string) ucfirst((string) $row['type'])); ?></span></td>
                                            <td><?php echo !empty($row['is_read']) ? 'Yes' : 'No'; ?></td>
                                            <td><?php echo htmlspecialchars(formatDateTimeValue((string) $row['created_at'], 'M d, Y h:i A')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($activeTab === 'applications'): ?>
                <section class="table-card">
                    <div class="table-head">
                        <h2>Application Queue</h2>
                        <span><?php echo count($applicationRows); ?> records</span>
                    </div>

                    <?php if (empty($applicationRows)): ?>
                        <p class="empty-note">No applications found yet.</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Applicant</th>
                                        <th>Email</th>
                                        <th>Purpose</th>
                                        <th>Status</th>
                                        <th>Updated</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($applicationRows as $row): ?>
                                        <tr>
                                            <td><?php echo (int) $row['id']; ?></td>
                                            <td><?php echo htmlspecialchars(trim((string) $row['first_name'] . ' ' . (string) $row['last_name'])); ?></td>
                                            <td><?php echo htmlspecialchars((string) $row['email']); ?></td>
                                            <td><?php echo htmlspecialchars((string) $row['purpose']); ?></td>
                                            <td><span class="status-chip status-<?php echo htmlspecialchars(statusClass((string) $row['status'])); ?>"><?php echo htmlspecialchars((string) $row['status']); ?></span></td>
                                            <td><?php echo htmlspecialchars(formatDateTimeValue((string) $row['updated_at'], 'M d, Y h:i A')); ?></td>
                                            <td>
                                                <form method="post" action="backend.php?tab=applications" class="inline-form">
                                                    <input type="hidden" name="action" value="update_application_status">
                                                    <input type="hidden" name="application_id" value="<?php echo (int) $row['id']; ?>">
                                                    <select name="status" class="status-select">
                                                        <?php foreach (['Submitted', 'Under Review', 'For Biometrics', 'Approved', 'Rejected'] as $status): ?>
                                                            <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $status === $row['status'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($status); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="ghost-btn primary-small">Save</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($activeTab === 'appointments'): ?>
                <section class="table-card">
                    <div class="table-head">
                        <h2>Appointment Queue</h2>
                        <span><?php echo count($appointmentRows); ?> records</span>
                    </div>

                    <?php if (empty($appointmentRows)): ?>
                        <p class="empty-note">No appointments found yet.</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Email</th>
                                        <th>Schedule</th>
                                        <th>Location</th>
                                        <th>On-site</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($appointmentRows as $row): ?>
                                        <tr>
                                            <td><?php echo (int) $row['id']; ?></td>
                                            <td><?php echo htmlspecialchars((string) $row['email']); ?></td>
                                            <td><?php echo htmlspecialchars(formatDateTimeValue((string) $row['appointment_date'], 'M d, Y')); ?> at <?php echo htmlspecialchars(formatDateTimeValue((string) $row['appointment_time'], 'h:i A')); ?></td>
                                            <td><?php echo htmlspecialchars((string) $row['location']); ?></td>
                                            <td><?php echo (int) $row['onsite_required'] === 1 ? 'Required' : 'Not required'; ?></td>
                                            <td><span class="status-chip status-<?php echo htmlspecialchars(statusClass((string) $row['status'])); ?>"><?php echo htmlspecialchars((string) $row['status']); ?></span></td>
                                            <td>
                                                <form method="post" action="backend.php?tab=appointments" class="inline-form">
                                                    <input type="hidden" name="action" value="update_appointment_status">
                                                    <input type="hidden" name="appointment_id" value="<?php echo (int) $row['id']; ?>">
                                                    <select name="status" class="status-select">
                                                        <?php foreach (['Scheduled', 'Completed', 'Missed', 'Rescheduled', 'Cancelled'] as $status): ?>
                                                            <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $status === $row['status'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($status); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="ghost-btn primary-small">Save</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($activeTab === 'notifications'): ?>
                <section class="table-card">
                    <div class="table-head">
                        <h2>Create Notification</h2>
                        <span>Send update to any user</span>
                    </div>
                    <form method="post" action="backend.php?tab=notifications" class="backend-form">
                        <input type="hidden" name="action" value="create_notification">
                        <div class="backend-form-grid">
                            <div class="field">
                                <label for="target_email">Recipient</label>
                                <select id="target_email" name="target_email" required>
                                    <option value="">Select recipient</option>
                                    <?php foreach ($recipientOptions as $recipient): ?>
                                        <?php
                                            $fullName = trim((string) ($recipient['firstName'] ?? '') . ' ' . (string) ($recipient['lastName'] ?? ''));
                                            $emailLabel = (string) ($recipient['email'] ?? '');
                                        ?>
                                        <option value="<?php echo htmlspecialchars($emailLabel); ?>">
                                            <?php echo htmlspecialchars($fullName !== '' ? $fullName . ' - ' . $emailLabel : $emailLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="notification_type">Type</label>
                                <select id="notification_type" name="type" required>
                                    <option value="info">Info</option>
                                    <option value="success">Success</option>
                                    <option value="warning">Warning</option>
                                    <option value="error">Error</option>
                                </select>
                            </div>
                        </div>
                        <div class="field">
                            <label for="notification_title">Title</label>
                            <input type="text" id="notification_title" name="title" maxlength="160" required>
                        </div>
                        <div class="field">
                            <label for="notification_message">Message</label>
                            <textarea id="notification_message" name="message" rows="3" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-inline">Send Notification</button>
                    </form>
                </section>

                <section class="table-card">
                    <div class="table-head">
                        <h2>Notification Records</h2>
                        <span><?php echo count($notificationRows); ?> records</span>
                    </div>
                    <?php if (empty($notificationRows)): ?>
                        <p class="empty-note">No notification records found.</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Email</th>
                                        <th>Title</th>
                                        <th>Message</th>
                                        <th>Type</th>
                                        <th>Read</th>
                                        <th>Date</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($notificationRows as $row): ?>
                                        <tr>
                                            <td><?php echo (int) $row['id']; ?></td>
                                            <td><?php echo htmlspecialchars((string) $row['email']); ?></td>
                                            <td><?php echo htmlspecialchars((string) $row['title']); ?></td>
                                            <td><?php echo htmlspecialchars((string) $row['message']); ?></td>
                                            <td><span class="status-chip status-<?php echo htmlspecialchars(statusClass((string) $row['type'])); ?>"><?php echo htmlspecialchars(ucfirst((string) $row['type'])); ?></span></td>
                                            <td><?php echo !empty($row['is_read']) ? 'Yes' : 'No'; ?></td>
                                            <td><?php echo htmlspecialchars(formatDateTimeValue((string) $row['created_at'], 'M d, Y h:i A')); ?></td>
                                            <td>
                                                <form method="post" action="backend.php?tab=notifications" class="inline-form">
                                                    <input type="hidden" name="action" value="toggle_notification_read">
                                                    <input type="hidden" name="notification_id" value="<?php echo (int) $row['id']; ?>">
                                                    <input type="hidden" name="mark_read" value="<?php echo !empty($row['is_read']) ? 0 : 1; ?>">
                                                    <button type="submit" class="ghost-btn"><?php echo !empty($row['is_read']) ? 'Mark Unread' : 'Mark Read'; ?></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($activeTab === 'users'): ?>
                <section class="table-card">
                    <div class="table-head">
                        <h2>User Records</h2>
                        <span><?php echo count($userRows); ?> records</span>
                    </div>

                    <?php if (empty($userRows)): ?>
                        <p class="empty-note">No users found.</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Application</th>
                                        <th>Appointment</th>
                                        <th>Email Notif</th>
                                        <th>On-site Notif</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($userRows as $row): ?>
                                        <?php $fullName = trim((string) ($row['firstName'] ?? '') . ' ' . (string) ($row['lastName'] ?? '')); ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($fullName !== '' ? $fullName : 'User'); ?></td>
                                            <td><?php echo htmlspecialchars((string) $row['email']); ?></td>
                                            <td><span class="status-chip status-<?php echo htmlspecialchars(statusClass((string) $row['application_status'])); ?>"><?php echo htmlspecialchars((string) $row['application_status']); ?></span></td>
                                            <td><span class="status-chip status-<?php echo htmlspecialchars(statusClass((string) $row['appointment_status'])); ?>"><?php echo htmlspecialchars((string) $row['appointment_status']); ?></span></td>
                                            <td><?php echo (int) $row['email_notifications'] === 1 ? 'On' : 'Off'; ?></td>
                                            <td><?php echo (int) $row['onsite_notifications'] === 1 ? 'On' : 'Off'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </main>
    </div>
    <script src="script.js?v=<?php echo (int) (@filemtime(__DIR__ . '/script.js') ?: time()); ?>"></script>
</body>
<div class="footer">
    <div class="footer-container">
        <p>@2026 NBI Clearance. All Right Reserved</p>
        <p>Contact Us</p>
    </div>
</html>
