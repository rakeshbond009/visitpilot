package com.codepilot.vms;

import android.app.Notification;
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

import androidx.core.app.NotificationCompat;

import com.google.firebase.messaging.FirebaseMessagingService;
import com.google.firebase.messaging.RemoteMessage;

import java.util.Map;

/**
 * Native FCM handler — works in ALL states: killed, background, foreground.
 * Posts a full-screen "call-style" notification so Android wakes the screen
 * and shows the app even from the lock screen.
 */
public class MyFirebaseMessagingService extends FirebaseMessagingService {

    private static final String CHANNEL_ID   = "vms_call_channel";
    private static final String CHANNEL_NAME = "Visitor Arrival Calls";
    private static final int    NOTIF_ID     = 9001;

    @Override
    public void onMessageReceived(RemoteMessage remoteMessage) {
        super.onMessageReceived(remoteMessage);

        Map<String, String> data = remoteMessage.getData();
        if (data == null || data.isEmpty()) return;

        String type           = data.get("type");
        String isCallPriority = data.get("is_call_priority");

        // Only process visitor arrival / call-priority messages
        boolean isArrival = "visitor_arrival".equals(type) || "true".equals(isCallPriority);
        if (!isArrival) return;

        String title   = data.containsKey("title")   ? data.get("title")   : "Visitor Arrival";
        String body    = data.containsKey("body")     ? data.get("body")    : "A visitor is waiting at the gate";
        String visitId = data.containsKey("visit_id") ? data.get("visit_id") : "";

        showFullScreenNotification(title, body, visitId, data);
    }

    private void showFullScreenNotification(String title, String body,
                                             String visitId, Map<String, String> data) {
        NotificationManager nm =
                (NotificationManager) getSystemService(Context.NOTIFICATION_SERVICE);
        if (nm == null) return;

        // 1. Create / update the channel (required on Android 8+)
        createNotificationChannel(nm);

        // 2. Intent that opens MainActivity when user taps notification
        Intent mainIntent = getPackageManager().getLaunchIntentForPackage(getPackageName());
        if (mainIntent == null) {
            mainIntent = new Intent(this, MainActivity.class);
        }
        mainIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK
                | Intent.FLAG_ACTIVITY_CLEAR_TOP
                | Intent.FLAG_ACTIVITY_SINGLE_TOP);
        // Pass the data through so App.js can pick it up
        for (Map.Entry<String, String> entry : data.entrySet()) {
            mainIntent.putExtra(entry.getKey(), entry.getValue());
        }

        int flags = PendingIntent.FLAG_UPDATE_CURRENT;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            flags |= PendingIntent.FLAG_IMMUTABLE;
        }

        PendingIntent contentIntent = PendingIntent.getActivity(
                this, NOTIF_ID, mainIntent, flags);

        // 3. Full-screen intent — this is what wakes the lock screen
        PendingIntent fullScreenIntent = PendingIntent.getActivity(
                this, NOTIF_ID + 1, mainIntent, flags);

        // 4. Sound URI (default ringtone gives audible alert)
        Uri soundUri = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE);
        if (soundUri == null) {
            soundUri = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION);
        }

        // 5. Build the notification
        NotificationCompat.Builder builder = new NotificationCompat.Builder(this, CHANNEL_ID)
                .setSmallIcon(R.drawable.notification_icon)
                .setContentTitle(title)
                .setContentText(body)
                .setStyle(new NotificationCompat.BigTextStyle().bigText(body))
                .setPriority(NotificationCompat.PRIORITY_MAX)
                .setCategory(NotificationCompat.CATEGORY_CALL)   // treat as incoming call
                .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
                .setAutoCancel(true)
                .setOngoing(true)                                  // can't be swiped away
                .setSound(soundUri)
                .setVibrate(new long[]{0, 500, 200, 500, 200, 500})
                .setLights(Color.RED, 500, 500)
                .setContentIntent(contentIntent)
                .setFullScreenIntent(fullScreenIntent, true);      // <-- THE KEY CALL

        // 6. Post it
        nm.notify(NOTIF_ID, builder.build());
    }

    /**
     * Creates an Android 8+ notification channel with the highest importance,
     * a default ringtone, and lock-screen PUBLIC visibility.
     */
    private void createNotificationChannel(NotificationManager nm) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return;

        NotificationChannel existing = nm.getNotificationChannel(CHANNEL_ID);
        if (existing != null) return; // already created — do not overwrite user settings

        NotificationChannel channel = new NotificationChannel(
                CHANNEL_ID, CHANNEL_NAME, NotificationManager.IMPORTANCE_HIGH);
        channel.setDescription("Full-screen alerts for visitor arrivals");
        channel.enableLights(true);
        channel.setLightColor(Color.RED);
        channel.enableVibration(true);
        channel.setVibrationPattern(new long[]{0, 500, 200, 500, 200, 500});
        channel.setLockscreenVisibility(Notification.VISIBILITY_PUBLIC);
        channel.setBypassDnd(true);

        // Attach ringtone so the channel itself plays sound
        Uri soundUri = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE);
        if (soundUri != null) {
            AudioAttributes audioAttrs = new AudioAttributes.Builder()
                    .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                    .setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE)
                    .build();
            channel.setSound(soundUri, audioAttrs);
        }

        nm.createNotificationChannel(channel);
    }
}
