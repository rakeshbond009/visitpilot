# VisitPilot VMS — Dahua DoLynk Cloud Integration Reference

## Overview
VisitPilot integrates with Dahua DoLynk Cloud (Singapore Gateway) to push approved visitor profiles to physical access control hardware (face recognition terminals). When a visit is approved via the web app, the visitor's profile (name, photo, card number) is automatically synced to the device.

---

## Gateway Details
| Property       | Value                                        |
|----------------|----------------------------------------------|
| **Gateway URL**| `https://open-api-sg.dolynkcloud.com`        |
| **Region**     | Singapore                                    |
| **Auth**       | V2 (SHA512 HMAC) — single version for all endpoints |
| **Config stored in** | `system_settings` DB table (key: `dahua_config`) |

---

## Authentication — V2 Protocol (SHA512)

All API calls use **V2 authentication only** (V1/MD5 was deprecated on the Singapore gateway).

### Signature Factor
```
factor = AppId + AccessToken + Timestamp(ms) + Nonce + Method + "\n" + SHA512(body)
sign   = UPPERCASE(HMAC-SHA512(factor, ClientSecret))
```

### Required Headers
```
Authorization: <sign>
X-Ca-Timestamp: <timestamp_ms>
X-Ca-Nonce: <nonce>
X-Ca-AppId: <appId>
appAccessToken: <tokenV2>
Version: V1
Content-Type: application/json
TraceId: tid-<random>-<timestamp>
```

### Token Endpoint
`POST /open-api/api-base/v2/auth/accessToken`

---

## Sync Pipeline

### Trigger
Sync is triggered automatically when a visit is **approved** and also via the "Push to Cloud" button in the visit detail view.

Entry point: `DahuaHelper::syncVisitor($visitId, $pdo)` in `includes/dahua_helper.php`

### 3-Step Flow

#### Step 1 — Create User
`POST /open-api/api-iot/v2/device/accessControl/addUsers`

```json
{
  "deviceId": "<device_sn>",
  "users": [{
    "userId": "432",
    "userName": "Visitor Name",
    "userType": 0,
    "authorityList": ["1"],
    "userPermission": 1,
    "role": "user",
    "departmentId": "1",
    "startTime": "2026-04-11 12:00:00",
    "endTime": "2036-12-31 23:59:59"
  }]
}
```

**Key rules:**
- `userId` must be **numeric only** (e.g., `432`). Alphanumeric IDs (e.g., `VP432`) are silently rejected by the firmware.
- `endTime` must be far in the future or the device marks the user as expired.
- `userPermission: 1` and `authorityList: ["1"]` are required for access rights. Without these, Permission shows as blank on the device.

#### Step 2 — Authorize Face
`POST /open-api/api-iot/v2/device/accessControl/authorizeAccessFace`

```json
{
  "deviceId": "<device_sn>",
  "faces": [{
    "userId": "432",
    "photoURL": "https://visitor.codepilotx.com/uploads/dahua_compressed/432.jpg"
  }]
}
```

**Key rules:**
- Gateway **requires a public HTTPS URL** (`photoURL`). Base64 (`faceImage`) returns `PRM001: photoURL must not be blank`.
- Image must be **under 100KB**, JPEG format, portrait orientation (300×400px recommended).
- The system auto-compresses photos and saves to `uploads/dahua_compressed/<visitId>.jpg` before syncing.
- If user hasn't propagated to device yet, API returns `IDV0098`. System retries up to **3 times with 5s delay**.

#### Step 3 — Authorize Card
`POST /open-api/api-iot/v2/device/accessControl/authorizeAccessCard`

```json
{
  "deviceId": "<device_sn>",
  "cards": [{
    "userId": "432",
    "cardNo": "6C86752E",
    "cardStatus": 0
  }]
}
```

**Key rules:**
- Same retry logic as face if `IDV0098` is returned.
- `cardNo` is the hex code from the visitor's access card (`visit_code` field in DB).

---

## Photo Compression Pipeline

Before syncing, visitor photos are auto-processed:
1. Source: `uploads/photos/<original_photo>` (or `uploads/photos/fix_biometric.jpg` if a manual override exists)
2. Resize to **300×400px** (portrait)
3. Compress JPEG quality from 85% downward until file is **< 95KB**
4. Save to: `uploads/dahua_compressed/<visitId>.jpg`
5. Public URL: `https://visitor.codepilotx.com/uploads/dahua_compressed/<visitId>.jpg`

---

## Device Webhook (Inbound Events)

When a visitor scans at the device, Dahua Cloud sends an event to:
`https://visitor.codepilotx.com/api/dahua/webhook.php?tenant=<tenant_slug>`

The webhook processes `AccessControl` events and updates the visit status to `checked_in`.

Event type: `VTO > AccessControl (AccessControl)` — visible in DoLynk Developer > Tools > Message Debug.

---

## Config Keys (system_settings table)

| Key            | Description                        |
|----------------|------------------------------------|
| `client_id`    | DoLynk App ID                      |
| `client_secret`| DoLynk App Secret                  |
| `product_id`   | DoLynk Product ID                  |
| `base_url`     | `https://open-api-sg.dolynkcloud.com` |
| `device_sns`   | Comma-separated device serial numbers |

---

## Key Files
| File | Purpose |
|------|---------|
| `includes/dahua_helper.php` | Core sync logic, auth, photo compression |
| `api/dahua/webhook.php` | Inbound event handler from Dahua Cloud |
| `api/dahua/test_sync.php` | Manual sync trigger: `/api/dahua/test_sync.php?id=<visitId>` |
| `uploads/dahua_compressed/` | Auto-compressed photos for device sync |
| `dahua_debug.txt` | Runtime debug log (excluded from Git) |

---

## Known Issues & Gotchas
1. **Duplicate ID rejection**: Dahua hardware rejects syncing the same `userId` twice. Delete the old entry from the device before re-syncing.
2. **`faceList`/`cardList` in `addUsers`**: These nested keys are silently ignored by the API. Face and card MUST be sent as separate requests.
3. **Propagation delay**: After `addUsers`, the device takes a few seconds to receive the profile. Face/card sync retries handle this automatically.
4. **100KB image limit**: Hardware rejects any image over 100KB. The compression pipeline handles this automatically.
5. **V1 endpoints deprecated**: Singapore gateway has fully dropped V1/MD5 auth. All calls use V2/SHA512 exclusively.
6. **Debug logging**: All API responses are logged to `dahua_debug.txt` on the hosted server. Check this first when debugging.
