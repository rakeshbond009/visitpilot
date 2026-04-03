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

        await Notifications.scheduleNotificationAsync({
            content: {
                title: payload.title || "Visitor Arrival",
                body: payload.body || "A visitor is waiting at the gate",
                data: payload,
                categoryIdentifier: 'visitor_arrival',
                sound: true,
                priority: Notifications.AndroidNotificationPriority.MAX,
            },
            trigger: null,
            channelId: 'vms_urgent_alerts_v2',
        });
    }
});

const Stack = createStackNavigator();

const linking = {
    prefixes: ['https://visitor.visitpilot.com', 'com.visitpilot.vms://'],
    config: {
        screens: {
            Login: 'login',
            HostDashboard: 'host',
            SecurityDashboard: 'security',
            AdminDashboard: 'admin',
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
        let data = raw.data || raw.params || raw;
        if (typeof data === 'string') {
            try { data = JSON.parse(data); } catch (e) { }
        }

        let visit_id = data.visit_id || data.visitId || data.id || raw.visit_id || raw.visitId || raw.id;
        
        if (!visit_id && data.body) {
            try {
                const parsedBody = JSON.parse(data.body);
                visit_id = parsedBody.visit_id || parsedBody.visitId || parsedBody.id;
                data = { ...data, ...parsedBody };
            } catch (e) { }
        }

        return {
            visit_id: visit_id,
            name: data.visitor_name || data.name || data.title || "Unknown Visitor",
            mobile: data.visitor_mobile || data.mobile || data.phone || data.visitorMobile || "",
            photo: data.visitor_photo || data.photo_url || data.photo || data.visitorPhoto,
            company: data.company || data.organization || data.visitor_company || "General Visitor",
            purpose: data.purpose || data.reason || data.body || "General Visit",
            assets_carried: data.assets_carried || data.assets || data.asset || "None",
            type: data.type || "visitor_arrival"
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

                    const urls = [
                        'https://www.soundjay.com/phone/telephone-ring-03a.mp3',
                        'https://www.soundjay.com/phone/phone-calling-1.mp3',
                        'https://raw.githubusercontent.com/rafaelreis-hotmart/Audio-Sample/master/sample.mp3'
                    ];

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
                    sound.stopAsync().catch(() => {});
                    sound.unloadAsync().catch(() => {});
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
            }
        });

        responseListener.current = Notifications.addNotificationResponseReceivedListener(r => {
            const data = standardizeArrivalData(r.notification.request.content.data);
            if (data && (data.type === 'visitor_arrival' || data.is_call_priority === 'true')) {
                setArrivalData(data); setShowOverlay(true);
            }
        });

        return () => {
            subscription.remove();
            if (notificationListener.current) Notifications.removeNotificationSubscription(notificationListener.current);
            if (responseListener.current) Notifications.removeNotificationSubscription(responseListener.current);
        };
    }, []);

    const handleAction = async (visitId, action) => {
        setShowOverlay(false);
        setArrivalData(null);
        Vibration.cancel();
        if (sound) {
            sound.stopAsync().catch(() => {});
            sound.unloadAsync().catch(() => {});
            setSound(null);
        }

        try {
            await Notifications.dismissAllNotificationsAsync().catch(() => {});
            const response = await apiClient.post('api/visit/status_action.php', { action, visit_id: visitId });
            if (response.data.status !== 'success') {
                Alert.alert('Error', response.data.message || 'Action failed');
            }
        } catch (error) {
            Alert.alert('Network Error', 'The action failed to reach the server.');
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
            <NavigationContainer linking={linking}>
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
                    onReject={() => handleAction(arrivalData.visit_id, 'reject')}
                    onDismiss={() => {
                        setShowOverlay(false);
                        setArrivalData(null);
                        Vibration.cancel();
                        if (sound) {
                           sound.stopAsync().catch(() => {});
                           sound.unloadAsync().catch(() => {});
                           setSound(null);
                        }
                    }}
                />
            )}
        </View>
    );
}
