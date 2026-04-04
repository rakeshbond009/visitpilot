import 'react-native-gesture-handler';
import React, { useEffect, useRef, useState } from 'react';
import { View, Alert, Platform, ActivityIndicator, Vibration, DeviceEventEmitter } from 'react-native';
import { NavigationContainer, createNavigationContainerRef } from '@react-navigation/native';
import { createStackNavigator } from '@react-navigation/stack';
import { StatusBar } from 'expo-status-bar';
import * as Notifications from 'expo-notifications';
import * as TaskManager from 'expo-task-manager';
import { Audio } from 'expo-av';
import AsyncStorage from '@react-native-async-storage/async-storage';

// Components
import IncomingCallScreen from './components/IncomingCallScreen';

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

const BACKGROUND_NOTIFICATION_TASK = 'BACKGROUND_NOTIFICATION_TASK';
const OverlayPermissionModule = Platform.OS === 'android' ? require('react-native').NativeModules?.OverlayPermissionModule : null;

Notifications.setNotificationHandler({
    handleNotification: async (n) => {
        // Only show alert/sound if it's NOT an arrival (which has its own overlay)
        const type = n.request.content.data?.type;
        const isArrival = type === 'visitor_arrival' || n.request.content.data?.is_call_priority === 'true';
        return {
            shouldShowAlert: true,
            shouldPlaySound: !isArrival, // Don't play default sound for arrivals as we have our own ringing
            shouldSetBadge: true,
        };
    },
});

TaskManager.defineTask(BACKGROUND_NOTIFICATION_TASK, async ({ data, error }) => {
    if (error) return;
    let payload = data?.notification?.data || data;
    if (payload && (payload.type === 'visitor_arrival' || payload.is_call_priority === 'true')) {
        try {
            await AsyncStorage.setItem('pending_arrival_call', JSON.stringify(payload));
            if (OverlayPermissionModule?.wakeUpApp) OverlayPermissionModule.wakeUpApp();
            await Notifications.scheduleNotificationAsync({
                content: {
                    title: payload.title || "Visitor Arrival",
                    body: payload.body || "A visitor is waiting at the gate",
                    data: payload,
                    sound: true,
                    priority: Notifications.AndroidNotificationPriority.MAX,
                },
                trigger: null,
                channelId: 'vms_urgent_alerts_v2',
            });
        } catch (e) {}
    }
});

const Stack = createStackNavigator();
const navigationRef = createNavigationContainerRef();

