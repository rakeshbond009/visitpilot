# Visitor Management System (VMS)

A complete, responsive, and secure Visitor Management System built with PHP, MySQL/MariaDB, and Bootstrap 5.

## Features
- **Visitor Registration**: Capture details + Photo (Webcam support).
- **Check-In/Out**: QR Code scanning or manual processing.
- **Pass Issuance**: Digital (Display/WhatsApp) & Physical (Printable) passes with QR Codes.
- **Roles**: Admin (Manage Employees, Reports) & Security (Manage Visitors).
- **Reports**: Date-wise, Host-wise, Export to CSV.
- **Security**: ID Masking, Secure Login, CSRF Protection (Session based).

## Prerequisites
- XAMPP / WAMP / LAMP Stack (PHP 8.0+, MySQL/MariaDB).
- Web Browser with Camera Access (for Photo/QR Scanning).
- Internet connection (for Bootstrap CDN & QR Code API). 
  *Note: For offline usage, download Bootstrap css/js and `phpqrcode` library locally.*

## Installation Steps

1. **Setup Folder**:
   - Copy the `VMS` folder to `htdocs` (XAMPP) or `www` (WAMP).
   - Ensure path: `C:\xampp\htdocs\VMS`.

2. **Database Setup**:
   - Open phpMyAdmin (`http://localhost/phpmyadmin`).
   - Create a database named `vms_db`.
   - Import `database.sql` from the project root.
     - Go to Import tab -> Browse -> Select `database.sql` -> Go.

3. **Configuration**:
   - Open `includes/db.php`.
   - Verify DB credentials (`$host`, `$username`, `$password`, `$dbname`).
   - Default XAMPP users have no password. If you have one, update it.

4. **Directory Permissions**:
   - Ensure `uploads/photos` and `uploads/qrcodes` are writable.
   - Windows usually allows this by default. On Linux: `chmod -R 777 uploads`.

5. **Run Application**:
   - Open browser: `http://localhost/VMS`.
   - **Login Credentials**:
     - **Admin**: `admin` / `admin123`
     - **Security**: `security` / `admin123`

## Usage Guide
- **Security** logs in to register visitors, scan passes, and check people in/out.
- **Admin** logs in to add employees and view comprehensive reports.

## Developer Notes
- **QR Generation**: Uses `api.qrserver.com` for simplicity. For offline, install `phpqrcode` in `lib/` and update `security/pass.php`.
- **Photo Capture**: Uses HTML5 MediaDevices API. Requires SSL on some browsers (or localhost).

## License
Open Source.
