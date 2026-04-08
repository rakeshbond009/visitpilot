# Master Setup Guide: Visitor Management System (VMS)

This guide is the single source of truth for setting up, configuring, and maintaining the VMS project, including the Mobile App, Firebase, and WhatsApp integrations.

---

## 1. Project Overview & Features
A complete, responsive, and secure Visitor Management System built with PHP, MySQL/MariaDB, and React Native (Expo).

- **Visitor Registration**: Capture details + Photo (Webcam/Mobile support).
- **Check-In/Out**: QR Code scanning or manual processing.
- **Pass Issuance**: Digital (Display/WhatsApp) & Physical (Printable) passes with QR Codes.
- **Roles**: Admin (Manage Employees, Reports) & Security (Manage Visitors).

---

## 2. Server-Side Installation (XAMPP/WAMP)

### A. Environment Setup
1. **Directory**: Copy the project to `C:\xampp\htdocs\visitpilot`.
2. **Database**: 
   - Create a database named `vms_db`.
   - Import `database.sql` from the project root.
3. **Configuration**: Open `includes/db.php` and verify DB credentials.
4. **Permissions**: Ensure `uploads/photos` and `uploads/qrcodes` are writable.

### B. Admin Credentials
- **Admin**: `admin` / `admin123`
- **Security**: `security` / `admin123`

---

## 3. Firebase & Push Notifications (CRITICAL)

There are TWO different Firebase files you must manage. They are NOT the same.

### A. Mobile App Config (The Receiver)
This file tells the Android app which project to listen to.
- **File Name**: `google-services.json`
- **Absolute Path**: `c:\xampp\htdocs\visitpilot\mobile_app\android\app\google-services.json`
- **Update Logic**: If you change the package name or Firebase project, download a new `google-services.json` from the Firebase Console (Android settings) and replace this file.

### B. Server-Side Config (The Sender)
This file allows the PHP server to send notifications via the Firebase Admin SDK.
- **File Name**: `vms-notification-c484b-firebase-adminsdk-fbsvc-b8987c9f5b.json` (or similar `.json` file).
- **Absolute Path**: `c:\xampp\htdocs\visitpilot\includes\`
- **Update Logic**: Download the "Service Account Private Key" from Firebase Console > Project Settings > Service Accounts. 
- **Code Link**: You MUST update the filename in `c:\xampp\htdocs\visitpilot\includes\push_helper.php` (Lines 39, 212, 341) to match your new JSON file.

### C. Sequential Setup Steps:
1. Add Android App to Firebase Console with package `com.codepilotx.vms`.
2. Download `google-services.json` to the Android app folder.
3. Generate "Private Key" from Service Accounts and upload to `includes/`.
4. Update token in `push_helper.php`.

---

## 4. WhatsApp Implementation (Meta Cloud API)

Our project uses the **Meta (Facebook) WhatsApp Cloud API** (v18.0+) to send notifications.

### A. Implementation Specifics (Code)
- **Primary Helper**: `includes/whatsapp_helper.php`
- **Automation Logic**:
  - **Visitor Arrival**: Triggered in `bg_jobs.php` (`runJob_registerVisitor`) → Alert sent to Host.
  - **Visit Approval**: Triggered in `bg_jobs.php` (`runJob_approveVisit`) → Pass (PDF) sent to Visitor.
  - **Manual Resend**: Handled by `api/visit/resend_whatsapp.php`.

### B. Database Configuration
Settings must be set in the `system_settings` table:
- `whatsapp_access_token`: Your Permanent Meta Token.
- `whatsapp_phone_number_id`: Your Phone Number ID from Meta.
- `whatsapp_enabled_processes`: `["visitor_arrival_host_alert", "visitor_otp_verification", "visit_approval_visitor_notify"]`

### C. Required Templates (Meta Dashboard)
Templates with these names MUST be approved in your Meta Business Suite:
1. `visitor_otp_verification`
2. `visitor_meet_notify`
3. `visit_approval_visitor_notify` (Requires Document Header)
4. `visitor_arrival_host_alert`

---

## 5. Absolute Path Reference Table

| Target | Absolute Path on Your PC |
|---|---|
| **Android Firebase Config** | `c:\xampp\htdocs\visitpilot\mobile_app\android\app\google-services.json` |
| **Server Firebase Key** | `c:\xampp\htdocs\visitpilot\includes\[YOUR_JSON_FILE].json` |
| **Push Helper (PHP)** | `c:\xampp\htdocs\visitpilot\includes\push_helper.php` |
| **WhatsApp Helper (PHP)** | `c:\xampp\htdocs\visitpilot\includes\whatsapp_helper.php` |
| **Mobile App Root** | `c:\xampp\htdocs\visitpilot\mobile_app\` |
| **Server API Root** | `c:\xampp\htdocs\visitpilot\api\` |
| **WhatsApp Logs** | `c:\xampp\htdocs\visitpilot\whatsapp_log.txt` |

---

> [!WARNING]
> After changing `google-services.json`, you **MUST** run a clean build for the changes to take effect:
> `cd mobile_app/android && ./gradlew clean && ./gradlew assembleRelease`
