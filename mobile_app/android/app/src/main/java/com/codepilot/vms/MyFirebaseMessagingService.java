package com.codepilot.vms;

import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.graphics.Color;
import android.media.AudioAttributes;
import android.media.RingtoneManager;
import android.net.Uri;
import android.os.Build;
import android.os.PowerManager;
import androidx.core.app.NotificationCompat;
import com.google.firebase.messaging.FirebaseMessagingService;
import com.google.firebase.messaging.RemoteMessage;
import java.util.Map;

public class MyFirebaseMessagingService extends FirebaseMessagingService {

    private static final String CHANNEL_ID = "vms_call_service_v1";

    @Override
    public void onMessageReceived(RemoteMessage remoteMessage) {
        super.onMessageReceived(remoteMessage);

        Map<String, String> data = remoteMessage.getData();
        if (data == null || data.isEmpty()) return;

        String type = data.get("type");
        // Only trigger full-screen for visitor_arrival
        if (!"visitor_arrival".equals(type) && !"true".equals(data.get("is_call_priority"))) {
            return;
        }

        String title = data.containsKey("title") ? data.get("title") : "Visitor Arrival";
        String body = data.containsKey("body") ? data.get("body") : "A visitor is waiting at the gate";

        // 1. Force screen wake
        PowerManager pm = (PowerManager) getSystemService(Context.POWER_SERVICE);
        PowerManager.WakeLock wakeLock = pm.newWakeLock(PowerManager.FULL_WAKE_LOCK | 
                PowerManager.ACQUIRE_CAUSES_WAKEUP | 
                PowerManager.ON_AFTER_RELEASE, "VisitPilot:CallWakeLock");
        wakeLock.acquire(15000); // 15 seconds

        // 2. Prepare Intent
        Intent fullScreenIntent = new Intent(this, IncomingCallActivity.class);
        for (Map.Entry<String, String> entry : data.entrySet()) {
            fullScreenIntent.putExtra(entry.getKey(), entry.getValue());
        }
        fullScreenIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | 
                                 Intent.FLAG_ACTIVITY_NO_USER_ACTION | 
                                 Intent.FLAG_ACTIVITY_EXCLUDE_FROM_RECENTS);

        // 3. Start Activity Immediately
        startActivity(fullScreenIntent);

        // 4. Show Notification with fullScreenIntent for system compatibility
        showCallNotification(title, body, fullScreenIntent);

        if (wakeLock.isHeld()) {
            wakeLock.release();
        }
    }

    private void showCallNotification(String title, String body, Intent intent) {
        NotificationManager notificationManager = (NotificationManager) getSystemService(Context.NOTIFICATION_SERVICE);
        
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel channel = new NotificationChannel(CHANNEL_ID, "Visitor Alerts", NotificationManager.IMPORTANCE_HIGH);
            channel.setDescription("Critical alerts for visitor arrival");
            channel.enableLights(true);
            channel.setLightColor(Color.RED);
            channel.setBypassDnd(true);
            channel.setLockscreenVisibility(NotificationCompat.VISIBILITY_PUBLIC);
            notificationManager.createNotificationChannel(channel);
        }

        int flags = PendingIntent.FLAG_UPDATE_CURRENT;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            flags |= PendingIntent.FLAG_IMMUTABLE;
        }

        PendingIntent pendingIntent = PendingIntent.getActivity(this, (int)System.currentTimeMillis(), intent, flags);

        NotificationCompat.Builder builder = new NotificationCompat.Builder(this, CHANNEL_ID)
                .setSmallIcon(android.R.drawable.ic_dialog_info)
                .setContentTitle(title)
                .setContentText(body)
                .setPriority(NotificationCompat.PRIORITY_MAX)
                .setCategory(NotificationCompat.CATEGORY_CALL)
                .setFullScreenIntent(pendingIntent, true)
                .setAutoCancel(true)
                .setOngoing(true)
                .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
                .setSound(RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE));

        notificationManager.notify(991, builder.build());
    }
}
