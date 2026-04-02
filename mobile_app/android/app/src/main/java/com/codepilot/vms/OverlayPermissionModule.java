package com.codepilot.vms;

import android.app.Activity;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.provider.Settings;
import android.view.WindowManager;
import com.facebook.react.bridge.Promise;
import com.facebook.react.bridge.ReactApplicationContext;
import com.facebook.react.bridge.ReactContextBaseJavaModule;
import com.facebook.react.bridge.ReactMethod;

public class OverlayPermissionModule extends ReactContextBaseJavaModule {
    private final ReactApplicationContext reactContext;

    public OverlayPermissionModule(ReactApplicationContext reactContext) {
        super(reactContext);
        this.reactContext = reactContext;
    }

    @Override
    public String getName() {
        return "OverlayPermissionModule";
    }

    @ReactMethod
    public void hasOverlayPermission(Promise promise) {
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                promise.resolve(Settings.canDrawOverlays(reactContext));
            } else {
                promise.resolve(true);
            }
        } catch (Exception e) {
            promise.reject("ERR_OVERLAY_PERMISSION", e.getMessage());
        }
    }

    @ReactMethod
    public void openOverlaySettings() {
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                // Use current activity if available for better deep-linking on some Android
                // versions
                Activity currentActivity = getCurrentActivity();
                Intent intent = new Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION);
                intent.setData(Uri.parse("package:" + reactContext.getPackageName()));

                if (currentActivity != null) {
                    currentActivity.startActivity(intent);
                } else {
                    intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                    reactContext.startActivity(intent);
                }
            }
        } catch (Exception e) {
            e.printStackTrace();
        }
    }

    @ReactMethod
    public void wakeUpApp() {
        // If the app is in foreground, just ensure screen is on
        if (getCurrentActivity() != null) {
            final Activity activity = getCurrentActivity();
            activity.runOnUiThread(new Runnable() {
                @Override
                public void run() {
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
                        activity.setShowWhenLocked(true);
                        activity.setTurnScreenOn(true);
                        activity.getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
                    } else {
                        activity.getWindow().addFlags(
                                WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED |
                                        WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON |
                                        WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON |
                                        WindowManager.LayoutParams.FLAG_DISMISS_KEYGUARD);
                    }
                }
            });
        }

        // ALWAYS try to bring to front/launch if called, to handle background/killed
        // states
        try {
            String packageName = reactContext.getPackageName();
            Intent intent = reactContext.getPackageManager().getLaunchIntentForPackage(packageName);
            if (intent != null) {
                intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK |
                        Intent.FLAG_ACTIVITY_REORDER_TO_FRONT |
                        Intent.FLAG_ACTIVITY_SINGLE_TOP);
                // KEY FIX: When app is killed, getCurrentActivity() is null so window flags
                // never run. We must wake the screen BEFORE launching by using PowerManager.
                try {
                    android.os.PowerManager pm = (android.os.PowerManager) reactContext
                            .getSystemService(android.content.Context.POWER_SERVICE);
                    if (pm != null && !pm.isInteractive()) {
                        // Screen is OFF - force it on
                        android.os.PowerManager.WakeLock wl = pm.newWakeLock(
                                android.os.PowerManager.FULL_WAKE_LOCK |
                                        android.os.PowerManager.ACQUIRE_CAUSES_WAKEUP |
                                        android.os.PowerManager.ON_AFTER_RELEASE,
                                "VMS:ScreenWake");
                        wl.acquire(5000); // 5 seconds - enough to show the UI
                    }
                } catch (Exception ignored) {
                }
                reactContext.startActivity(intent);
            }
        } catch (Exception e) {
            e.printStackTrace();
        }
    }
}
