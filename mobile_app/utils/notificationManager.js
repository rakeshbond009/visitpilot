import * as Notifications from 'expo-notifications';
import * as Device from 'expo-device';
import Constants from 'expo-constants';
import { Platform, Alert, Linking as RNLinking } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import apiClient from './apiClient';

// For Overlay permission (Appear on top)
import * as IntentLauncher from 'expo-intent-launcher';
import { NativeModules } from 'react-native';
const OverlayPermissionModule = NativeModules?.OverlayPermissionModule;

// NOTE: setNotificationHandler is set centrally in App.js — do NOT set it here to avoid conflicts.

export async function checkOverlayPermission(userId = null) {
  if (Platform.OS === 'android') {
    try {
      // 1. Check if permission is ALREADY granted natively
      if (OverlayPermissionModule && OverlayPermissionModule.hasOverlayPermission) {
        const isGranted = await OverlayPermissionModule.hasOverlayPermission();
        if (isGranted) {
          console.log("[Permission Check] Overlay permission is already GRANTED.");
          return;
        }
      }

      // 2. If not granted, check if we've already prompted the user to avoid annoyance
      const storageKey = userId ? `overlay_perm_prompt_v5_${userId}` : 'overlay_perm_prompt_v5_generic';

      const hasRequested = await AsyncStorage.getItem(storageKey);
      if (hasRequested === 'true') return;

      // If we are here, we should prompt
      Alert.alert(
        "Enable Full Screen Alerts",
        "To receive visitor approval calls on your lock screen, you MUST enable 'Appear on top' permission. Click 'Open Settings' and look for 'VisitPilot' in the list.",
        [
          {
            text: "Skip",
            style: "cancel",
            onPress: async () => await AsyncStorage.setItem(storageKey, 'true')
          },
          {
            text: "Open Settings",
            onPress: async () => {
              await AsyncStorage.setItem(storageKey, 'true');
              if (Platform.OS === 'android') {
                const pkg = Constants?.expoConfig?.android?.package || 'com.codepilotx.vms';
                // Try to open the specific overlay permission page directly
                try {
                  await IntentLauncher.startActivityAsync('android.settings.action.MANAGE_OVERLAY_PERMISSION', {
                    data: `package:${pkg}`,
                  });
                } catch (err) {
                  // Fallback to app settings if direct intent fails
                  RNLinking.openSettings();
                }
              } else {
                RNLinking.openSettings();
              }
            }
          }
        ],
        { cancelable: false }
      );
    } catch (e) {
      console.log("Error checking overlay permission status", e);
    }
  }
}

export async function registerForPushNotificationsAsync(retryCount = 3, retryDelayMs = 2000) {
  let token;

  if (Platform.OS === 'android') {
    await Notifications.setNotificationChannelAsync('default', {
      name: 'Default',
      importance: Notifications.AndroidImportance.MAX,
      vibrationPattern: [0, 250, 250, 250],
      lightColor: '#FF231F7C',
    });

    await Notifications.setNotificationChannelAsync('vms_urgent_alerts_v2', {
      name: 'Visitor Alerts (Urgent V2)',
      importance: Notifications.AndroidImportance.MAX,
      sound: 'default',
      vibrationPattern: [0, 250, 250, 250],
      lightColor: '#FF231F7C',
      lockscreenVisibility: Notifications.AndroidNotificationVisibility.PUBLIC,
      bypassDnd: true,
    });
  }

  if (!Device.isDevice) {
    console.log('Must use physical device for Push Notifications');
    return null;
  }

  const { status: existingStatus } = await Notifications.getPermissionsAsync();
  let finalStatus = existingStatus;
  if (existingStatus !== 'granted') {
    const { status } = await Notifications.requestPermissionsAsync();
    finalStatus = status;
  }

  if (finalStatus !== 'granted') {
    return null;
  }

  // Always clear cached token on first init to ensure it's not stale/unregistered
  await AsyncStorage.removeItem('cached_fcm_token');
  await AsyncStorage.removeItem('last_fcm_token');

  for (let attempt = 1; attempt <= retryCount; attempt++) {
    try {
      const result = await Notifications.getDevicePushTokenAsync();
      token = result.data;
      if (token && token.length > 20) {
        console.log(`[FCM] Native Device Token obtained (attempt ${attempt}):`, token.substring(0, 20) + '...');
        await AsyncStorage.setItem('cached_fcm_token', token);
        return token;
      }
      console.log(`[FCM] Token empty on attempt ${attempt}, retrying...`);
    } catch (e) {
      console.log(`[FCM] getDevicePushTokenAsync failed (attempt ${attempt}):`, e.message);
    }
    if (attempt < retryCount) {
      await new Promise(resolve => setTimeout(resolve, retryDelayMs));
    }
  }

  try {
    const cached = await AsyncStorage.getItem('cached_fcm_token');
    if (cached && cached.length > 20) {
      console.log('[FCM] Using cached token as fallback:', cached.substring(0, 20) + '...');
      return cached;
    }
  } catch (e) { }

  try {
    const projectId = Constants?.expoConfig?.extra?.eas?.projectId;
    token = (await Notifications.getExpoPushTokenAsync({
      ...(projectId ? { projectId } : {})
    })).data;
    console.log('[FCM] Fallback Expo Push Token obtained:', token ? token.substring(0, 20) + '...' : 'null');
    return token;
  } catch (expoError) {
    console.log('[FCM] All token retrieval methods failed:', expoError.message);
  }

  return null;
}

export async function updateTokenOnServer(token) {
  if (!token) return;
  try {
    // Check if user is logged in
    const userData = await AsyncStorage.getItem('userData');
    if (!userData) {
      console.log("Skipping FCM token update: No user session active yet.");
      return;
    }

    const response = await apiClient.post('api/user/update_fcm.php', {
      fcm_token: token
    });
    if (response.data.success) {
      await AsyncStorage.setItem('last_fcm_token', token);
      console.log("FCM Token updated on server");
    }
  } catch (error) {
    // Downgraded to console.log to avoid the Red Error Screen on ephemeral network or session issues
    console.log("Failed to update FCM token on server (likely non-critical session issue):", error.message);
  }
}
