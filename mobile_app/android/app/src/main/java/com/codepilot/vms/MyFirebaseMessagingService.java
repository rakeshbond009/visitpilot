package com.codepilot.vms;

import android.content.Context;
import android.content.Intent;
import android.os.PowerManager;
import android.util.Log;
import com.google.firebase.messaging.FirebaseMessagingService;
import com.google.firebase.messaging.RemoteMessage;
import java.util.Map;

public class MyFirebaseMessagingService extends FirebaseMessagingService {

    private static final String TAG = "MyFirebaseMsgService";

    @Override
    public void onMessageReceived(RemoteMessage remoteMessage) {
        super.onMessageReceived(remoteMessage);

        Map<String, String> data = remoteMessage.getData();
        if (data == null || data.isEmpty()) return;

        String type = data.get("type");
        // Only trigger wake-up for visitor_arrival or call priority
        if (!"visitor_arrival".equals(type) && !"true".equals(data.get("is_call_priority"))) {
            return;
        }

        Log.d(TAG, "Arrival detected! Waking up app...");

        // 1. Force screen wake
        PowerManager pm = (PowerManager) getSystemService(Context.POWER_SERVICE);
        PowerManager.WakeLock wakeLock = pm.newWakeLock(PowerManager.FULL_WAKE_LOCK | 
                PowerManager.ACQUIRE_CAUSES_WAKEUP | 
                PowerManager.ON_AFTER_RELEASE, "VisitPilot:CallWakeLock");
        
        // Acquire for 10 seconds to allow the app to launch
        wakeLock.acquire(10000);

        // 2. Launch MAIN ACTIVITY (React Native)
        // This will reuse the existing instance or start a new one
        // MainActivity.kt has the flags to show over lock screen
        Intent intent = new Intent(this, MainActivity.class);
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | 
                       Intent.FLAG_ACTIVITY_REORDER_TO_FRONT |
                       Intent.FLAG_ACTIVITY_SINGLE_TOP);
        
        // Pass all data as extras so React Native can access it if needed
        for (Map.Entry<String, String> entry : data.entrySet()) {
            intent.putExtra(entry.getKey(), entry.getValue());
        }

        startActivity(intent);

        if (wakeLock.isHeld()) {
            wakeLock.release();
        }
    }
}
