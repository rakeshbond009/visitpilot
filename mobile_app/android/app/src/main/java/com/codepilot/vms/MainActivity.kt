package com.codepilot.vms

import android.app.KeyguardManager
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.util.Log
import android.view.WindowManager
import com.facebook.react.ReactActivity
import com.facebook.react.ReactActivityDelegate
import com.facebook.react.bridge.Arguments
import com.facebook.react.defaults.DefaultNewArchitectureEntryPoint.fabricEnabled
import com.facebook.react.defaults.DefaultReactActivityDelegate
import com.facebook.react.modules.core.DeviceEventManagerModule
import expo.modules.ReactActivityDelegateWrapper

class MainActivity : ReactActivity() {

  override fun onCreate(savedInstanceState: Bundle?) {
    // Set the theme to AppTheme BEFORE onCreate
    setTheme(R.style.AppTheme)
    
    // Always call setupWakeUp to ensure the window is ready
    setupWakeUp()
    
    super.onCreate(null)
    
    // Handle initial intent (e.g. from background)
    handleIntent(intent)
  }

  override fun onNewIntent(intent: Intent?) {
    super.onNewIntent(intent)
    setIntent(intent)
    setupWakeUp()
    handleIntent(intent)
  }

  private fun handleIntent(intent: Intent?) {
    intent?.let {
        // 1. Handle "native_action" from IncomingCallActivity
        if (it.hasExtra("native_action")) {
            val action = it.getStringExtra("native_action")
            val visitId = it.getStringExtra("visit_id")
            val bundle = Bundle().apply {
                putString("action", action)
                putString("visit_id", visitId)
            }
            emitToReactNative("onVisitorArrival", bundle)
        }
        
        // 2. Handle direct arrival data if MainActivity is launched directly
        // (This serves as a fallback or for non-priority notifications)
        else if (it.hasExtra("visit_id") || it.hasExtra("visitor_name")) {
            val bundle = Bundle()
            val extras = it.extras
            if (extras != null) {
                for (key in extras.keySet()) {
                    val value = extras.get(key)
                    if (value != null) {
                        bundle.putString(key, value.toString())
                    }
                }
            }
            emitToReactNative("showArrivalOverlay", bundle)
        }
    }
  }

  private fun emitToReactNative(eventName: String, params: Bundle) {
    try {
        val reactContext = reactNativeHost.reactInstanceManager.currentReactContext
        if (reactContext != null) {
            val map = Arguments.fromBundle(params)
            reactContext.getJSModule(DeviceEventManagerModule.RCTDeviceEventEmitter::class.java)
                .emit(eventName, map)
        } else {
            Log.w("MainActivity", "ReactContext is null, cannot emit $eventName")
        }
    } catch (e: Exception) {
        Log.e("MainActivity", "Emit Error: ${e.message}")
    }
  }

  private fun setupWakeUp() {
    Log.d("MainActivity", "Requesting Screen WakeUp and Keyguard Dismissal")
    
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
        setShowWhenLocked(true)
        setTurnScreenOn(true)
        val keyguardManager = getSystemService(Context.KEYGUARD_SERVICE) as KeyguardManager
        keyguardManager.requestDismissKeyguard(this, null)
    } 
    
    window.addFlags(
        WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or
        WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON or
        WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON or
        WindowManager.LayoutParams.FLAG_DISMISS_KEYGUARD or
        WindowManager.LayoutParams.FLAG_ALLOW_LOCK_WHILE_SCREEN_ON
    )
  }

  override fun getMainComponentName(): String = "main"

  override fun createReactActivityDelegate(): ReactActivityDelegate {
    return ReactActivityDelegateWrapper(this, fabricEnabled, DefaultReactActivityDelegate(
               this,
               mainComponentName,
               fabricEnabled
           ))
  }

  override fun invokeDefaultOnBackPressed() {
      if (Build.VERSION.SDK_INT <= Build.VERSION_CODES.R) {
          if (!moveTaskToBack(false)) {
              super.invokeDefaultOnBackPressed()
          }
          return
      }
      super.invokeDefaultOnBackPressed()
  }
}
