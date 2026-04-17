# NBI Clearance Portal

## Project Overview

NBI Clearance Portal is a secure web application for managing National Bureau of Investigation (NBI) clearance applications. It supports user registration, login with OTP verification, appointment scheduling, notifications, and admin management.

## Key Features

- User registration and login
- OTP-based authentication for users
- Admin dashboard for managing applications and appointments
- Applicant data management and status tracking
- Appointment scheduling with calendar selection
- Notification system for users
- Password recovery and reset flows
- Email integration via PHPMailer
- Role-based access control (user vs admin)
- Responsive frontend with custom styling

## Technology Stack

- Backend: PHP
- Database: MySQL
- Frontend: HTML, CSS, JavaScript
- Email: PHPMailer
- Authentication: Session-based login and OTP verification

## Setup Instructions

1. Place the project in your web server document root, for example `d:\XAMPP\htdocs\NBICLEARANCE`.
2. Create a MySQL database named `nbi_clearance`.
3. Update database credentials in `connect.php` if your MySQL setup differs from the default:
   - Host: `localhost`
   - User: `root`
   - Password: `` (empty)
   - Database: `nbi_clearance`
4. Make sure Composer dependencies are installed, if applicable.
5. Run the app in your browser using your local server, for example `http://localhost/NBICLEARANCE/index.php`.

## Important Files

- `connect.php` — Database connection and charset setup
- `auth_helpers.php` — Authentication helpers, session messages, token generation, password verification
- `register.php` — User signup, sign-in, OTP email generation
- `verify_login.php` — OTP verification workflow
- `forgot_password.php` — Initiate password reset email
- `reset_password.php` — Verify reset token and update password
- `admin_login.php` — Admin sign-in page and default admin setup
- `homepage.php` — Authenticated user dashboard, application form, appointment scheduling, notifications
- `backend.php` — Admin backend dashboard and management screens
- `mailer_config.php` — Mailer configuration settings
- `mailer.php` — Email sending logic and PHPMailer integration
- `index.php` — Landing page with login and signup flows
- `logout.php` — User logout flow
- `script.js` — Frontend interaction and UI behavior scripts
- `style.css` — Layout and responsive styling for the application

## Database Tables

The application creates and uses these main tables:

- `users` — Registered users, passwords, and roles
- `login_verifications` — OTP tokens for email login
- `password_resets` — Password reset tokens and expiration
- `nbi_applications` — User application details and statuses
- `nbi_appointments` — Scheduled appointment details
- `nbi_notifications` — Messages and notifications for users
- `nbi_user_settings` — User notification preferences

## Authentication Flow

1. User signs up via `index.php`
2. User logs in with email and password in `index.php`
3. System sends OTP and redirects to `verify_login.php`
4. User enters OTP to authenticate
5. Authenticated users are redirected to `homepage.php`
6. Admin users log in through `admin_login.php`

## Admin Flow

- Admins sign in through `admin_login.php`
- Admin dashboard is available at `backend.php`
- Admins can review applications, schedule appointments, and send notifications

## Email & Notification

- Email settings are configured in `mailer_config.php`
- Email sending logic is handled in `mailer.php`
- Notifications are stored in `nbi_notifications` and displayed in `homepage.php`

## Existing Documentation Files

- `CODE_DOCUMENTATION.txt` — Existing plaintext project documentation
- `code_documentation.html` — Existing HTML documentation view
- `Documentation_Template_MiniProject.docx` — DOCX source file included in the project root

## Notes

- The project uses secure password hashing via `password_hash()` and `password_verify()`.
- Legacy MD5 password hashes are detected and upgraded transparently on login.
- OTP and reset tokens are stored as hashed values for added security.
