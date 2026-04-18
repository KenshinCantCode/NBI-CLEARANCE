<?php
require_once 'auth_helpers.php';
require_once 'connect.php';

if (!isset($_SESSION['email'])) {
    setFlashMessage('error', 'Please sign in first.');
    redirectTo('index.php');
}

function ensureHomepageTables(mysqli $conn): void
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

function createNotification(mysqli $conn, string $email, string $title, string $message, string $type = 'info'): void
{
    $stmt = $conn->prepare("INSERT INTO nbi_notifications (email, title, message, type) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param("ssss", $email, $title, $message, $type);
    $stmt->execute();
    $stmt->close();
}

function formatDateLabel(?string $dateValue, ?string $timeValue = null): string
{
    if (!$dateValue) {
        return 'Pending';
    }

    $dateTimestamp = strtotime($dateValue);
    if (!$dateTimestamp) {
        return $dateValue;
    }

    $formatted = date('M d, Y', $dateTimestamp);
    if ($timeValue) {
        $timeTimestamp = strtotime($timeValue);
        if ($timeTimestamp) {
            $formatted .= ' at ' . date('h:i A', $timeTimestamp);
        }
    }

    return $formatted;
}

function toTabOrDashboard(string $tab): string
{
    $validTabs = ['dashboard', 'application', 'appointment', 'notifications', 'settings'];
    return in_array($tab, $validTabs, true) ? $tab : 'dashboard';
}

function appointmentLocationOptions(): array
{
    return [
        'NBI PAVIA',
        'NBI SAN FELIX',
        'NBI FORT SAN PEDRO'
    ];
}

function normalizeAppointmentLocation(string $value): string
{
    return strtoupper(preg_replace('/\s+/', ' ', trim($value)));
}

function resolveAppointmentLocation(string $value): string
{
    $normalizedValue = normalizeAppointmentLocation($value);
    if ($normalizedValue === '') {
        return '';
    }

    foreach (appointmentLocationOptions() as $option) {
        if (normalizeAppointmentLocation($option) === $normalizedValue) {
            return $option;
        }
    }

    return '';
}

ensureUserRoleColumn($conn);
$email = (string) $_SESSION['email'];
$activeTab = toTabOrDashboard((string) ($_GET['tab'] ?? 'dashboard'));
$currentRole = getUserRole($conn, $email);
$_SESSION['role'] = $currentRole;
$canAccessBackend = $currentRole === 'admin';

$dbReady = true;
$dbSetupError = '';
try {
    ensureHomepageTables($conn);
} catch (Throwable $exception) {
    $dbReady = false;
    $dbSetupError = $exception->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbReady) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_application') {
        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $middleName = trim((string) ($_POST['middle_name'] ?? ''));
        $lastName = trim((string) ($_POST['last_name'] ?? ''));
        $birthdate = trim((string) ($_POST['birthdate'] ?? ''));
        $gender = trim((string) ($_POST['gender'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $addressLine = trim((string) ($_POST['address_line'] ?? ''));
        $purpose = trim((string) ($_POST['purpose'] ?? ''));

        $birthDateObj = DateTime::createFromFormat('Y-m-d', $birthdate);
        $birthDateValid = $birthDateObj && $birthDateObj->format('Y-m-d') === $birthdate;
        $allowedGenders = ['Male', 'Female', 'Other'];

        if (
            $firstName === '' ||
            $lastName === '' ||
            !$birthDateValid ||
            $birthdate >= date('Y-m-d') ||
            !in_array($gender, $allowedGenders, true) ||
            $phone === '' ||
            $addressLine === '' ||
            $purpose === ''
        ) {
            setFlashMessage('error', 'Please complete all required application fields with valid values.');
            redirectTo('homepage.php?tab=application');
        }

        $status = 'Submitted';
        $saveStmt = $conn->prepare(
            "INSERT INTO nbi_applications
                (email, first_name, middle_name, last_name, birthdate, gender, phone, address_line, purpose, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                first_name=VALUES(first_name),
                middle_name=VALUES(middle_name),
                last_name=VALUES(last_name),
                birthdate=VALUES(birthdate),
                gender=VALUES(gender),
                phone=VALUES(phone),
                address_line=VALUES(address_line),
                purpose=VALUES(purpose),
                status=VALUES(status),
                updated_at=CURRENT_TIMESTAMP"
        );

        if (!$saveStmt) {
            setFlashMessage('error', 'Unable to save application right now.');
            redirectTo('homepage.php?tab=application');
        }

        $saveStmt->bind_param(
            "ssssssssss",
            $email,
            $firstName,
            $middleName,
            $lastName,
            $birthdate,
            $gender,
            $phone,
            $addressLine,
            $purpose,
            $status
        );
        $saveStmt->execute();
        $saveStmt->close();

        createNotification(
            $conn,
            $email,
            'Application updated',
            'Your application details were saved successfully.',
            'success'
        );

        setFlashMessage('success', 'Application details saved.');
        redirectTo('homepage.php?tab=application');
    }

    if ($action === 'save_appointment') {
        $appointmentDate = trim((string) ($_POST['appointment_date'] ?? ''));
        $appointmentTime = trim((string) ($_POST['appointment_time'] ?? ''));
        $location = resolveAppointmentLocation((string) ($_POST['location'] ?? ''));
        $onsiteRequired = isset($_POST['onsite_required']) ? 1 : 0;
        $notes = trim((string) ($_POST['notes'] ?? ''));

        $dateObj = DateTime::createFromFormat('Y-m-d', $appointmentDate);
        $timeObj = DateTime::createFromFormat('H:i', $appointmentTime);
        $dateValid = $dateObj && $dateObj->format('Y-m-d') === $appointmentDate;
        $timeValid = $timeObj && $timeObj->format('H:i') === $appointmentTime;

        if (!$dateValid || !$timeValid || $location === '') {
            setFlashMessage('error', 'Please enter a valid appointment date, time, and location.');
            redirectTo('homepage.php?tab=appointment');
        }

        if ($appointmentDate < date('Y-m-d')) {
            setFlashMessage('error', 'Appointment date must be today or a future date.');
            redirectTo('homepage.php?tab=appointment');
        }

        $weekDayNumber = (int) date('N', strtotime($appointmentDate));
        if ($weekDayNumber >= 6) {
            setFlashMessage('error', 'Saturday and Sunday are not available for appointments.');
            redirectTo('homepage.php?tab=appointment');
        }

        $timeHour = (int) $timeObj->format('H');
        $timeMinute = (int) $timeObj->format('i');
        $timeTotalMinutes = ($timeHour * 60) + $timeMinute;
        if ($timeTotalMinutes < 540 || $timeTotalMinutes > 1020) {
            setFlashMessage('error', 'Available appointment time is only from 9:00 AM to 5:00 PM.');
            redirectTo('homepage.php?tab=appointment');
        }

        $appointmentTimeSql = $timeObj->format('H:i:s');
        $status = 'Scheduled';

        $saveStmt = $conn->prepare(
            "INSERT INTO nbi_appointments
                (email, appointment_date, appointment_time, location, onsite_required, notes, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                appointment_date=VALUES(appointment_date),
                appointment_time=VALUES(appointment_time),
                location=VALUES(location),
                onsite_required=VALUES(onsite_required),
                notes=VALUES(notes),
                status=VALUES(status),
                updated_at=CURRENT_TIMESTAMP"
        );

        if (!$saveStmt) {
            setFlashMessage('error', 'Unable to save appointment right now.');
            redirectTo('homepage.php?tab=appointment');
        }

        $saveStmt->bind_param(
            "ssssiss",
            $email,
            $appointmentDate,
            $appointmentTimeSql,
            $location,
            $onsiteRequired,
            $notes,
            $status
        );
        $saveStmt->execute();
        $saveStmt->close();

        $settingsOnsiteNotifications = 1;
        $settingsStmt = $conn->prepare("SELECT onsite_notifications FROM nbi_user_settings WHERE email=? LIMIT 1");
        if ($settingsStmt) {
            $settingsStmt->bind_param("s", $email);
            $settingsStmt->execute();
            $settingsResult = $settingsStmt->get_result();
            $settingsRow = $settingsResult->fetch_assoc();
            if ($settingsRow && isset($settingsRow['onsite_notifications'])) {
                $settingsOnsiteNotifications = (int) $settingsRow['onsite_notifications'];
            }
            $settingsStmt->close();
        }

        if ($settingsOnsiteNotifications === 1) {
            $onsiteMessage = $onsiteRequired
                ? 'Please go on-site on ' . formatDateLabel($appointmentDate, $appointmentTimeSql) . ' at ' . $location . '.'
                : 'Your appointment was scheduled for ' . formatDateLabel($appointmentDate, $appointmentTimeSql) . ' at ' . $location . '.';
            createNotification($conn, $email, 'Appointment scheduled', $onsiteMessage, 'info');
        }

        setFlashMessage('success', 'Appointment scheduled successfully.');
        redirectTo('homepage.php?tab=appointment');
    }

    if ($action === 'cancel_appointment') {
        $deleteStmt = $conn->prepare("DELETE FROM nbi_appointments WHERE email=?");
        if (!$deleteStmt) {
            setFlashMessage('error', 'Unable to cancel appointment right now.');
            redirectTo('homepage.php?tab=appointment');
        }

        $deleteStmt->bind_param("s", $email);
        $deleteStmt->execute();
        $deletedRows = $deleteStmt->affected_rows;
        $deleteStmt->close();

        if ($deletedRows > 0) {
            createNotification(
                $conn,
                $email,
                'Appointment cancelled',
                'Your appointment was cancelled successfully. You can schedule a new one anytime.',
                'warning'
            );
            setFlashMessage('success', 'Appointment cancelled.');
        } else {
            setFlashMessage('info', 'No active appointment found to cancel.');
        }
        redirectTo('homepage.php?tab=appointment');
    }

    if ($action === 'mark_notification_read') {
        $notificationId = (int) ($_POST['notification_id'] ?? 0);
        if ($notificationId > 0) {
            $markStmt = $conn->prepare("UPDATE nbi_notifications SET is_read=1 WHERE id=? AND email=?");
            if ($markStmt) {
                $markStmt->bind_param("is", $notificationId, $email);
                $markStmt->execute();
                $markStmt->close();
            }
        }

        redirectTo('homepage.php?tab=notifications');
    }

    if ($action === 'delete_notification') {
        $notificationId = (int) ($_POST['notification_id'] ?? 0);
        if ($notificationId > 0) {
            $deleteStmt = $conn->prepare("DELETE FROM nbi_notifications WHERE id=? AND email=?");
            if ($deleteStmt) {
                $deleteStmt->bind_param("is", $notificationId, $email);
                $deleteStmt->execute();
                $deleteStmt->close();
            }
        }
        setFlashMessage('success', 'Notification deleted.');
        redirectTo('homepage.php?tab=notifications');
    }

    if ($action === 'save_settings') {
        $emailNotifications = isset($_POST['email_notifications']) ? 1 : 0;
        $onsiteNotifications = isset($_POST['onsite_notifications']) ? 1 : 0;

        $saveStmt = $conn->prepare(
            "INSERT INTO nbi_user_settings (email, email_notifications, onsite_notifications)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                email_notifications=VALUES(email_notifications),
                onsite_notifications=VALUES(onsite_notifications),
                updated_at=CURRENT_TIMESTAMP"
        );

        if (!$saveStmt) {
            setFlashMessage('error', 'Unable to save settings right now.');
            redirectTo('homepage.php?tab=settings');
        }

        $saveStmt->bind_param("sii", $email, $emailNotifications, $onsiteNotifications);
        $saveStmt->execute();
        $saveStmt->close();

        setFlashMessage('success', 'Settings updated.');
        redirectTo('homepage.php?tab=settings');
    }

    if ($action === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            setFlashMessage('error', 'All password fields are required.');
            redirectTo('homepage.php?tab=settings');
        }

        if ($newPassword !== $confirmPassword) {
            setFlashMessage('error', 'New passwords do not match.');
            redirectTo('homepage.php?tab=settings');
        }

        if (strlen($newPassword) < 8) {
            setFlashMessage('error', 'New password must be at least 8 characters long.');
            redirectTo('homepage.php?tab=settings');
        }

        $stmt = $conn->prepare("SELECT password FROM users WHERE email=? LIMIT 1");
        if (!$stmt) {
            setFlashMessage('error', 'Database error occurred.');
            redirectTo('homepage.php?tab=settings');
        }

        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            setFlashMessage('error', 'User not found.');
            redirectTo('homepage.php?tab=settings');
        }

        if (!password_verify($currentPassword, $user['password'])) {
            setFlashMessage('error', 'Current password is incorrect.');
            redirectTo('homepage.php?tab=settings');
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateStmt = $conn->prepare("UPDATE users SET password=? WHERE email=?");
        if (!$updateStmt) {
            setFlashMessage('error', 'Unable to update password right now.');
            redirectTo('homepage.php?tab=settings');
        }

        $updateStmt->bind_param("ss", $hashedPassword, $email);
        $updateStmt->execute();
        $updateStmt->close();

        setFlashMessage('success', 'Password changed successfully.');
        redirectTo('homepage.php?tab=settings');
    }
}

$fullName = 'User';
$firstNameFromUser = '';
$lastNameFromUser = '';
$userStmt = $conn->prepare("SELECT firstName, lastName FROM users WHERE email=? LIMIT 1");
if ($userStmt) {
    $userStmt->bind_param("s", $email);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    if ($row = $userResult->fetch_assoc()) {
        $firstNameFromUser = (string) ($row['firstName'] ?? '');
        $lastNameFromUser = (string) ($row['lastName'] ?? '');
        $fullName = trim($firstNameFromUser . ' ' . $lastNameFromUser);
        if ($fullName === '') {
            $fullName = 'User';
        }
    }
    $userStmt->close();
}

$applicationData = [];
$appointmentData = [];
$notifications = [];
$settings = [
    'email_notifications' => 1,
    'onsite_notifications' => 1
];
$unreadNotifications = 0;

if ($dbReady) {
    $applicationStmt = $conn->prepare("SELECT * FROM nbi_applications WHERE email=? LIMIT 1");
    if ($applicationStmt) {
        $applicationStmt->bind_param("s", $email);
        $applicationStmt->execute();
        $applicationResult = $applicationStmt->get_result();
        $applicationData = $applicationResult->fetch_assoc() ?: [];
        $applicationStmt->close();
    }

    $appointmentStmt = $conn->prepare("SELECT * FROM nbi_appointments WHERE email=? LIMIT 1");
    if ($appointmentStmt) {
        $appointmentStmt->bind_param("s", $email);
        $appointmentStmt->execute();
        $appointmentResult = $appointmentStmt->get_result();
        $appointmentData = $appointmentResult->fetch_assoc() ?: [];
        $appointmentStmt->close();
    }

    $settingsStmt = $conn->prepare("SELECT email_notifications, onsite_notifications FROM nbi_user_settings WHERE email=? LIMIT 1");
    if ($settingsStmt) {
        $settingsStmt->bind_param("s", $email);
        $settingsStmt->execute();
        $settingsResult = $settingsStmt->get_result();
        $settingsRow = $settingsResult->fetch_assoc();
        if ($settingsRow) {
            $settings = array_merge($settings, $settingsRow);
        }
        $settingsStmt->close();
    }

    $notificationsStmt = $conn->prepare("SELECT id, title, message, type, is_read, created_at FROM nbi_notifications WHERE email=? ORDER BY created_at DESC LIMIT 20");
    if ($notificationsStmt) {
        $notificationsStmt->bind_param("s", $email);
        $notificationsStmt->execute();
        $notificationsResult = $notificationsStmt->get_result();
        while ($notification = $notificationsResult->fetch_assoc()) {
            $notifications[] = $notification;
            if (empty($notification['is_read'])) {
                $unreadNotifications++;
            }
        }
        $notificationsStmt->close();
    }
}

$flash = pullFlashMessage();
$tabTitles = [
    'dashboard' => 'Dashboard',
    'application' => 'Application',
    'appointment' => 'Appointment',
    'notifications' => 'Notifications',
    'settings' => 'Settings'
];
$tabDescriptions = [
    'dashboard' => 'Welcome back, ' . $fullName,
    'application' => 'Complete or update your application details.',
    'appointment' => 'Choose your preferred schedule for processing.',
    'notifications' => 'Track reminders and important updates.',
    'settings' => 'Manage notification preferences and account security.'
];
$profileStatus = !empty($applicationData) ? 'Ready' : 'Needs details';
$applicationStatus = !empty($applicationData['status']) ? (string) $applicationData['status'] : 'Not Started';
$appointmentHeadline = !empty($appointmentData) ? 'Scheduled' : 'Pending';
$appointmentMeta = !empty($appointmentData)
    ? formatDateLabel((string) $appointmentData['appointment_date'], (string) $appointmentData['appointment_time'])
    : 'No appointment selected';


$applicationFirstName = (string) ($applicationData['first_name'] ?? $firstNameFromUser);
$applicationMiddleName = (string) ($applicationData['middle_name'] ?? '');
$applicationLastName = (string) ($applicationData['last_name'] ?? $lastNameFromUser);
$applicationBirthdate = (string) ($applicationData['birthdate'] ?? '');
$applicationGender = (string) ($applicationData['gender'] ?? '');
$applicationPhone = (string) ($applicationData['phone'] ?? '');
$applicationAddress = (string) ($applicationData['address_line'] ?? '');
$applicationPurpose = (string) ($applicationData['purpose'] ?? '');

$appointmentDateValue = (string) ($appointmentData['appointment_date'] ?? '');
$appointmentTimeValue = (string) ($appointmentData['appointment_time'] ?? '');
if ($appointmentTimeValue !== '') {
    $appointmentTimeValue = substr($appointmentTimeValue, 0, 5);
}
$appointmentLocationValue = resolveAppointmentLocation((string) ($appointmentData['location'] ?? ''));
$appointmentNotesValue = (string) ($appointmentData['notes'] ?? '');
$appointmentOnsite = !isset($appointmentData['onsite_required']) || (int) $appointmentData['onsite_required'] === 1;
$appointmentLocationOptions = appointmentLocationOptions();
$bodyClass = 'home-page';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Citizen Portal | NBI Clearance</title>
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
                    <span>Citizen Services Portal</span>
                </div>
            </div>

            <nav class="sidebar-nav">
                <a href="homepage.php?tab=dashboard" data-nav-link class="<?php echo $activeTab === 'dashboard' ? 'active' : ''; ?>"><i class="fa-solid fa-house"></i> Home</a>
                <a href="homepage.php?tab=application" data-nav-link class="<?php echo $activeTab === 'application' ? 'active' : ''; ?>"><i class="fa-solid fa-file-lines"></i> Application</a>
                <a href="homepage.php?tab=appointment" data-nav-link class="<?php echo $activeTab === 'appointment' ? 'active' : ''; ?>"><i class="fa-solid fa-calendar-check"></i> Appointment</a>
                <a href="homepage.php?tab=notifications" data-nav-link class="<?php echo $activeTab === 'notifications' ? 'active' : ''; ?>"><i class="fa-solid fa-bell"></i> Notifications<?php if ($unreadNotifications > 0): ?> (<?php echo (int) $unreadNotifications; ?>)<?php endif; ?></a>
                <a href="homepage.php?tab=settings" data-nav-link class="<?php echo $activeTab === 'settings' ? 'active' : ''; ?>"><i class="fa-solid fa-gear"></i> Settings</a>
                <?php if ($canAccessBackend): ?>
                    <a href="backend.php" data-nav-link><i class="fa-solid fa-database"></i> Backend</a>
                <?php endif; ?>
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
                    <p><?php echo htmlspecialchars($tabDescriptions[$activeTab]); ?></p>
                </div>
                <div class="topbar-right">
                    <span class="status-pill"><i class="fa-solid fa-circle"></i> Official Portal</span>
                    <div class="user-chip"><?php echo strtoupper(substr($fullName, 0, 1)); ?></div>
                </div>
            </header>

            <?php if ($flash): ?>
                <div class="notice notice-<?php echo htmlspecialchars((string) $flash['type']); ?>">
                    <?php echo htmlspecialchars((string) $flash['message']); ?>
                </div>
            <?php endif; ?>

            <?php if (!$dbReady): ?>
                <div class="notice notice-error">
                    Unable to initialize homepage modules. <?php echo htmlspecialchars($dbSetupError); ?>
                </div>
            <?php endif; ?>

            <?php if ($activeTab === 'dashboard'): ?>
                <section class="cards-grid">
                    <article class="stat-card">
                        <i class="fa-solid fa-id-card card-icon"></i>
                        <p class="card-label">Profile Status</p>
                        <h3><?php echo htmlspecialchars($profileStatus); ?></h3>
                        <span class="card-meta"><?php echo !empty($applicationData) ? 'Updated recently' : 'Complete your application'; ?></span>
                    </article>
                    <article class="stat-card">
                        <i class="fa-solid fa-calendar-day card-icon"></i>
                        <p class="card-label">Next Appointment</p>
                        <h3><?php echo htmlspecialchars($appointmentHeadline); ?></h3>
                        <span class="card-meta"><?php echo htmlspecialchars($appointmentMeta); ?></span>
                    </article>
                    <article class="stat-card">
                        <i class="fa-solid fa-file-circle-check card-icon"></i>
                        <p class="card-label">Application</p>
                        <h3><?php echo htmlspecialchars($applicationStatus); ?></h3>
                        <span class="card-meta"><?php echo !empty($applicationData) ? 'Form is saved' : 'Continue your details'; ?></span>
                    </article>
                </section>

            <?php endif; ?>

            <?php if ($activeTab === 'application'): ?>
                <section class="module-panel">
                    <div class="application-intro">
                        <div>
                            <h2>NBI Clearance Application</h2>
                            <p class="module-copy">Fill out all required fields and review details before saving.</p>
                        </div>
                        <div class="application-status">
                            <span class="application-status-label">Application Status</span>
                            <strong><?php echo htmlspecialchars($applicationStatus); ?></strong>
                        </div>
                    </div>

                    <form method="post" action="homepage.php?tab=application" class="module-form application-form">
                        <input type="hidden" name="action" value="save_application">

                        <section class="application-block">
                            <div class="application-block-head">
                                <h3><i class="fa-solid fa-id-card"></i> Personal Identity</h3>
                                <p>Use your legal name and correct birth details.</p>
                            </div>

                            <div class="form-grid form-grid-3">
                                <div class="field">
                                    <label for="first_name">First Name <span class="required">*</span></label>
                                    <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($applicationFirstName); ?>" required>
                                </div>
                                <div class="field">
                                    <label for="middle_name">Middle Name</label>
                                    <input type="text" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($applicationMiddleName); ?>">
                                </div>
                                <div class="field">
                                    <label for="last_name">Last Name <span class="required">*</span></label>
                                    <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($applicationLastName); ?>" required>
                                </div>
                            </div>

                            <div class="form-grid form-grid-2">
                                <div class="field">
                                    <label for="birthdate">Date of Birth <span class="required">*</span></label>
                                    <input type="date" id="birthdate" name="birthdate" value="<?php echo htmlspecialchars($applicationBirthdate); ?>" required>
                                </div>
                                <div class="field">
                                    <label for="gender">Gender <span class="required">*</span></label>
                                    <select id="gender" name="gender" required>
                                        <option value="">Select gender</option>
                                        <option value="Male" <?php echo $applicationGender === 'Male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo $applicationGender === 'Female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="Other" <?php echo $applicationGender === 'Other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                            </div>
                        </section>

                        <section class="application-block">
                            <div class="application-block-head">
                                <h3><i class="fa-solid fa-phone"></i> Contact Information</h3>
                                <p>Provide reachable contact details and your clearance purpose.</p>
                            </div>

                            <div class="form-grid form-grid-2">
                                <div class="field">
                                    <label for="phone">Phone Number <span class="required">*</span></label>
                                    <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($applicationPhone); ?>" required>
                                </div>
                                <div class="field">
                                    <label for="purpose">Purpose of Clearance <span class="required">*</span></label>
                                    <select name="purpose" id="purpose" required>
                                        <option value="">Select purpose</option>
                                        <?php foreach (['Employment', 'Education', 'Travel', 'Personal'] as $purposeOption): ?>
                                            <option value="<?php echo htmlspecialchars($purposeOption); ?>" <?php echo $applicationPurpose === $purposeOption ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($purposeOption); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </section>

                        <section class="application-block">
                            <div class="application-block-head">
                                <h3><i class="fa-solid fa-location-dot"></i> Residential Address</h3>
                                <p>Enter your current complete address.</p>
                            </div>

                            <div class="field">
                                <label for="address_line">Complete Address <span class="required">*</span></label>
                                <textarea id="address_line" name="address_line" rows="3" required><?php echo htmlspecialchars($applicationAddress); ?></textarea>
                            </div>
                        </section>

                        <div class="form-footer">
                            <p class="helper-text">
                                <?php if (!empty($applicationData)): ?>
                                    <span class="success-text"><i class="fa-solid fa-circle-check"></i> Saved on <?php echo htmlspecialchars(date('M d, Y h:i A', strtotime((string) $applicationData['updated_at']))); ?></span>
                                <?php else: ?>
                                    <span class="info-text"><i class="fa-solid fa-info-circle"></i> Complete all required fields marked with *</span>
                                <?php endif; ?>
                            </p>
                            <button type="submit" class="btn btn-inline">Save Application</button>
                        </div>
                    </form>
                </section>
            <?php endif; ?>

            <?php if ($activeTab === 'appointment'): ?>
                <section class="module-panel">
                    <h2>Appointment Schedule</h2>
                    <p class="module-copy">Choose a weekday schedule. Saturdays and Sundays are blocked, and available time is only from 9:00 AM to 5:00 PM.</p>

                    <form method="post" action="homepage.php?tab=appointment" class="module-form appointment-form" id="appointmentForm">
                        <input type="hidden" name="action" value="save_appointment">

                        <div class="appointment-layout">
                            <section class="calendar-card" id="appointmentCalendar" data-selected-date="<?php echo htmlspecialchars($appointmentDateValue); ?>">
                                <div class="calendar-topbar">
                                    <button class="calendar-nav-btn" id="calendarPrev" type="button" aria-label="Previous month">
                                        <i class="fa-solid fa-chevron-left"></i>
                                    </button>
                                    <h3 id="calendarMonthLabel">Calendar</h3>
                                    <button class="calendar-nav-btn" id="calendarNext" type="button" aria-label="Next month">
                                        <i class="fa-solid fa-chevron-right"></i>
                                    </button>
                                </div>

                                <div class="calendar-weekdays">
                                    <span>Mon</span>
                                    <span>Tue</span>
                                    <span>Wed</span>
                                    <span>Thu</span>
                                    <span>Fri</span>
                                    <span>Sat</span>
                                    <span>Sun</span>
                                </div>
                                <div class="calendar-days" id="calendarDays"></div>

                                <p class="calendar-note"><i class="fa-regular fa-circle-check"></i> Weekends are unavailable.</p>
                            </section>

                            <section class="appointment-details">
                                <div class="field">
                                    <label for="appointment_date">Selected Date</label>
                                    <input type="date" id="appointment_date" name="appointment_date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($appointmentDateValue); ?>" required>
                                    <p id="appointment_date_display" class="date-preview-text">No date selected yet.</p>
                                    <small class="field-help">Pick from the calendar or use the date picker. Saturdays and Sundays are blocked.</small>
                                </div>

                                <div class="form-grid form-grid-2">
                                    <div class="field">
                                        <label for="appointment_time">Time</label>
                                        <input type="time" id="appointment_time" name="appointment_time" min="09:00" max="17:00" step="1800" value="<?php echo htmlspecialchars($appointmentTimeValue); ?>" required>
                                        <small class="field-help">Available slot: 9:00 AM to 5:00 PM</small>
                                    </div>
                                    <div class="field">
                                        <label for="location">Location</label>
                                        <select name="location" id="location" required>
                                            <option value="">Select location</option>
                                            <?php foreach ($appointmentLocationOptions as $locationOption): ?>
                                                <option value="<?php echo htmlspecialchars($locationOption); ?>" <?php echo $appointmentLocationValue === $locationOption ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($locationOption); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <label class="check-item">
                                    <input type="checkbox" id="onsite_required" name="onsite_required" value="1" <?php echo $appointmentOnsite ? 'checked' : ''; ?>>
                                    <span>On-site appearance is required.</span>
                                </label>

                                <div class="field">
                                    <label for="notes">Notes</label>
                                    <textarea id="notes" name="notes" rows="3" placeholder="Optional reminder or details"><?php echo htmlspecialchars($appointmentNotesValue); ?></textarea>
                                </div>
                            </section>
                        </div>

                        <div class="form-footer">
                            <p class="helper-text">
                                <?php if (!empty($appointmentData)): ?>
                                    Scheduled for <strong><?php echo htmlspecialchars(formatDateLabel((string) $appointmentData['appointment_date'], (string) $appointmentData['appointment_time'])); ?></strong>
                                <?php else: ?>
                                    No appointment selected yet.
                                <?php endif; ?>
                            </p>
                            <button type="submit" class="btn btn-inline">Save Appointment</button>
                        </div>
                    </form>

                    <?php if (!empty($appointmentData)): ?>
                        <form method="post" action="homepage.php?tab=appointment" class="appointment-cancel-form" onsubmit="return confirm('Cancel your current appointment?');">
                            <input type="hidden" name="action" value="cancel_appointment">
                            <button type="submit" class="ghost-btn danger-btn">
                                <i class="fa-solid fa-xmark"></i> Cancel Appointment
                            </button>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($activeTab === 'notifications'): ?>
                <section class="module-panel">
                    <h2>Notifications</h2>
                    <p class="module-copy">Messages about application updates and on-site reminders appear here.</p>

                    <?php if (empty($notifications)): ?>
                        <div class="empty-state">
                            <i class="fa-regular fa-bell"></i>
                            <p>No notifications yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="notification-list">
                            <?php foreach ($notifications as $notification): ?>
                                <?php
                                    $notificationId = (int) $notification['id'];
                                    $fullMessage = trim((string) ($notification['message'] ?? ''));
                                    $previewMessage = $fullMessage;
                                    if (strlen($previewMessage) > 110) {
                                        $previewMessage = substr($previewMessage, 0, 107) . '...';
                                    }
                                    $notificationBodyId = 'notification-body-' . $notificationId;
                                ?>
                                <article class="notification-item <?php echo empty($notification['is_read']) ? 'unread' : ''; ?>">
                                    <div class="notification-main">
                                        <h3><?php echo htmlspecialchars((string) $notification['title']); ?></h3>
                                        <p class="notification-preview"><?php echo htmlspecialchars($previewMessage); ?></p>
                                        <div class="notification-body" id="<?php echo htmlspecialchars($notificationBodyId); ?>" hidden>
                                            <p><?php echo nl2br(htmlspecialchars($fullMessage)); ?></p>
                                        </div>
                                        <span><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime((string) $notification['created_at']))); ?></span>
                                    </div>
                                    <div class="notification-actions">
                                        <button type="button" class="ghost-btn notification-open-btn" data-target="<?php echo htmlspecialchars($notificationBodyId); ?>" aria-expanded="false">
                                            Open
                                        </button>
                                        <?php if (empty($notification['is_read'])): ?>
                                            <span class="badge">Unread</span>
                                            <form method="post" action="homepage.php?tab=notifications">
                                                <input type="hidden" name="action" value="mark_notification_read">
                                                <input type="hidden" name="notification_id" value="<?php echo $notificationId; ?>">
                                                <button type="submit" class="ghost-btn">Mark Read</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="badge badge-muted">Read</span>
                                        <?php endif; ?>

                                        <form method="post" action="homepage.php?tab=notifications" onsubmit="return confirm('Delete this notification?');">
                                            <input type="hidden" name="action" value="delete_notification">
                                            <input type="hidden" name="notification_id" value="<?php echo $notificationId; ?>">
                                            <button type="submit" class="ghost-btn danger-btn">Delete</button>
                                        </form>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($activeTab === 'settings'): ?>
                <section class="module-panel">
                    <h2>Settings & Security</h2>
                    <p class="module-copy">Manage your notification preferences and account security settings.</p>
                    
                    <div class="settings-section">
                        <h3 class="settings-section-title">Preferences</h3>
                        <form method="post" action="homepage.php?tab=settings" class="module-form">
                            <input type="hidden" name="action" value="save_settings">

                            <label class="check-item check-item-lg">
                                <input type="checkbox" name="email_notifications" value="1" <?php echo !empty($settings['email_notifications']) ? 'checked' : ''; ?>>
                                <span>
                                    <strong><i class="fa-solid fa-envelope"></i> Email Notifications</strong>
                                    <small>Receive appointment and status updates via email.</small>
                                </span>
                            </label>

                            <label class="check-item check-item-lg">
                                <input type="checkbox" name="onsite_notifications" value="1" <?php echo !empty($settings['onsite_notifications']) ? 'checked' : ''; ?>>
                                <span>
                                    <strong><i class="fa-solid fa-bell"></i> On-site Reminders</strong>
                                    <small>Receive reminders when you need to go to the NBI facility.</small>
                                </span>
                            </label>

                            <button type="submit" class="btn btn-inline">Save Preferences</button>
                        </form>
                    </div>

                    <div class="settings-section">
                        <h3 class="settings-section-title">Change Password</h3>
                        <form method="post" action="homepage.php?tab=settings" class="module-form" id="changePasswordForm">
                            <input type="hidden" name="action" value="change_password">

                            <div class="field">
                                <label for="current_password"><i class="fa-solid fa-lock"></i> Current Password</label>
                                <input type="password" id="current_password" name="current_password" placeholder="Enter your current password" required>
                            </div>

                            <div class="field">
                                <label for="new_password"><i class="fa-solid fa-key"></i> New Password</label>
                                <input type="password" id="new_password" name="new_password" placeholder="Enter your new password (min. 8 characters)" required>
                                <small class="field-help">Password must be at least 8 characters long.</small>
                            </div>

                            <div class="field">
                                <label for="confirm_password"><i class="fa-solid fa-check-circle"></i> Confirm New Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter your new password" required>
                            </div>

                            <button type="submit" class="btn btn-inline">Update Password</button>
                        </form>
                    </div>
                </section>
            <?php endif; ?>
        </main>
    </div>
    <script src="script.js?v=<?php echo (int) (@filemtime(__DIR__ . '/script.js') ?: time()); ?>"></script>
    <?php include 'footer.php'; ?>
</body>
</html>
