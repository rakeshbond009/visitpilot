package com.codepilot.vms;

import android.app.Activity;
import android.app.KeyguardManager;
import android.content.Context;
import android.content.Intent;
import android.media.AudioAttributes;
import android.media.MediaPlayer;
import android.media.RingtoneManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Vibrator;
import android.view.WindowManager;
import android.view.View;
import android.widget.TextView;
import android.util.Log;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import org.json.JSONObject;

public class IncomingCallActivity extends Activity {

    private static final String TAG = "IncomingCallActivity";
    private MediaPlayer mediaPlayer;
    private Vibrator vibrator;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // 1. Wake Screen and Unlock
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setShowWhenLocked(true);
            setTurnScreenOn(true);
            KeyguardManager km = (KeyguardManager) getSystemService(Context.KEYGUARD_SERVICE);
            if (km != null) {
                km.requestDismissKeyguard(this, null);
            }
        } else {
            getWindow().addFlags(WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED |
                                WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON |
                                WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON |
                                WindowManager.LayoutParams.FLAG_DISMISS_KEYGUARD);
        }

        setContentView(R.layout.activity_incoming_call);

        // 2. Extract Data
        Bundle extras = getIntent().getExtras();
        String title = extras != null ? extras.getString("title", "Visitor Arrival") : "Visitor Arrival";
        String body = extras != null ? extras.getString("body", "A visitor is waiting") : "A visitor is waiting";
        String visitId = extras != null ? extras.getString("visit_id") : null;
        String visitorName = extras != null ? extras.getString("visitor_name") : null;
        String company = extras != null ? extras.getString("company") : null;

        TextView titleView = findViewById(R.id.call_title);
        TextView bodyView = findViewById(R.id.call_body);
        TextView detailsView = findViewById(R.id.visitor_details);

        titleView.setText(title);
        bodyView.setText(body);
        if (visitorName != null) {
            detailsView.setText(visitorName + (company != null ? " (" + company + ")" : ""));
        }

        // 3. Button Listeners
        findViewById(R.id.btn_accept).setOnClickListener(v -> handleAction(visitId, "approve"));
        findViewById(R.id.btn_reject).setOnClickListener(v -> handleAction(visitId, "reject"));

        // 4. Start Sound and Vibration
        startRinging();
    }

    private void startRinging() {
        try {
            Uri callSound = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE);
            if (callSound == null) {
                callSound = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION);
            }

            mediaPlayer = new MediaPlayer();
            mediaPlayer.setDataSource(this, callSound);
            
            AudioAttributes aa = new AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                    .build();
            
            mediaPlayer.setAudioAttributes(aa);
            mediaPlayer.setLooping(true);
            mediaPlayer.prepare();
            mediaPlayer.start();

            vibrator = (Vibrator) getSystemService(Context.VIBRATOR_SERVICE);
            if (vibrator != null) {
                long[] pattern = {0, 1000, 1000};
                vibrator.vibrate(pattern, 0);
            }
        } catch (Exception e) {
            Log.e(TAG, "Ringing error: " + e.getMessage());
        }
    }

    private void stopRinging() {
        if (mediaPlayer != null) {
            mediaPlayer.stop();
            mediaPlayer.release();
            mediaPlayer = null;
        }
        if (vibrator != null) {
            vibrator.cancel();
        }
    }

    private void handleAction(String visitId, String action) {
        stopRinging();
        
        if (visitId != null) {
             // In background, perform API call
             updateStatusOnServer(visitId, action);
        }

        // Launch the main app anyway to show the dashboard
        Intent mainIntent = new Intent(this, MainActivity.class);
        mainIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        startActivity(mainIntent);
        
        finish();
    }

    private void updateStatusOnServer(final String visitId, final String action) {
        new Thread(() -> {
            try {
                // Determine API URL (Hardcoded to production as requested)
                URL url = new URL("https://visitor.codepilotx.com/api/visit/status_action.php");
                HttpURLConnection conn = (HttpURLConnection) url.openConnection();
                conn.setRequestMethod("POST");
                conn.setRequestProperty("Content-Type", "application/json");
                conn.setDoOutput(true);

                JSONObject params = new JSONObject();
                params.put("visit_id", visitId);
                params.put("action", action);

                OutputStream os = conn.getOutputStream();
                os.write(params.toString().getBytes(StandardCharsets.UTF_8));
                os.flush();
                os.close();

                int responseCode = conn.getResponseCode();
                Log.d(TAG, "API Status Update Response: " + responseCode);
                conn.disconnect();
            } catch (Exception e) {
                Log.e(TAG, "API Update Error: " + e.getMessage());
            }
        }).start();
    }

    @Override
    public void onBackPressed() {
        // Disable back button - force action
    }

    @Override
    protected void onDestroy() {
        super.onDestroy();
        stopRinging();
    }
}
