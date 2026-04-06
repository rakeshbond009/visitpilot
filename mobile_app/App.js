import 'react-native-gesture-handler';
import React, { useEffect, useRef, useState } from 'react';
import { View, Text, Alert, Linking, Platform, ActivityIndicator, Vibration, DeviceEventEmitter } from 'react-native';
import { NavigationContainer } from '@react-navigation/native';
import { createStackNavigator } from '@react-navigation/stack';
import { StatusBar } from 'expo-status-bar';
import * as Notifications from 'expo-notifications';
import * as TaskManager from 'expo-task-manager';
import { Audio } from 'expo-av';
import AsyncStorage from '@react-native-async-storage/async-storage';

// Components
import IncomingCallScreen from './components/IncomingCallScreen';
import { APP_VERSION } from './constants';

const BACKGROUND_NOTIFICATION_TASK = 'BACKGROUND_NOTIFICATION_TASK';

// For Overlay permission (Appear on top)
import { NativeModules } from 'react-native';
const OverlayPermissionModule = NativeModules?.OverlayPermissionModule;

// Utils
import apiClient from './utils/apiClient';
import { registerForPushNotificationsAsync, updateTokenOnServer } from './utils/notificationManager';

// Screens
import LoginScreen from './screens/LoginScreen';
import HostDashboard from './screens/HostDashboard';
import SecurityDashboard from './screens/SecurityDashboard';
import AdminDashboard from './screens/AdminDashboard';
import InviteVisitor from './screens/InviteVisitor';
import RegisterVisitor from './screens/RegisterVisitor';
import VisitorReports from './screens/VisitorReports';
import EmployeeReport from './screens/EmployeeReport';
import MyVisitorsHistory from './screens/MyVisitorsHistory';
import ComingSoon from './screens/ComingSoon';

// Context
import { PermissionProvider, usePermissions } from './context/PermissionContext';

// Configure notification handler
Notifications.setNotificationHandler({
    handleNotification: async () => ({
        shouldShowAlert: true,
        shouldPlaySound: true,
        shouldSetBadge: true,
    }),
});

const BACKGROUND_TASK_TIMEOUT = 10000;

TaskManager.defineTask(BACKGROUND_NOTIFICATION_TASK, async ({ data, error }) => {
    if (error) {
        console.error("[BG Task] Error:", error);
        return;
    }

    console.log("[BG Task] Triggered with data:", JSON.stringify(data));

    let payload = data?.notification?.data || data;

    if (payload && (payload.type === 'visitor_arrival' || payload.is_call_priority === 'true')) {
        console.log("[BG Task] Valid arrival detected. Waking up...");

        try {
            await AsyncStorage.setItem('pending_arrival_call', JSON.stringify(payload));
        } catch (storageErr) {
            console.error("[BG Task] Storage Error:", storageErr.message);
        }

        if (OverlayPermissionModule && OverlayPermissionModule.wakeUpApp) {
            OverlayPermissionModule.wakeUpApp();
        }

        await Notifications.setNotificationChannelAsync('visit_status_v1', {
            name: 'Visit Status Updates',
            importance: Notifications.AndroidImportance.DEFAULT,
            sound: 'default',
        });

        await Notifications.scheduleNotificationAsync({
            content: {
                title: payload.title || "Visitor Arrival",
                body: payload.body || "A visitor is waiting at the gate",
                data: payload,
                categoryIdentifier: 'visitor_arrival',
                sound: true,
                bypassDnd: true,
                priority: Notifications.AndroidNotificationPriority.MAX,
            },
            trigger: null,
            channelId: 'vms_urgent_alerts_v2',
        });
    }
});

const Stack = createStackNavigator();
const navigationRef = React.createRef();

const linking = {
    prefixes: ['https://visitor.visitpilot.com', 'com.visitpilot.vms://'],
    config: {
        screens: {
            Login: 'login',
            HostDashboard: 'host',
            SecurityDashboard: 'security',
            AdminDashboard: 'admin',
            MyVisitorsHistory: 'history',
        },
    },
};

export default function App() {
    return (
        <PermissionProvider>
            <AppContent />
        </PermissionProvider>
    );
}

