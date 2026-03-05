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
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
    <div class="auth-layout">
        <aside class="auth-visual">
            <p class="eyebrow">National Bureau of Investigation</p>
            <h1>Clearance System Portal</h1>
            <p class="auth-copy">Access your account to manage your NBI clearance application in a secure and streamlined workspace.</p>
            <div class="auth-points">
                <span><i class="fa-solid fa-shield-halved"></i> Secure sign in</span>
                <span><i class="fa-solid fa-bolt"></i> Faster processing</span>
                <span><i class="fa-solid fa-circle-check"></i> Centralized updates</span>
            </div>
            <div class="auth-metrics">
                <div>
                    <strong>24/7</strong>
                    <p>Account access</p>
                </div>
                <div>
                    <strong>100%</strong>
                    <p>Web responsive</p>
                </div>
            </div>
        </aside>

        <main class="auth-panel">
            <div class="container" id="signup" style="display:none;">
              <h2 class="form-title">Create Account</h2>
              <p class="form-subtitle">Set up your account to begin your application.</p>
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
               <input type="submit" class="btn" value="Sign Up" name="signUp">
              </form>
              <div class="links">
                <p>Already have an account?</p>
                <button type="button" id="signInButton">Sign In</button>
              </div>
            </div>

            <div class="container" id="signIn">
                <h2 class="form-title">Welcome Back</h2>
                <p class="form-subtitle">Sign in to continue to your dashboard.</p>
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
                    <a href="#">Recover Password</a>
                  </p>
                 <input type="submit" class="btn" value="Sign In" name="signIn">
                </form>
                <div class="links">
                  <p>Need an account?</p>
                  <button type="button" id="signUpButton">Sign Up</button>
                </div>
            </div>
        </main>
    </div>
    <script src="script.js"></script>
</body>
</html>
