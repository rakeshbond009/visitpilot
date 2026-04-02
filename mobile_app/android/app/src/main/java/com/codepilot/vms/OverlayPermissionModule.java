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
        // 1. Hardware Wake (PowerManager) - Force screen on
        try {
            android.os.PowerManager pm = (android.os.PowerManager) reactContext.getSystemService(android.content.Context.POWER_SERVICE);
            if (pm != null) {
                // PARTIAL_WAKE_LOCK keeps CPU on, but we want the SCREEN on
                // ACQUIRE_CAUSES_WAKEUP + ON_AFTER_RELEASE forces screen on
                android.os.PowerManager.WakeLock wakeLock = pm.newWakeLock(
                    android.os.PowerManager.FULL_WAKE_LOCK |
                    android.os.PowerManager.ACQUIRE_CAUSES_WAKEUP |
                    android.os.PowerManager.ON_AFTER_RELEASE,
                    "VMS:WakeUpLock"
                );
                wakeLock.acquire(3000); // Hold for 3 seconds
            }
        } catch (Exception e) {
            e.printStackTrace();
        }

        // 2. Activity Wake (If app is foreground-ish)
        if (getCurrentActivity() != null) {
            final Activity activity = getCurrentActivity();
            activity.runOnUiThread(new Runnable() {
                @Override
                public void run() {
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
                        activity.setShowWhenLocked(true);
                        activity.setTurnScreenOn(true);
                        activity.getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON |
                                                       WindowManager.LayoutParams.FLAG_ALLOW_LOCK_WHILE_SCREEN_ON |
                                                       WindowManager.LayoutParams.FLAG_DISMISS_KEYGUARD);
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

        // 3. Launch/Bring to Front
        try {
            String packageName = reactContext.getPackageName();
            Intent intent = reactContext.getPackageManager().getLaunchIntentForPackage(packageName);
            if (intent != null) {
                intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | 
                                Intent.FLAG_ACTIVITY_REORDER_TO_FRONT |
                                Intent.FLAG_ACTIVITY_SINGLE_TOP);
                reactContext.startActivity(intent);
            }
        } catch (Exception e) {
            e.printStackTrace();
        }
    }
}
