# VisitPilot Notification System Documentation

This document outlines the implementation details of the real-time notification system in VisitPilot to ensure its stability and facilitate future maintenance.

## 1. Core Architecture
The system uses a **polling-based architecture** combined with **client-side state tracking** to deliver real-time alerts without the complexity of WebSockets.

### Polling Mechanisms
- **Host Dashboard:** Polls every **2 seconds** (`assets/js/notifications.js`).
- **Security Dashboard:** Polls every **4 seconds** (`assets/js/security_notifications.js`).
- **Data Source:** Fetch calls to specialized PHP APIs that return recent status changes based on a `last_check` timestamp.

---

## 2. Key Components

### A. Audio Context Unlocking (Critical)
Browsers block auto-playing audio until a user interacts with the page. 
- **Implementation:** Both `notifications.js` and `security_notifications.js` attach a one-time event listener to `click` and `keydown`. 
- **Logic:** On the first interaction, a short silent sound is played to "unlock" the audio context, allowing subsequent notification sounds to play automatically even if the tab is in the background.

### B. Background Mode (The "Heartbeat")
To prevent mobile browsers and desktop power-savers from putting the VisitPilot tab to sleep (throttling JS execution), a "Background Mode" was implemented.
- **Implementation:** A low-volume, silent `.wav` file (`hbAudio`) plays in a loop.
- **Effect:** This tricks the browser into treating the tab as an active "media" tab, maintaining high-frequency polling even when minimized.

### C. Duplicate Prevention
To avoid showing the same notification multiple times if a poll overlaps or a page is refreshed:
- **Local Storage:** The system stores a set of `notified_visits` IDs in the browser's `localStorage`.
- **Filtering:** Incoming API data is cross-referenced against this set before triggering any UI alert or sound.
- **Daily Cleanup:** Metadata in `localStorage` tracks the date; if a new day begins, the notification cache is cleared automatically.

---

## 3. Workflow Triggers

### Host Notifications (`notifications.js`)
1. **Trigger:** A new visit record appears in `pending` status where the current user is the host.
2. **Action:** Plays a looping alarm sound (`951-preview.mp3`).
3. **UI:** Opens the `newVisitorModal`. 
4. **Mobile Sync:** If a `mobile_fcm_token` is found in `localStorage`, it automatically registers the device with the backend for native push notifications.

### Security/Admin Notifications (`security_notifications.js`)
1. **Trigger:** A visit status moves to `approved` or `rejected` (status update).
2. **Action:** Plays a short notification "ping" (`2869-preview.mp3`).
3. **UI:** 
   - On the **Dashboard**: Calls `window.notifyStatusChange()` to show a rich UI toast and refreshes the metrics.
   - On **Other Pages**: Shows a generic `AppDialog` popup with a "Manage Visit" shortcut.

---

## 4. Backend API Endpoints
- **Host Polling:** `host/api/check_new_visits.php`
- **Security Polling:** `security/api/check_status_updates.php`
- **Token Registration:** `api/user/update_fcm.php`

---

## 5. Troubleshooting Guide

### "Notifications aren't playing sound"
- **Check 1:** Ensure the user has clicked *anywhere* on the page at least once after loading.
- **Check 2:** Check if the browser tab is muted.
- **Check 3:** Verify the `last_check` parameter in the Network tab of DevTools to see if the API is returning data.

### "Dashboard isn't updating when a notification arrives"
- **Check 1:** In `security_notifications.js`, ensure `window.VMS_REFRESH_DASHBOARD` is correctly assigned to the dashboard's refresh function.
- **Check 2:** Look for JavaScript errors in the console that might be halting execution (specifically `TypeError` on missing DOM elements).

### "Notifications keep repeating"
- **Check 1:** Clear the browser's `localStorage` (specifically the `vms_notified_visits` key).
- **Check 2:** Ensure the backend is correctly returning the `timestamp` in the JSON response to advance the `last_check` cursor.

---
*Created on 2026-04-01 for VisitPilot Stability Documentation.*
