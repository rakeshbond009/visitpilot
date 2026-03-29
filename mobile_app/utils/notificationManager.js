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
      // DEBUG: Force reset for testing if needed
      // await AsyncStorage.removeItem(`overlay_permission_requested_${userId}`);

      // MODIFIED: Changed key version to 'v3' to force re-prompt for all users in this update
      const storageKey = userId ? `overlay_permission_v3_${userId}` : 'overlay_permission_v3_generic';
      
      const hasRequested = await AsyncStorage.getItem(storageKey);
      console.log(`[Permission Check] User: ${userId}, Key: ${storageKey}, Status: ${hasRequested}`);

      if (!userId) {
          console.error("[Permission Check] CRITICAL: userId is missing! Using generic key.");
      }

      if (hasRequested === 'true') {
        // Already requested and presumably handled.
        // For debugging, you might want to comment this out to force it every time.
        return;
      }

      // If we are here, we should prompt
      Alert.alert(
        "Enable Full Screen Alerts",
        "To receive visitor approval calls on your lock screen, you MUST enable 'Appear on top' permission. Please find 'VMS' in the list and enable it.",
        [
          { 
            text: "Skip", 
            style: "cancel",
            onPress: async () => {
                 // Mark as requested, but maybe we should allow re-prompt later?
                 // For now, mark true to avoid loop
                 await AsyncStorage.setItem(storageKey, 'true');
            }
          },
          {
            text: "Open Settings",
            onPress: async () => {
              RNLinking.openSettings();
              await AsyncStorage.setItem(storageKey, 'true');
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
    const response = await apiClient.post('api/user/update_fcm.php', {
      fcm_token: token
    });
    if (response.data.success) {
      await AsyncStorage.setItem('last_fcm_token', token);
      console.log("FCM Token updated on server");
    }
  } catch (error) {
    console.error("Failed to update FCM token on server:", error);
  }
}
