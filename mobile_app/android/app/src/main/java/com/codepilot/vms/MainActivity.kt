package com.codepilot.vms

import android.os.Build
import android.os.Bundle
import android.view.WindowManager

import com.facebook.react.ReactActivity
import com.facebook.react.ReactActivityDelegate
import com.facebook.react.defaults.DefaultNewArchitectureEntryPoint.fabricEnabled
import com.facebook.react.defaults.DefaultReactActivityDelegate
import expo.modules.ReactActivityDelegateWrapper


import com.facebook.react.bridge.Arguments
import com.facebook.react.modules.core.DeviceEventManagerModule
import android.app.KeyguardManager
import android.content.Context

class MainActivity : ReactActivity() {
  override fun onCreate(savedInstanceState: Bundle?) {
    // Set the theme to AppTheme BEFORE onCreate
    setTheme(R.style.AppTheme);
    super.onCreate(null)
    setupWakeUp()
    handleIntent(intent)
  }

  override fun onNewIntent(intent: android.content.Intent?) {
    super.onNewIntent(intent)
    setupWakeUp()
    handleIntent(intent)
  }

  private fun handleIntent(intent: android.content.Intent?) {
    if (intent == null) return
    
    // Extract extras from our specific notification flow
    if (intent.hasExtra("visit_id") || intent.hasExtra("visitor_name") || intent.hasExtra("type")) {
        val bundle = Bundle()
        val extras = intent.extras
        if (extras != null) {
            for (key in extras.keySet()) {
                val value = extras.get(key)
                if (value != null) {
                    bundle.putString(key, value.toString())
                }
            }
        }
        
        // Ensure "assets" is populated if "assets_carried" is present
        if (extras?.containsKey("assets_carried") == true && !extras.containsKey("assets")) {
            bundle.putString("assets", extras.getString("assets_carried"))
        }

        emitToReactNative("showArrivalOverlay", bundle)
    }
  }

  private fun emitToReactNative(eventName: String, params: Bundle) {
    try {
        val reactContext = reactNativeHost.reactInstanceManager.currentReactContext
        if (reactContext != null) {
            val map = Arguments.fromBundle(params)
            reactContext.getJSModule(DeviceEventManagerModule.RCTDeviceEventEmitter::class.java)
                .emit(eventName, map)
        }
    } catch (e: Exception) {
        // Silently fail if bridge isn't ready
    }
  }

  private fun setupWakeUp() {
    // --- CUSTOM PATCH: Wake Up & Show Over Lock Screen ---
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
        setShowWhenLocked(true)
        setTurnScreenOn(true)
        val keyguardManager = getSystemService(Context.KEYGUARD_SERVICE) as KeyguardManager
        keyguardManager.requestDismissKeyguard(this, null)
    } else {
        window.addFlags(
            WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or
            WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON or
            WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON or
            WindowManager.LayoutParams.FLAG_DISMISS_KEYGUARD
        )
    }
    window.addFlags(WindowManager.LayoutParams.FLAG_DISMISS_KEYGUARD)
    // Ensure screen stays on for the call
    window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
  }

  /**
   * Returns the name of the main component registered from JavaScript. This is used to schedule
   * rendering of the component.
   */
  override fun getMainComponentName(): String = "main"

  /**
   * Returns the instance of the [ReactActivityDelegate]. We use [DefaultReactActivityDelegate]
   * which allows you to enable New Architecture with a single boolean flags [fabricEnabled]
   */
  override fun createReactActivityDelegate(): ReactActivityDelegate {
    return ReactActivityDelegateWrapper(this, fabricEnabled, DefaultReactActivityDelegate(
               this,
               mainComponentName,
               fabricEnabled
           ))
  }

  /**
    * Align the back button behavior with Android S
    * where moving root activities to background instead of finishing activities.
    * @see <a href="https://developer.android.com/reference/android/app/Activity#onBackPressed()">onBackPressed</a>
    */
  override fun invokeDefaultOnBackPressed() {
      if (Build.VERSION.SDK_INT <= Build.VERSION_CODES.R) {
          if (!moveTaskToBack(false)) {
              // For non-root activities, use the default implementation to finish them.
              super.invokeDefaultOnBackPressed()
          }
          return
      }

      // Use the default back button implementation on Android S
      // because it's doing more than [Activity.moveTaskToBack] in fact.
      super.invokeDefaultOnBackPressed()
  }
}
