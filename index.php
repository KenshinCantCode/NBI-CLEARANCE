<?php
require_once 'auth_helpers.php';
$flash = pullFlashMessage();
$showSignUp = (isset($_GET['form']) && $_GET['form'] === 'signup');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NBI Clearance Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?php echo (int) (@filemtime(__DIR__ . '/style.css') ?: time()); ?>">
</head>
<body class="auth-page index-page">
    <div class="auth-layout">
        <aside class="auth-visual">
            <div class="auth-brand">
                <img src="assets/nbi.png" alt="NBI Clearance Portal logo" class="brand-logo">
                <div>
                    <p class="auth-brand-kicker">Republic of the Philippines</p>
                    <strong>National Bureau of Investigation</strong>
                </div>
            </div>
            <p class="eyebrow">Online Application and Appointment System</p>
            <h1>Track and Manage Your Application and Appointment</h1>
            <p class="auth-copy">Easy Access On Application and Appointment</p>
        </aside>

        <main class="index-panel">
            <div class="container" id="signup" style="display:<?php echo $showSignUp ? 'block' : 'none'; ?>;">
              <div class="auth-card-brand">
                <img src="assets/nbi.png" alt="" class="card-logo">
                <div>
                    <p>NBI Clearance Portal</p>
                    <span>Citizen registration</span>
                </div>
              </div>
              <h2 class="form-title">Create Account</h2>
              <p class="form-subtitle">Set up your account to begin your application.</p>
              <?php if ($flash && $showSignUp): ?>
                <div class="notice notice-<?php echo htmlspecialchars($flash['type']); ?>">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
              <?php endif; ?>
              <form method="post" action="register.php">
                <div class="input-group">
                   <i class="fas fa-user"></i>
                   <input type="text" name="fName" id="fName" placeholder="First Name" required>
                   <label for="fName">First Name</label>
                </div>
                <div class="input-group">
                    <i class="fas fa-user"></i>
                    <input type="text" name="lName" id="lName" placeholder="Last Name" required>
                    <label for="lName">Last Name</label>
                </div>
                <div class="input-group">
                    <i class="fas fa-envelope"></i>
                    <input type="email" name="email" id="signupEmail" placeholder="Email" required>
                    <label for="signupEmail">Email</label>
                </div>
                <div class="input-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="password" id="signupPassword" placeholder="Password" required>
                    <label for="signupPassword">Password</label>
                </div>
                <div class="input-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="confirm_password" id="signupConfirmPassword" placeholder="Confirm Password" required>
                    <label for="signupConfirmPassword">Confirm Password</label>
                </div>
               <input type="submit" class="btn" value="Sign Up" name="signUp">
              </form>
              <div class="links">
                <p>Already have an account?</p>
                <button type="button" id="signInButton">Sign In</button>
              </div>
            </div>

            <div class="container" id="signIn" style="display:<?php echo $showSignUp ? 'none' : 'block'; ?>;">
                <div class="auth-card-brand">
                    <img src="assets/nbi.png" alt="" class="card-logo">
                    <div>
                        <p>NBI Clearance Portal</p>
                        <span>Citizen sign in</span>
                    </div>
                </div>
                <h2 class="form-title">Welcome Back</h2>
                <p class="form-subtitle">Sign in to continue to your dashboard.</p>
                <?php if ($flash && !$showSignUp): ?>
                    <div class="notice notice-<?php echo htmlspecialchars($flash['type']); ?>">
                        <?php echo htmlspecialchars($flash['message']); ?>
                    </div>
                <?php endif; ?>
                <form method="post" action="register.php">
                  <div class="input-group">
                      <i class="fas fa-envelope"></i>
                      <input type="email" name="email" id="signinEmail" placeholder="Email" required>
                      <label for="signinEmail">Email</label>
                  </div>
                  <div class="input-group">
                      <i class="fas fa-lock"></i>
                      <input type="password" name="password" id="signinPassword" placeholder="Password" required>
                      <label for="signinPassword">Password</label>
                  </div>
                  <p class="recover">
                    <a href="forgot_password.php">Recover Password</a>
                  </p>
                 <input type="submit" class="btn" value="Sign In" name="signIn">
                </form>
                <div class="links">
                  <p>Need an account?</p>
                  <button type="button" id="signUpButton">Sign Up</button>
                </div>
                <div class="links">
                  <p>Admin access?</p>
                  <a class="text-link" href="admin_login.php">Admin Login</a>
                </div>
            </div>
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
