import 'react-native-gesture-handler';
import React, { useEffect, useRef, useState } from 'react';
import { View, Alert, Platform, ActivityIndicator, Vibration, DeviceEventEmitter, AppState } from 'react-native';
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

// Global sound shutdown helper
const globalStopNoises = async (soundRef, setOverlay, setArrival) => {
    if (setOverlay) setOverlay(false);
    if (setArrival) setArrival(null);
    Vibration.cancel();
    if (OverlayPermissionModule?.stopRinging) OverlayPermissionModule.stopRinging();
    if (soundRef.current) {
        try {
            await soundRef.current.stopAsync();
            await soundRef.current.unloadAsync();
        } catch (e) {}
        soundRef.current = null;
    }
};

Notifications.setNotificationHandler({
    handleNotification: async (n) => {
        const data = n.request.content.data;
        const isArrival = data?.type === 'visitor_arrival' || data?.is_call_priority === 'true';
        return {
            shouldShowAlert: true,
            shouldPlaySound: !isArrival, 
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
    config: { screens: { Login: 'login', HostDashboard: 'host', SecurityDashboard: 'security', AdminDashboard: 'admin' } },
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
    const soundRef = useRef(null);
    const [showOverlay, setShowOverlay] = useState(false);
    const [arrivalData, setArrivalData] = useState(null);
    const [pendingVisitId, setPendingVisitId] = useState(null);
    const { role, loading } = usePermissions();

    const standardizeArrivalData = (raw) => {
        if (!raw) return null;
        let dataMap = raw.data || raw.params || raw;
        if (typeof dataMap === 'string') { try { dataMap = JSON.parse(dataMap); } catch (e) {} }

        let vId = dataMap.visit_id || dataMap.visitId || dataMap.id || raw.visit_id || raw.visitId || raw.id || (dataMap?.data?.visit_id);
        if (!vId) return null;

        const type = dataMap.type || (dataMap?.data?.type) || raw.type || null;
        const isCall = String(dataMap.is_call_priority || (dataMap?.data?.is_call_priority) || 'false');

        return {
            visit_id: vId,
            name: dataMap.visitor_name || dataMap.name || dataMap.title || "Unknown Visitor",
            mobile: dataMap.visitor_mobile || dataMap.mobile || dataMap.phone || "",
            photo: dataMap.visitor_photo || dataMap.photo_url || dataMap.photo,
            company: dataMap.company || dataMap.organization || "General Visitor",
            purpose: dataMap.purpose || dataMap.reason || "General Visit",
            assets_carried: dataMap.assets_carried || dataMap.assets || "None",
            type: type,
            is_call_priority: isCall
        };
    };

    const stopAllNoises = () => globalStopNoises(soundRef, setShowOverlay, setArrivalData);

    const performNavigation = (vId) => {
        if (!vId || !role) return;
        let target = 'HostDashboard';
        if (role === 'security') target = 'SecurityDashboard';
        else if (role === 'admin') target = 'AdminDashboard';

        const navigateToTarget = () => {
            if (navigationRef.isReady()) {
                navigationRef.navigate(target, { openVisitId: vId, timestamp: Date.now() });
                setPendingVisitId(null);
            } else {
                setTimeout(navigateToTarget, 1000);
            }
        };
        navigateToTarget();
    };

    useEffect(() => {
        if (role && pendingVisitId) performNavigation(pendingVisitId);
    }, [role, pendingVisitId]);

    useEffect(() => {
        const appStateSub = AppState.addEventListener('change', (nextAppState) => {
            if (nextAppState === 'background') stopAllNoises();
        });
        return () => appStateSub.remove();
    }, []);

    useEffect(() => {
        let isLooping = true;
        async function runRinging() {
            if (showOverlay) {
                try {
                    await Audio.setAudioModeAsync({ allowsRecordingIOS: false, staysActiveInBackground: true, interruptionModeIOS: 1, playsInSilentModeIOS: true, shouldDuckAndroid: true, interruptionModeAndroid: 1, playThroughEarpieceAndroid: false });
                    const { sound } = await Audio.Sound.createAsync({ uri: 'https://raw.githubusercontent.com/rafaelreis-hotmart/Audio-Sample/master/sample.mp3' }, { shouldPlay: true, isLooping: true, volume: 1.0 });
                    if (isLooping) soundRef.current = sound; else sound.unloadAsync();
                } catch (e) {}
                if (isLooping) Vibration.vibrate([1000, 1000, 1000], true);
            } else {
                isLooping = false;
                await stopAllNoises();
            }
        }
        runRinging();
        return () => { isLooping = false; stopAllNoises(); };
    }, [showOverlay]);

    useEffect(() => {
        const deviceSub = DeviceEventEmitter.addListener('showArrivalOverlay', (data) => {
            const std = standardizeArrivalData(data);
            if (std && (std.type === 'visitor_arrival' || std.is_call_priority === 'true')) {
                setArrivalData(std); setShowOverlay(true);
            }
        });

        setTimeout(() => registerForPushNotificationsAsync().then(t => t && updateTokenOnServer(t)), 4000);

        const checkInitAsync = async () => {
            const res = await Notifications.getLastNotificationResponseAsync();
            if (res) {
                const data = standardizeArrivalData(res.notification.request.content.data);
                if (data && (data.type === 'visitor_arrival' || data.is_call_priority === 'true')) {
                    setArrivalData(data); setShowOverlay(true);
                } else if (data) {
                    await stopAllNoises(); setPendingVisitId(data.visit_id);
                }
            }
            const stored = await AsyncStorage.getItem('pending_arrival_call');
            if (stored) {
                const data = standardizeArrivalData(JSON.parse(stored));
                if (data) {
                    await AsyncStorage.removeItem('pending_arrival_call');
                    if (data.type === 'visitor_arrival' || data.is_call_priority === 'true') {
                        setArrivalData(data); setShowOverlay(true);
                    }
                }
            }
        };
        checkInitAsync();

        notificationListener.current = Notifications.addNotificationReceivedListener(n => {
            const data = standardizeArrivalData(n.request.content.data);
            if (!data) return;
            if (data.type === 'visitor_arrival' || data.is_call_priority === 'true') {
                setArrivalData(data); setShowOverlay(true);
            } else {
                setPendingVisitId(data.visit_id);
            }
        });

        responseListener.current = Notifications.addNotificationResponseReceivedListener(async r => {
            await stopAllNoises();
            const data = standardizeArrivalData(r.notification.request.content.data);
            if (!data) return;
            if (data.type === 'visitor_arrival' || data.is_call_priority === 'true') {
                setArrivalData(data); setShowOverlay(true);
            } else {
                setPendingVisitId(data.visit_id);
            }
        });

        return () => {
            deviceSub.remove();
            if (notificationListener.current) Notifications.removeNotificationSubscription(notificationListener.current);
            if (responseListener.current) Notifications.removeNotificationSubscription(responseListener.current);
        };
    }, []);

    const handleAction = async (vId, action, reason = null) => {
        await stopAllNoises();
        try {
            await Notifications.dismissAllNotificationsAsync();
            await apiClient.post('api/visit/status_action.php', { action, visit_id: vId, reason });
        } catch (e) {}
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
                    onReject={(r) => handleAction(arrivalData.visit_id, 'reject', r)}
                    onDismiss={() => stopAllNoises()}
                />
            )}
        </View>
    );
}
