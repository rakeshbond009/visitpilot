import * as Notifications from 'expo-notifications';
import * as Device from 'expo-device';
import Constants from 'expo-constants';
import { Platform, Alert, Linking as RNLinking } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import apiClient from './apiClient';

// For Overlay permission (Appear on top)
import { NativeModules } from 'react-native';
const OverlayPermissionModule = NativeModules?.OverlayPermissionModule;

export async function checkOverlayPermission(userId = null) {
  if (Platform.OS === 'android') {
    try {
      if (OverlayPermissionModule && OverlayPermissionModule.hasOverlayPermission) {
        const isGranted = await OverlayPermissionModule.hasOverlayPermission();
        if (isGranted) return;
      }

      const storageKey = userId ? `overlay_perm_prompt_v4_${userId}` : 'overlay_perm_prompt_v4_generic';
      const hasRequested = await AsyncStorage.getItem(storageKey);
      if (hasRequested === 'true') return;

      Alert.alert(
        "Enable Full Screen Alerts",
        "To receive visitor approval calls on your lock screen, you MUST enable 'Appear on top' permission.",
        [
          { text: "Skip", style: "cancel", onPress: () => AsyncStorage.setItem(storageKey, 'true') },
          { 
            text: "Open Settings", 
            onPress: () => {
              AsyncStorage.setItem(storageKey, 'true');
              if (OverlayPermissionModule && OverlayPermissionModule.openOverlaySettings) {
                OverlayPermissionModule.openOverlaySettings();
              } else {
                RNLinking.openSettings();
              }
            }
          }
        ]
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

    await Notifications.setNotificationChannelAsync('vms_status_updates', {
      name: 'Visit Status Updates',
      importance: Notifications.AndroidImportance.MAX,
      sound: 'default',
      vibrationPattern: [0, 250, 250, 250],
      lightColor: '#0d6efd',
      lockscreenVisibility: Notifications.AndroidNotificationVisibility.PUBLIC,
    });
  }

  if (!Device.isDevice) return null;

  const { status: existingStatus } = await Notifications.getPermissionsAsync();
  let finalStatus = existingStatus;
  if (existingStatus !== 'granted') {
    const { status } = await Notifications.requestPermissionsAsync();
    finalStatus = status;
  }

  if (finalStatus !== 'granted') return null;

  await AsyncStorage.removeItem('cached_fcm_token');
  await AsyncStorage.removeItem('last_fcm_token');

  for (let attempt = 1; attempt <= retryCount; attempt++) {
    try {
      const result = await Notifications.getDevicePushTokenAsync();
      token = result.data;
      if (token && token.length > 20) {
        await AsyncStorage.setItem('cached_fcm_token', token);
        return token;
      }
    } catch (e) {}
    if (attempt < retryCount) await new Promise(resolve => setTimeout(resolve, retryDelayMs));
  }

  try {
    const cached = await AsyncStorage.getItem('cached_fcm_token');
    if (cached) return cached;
  } catch (e) {}

  return null;
}

export async function updateTokenOnServer(token) {
  if (!token) return;
  try {
    const userData = await AsyncStorage.getItem('userData');
    if (!userData) return;

    const response = await apiClient.post('api/user/update_fcm.php', { fcm_token: token });
    if (response.data.success) {
      await AsyncStorage.setItem('last_fcm_token', token);
    }
  } catch (error) {
    console.log("Token update failed:", error.message);
  }
}
