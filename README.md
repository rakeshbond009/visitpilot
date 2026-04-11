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

---
# Dahua DoLynk Cloud Integration Guide (VisitPilot)

This guide documents the full setup for connecting the VisitPilot VMS with Dahua DoLynk Cloud hardware (v2 API). Refer to this if you need to recreate the environment or troubleshoot authentication issues.

> [!IMPORTANT]
> **VERIFIED WORKING** as of April 2026 (Visit #433). The 3-step sequential pipeline with `photoData` array is the ONLY confirmed working approach. Do not revert to atomic payloads.

---

## 🚀 Phase 1: Dahua Developer Portal Setup
**URL:** [https://open.dolynkcloud.com/](https://open.dolynkcloud.com/)

### 1. Create a Product
1. Log in and go to Console → Overview → Create Now → Select **Open IoT**.
2. Enter any name for the product and click **ok**.
3. Click verify and enter password to get:
   - **AccessKey (Client ID)**: Used as `dahua_app_id` in our system.
   - **SecretAccessKey**: Used as `dahua_app_secret`.
   - **ProductID**: Obtained after product creation.

### 2. Subscribe to Service Packages
1. Go to **Select Service Packages**.
2. Find **Access Control Service Package** and click **Subscribe**.
3. This is mandatory for `addUsers` and `authorizeAccessFace` APIs to work.

### 3. Add Hardware Devices
1. Go to Develop Function → **Cloud Access**.
2. Click **Add Device** and enter the Device ID, Device Password, Category Code (ASI), Device Account (admin).
3. Ensure the device status shows **Online** before testing sync.

### 4. Message Subscription (Webhooks)
To receive real-time "Check-In" notifications when someone scans their face:
1. Go to **Develop Functions** → **Cloud Message**.
2. Select your Product (**VisitPilot**).
3. Set **Message Type** as "Device Message", and **Device Type** as "ASI".
4. Under **Types of Subscribable Messages**, check **AccessControl**.
5. Set the **Callback URL** to:
   `https://visitor.codepilotx.com/api/dahua/webhook.php`
6. Click **Save**.

---

## 🛠 Phase 2: System Configuration (App Side)

In the VisitPilot Admin Dashboard (**Settings → Dahua Integration**), set:

| Setting Key | Value Source |
| :--- | :--- |
| **Dahua App ID** | `AccessKey` from Portal |
| **App Secret Key** | `SecretAccessKey` from Portal |
| **Product ID** | `ProductID` from Portal |
| **API Base URL** | `https://open-api-sg.dolynkcloud.com` (Singapore gateway) |
| **Device SNs** | Comma-separated Serial Numbers of your devices |

---

## 💻 Phase 3: The Sync Pipeline (CRITICAL — DO NOT CHANGE)

All logic lives in `includes/dahua_helper.php → DahuaHelper::syncVisitor()`.

### Step 1: Image Compression
Dahua hardware rejects photos over **100KB**. Our pipeline:
1. Reads visitor photo from `uploads/visitors/`.
2. Resizes to **640×480px** using GD library (Dahua hardware requires sufficient resolution to detect facial features).
3. Iteratively compresses JPEG quality (`85 → 80 → 75...`) until file is under **95KB**, BUT stops compressing at quality **55**. 
   - *CRITICAL FIX:* If a photo is compressed too much (e.g., 9.8KB), the API returns `200 Success` but the hardware silenty rejects it as "blurry" and shows "Not Added" on the screen.
4. Saves to `uploads/dahua_compressed/{visit_id}.jpg` (publicly accessible via HTTPS).
5. Public URL: `https://visitor.codepilotx.com/uploads/dahua_compressed/{id}.jpg`

### Step 2: Authentication (V2 / HMAC-SHA512)
The Singapore gateway requires **V2 authentication only** (V1/MD5 is deprecated).

```
Sign = HMAC-SHA512(
  AccessKey + AppAccessToken + Timestamp + Nonce + Method + "\n" + SHA512(Body)
)
```

**Critical header values** (case-sensitive):
```http
Version: v1          ← lowercase v1 (NOT V1)
ProductId: [ID]      ← camelCase ProductId (NOT ProductID)
```

### Step 3: 3-Step Sequential Sync

> [!WARNING]
> The `addUsers` API **silently ignores** `faceList` and `cardList` nested inside the payload. You MUST call all three endpoints separately.

**Step 3a — Add User** (`POST /addUsers`):
```json
{
  "deviceId": "SN123",
  "users": [{"userId": "433", "userName": "John", "userType": 0, ...}]
}
```

**Step 3b — Authorize Face** (`POST /authorizeAccessFace`):
```json
{
  "deviceId": "SN123",
  "faces": [{
    "userId": "433",
    "photoData": ["<base64_NO_data_uri_prefix>"],
    "photoURL": "https://visitor.codepilotx.com/uploads/dahua_compressed/433.jpg"
  }]
}
```
> Per **Dahua API Guide v2.4 §4.12.1.4.2**: `photoData` is a base64 **array** (must strip `data:image/jpeg;base64,` header). When both `photoData` and `photoURL` are present, `photoData` prevails. `photoURL` satisfies the cloud-layer validation check.

**Step 3c — Authorize Card** (`POST /authorizeAccessCard`):
```json
{
  "deviceId": "SN123",
  "cards": [{"userId": "433", "cardNo": "3110C749", "cardStatus": 0}]
}
```

**Retry logic**: Face and Card calls retry up to **3×** with 5s delay if `IDV0098` (user not yet propagated to device) is returned.

### ID Format
- UserIDs are **numeric-only** (the visit's database ID). e.g., `"433"`
- Do NOT use `VP` prefixes — hardware firmware rejects non-numeric IDs.

---

## 🔥 Troubleshooting

| Error Code | Meaning | Fix |
| :--- | :--- | :--- |
| `AUT001` | Invalid signature | Check `v1` lowercase, `ProductId` camelCase, correct HMAC key |
| `PRM001` | Parameter error | `photoURL` blank or `photoData` not an array |
| `IDV0098` | User not found on device | Normal — retry loop handles this (user propagation delay) |
| `IDV0061` | Duplicate Records | User data got overwritten mid-sync (usually caused by rapidly double-clicking sync) |

> [!CAUTION]
> **Duplicate entries and Rapid Syncing:** 
> 1. Duplicate entries are NOT allowed. If a sync fails mid-way, you MUST manually delete the user from the device's Person Management UI before re-syncing.
> 2. **Never overlap syncs** for the same user. Syncing 4 times in 90 seconds will cause the device to delete the face/card to prepare for a new insert, resulting in an `IDV0061` crash on the card step. Wait for the pipeline to finish completely.

### Debug Log
All sync activity is logged to: `https://visitor.codepilotx.com/dahua_debug.txt`

---

## 📂 Phase 4: File Manifest
- `includes/dahua_helper.php` — Main sync engine (3-step pipeline)
- `api/dahua/webhook.php` — Receives check-in events from Dahua Cloud
- `api/dahua/test_sync.php` — Manual sync trigger: `?id={visit_id}`
- `uploads/dahua_compressed/` — Auto-compressed photos served publicly
- `dahua_debug.txt` — Runtime sync log

---

## 🚀 Phase 1: Dahua Developer Portal Setup
**URL:** [https://open.dolynkcloud.com/](https://open.dolynkcloud.com/)

### 1. Create a Product
1. Log in and go to Console-- Overview ---Create Now--Select **Open IoT** >.
2. Enter any name for the product and click **ok**.

click verify and enter password to get

3. **AccessKey (Client ID)**: Used as `dahua_app_id` in our system.
   Cloud App Identifier (Client ID)
   - **SecretAccessKey**: Used as `dahua_app_secret`.
   - **ProductID**: Obtained after product creation.
  

### 2. Subscribe to Service Packages
1. Go to **Select Service Packages**.
2. Find **Access Control Service Package** and click **Subscribe**.
3. This is mandatory for `addUsers` and `authorizeAccessFace` APIs to work.

### 3. Add Hardware Devices
1. Go to Develop FUnction--**Cloud Access**.
2. Click **Add Device** and enter the device ID--Device Passowrd--Category Code (ASI) Device Account (admin)

3. Ensure the device status shows **Online** in the portal before testing sync.

### 4. Message Subscription (Webhooks)
To receive real-time "Check-In" notifications when someone scans their face:
1. Go to **Develop Functions** > **Cloud Message**. (Left panel)
2. Select your Product (**VisitPilot**).
3. Select **Message Type** as "Message Type" and **Device Message** as "ASI".
4. Under **Types of Subscribable Messages**, check **AccessControl**.
5. Set the **Callback URL** to:
   `https://your-domain.com/api/dahua/webhook.php?tenant=YOUR_TENANT_KEY`
6. Click **Save**.

---

## 🛠 Phase 2: System Configuration (App Side)

In the VisitPilot Admin Dashboard (**Settings > Dahua Integration**), set:

| Setting Key | Value Source |
| :--- | :--- |
| **Dahua App ID** | `AccessKey` from Portal |
| **App Secret Key** | `SecretAccessKey` from Portal |
| **Product ID** | `ProductID` from Portal |
| **API Base URL** | `https://open-api-sg.dolynkcloud.com` (for Singapore) |
| **Device SNs** | Comma-separated Serial Numbers of your devices |

---

## 💻 Phase 3: PHP Technical Implementation Details

This section details how the PHP code in `includes/dahua_helper.php` handles specific hardware integrations.

### 1. Sending Visitor Photos (Biometric Sync)
Dahua ASI devices require the face image to be sent as a Base64 encoded string within the `authorizeAccessFace` API call. 

**Our Implementation:**
1. Code fetches the `photo_path` from the `visitors` table.
2. PHP uses `file_get_contents($photoPath)` to read the raw image.
3. The image is converted via `base64_encode()` and placed in the `faceImage` field of the JSON payload.
4. **Endpoint:** `/open-api/api-iot/v2/device/accessControl/authorizeAccessFace`

### 2. Scanning QR Codes (Virtual Cards)
The physical "QR Code" scanning on the ASI device is treated as a "Card Swipe" in the Dahua Cloud.

**Our Implementation:**
1. The 6-digit `visit_code` is fetched for the visit.
2. We call the `authorizeAccessCard` API.
3. The `visit_code` is sent in the `cardNo` field with `cardStatus` set to `0` (Normal).
4. When the visitor holds their QR code to the device, the device sees the code as a card number and grants access.
5. **Endpoint:** `/open-api/api-iot/v2/device/accessControl/authorizeAccessCard`

### 3. Getting Data Back (Webhook Reverse-Mapping)
When a face is recognized or a QR is scanned, the device pushes an event to Dahua Cloud, which then hits our `api/dahua/webhook.php`.

**Our Implementation:**
1. The webhook listener receives a JSON payload containing a `personId`.
2. To make this work, we prefix our IDs during sync as `VP` + `visit_id` (e.g., `VP105`).
3. In `processEvent()`, the code strips the `VP` prefix to retrieve the original database primary key.
4. The system then runs an `UPDATE visits SET status = 'checked_in'...` to reflect real-time attendance.

### 4. Signature & Encryption Logic
The `generateSignV2` function handles the **HMAC-SHA512** security handshake. 
*   It strips whitespace from the JSON body using `preg_replace('/[ \t\n\r\f\v\x0B]/u', '', $str)`.
*   It appends the HTTP method `POST` to the hashing sequence.
*   It includes the `ProductID` and `Version: V1` headers to satisfy the v2 API gateway.

---

## 📂 Phase 4: File Manifest
- `includes/dahua_helper.php`: Main processing engine.
- `api/dahua/webhook.php`: Webhook listener script.
- `test_dahua.php`: Authentication diagnostic script.
- `dahua_debug.txt`: Traceability log for signature strings.

---
# Firebase Notification Setup Guide (Detailed)

This guide provides step-by-step instructions to ensure your mobile app (`com.codepilotx.vms`) and your backend server are perfectly synced for Firebase Cloud Messaging (FCM).

---

## 1. Firebase Console (The Foundation)

### A. Add your Android App
1. Go to the [Firebase Console](https://console.firebase.google.com/).
2. Select your project (e.g., **codepilotx**).
3. Click the **Android icon** to add a new app.
4. **Package Name**: Enter `com.codepilotx.vms` (MUST match `app.json` exactly).
5. **App Nickname**: `VisitPilot Android`.
6. **SHA-1 Fingerprint**: 
   - Open your terminal in the `mobile_app` folder.
   - Run: `cd android && ./gradlew signingReport`
   - Copy the `SHA-1` from the `debug` or `release` variant.
7. Click **Register App**.

### B. Download `google-services.json`
1. Download the file generated by Firebase.
2. **Move it** to: `mobile_app/android/app/google-services.json`.
3. **CRITICAL**: Delete any old `google-services.json` files and replace them with this one.

---

## 2. Server-Side Setup (The Sender)

The server needs permission to send notifications to your devices.

### A. Generate Private Key
1. In Firebase Console, go to **Project Settings** (gear icon) > **Service Accounts**.
2. Click **Generate New Private Key**.
3. A JSON file will download (e.g., `codepilotx-firebase-adminsdk-xxxxx.json`).

### B. Upload to Server
1. Upload this JSON file to your backend server (e.g., in a `config/` or `secret/` folder).
2. Update your PHP/Node.js notification script to point to this new JSON file.
3. **Environment Variables**: Ensure your `GOOGLE_APPLICATION_CREDENTIALS` points to this file.

---

## 3. App-Side Checks (The Receiver)

### A. FCM Token Registration
When a user logs in, the app generates an FCM token. If notifications aren't working:
1. **Logout and Log back in**: This forces the app to request a *new* token using the updated `google-services.json`.
2. **Check Database**: Verify the `fcm_token` column in your `users` or `devices` table is being populated with a long string starting with `f...`.

### B. Permissions
1. On Android 13+, the user MUST manually "Allow" notifications. 
2. Ensure you have the "Display over other apps" permission enabled for the Visitor Arrival popup to work.

---

## 4. Troubleshooting Steps
- **Issue**: "Invalid Token" Error on Server.
  - **Reason**: The server is using a Key from Project A, but the app is sending a Token for Project B.
  - **Fix**: Ensure the `google-services.json` in the app and the `adminsdk.json` on the server are from the **same** Firebase project.
- **Issue**: Notification arrives in background but not foreground.
  - **Reason**: `onMessageReceived` is not handled in the app.
  - **Fix**: Check `App.js` `Notifications.addNotificationReceivedListener`.

---

> [!IMPORTANT]
> **Always** rebuild the APK after changing `google-services.json`. A simple Hot Reload will NOT update the native Firebase configuration.

---
# Expo Smart CI/CD Automation Guide
## The "VisitPilot" Blueprint for Zero-Frustration Updates

This guide documents the high-performance CI/CD pipeline built for the VisitPilot project. It enables **Instant OTA Updates** for UI changes and **Automated APK Builds** for native changes, all running locally on GitHub to save costs.

---

### 1. The Core Concepts

#### **A. The "Frequency" (Runtime Version)**
Updates and APKs only talk to each other if they are on the same "frequency." In Expo, this is the `runtimeVersion`.
*   **Always** set a fixed string like `"1.0.0"` in `app.json`.
*   **Never** leave it to be automatically generated, or your updates will show "No deployments for this runtime."

#### **B. The "Logic" (Path Filtering)**
We use `dorny/paths-filter` to detect what changed:
*   **JS/CSS changes** $\rightarrow$ Trigger ONLY a **1-minute OTA Update**.
*   **`app.json`, `android/`, `package.json`** $\rightarrow$ Trigger a **Full APK Build**.

---

### 2. The Configuration Files

#### **`app.json` (The Setup)**
Ensure these fields are locked in:
```json
{
  "expo": {
    "version": "1.0.0",
    "runtimeVersion": "1.0.0",
    "updates": {
       "url": "https://u.expo.dev/YOUR-PROJECT-ID"
    },
    "android": {
      "versionCode": 1,
      "adaptiveIcon": {
        "foregroundImage": "./assets/icon.png",
        "backgroundColor": "#ffffff"
      }
    }
  }
}
```

#### **`eas.json` (The Channels)**
Explicitly define channels to map updates to builds:
```json
{
  "build": {
    "preview": {
      "channel": "preview",
      "distribution": "internal",
      "android": { "buildType": "apk" }
    }
  }
}
```

---

### 3. The GitHub Actions Workflow (`.github/workflows/main.yml`)

Copy this structure for any new project:

```yaml
name: CI/CD Automation
on:
  push:
    branches: [main]

jobs:
  check_changes:
    runs-on: ubuntu-latest
    outputs:
      native: ${{ steps.filter.outputs.native }}
    steps:
      - uses: actions/checkout@v4
      - uses: dorny/paths-filter@v3
        id: filter
        with:
          filters: |
            native:
              - 'mobile_app/android/**'
              - 'mobile_app/app.json'
              - 'mobile_app/package.json'

  update:
    name: Instant OTA Update
    needs: check_changes
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: expo/expo-github-action@v8
        with:
          eas-version: latest
          token: ${{ secrets.EXPO_TOKEN }}
      - name: Install & Publish
        run: |
          cd mobile_app
          npm install
          # The key: Force Link the channel to the branch
          npx eas-cli channel:edit preview --branch preview --non-interactive || npx eas-cli channel:create preview --branch preview --non-interactive
          npx eas-cli update --branch preview --message "Auto-Sync" --non-interactive

  build:
    name: Local APK Build
    needs: check_changes
    if: needs.check_changes.outputs.native == 'true'
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: expo/expo-github-action@v8
        with:
          eas-version: latest
          token: ${{ secrets.EXPO_TOKEN }}
      - name: Build Locally on GitHub
        run: |
          cd mobile_app
          npm install
          # --local saves Expo credits and avoids queues
          eas build --platform android --profile preview --non-interactive --local
```

---

### 4. Implementation Checklist for New Projects

1.  **EAS Init:** Run `eas build:configure` in your new project.
2.  **Secrets:** Add `EXPO_TOKEN` to your GitHub Repository Secrets.
3.  **App Config:** Set a hardcoded `runtimeVersion` in `app.json`.
4.  **Action:** Create the `.github/workflows/main.yml` file.
5.  **First Build:** Run the build once manually to ensure your keystores/credentials are initialized on Expo.

---

### 5. Troubleshooting "Updates Not Showing"
*   **Check Runtime:** Does `app.json` on the Update match `app.json` in the binary?
*   **Check Channel:** Is the `preview` channel pointing to the `preview` branch? (Refresh using the "Auto-Linker" command in step 3).
*   **Restart App:** Sometimes the app needs to be closed and re-opened **twice** to download and then apply the update.

---

> [!TIP]
> **Why use `--local`?**
> Standard `eas build` runs on Expo's expensive cloud. Using `--local` inside a GitHub Action runs the build on GitHub's free (or cheaper) runner, saving you money and skipping the queue!

---
# Visitor Pass PDF Design System (v1.0.6)

This document serves as the technical reference for the high-fidelity PDF Visitor Pass generation system implemented for VisitPilot. Use this as a reference if the layout needs adjustment or if the system is migrated to a new server.

## 1. Core Architecture
The system uses a modified version of **FPDF** (FPDF 1.86). Standard FPDF was insufficient for this project's premium design requirements.

### Essential Class Extensions (in `includes/fpdf.php`)
We have extended the base FPDF class with three critical methods to support modern aesthetics:
- **`RoundedRect($x, $y, $w, $h, $r, $style, $corners)`**: Used for the card body and the detail grid boxes.
- **`ClippingRoundedRect($x, $y, $w, $h, $r)`**: Essential for the photo area. It creates a vector mask that "cuts" the rectangular image into a rounded-corner shape.
- **`UnsetClipping()`**: Must be called immediately after the `Image()` call to return the graphics state to normal.

## 2. Design Tokens & Geometry
- **Primary Brand Blue**: `rgb(17, 97, 238)` (#1161EE).
- **Background Grey**: `rgb(244, 247, 246)` (Canvas backdrop).
- **Detail Box Grey**: `rgb(242, 243, 245)` (#F2F3F5).
- **Font Face**: Helvetica / Arial (Standard FPDF cores).

### Coordinate Map (100mm x 210mm Canvas)
| Component | X Coord | Y Coord | Width | Height | Radius |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Outer Card** | 10 | 15 | 80mm | 185mm | 8mm |
| **Top Banner** | 10 | 15 | 80mm | 48mm | 8mm |
| **Photo Frame (White)**| 26 | 57 | 48mm | 48mm | 8mm |
| **Visible Photo** | 28 | 59 | 44mm | 44mm | 8mm |
| **Details Container** | 15 | 132 | 70mm | 32mm | 4mm |

## 3. Implementation Logic
### The "Broad Border" System
To achieve the professional white border look from the digital pass:
1. Draw a white `RoundedRect` of 48x48mm.
2. Activate the `ClippingRoundedRect` at a 2mm offset (28x59mm) with a size of 44x44mm.
3. Place the Image at those same 28x59mm coordinates.
4. The result is a 44mm rounded image perfectly centered inside a 48mm rounded white frame.

### Typographic Scaling
- **VISITOR PASS Header**: 24pt Bold.
- **Visitor Name**: 16pt Bold (UPPERCASE). This size was selected to prevent overflow on long names while remaining high-impact.
- **Visit Code**: 12pt Bold (Blue).

## 4. Troubleshooting & Server Requirements
- **PHP extension**: Requires `GD` for image processing if images are not standard formats.
- **Permissions**: `uploads/passes/` and `uploads/visitors/` must be writable. 
- **File Locks**: If you get a "FAILED generation" error on Windows, ensure the PDF file is not open in a browser tab, as Windows prevents writing to locked files.
- **Version Tracking**: The current production version is **v1.0.6**, visible in the footer of every generated pass.

---
# VisitPilot Notification System

## Dahua DoLynk Cloud Integration (V2) - ✅ FULLY VERIFIED

See the comprehensive Dahua integration guide above (Phase 1–4) for the complete reference.

**Quick Summary — Verified Working Stack:**
- **Gateway**: `https://open-api-sg.dolynkcloud.com` (Singapore)
- **Auth**: HMAC-SHA512, headers `Version: v1`, `ProductId` (camelCase)
- **Face Payload**: `photoData` as base64 array + `photoURL` as real HTTPS URL
- **Pipeline**: 3 separate API calls (addUsers → authorizeAccessFace → authorizeAccessCard)
- **Image limit**: < 100KB (auto-compressed to `uploads/dahua_compressed/`)
- **Log**: `dahua_debug.txt` on hosted server

This document outlines the implementation details of the real-time notification system in VisitPilot, covering both the web dashboard polling architecture and the React Native mobile app Push/Overlay system.

---

## 1. Mobile App Notification & Wake-Up System (React Native)

The mobile app (`com.visitpilot.vms`) uses a robust Firebase Cloud Messaging (FCM) integration paired with native Android modules to wake up the device and show urgent alerts (e.g., when a visitor arrives).

### A. Push Token Retrieval & Registration
- **Implementation:** `mobile_app/utils/notificationManager.js` handles token fetching using `expo-notifications`. 
- **Setup:** It initializes `vms_urgent_alerts_v2` (for high priority lock screen bypass) and a `default` notification channel.
- **Backend Sync:** The native token is synced to the server via `/api/user/update_fcm.php` and stored in the `user_devices` table.

### B. Background Wake-Up & Overlay (CRITICAL)
Standard notifications fail to wake deep-sleeping Android devices. We implemented a custom hardware wake-up system:
1. **Overlay Permission:** The app prompts the user exactly once for `ACTION_MANAGE_OVERLAY_PERMISSION` ("Appear on top"). This is handled via `OverlayPermissionModule.java` and `notificationManager.js`.
2. **Background Task:** A `TaskManager` daemon (`BACKGROUND_NOTIFICATION_TASK` in `App.js`) listens for incoming FCM data messages in the background.
3. **Execution:** Upon receiving a payload with `type: 'visitor_arrival'` or `is_call_priority: 'true'`, the task invokes `OverlayPermissionModule.wakeUpApp()`. This physically turns on the device screen and launches the app overlay over the lock screen.
4. **Resiliency:** The data payload is saved to `AsyncStorage('pending_arrival_call')` in case the overlay UI takes too long to render.

### C. On-Screen Call UI
- **Implementation:** `mobile_app/components/IncomingCallScreen.js` and `mobile_app/App.js` hook into the arrival payload.
- **Action:** If an urgent alert arrives, React Native sets `showOverlay` to `true`, rendering the full-screen `IncomingCallScreen` (mimicking a WhatsApp video/audio call UI).
- **Ringing:** Uses `expo-av` with `staysActiveInBackground` to loop phone-calling sounds, alongside `Vibration.vibrate()`.
- **Auto-Dismiss:** The call overlay automatically unmounts after 60 seconds if not answered. 

### D. Server-Side Push Dispatch
- **Implementation:** `includes/push_helper.php` reads service account JSON credentials to retrieve momentary OAuth tokens.
- **Parallel Dispatch:** Multi-recipient notifications (e.g., to all security guards simultaneously) are sent entirely in parallel using `curl_multi_init()` to avoid blocking the HTTP response on the PHP server.

---

## 2. Web Dashboard Notification System (Polling)

The web client operates on a generic fallback polling architecture to deliver real-time notifications on desktop browsers.

### A. Polling Mechanisms
- **Host Dashboard:** Polls every **2 seconds** (`assets/js/notifications.js`) via `host/api/check_new_visits.php`.
- **Security Dashboard:** Polls every **4 seconds** (`assets/js/security_notifications.js`) via `security/api/check_status_updates.php`.

### B. Audio Context Unlocking
Browsers block auto-playing audio until user interaction occurs.
- **Implementation:** The system attaches a one-time event listener to `click` and `keydown`. On interaction, a silent sound is played to unlock the audio context for subsequent automated sounds.

### C. Background Mode (Heartbeat)
Mobile browsers put non-active tabs to sleep, stopping background polling JS.
- **Fix:** A low-volume `.wav` file (`hbAudio`) repeats in the background, tricking the browser into thinking it's an active media tab.

### D. Duplicate Prevention
- Pushed alerts are tracked via `localStorage('notified_visits')`. Before a UI popup is shown, the ID is validated against this array to ensure the user isn't spammed with the same visitor status double-play.

---

## 3. Maintenance and Troubleshooting Guide

### Mobile Overlay Not Waking Screen
1. **Permissions:** Ensure the Android device explicitly allows "Appear on Top" and has "Display Over Other Apps" turned on for VMS.
2. **FCM Payload Type:** The payload from `push_helper.php` must explicitly contain `'is_call_priority' => 'true'` and `'type' => 'visitor_arrival'`.
3. **Task Manager:** Ensure `expo-task-manager` is correctly wired; check if `BACKGROUND_NOTIFICATION_TASK` executes in Adb Logcat. 

### Mobile Token Not Updating
1. **Network Sync:** Check if `update_fcm.php` returns a 200 OK. 
2. **DB Constraint:** Make sure `user_devices` table stores the lengthy generic raw device push token (often >150 chars).

### Host/Web Delay
1. **Server Limit:** Ensure your host server's LiteSpeed/Apache configs do not artificially throttle or cache the endpoints `check_new_visits.php` heavily. Use cache-control headers on these endpoints.
