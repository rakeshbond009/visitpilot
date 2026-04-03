package com.codepilot.vms;

import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.media.RingtoneManager;
import android.os.Build;
import android.os.PowerManager;
import android.util.Log;
import androidx.core.app.NotificationCompat;
import com.google.firebase.messaging.FirebaseMessagingService;
import com.google.firebase.messaging.RemoteMessage;
import java.util.Map;

public class MyFirebaseMessagingService extends FirebaseMessagingService {

    private static final String TAG = "MyFirebaseMsgService";

    private static final String CHANNEL_ID = "vms_urgent_alerts_v5";

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

        Log.d(TAG, "Arrival detected! Waking up app with Full Screen Intent...");

        // 1. Force screen wake (fallback)
        PowerManager pm = (PowerManager) getSystemService(Context.POWER_SERVICE);
        PowerManager.WakeLock wakeLock = pm.newWakeLock(PowerManager.FULL_WAKE_LOCK | 
                PowerManager.ACQUIRE_CAUSES_WAKEUP | 
                PowerManager.ON_AFTER_RELEASE, "VisitPilot:CallWakeLock");
        wakeLock.acquire(10000);

        // 2. Prepare Intent for IncomingCallActivity (NATIVE CALL SCREEN)
        Intent intent = new Intent(this, IncomingCallActivity.class);
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | 
                       Intent.FLAG_ACTIVITY_NO_USER_ACTION | 
                       Intent.FLAG_ACTIVITY_EXCLUDE_FROM_RECENTS);
        
        for (Map.Entry<String, String> entry : data.entrySet()) {
            intent.putExtra(entry.getKey(), entry.getValue());
        }

        // 3. LAUNCH DIRECTLY (Aggressive)
        startActivity(intent);

        // 4. SHOW HIGH-PRIORITY NOTIFICATION WITH FULL SCREEN INTENT
        showNotification(data.get("title"), data.get("body"), intent);

        if (wakeLock.isHeld()) {
            wakeLock.release();
        }
    }

    private void showNotification(String title, String body, Intent intent) {
        NotificationManager notificationManager = (NotificationManager) getSystemService(Context.NOTIFICATION_SERVICE);
        
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel channel = new NotificationChannel(CHANNEL_ID, "Urgent Visitor Arrivals", NotificationManager.IMPORTANCE_HIGH);
            channel.setDescription("Shows alerts for arriving visitors");
            channel.enableLights(true);
            channel.enableVibration(true);
            channel.setBypassDnd(true);
            channel.setLockscreenVisibility(android.app.Notification.VISIBILITY_PUBLIC);
            notificationManager.createNotificationChannel(channel);
        }

        int flags = PendingIntent.FLAG_UPDATE_CURRENT;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            flags |= PendingIntent.FLAG_IMMUTABLE;
        }

        PendingIntent pendingIntent = PendingIntent.getActivity(this, (int) System.currentTimeMillis(), intent, flags);

        androidx.core.app.NotificationCompat.Builder builder = new androidx.core.app.NotificationCompat.Builder(this, CHANNEL_ID)
                .setSmallIcon(R.drawable.notification_icon)
                .setContentTitle(title != null ? title : "Visitor Arrival")
                .setContentText(body != null ? body : "A visitor is waiting at the gate")
                .setPriority(androidx.core.app.NotificationCompat.PRIORITY_MAX)
                .setCategory(androidx.core.app.NotificationCompat.CATEGORY_CALL)
                .setFullScreenIntent(pendingIntent, true)
                .setAutoCancel(true)
                .setOngoing(true)
                .setVisibility(androidx.core.app.NotificationCompat.VISIBILITY_PUBLIC)
                .setSound(RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE));

        notificationManager.notify(1001, builder.build());
    }
}
