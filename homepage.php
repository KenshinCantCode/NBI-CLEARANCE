<?php
session_start();
include("connect.php");

?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Homepage</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="home-page">
    <?php
    $fullName = "User";
    if(isset($_SESSION['email'])){
        $email=$_SESSION['email'];
        $query=mysqli_query($conn, "SELECT users.* FROM `users` WHERE users.email='$email'");
        if($row=mysqli_fetch_array($query)){
            $fullName = $row['firstName'].' '.$row['lastName'];
        }
    }
    ?>

    <div class="home-layout">
      <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
          <i class="fa-solid fa-fingerprint"></i>
          <div>
            <p>NBI</p>
            <span>Clearance Portal</span>
          </div>
        </div>

        <nav class="sidebar-nav">
          <a href="#" class="active"><i class="fa-solid fa-house"></i> Home</a>
          <a href="#"><i class="fa-solid fa-file-lines"></i> Application</a>
          <a href="#"><i class="fa-solid fa-calendar-check"></i> Appointment</a>
          <a href="#"><i class="fa-solid fa-bell"></i> Notifications</a>
          <a href="#"><i class="fa-solid fa-gear"></i> Settings</a>
        </nav>

        <a class="logout-link" href="logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
      </aside>

      <div class="overlay" id="overlay"></div>

      <main class="main-content">
        <header class="topbar">
          <button id="menuToggle" class="menu-toggle" type="button" aria-label="Toggle Sidebar">
            <span></span>
            <span></span>
            <span></span>
          </button>
          <div class="topbar-title">
            <h1>Dashboard</h1>
            <p>Welcome back, <?php echo htmlspecialchars($fullName); ?></p>
          </div>
          <div class="topbar-right">
            <span class="status-pill"><i class="fa-solid fa-circle"></i> Active</span>
            <div class="user-chip"><?php echo strtoupper(substr($fullName, 0, 1)); ?></div>
          </div>
        </header>

        <section class="cards-grid">
          <article class="stat-card">
            <i class="fa-solid fa-id-card card-icon"></i>
            <p class="card-label">Profile Status</p>
            <h3>Verified</h3>
            <span class="card-meta">Updated recently</span>
          </article>
          <article class="stat-card">
            <i class="fa-solid fa-calendar-day card-icon"></i>
            <p class="card-label">Next Appointment</p>
            <h3>Pending</h3>
            <span class="card-meta">No appointment selected</span>
          </article>
          <article class="stat-card">
            <i class="fa-solid fa-file-circle-check card-icon"></i>
            <p class="card-label">Application</p>
            <h3>In Progress</h3>
            <span class="card-meta">Continue your details</span>
          </article>
        </section>

        <section class="hero-panel">
          <div>
            <h2>Hello, <?php echo htmlspecialchars($fullName); ?></h2>
            <p>Use the sidebar to complete your requirements, schedule your appointment, and monitor account updates in one place.</p>
          </div>
          <a href="#" class="hero-btn">Start Application</a>
        </section>
      </main>
    </div>
    <script src="script.js"></script>
</body>
</html>
