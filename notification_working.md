# VisitPilot Notification System Documentation

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
