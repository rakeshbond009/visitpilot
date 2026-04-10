# Dahua DoLynk Cloud Integration Guide (VisitPilot)

This guide documents the full setup for connecting the VisitPilot VMS with Dahua DoLynk Cloud hardware (v2 API). Refer to this if you need to recreate the environment or troubleshoot authentication issues.

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
