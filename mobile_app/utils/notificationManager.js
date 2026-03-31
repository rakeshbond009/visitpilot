import * as Notifications from 'expo-notifications';
import * as Device from 'expo-device';
import Constants from 'expo-constants';
import { Platform, Alert, Linking as RNLinking } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import apiClient from './apiClient';

// For Overlay permission (Appear on top)
import { NativeModules } from 'react-native';
const OverlayPermissionModule = NativeModules?.OverlayPermissionModule;

Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowAlert: true,
    shouldPlaySound: true,
    shouldSetBadge: true,
  }),
});

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
      // Note: We use a persistent key that isn't cleared on logout if we use removeItem('userData') instead of clear()
      const storageKey = userId ? `overlay_perm_prompt_v4_${userId}` : 'overlay_perm_prompt_v4_generic';
      
      const hasRequested = await AsyncStorage.getItem(storageKey);
      console.log(`[Permission Check] User: ${userId}, Key: ${storageKey}, PromptedBefore: ${hasRequested}`);

      if (hasRequested === 'true') {
        // We already prompted the user once, don't bother them again on every login
        return;
      }

      // If we are here, we should prompt
      Alert.alert(
        "Enable Full Screen Alerts",
        "To receive visitor approval calls on your lock screen, you MUST enable 'Appear on top' permission. We will now take you to the specific setting to enable it for 'VMS'.",
        [
          { 
            text: "Skip", 
            style: "cancel",
            onPress: async () => {
                 // Mark as prompted to avoid loop
                 await AsyncStorage.setItem(storageKey, 'true');
            }
          },
          {
            text: "Open Settings",
            onPress: async () => {
              // Mark as prompted
              await AsyncStorage.setItem(storageKey, 'true');
              
              // 3. Open the SPECIFIC overlay settings page instead of generic app settings
              if (OverlayPermissionModule && OverlayPermissionModule.openOverlaySettings) {
                  OverlayPermissionModule.openOverlaySettings();
              } else {
                  // Fallback to generic if module fails
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

export async function registerForPushNotificationsAsync() {
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
    // Alert.alert('Notice', 'Running on a simulator. Native tokens are not available.');
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

  // CRITICAL: For direct FCM V1/APNS backend, we MUST use the native device token
  try {
    const deviceToken = (await Notifications.getDevicePushTokenAsync()).data;
    console.log("Native Device Token obtained:", deviceToken);
    token = deviceToken;
  } catch (e) {
    console.log("Error getting native device token:", e);
    // Alert.alert('Native Token Error', e.message || 'Unknown error');
    // Fallback
    try {
      const projectId = Constants?.expoConfig?.extra?.eas?.projectId;
      token = (await Notifications.getExpoPushTokenAsync({
        ...(projectId ? { projectId } : {})
      })).data;
      console.log("Fallback Expo Push Token obtained:", token);
    } catch (expoError) {
      console.log("All token retrieval methods failed:", expoError);
      Alert.alert('Fatal Token Error', 'Native: ' + (e.message || 'None') + '\nExpo: ' + (expoError.message || 'None'));
    }
  }

  return token;
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