function AppContent() {
    const notificationListener = useRef();
    const responseListener = useRef();
    const [showOverlay, setShowOverlay] = useState(false);
    const [arrivalData, setArrivalData] = useState(null);
    const [sound, setSound] = useState(null);

    const { role, hasPermission, loading } = usePermissions();

    const standardizeArrivalData = (raw) => {
        if (!raw) return null;
        let data = raw.data || raw;
        if (typeof data === 'string') {
            try { data = JSON.parse(data); } catch (e) { }
        }

        // Deep picker: search for visit_id and type in any nested level
        const findKey = (obj, key) => {
            if (!obj || typeof obj !== 'object') return null;
            if (obj[key]) return obj[key];
            for (let k in obj) {
                let res = findKey(obj[k], key);
                if (res) return res;
            }
            return null;
        };

        const visitId = findKey(raw, 'visit_id') || findKey(raw, 'visitId') || findKey(raw, 'id');
        const type = findKey(raw, 'type') || "visit_update";

        return {
            visit_id: String(visitId || ""),
            type: type,
            title: raw.title || findKey(raw, 'title'),
            body: raw.body || findKey(raw, 'body')
        };
    };

    // --- RINGING & VIBRATION ---
    useEffect(() => {
        let isLooping = true;

        async function startRinging() {
            if (showOverlay) {
                try {
                    await Audio.setAudioModeAsync({
                        allowsRecordingIOS: false,
                        staysActiveInBackground: true,
                        interruptionModeIOS: 1,
                        playsInSilentModeIOS: true,
                        shouldDuckAndroid: true,
                        interruptionModeAndroid: 1,
                        playThroughEarpieceAndroid: false
                    });

                    for (const url of urls) {
                        try {
                            const { sound: newSound } = await Audio.Sound.createAsync(
                                { uri: url },
                                { shouldPlay: true, isLooping: true, volume: 1.0 }
                            );
                            if (isLooping) {
                                setSound(newSound);
                                break;
                            } else {
                                newSound.unloadAsync();
                            }
                        } catch (e) { }
                    }
                } catch (e) { }

                if (isLooping) Vibration.vibrate([1000, 1000, 1000], true);
            } else {
                isLooping = false;
                if (sound) {
                    sound.stopAsync().catch(() => { });
                    sound.unloadAsync().catch(() => { });
                    setSound(null);
                }
                Vibration.cancel();
            }
        }

        startRinging();
        return () => {
            isLooping = false;
            Vibration.cancel();
        };
    }, [showOverlay]);

    // --- AUTO DISMISS ---
    useEffect(() => {
        let timer;
        if (showOverlay) {
            timer = setTimeout(() => setShowOverlay(false), 60000);
        }
        return () => clearTimeout(timer);
    }, [showOverlay]);

    // --- NOTIFICATION LISTENERS ---
    useEffect(() => {
        const subscription = DeviceEventEmitter.addListener('showArrivalOverlay', (data) => {
            const standardized = standardizeArrivalData(data);
            if (standardized) {
                setArrivalData(standardized);
                setShowOverlay(true);
            }
        });

        setTimeout(() => {
            registerForPushNotificationsAsync(3, 2000).then(token => {
                if (token) updateTokenOnServer(token);
            });
        }, 5000);

        const checkNotifications = async () => {
            try {
                const response = await Notifications.getLastNotificationResponseAsync();
                if (response) {
                    const data = standardizeArrivalData(response.notification.request.content.data);
                    if (data && (data.type === 'visitor_arrival' || data.is_call_priority === 'true')) {
                        setArrivalData(data); setShowOverlay(true); return true;
                    } else if (data && data.type === 'visit_update') {
                        // Cold start deep link
                        if (navigationRef.current) {
                            navigationRef.current.navigate('MyVisitorsHistory', { 
                                visit_id: String(data.visit_id), 
                                autoOpenDetails: true 
                            });
                            return true;
                        }
                    }
                }

                const stored = await AsyncStorage.getItem('pending_arrival_call');
                if (stored) {
                    const data = standardizeArrivalData(JSON.parse(stored));
                    await AsyncStorage.removeItem('pending_arrival_call');
                    if (data) {
                        setArrivalData(data); setShowOverlay(true); return true;
                    }
                }
            } catch (e) { }
            return false;
        };

        checkNotifications();
        const poll = setInterval(async () => {
            if (await checkNotifications()) clearInterval(poll);
        }, 2000);
        setTimeout(() => clearInterval(poll), 10000);

        notificationListener.current = Notifications.addNotificationReceivedListener(n => {
            const data = standardizeArrivalData(n.request.content.data);
            if (data && (data.type === 'visitor_arrival' || data.is_call_priority === 'true')) {
                setArrivalData(data); setShowOverlay(true);
            } else if (data && data.type === 'visit_update') {
                // Foreground logic: Android doesn't show a heads-up if app is in focus
                // So we show a manual UI alert
                Alert.alert(
                    data.title || "Visit Update",
                    data.body || "There is a status update for your visit.",
                    [
                        { text: "View Details", onPress: () => {
                            if (navigationRef.current) {
                                navigationRef.current.navigate('MyVisitorsHistory', { 
                                    visit_id: String(data.visit_id), 
                                    autoOpenDetails: true 
                                });
                            }
                        }},
                        { text: "Dismiss", style: "cancel" }
                    ]
                );
            }
        });

        responseListener.current = Notifications.addNotificationResponseReceivedListener(response => {
            const raw = response.notification.request.content.data;
            const data = standardizeArrivalData(raw);

            // EMERGENCY DEBUG: Show the exact raw data of the tapped push to understand nesting
            Alert.alert("Push Tapped (Raw Data)", JSON.stringify(raw).substring(0, 300));

            if (data && (data.type === 'visitor_arrival' || data.is_call_priority === 'true')) {
                setArrivalData(data); setShowOverlay(true);
            } else if (data && data.type === 'visit_update') {
                if (navigationRef.current) {
                    navigationRef.current.navigate('MyVisitorsHistory', { 
                        visit_id: String(data.visit_id),
                        autoOpenDetails: true 
                    });
                }
            } else {
                Alert.alert("Push Debug", `Unknown notification type: ${data.type || 'EMPTY'}. Full raw keys: ${Object.keys(raw).join(', ')}`);
            }
        });

        return () => {
            subscription.remove();
            if (notificationListener.current) Notifications.removeNotificationSubscription(notificationListener.current);
            if (responseListener.current) Notifications.removeNotificationSubscription(responseListener.current);
        };
    }, []);

    const handleAction = async (visitId, action, reason = null) => {
        setShowOverlay(false);
        setArrivalData(null);
        Vibration.cancel();

        // STOP NATIVE Alert
        if (OverlayPermissionModule && OverlayPermissionModule.stopRinging) {
            OverlayPermissionModule.stopRinging();
        }

        if (sound) {
            sound.stopAsync().catch(() => { });
            sound.unloadAsync().catch(() => { });
            setSound(null);
        }

        try {
            await Notifications.dismissAllNotificationsAsync().catch(() => { });
            // Set a local timeout for the response so we don't hang if server is slow
            const response = await apiClient.post('api/visit/status_action.php', {
                action,
                visit_id: visitId,
                reason: reason
            }, { timeout: 10000 }); // 10s local override

            if (response.data.status === 'success') {
                // Success toast or nothing (standardized)
            } else {
                Alert.alert('Error', response.data.message || `Failed to ${action}`);
            }
        } catch (error) {
            console.error('Action Failed:', error);
            // Don't alert if it's just a timeout but the action likely completed on server
            if (error.code !== 'ECONNABORTED') {
                Alert.alert('Error', 'Connection failed while processing action.');
            }
        }
    };

    if (loading) {
        return (
            <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: '#f8f9fa' }}>
                <ActivityIndicator size="large" color="#0d6efd" />
            </View>
        );
    }

    return (
        <View style={{ flex: 1 }}>
            <StatusBar style="light" />
            <NavigationContainer linking={linking} ref={navigationRef}>
                <Stack.Navigator initialRouteName="Login" screenOptions={{ headerShown: false }}>
                    <Stack.Screen name="Login" component={LoginScreen} />
                    <Stack.Screen name="HostDashboard" component={HostDashboard} />
                    <Stack.Screen name="SecurityDashboard" component={SecurityDashboard} />
                    <Stack.Screen name="AdminDashboard" component={AdminDashboard} />
                    <Stack.Screen name="InviteVisitor" component={InviteVisitor} />
                    <Stack.Screen name="RegisterVisitor" component={RegisterVisitor} />
                    <Stack.Screen name="Reports" component={VisitorReports} />
                    <Stack.Screen name="EmployeeReport" component={EmployeeReport} />
                    <Stack.Screen name="MyVisitorsHistory" component={MyVisitorsHistory} />
                    <Stack.Screen name="Departments" component={ComingSoon} initialParams={{ screenName: 'Departments' }} />
                    <Stack.Screen name="Tenants" component={ComingSoon} initialParams={{ screenName: 'Tenants' }} />
                </Stack.Navigator>
            </NavigationContainer>

            {showOverlay && arrivalData && (
                <IncomingCallScreen
                    visible={showOverlay}
                    visitorData={arrivalData}
                    onAccept={() => handleAction(arrivalData.visit_id, 'approve')}
                    onReject={(reason) => handleAction(arrivalData.visit_id, 'reject', reason)}
                    onDismiss={() => {
                        setShowOverlay(false);
                        setArrivalData(null);
                        Vibration.cancel();
                        if (OverlayPermissionModule && OverlayPermissionModule.stopRinging) {
                            OverlayPermissionModule.stopRinging();
                        }
                        if (sound) {
                            sound.stopAsync().catch(() => { });
                            sound.unloadAsync().catch(() => { });
                            setSound(null);
                        }
                    }}
                />
            )}
        </View>
    );
}