const linking = {
    prefixes: ['https://visitor.visitpilot.com', 'com.visitpilot.vms://'],
    config: {
        screens: { Login: 'login', HostDashboard: 'host', SecurityDashboard: 'security', AdminDashboard: 'admin' },
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
    const [pendingVisitId, setPendingVisitId] = useState(null);
    const { role, loading } = usePermissions();

    const standardizeArrivalData = (raw) => {
        if (!raw) return null;
        let data = raw.data || raw.params || raw;
        if (typeof data === 'string') { try { data = JSON.parse(data); } catch (e) {} }

        let visit_id = data.visit_id || data.visitId || data.id || raw.visit_id || raw.visitId || raw.id;
        if (!visit_id) return null;

        const type = data.type || raw.type || null;

        return {
            visit_id: visit_id,
            name: data.visitor_name || data.name || data.title || "Unknown Visitor",
            mobile: data.visitor_mobile || data.mobile || data.phone || data.visitorMobile || "",
            photo: data.visitor_photo || data.photo_url || data.photo || data.visitorPhoto,
            company: data.company || data.organization || data.visitor_company || "General Visitor",
            purpose: data.purpose || data.reason || data.body || "General Visit",
            assets_carried: data.assets_carried || data.assets || data.asset || "None",
            type: type,
            is_call_priority: String(data.is_call_priority || 'false')
        };
    };

    const stopAllNoises = async () => {
        setShowOverlay(false);
        setArrivalData(null);
        Vibration.cancel();
        if (OverlayPermissionModule?.stopRinging) OverlayPermissionModule.stopRinging();
        if (sound) {
            try { await sound.stopAsync(); await sound.unloadAsync(); } catch (e) {}
            setSound(null);
        }
    };

    const performNavigation = (visit_id) => {
        if (!visit_id || !role) return;
        
        let targetScreen = 'HostDashboard';
        if (role === 'security') targetScreen = 'SecurityDashboard';
        else if (role === 'admin') targetScreen = 'AdminDashboard';

        const navigateToTarget = () => {
            if (navigationRef.isReady()) {
                navigationRef.navigate(targetScreen, { openVisitId: visit_id, timestamp: Date.now() });
                setPendingVisitId(null);
            } else {
                setTimeout(navigateToTarget, 500);
            }
        };
        navigateToTarget();
    };

    useEffect(() => {
        if (role && pendingVisitId) {
            performNavigation(pendingVisitId);
        }
    }, [role, pendingVisitId]);

    useEffect(() => {
        let isLooping = true;
        async function startRinging() {
            if (showOverlay) {
                try {
                    await Audio.setAudioModeAsync({ allowsRecordingIOS: false, staysActiveInBackground: true, interruptionModeIOS: 1, playsInSilentModeIOS: true, shouldDuckAndroid: true, interruptionModeAndroid: 1, playThroughEarpieceAndroid: false });
                    const { sound: newSound } = await Audio.Sound.createAsync({ uri: 'https://raw.githubusercontent.com/rafaelreis-hotmart/Audio-Sample/master/sample.mp3' }, { shouldPlay: true, isLooping: true, volume: 1.0 });
                    if (isLooping) setSound(newSound); else newSound.unloadAsync();
                } catch (e) {}
                if (isLooping) Vibration.vibrate([1000, 1000, 1000], true);
            } else {
                isLooping = false;
                if (sound) { 
                    try { await sound.stopAsync(); await sound.unloadAsync(); } catch (e) {}
                    setSound(null); 
                }
                Vibration.cancel();
            }
        }
        startRinging();
        return () => { isLooping = false; Vibration.cancel(); };
    }, [showOverlay]);

    useEffect(() => {
        const subscription = DeviceEventEmitter.addListener('showArrivalOverlay', (data) => {
            const std = standardizeArrivalData(data);
            if (std && (std.type === 'visitor_arrival' || std.is_call_priority === 'true')) {
                setArrivalData(std); setShowOverlay(true);
            }
        });

        setTimeout(() => {
            registerForPushNotificationsAsync().then(token => { if (token) updateTokenOnServer(token); });
        }, 3000);

        const checkNotifications = async () => {
            try {
                const response = await Notifications.getLastNotificationResponseAsync();
                if (response) {
                    const data = standardizeArrivalData(response.notification.request.content.data);
                    if (data && (data.type === 'visitor_arrival' || data.is_call_priority === 'true')) {
                        setArrivalData(data); setShowOverlay(true);
                    } else if (data && (data.type === 'visit_status_update' || data.type === 'visit_update')) {
                        await stopAllNoises();
                        setPendingVisitId(data.visit_id);
                    }
                }
                const stored = await AsyncStorage.getItem('pending_arrival_call');
                if (stored) {
                    const data = standardizeArrivalData(JSON.parse(stored));
                    await AsyncStorage.removeItem('pending_arrival_call');
                    if (data && (data.type === 'visitor_arrival' || data.is_call_priority === 'true')) {
                        setArrivalData(data); setShowOverlay(true);
                    }
                }
            } catch (e) {}
        };

        checkNotifications();

        notificationListener.current = Notifications.addNotificationReceivedListener(n => {
            const data = standardizeArrivalData(n.request.content.data);
            if (data && (data.type === 'visitor_arrival' || data.is_call_priority === 'true')) {
                setArrivalData(data); setShowOverlay(true);
            } else if (data && (data.type === 'visit_status_update' || data.type === 'visit_update')) {
                // FOREGROUND STATUS UPDATE: Trigger navigation automatically
                setPendingVisitId(data.visit_id);
            }
        });

        responseListener.current = Notifications.addNotificationResponseReceivedListener(async (r) => {
            const data = standardizeArrivalData(r.notification.request.content.data);
            if (data && (data.type === 'visitor_arrival' || data.is_call_priority === 'true')) {
                setArrivalData(data); setShowOverlay(true);
            } else if (data && (data.type === 'visit_status_update' || data.type === 'visit_update')) {
                await stopAllNoises();
                setPendingVisitId(data.visit_id);
            }
        });

        return () => {
            subscription.remove();
            if (notificationListener.current) Notifications.removeNotificationSubscription(notificationListener.current);
            if (responseListener.current) Notifications.removeNotificationSubscription(responseListener.current);
        };
    }, []);

    const handleAction = async (visitId, action, reason = null) => {
        await stopAllNoises();
        try {
            await Notifications.dismissAllNotificationsAsync().catch(() => {});
            const response = await apiClient.post('api/visit/status_action.php', { action, visit_id: visitId, reason: reason }, { timeout: 30000 });
            if (response.data.status !== 'success') Alert.alert('Error', response.data.message || `Failed to ${action}`);
        } catch (error) {
            if (error.code !== 'ECONNABORTED') Alert.alert('Error', 'Connection failed while processing action.');
        }
    };

    if (loading) return <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center' }}><ActivityIndicator size="large" color="#0d6efd" /></View>;

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
                    onDismiss={() => stopAllNoises()}
                />
            )}
        </View>
    );
}
