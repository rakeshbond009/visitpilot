import 'react-native-gesture-handler';
import React, { useEffect, useRef, useState } from 'react';
import { View, Alert, Linking, Platform, ActivityIndicator } from 'react-native';
import { NavigationContainer } from '@react-navigation/native';
import { createStackNavigator } from '@react-navigation/stack';
import * as Notifications from 'expo-notifications';
import * as TaskManager from 'expo-task-manager';
import notifee, { AndroidCategory, AndroidVisibility, AndroidImportance } from '@notifee/react-native';

// Components
import IncomingCallScreen from './components/IncomingCallScreen';

const BACKGROUND_NOTIFICATION_TASK = 'BACKGROUND_NOTIFICATION_TASK';

// For Overlay permission (Appear on top)
import { NativeModules } from 'react-native';
const OverlayPermissionModule = NativeModules?.OverlayPermissionModule;

import AsyncStorage from '@react-native-async-storage/async-storage';

TaskManager.defineTask(BACKGROUND_NOTIFICATION_TASK, async ({ data, error }) => {
    if (error) {
        console.error("[BG Task] Error:", error);
        return;
    }

    console.log("[BG Task] Triggered with data:", JSON.stringify(data));

    // Support both 'data-only' (Android background) and 'notification+data' (foreground/others)
    let payload = null;
    if (data) {
        if (data.notification) {
            payload = data.notification.data || data.notification;
        } else {
            payload = data;
        }
    }

    if (payload && (payload.type === 'visitor_arrival' || payload.is_call_priority === 'true')) {
        console.log("[BG Task] Valid arrival detected. Showing full-screen notification...");

        // 1. PERSIST FOR MAIN UI
        try {
            await AsyncStorage.setItem('pending_arrival_call', JSON.stringify(payload));
        } catch (storageErr) {
            console.error("[BG Task] Storage Error:", storageErr.message);
        }

        // 2. SHOW FULL-SCREEN CALL-STYLE NOTIFICATION via notifee
        //    This is the ONLY JS-level API that supports fullScreenAction (lock-screen wake)
        try {
            // Create channel first (idempotent — safe to call every time)
            const channelId = await notifee.createChannel({
                id: 'vms_calls_v1',
                name: 'Visitor Arrival Calls',
                importance: AndroidImportance.HIGH,
                sound: 'default',
                vibration: true,
                vibrationPattern: [300, 500, 300, 500],
                visibility: AndroidVisibility.PUBLIC,
                bypassDnd: true,
            });

            await notifee.displayNotification({
                id: 'visitor_call_notif',
                title: payload.title || '\uD83D\uDD14 Visitor Arrival',
                body: payload.body || 'A visitor is waiting at the gate',
                android: {
                    channelId,
                    category: AndroidCategory.CALL,
                    importance: AndroidImportance.HIGH,
                    visibility: AndroidVisibility.PUBLIC,
                    sound: 'default',
                    vibrationPattern: [300, 500, 300, 500],
                    // THE KEY: fullScreenAction wakes the lock screen like an incoming call
                    fullScreenAction: {
                        id: 'default',
                        launchActivity: 'default',
                    },
                    pressAction: {
                        id: 'default',
                        launchActivity: 'default',
                    },
                    ongoing: false,
                    autoCancel: true,
                    lights: [{ color: '#FF0000', onMs: 500, offMs: 500 }],
                },
            });
        } catch (notifeeErr) {
            console.error("[BG Task] Notifee error:", notifeeErr.message);
            // Fallback: still try wakeUpApp so app at least comes to foreground
            if (OverlayPermissionModule && OverlayPermissionModule.wakeUpApp) {
                OverlayPermissionModule.wakeUpApp();
            }
        }
    }
});

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
    const [arrivalData, setArrivalData] = useState(null);
    const [showOverlay, setShowOverlay] = useState(false);

    // Permission context
    const { role, hasPermission, loading } = usePermissions();

    const standardizeArrivalData = (raw) => {
        if (!raw) return null;

        console.log("[DEBUG] Raw Notification Data:", JSON.stringify(raw));

        // Deep extract: some systems wrap our payload under a 'data' or 'body' key
        let data = raw.data || raw.params || raw;

        // Handle case where 'data' might be a JSON string itself
        if (typeof data === 'string') {
            try {
                const parsed = JSON.parse(data);
                data = parsed;
            } catch (e) {
                console.log("[DEBUG] Failed to parse data string:", e);
            }
        }

        // 1. Direct access
        let visit_id = data.visit_id || data.visitId || data.id || raw.visit_id || raw.visitId || raw.id;

        // 2. Nested body parsing (common with some FCM setups)
        if (!visit_id && data.body) {
            try {
                const parsedBody = JSON.parse(data.body);
                visit_id = parsedBody.visit_id || parsedBody.visitId || parsedBody.id;
                // Merge parsed body into data for other fields
                data = { ...data, ...parsedBody };
            } catch (e) {
                // It's just a string body, not JSON
            }
        }

        const standardized = {
            visit_id: visit_id,
            name: data.visitor_name || data.name || data.title || "Unknown Visitor",
            mobile: data.visitor_mobile || data.mobile || data.phone || data.visitorMobile || "",
            photo: data.visitor_photo || data.photo_url || data.photo || data.visitorPhoto,
            company: data.company || data.organization || data.visitor_company || "General Visitor",
            purpose: data.purpose || data.reason || data.body || "General Visit",
            assets_carried: data.assets_carried || data.asset || "None",
            type: data.type || "visitor_arrival"
        };

        console.log("[DEBUG] Standardized Notification Object:", JSON.stringify(standardized));
        return standardized;
    };

    useEffect(() => {
        // Register background task first
        try {
            if (Platform.OS === 'android') {
                // Check if task is already registered to avoid errors
                TaskManager.isTaskRegisteredAsync(BACKGROUND_NOTIFICATION_TASK).then(registered => {
                    if (!registered) Notifications.registerTaskAsync(BACKGROUND_NOTIFICATION_TASK);
                });
            }
        } catch (e) {
            console.log("Task Manager Error:", e);
        }

        // Defer token registration by 5s so auto-login (checkExistingSession) has time to
        // populate 'userData' in AsyncStorage before updateTokenOnServer checks it.
        setTimeout(() => {
            registerForPushNotificationsAsync(3, 2000).then(token => {
                console.log('[App.js] Token Obtained:', token ? token.substring(0, 20) + '...' : 'null');
                if (token) updateTokenOnServer(token);
            });
        }, 5000);

        // Check for notifications on start, and poll for a few seconds in case of background launch
        const checkNotifications = async () => {
            try {
                // 1. Check for last response (if user tapped)
                const response = await Notifications.getLastNotificationResponseAsync();
                if (response) {
                    const data = standardizeArrivalData(response.notification.request.content.data);
                    if (data && (data.type === 'visitor_arrival' || data.is_call_priority === 'true')) {
                        setArrivalData(data);
                        setShowOverlay(true);
                        return true;
                    }
                }

                // 2. CHECK PERSISTENT STORAGE (Set by BG Task)
                const stored = await AsyncStorage.getItem('pending_arrival_call');
                if (stored) {
                    console.log("[App.js] Found pending arrival in storage");
                    const payload = JSON.parse(stored);
                    const data = standardizeArrivalData(payload);

                    // Immediately clear storage to avoid double-processing
                    await AsyncStorage.removeItem('pending_arrival_call');

                    if (data) {
                        setArrivalData(data);
                        setShowOverlay(true);
                        return true;
                    }
                }

                // 3. Check for presented notifications
                const presented = await Notifications.getPresentedNotificationsAsync();
                const arrivalNotif = presented.find(n => {
                    const d = n.request.content.data;
                    return d?.type === 'visitor_arrival' || d?.is_call_priority === 'true';
                });

                if (arrivalNotif) {
                    const data = standardizeArrivalData(arrivalNotif.request.content.data);
                    if (data) {
                        setArrivalData(data);
                        setShowOverlay(true);
                        return true;
                    }
                }
            } catch (err) {
                console.log("Check Notifications Error:", err);
            }
            return false;
        };

        // Initial check
        checkNotifications();

        // Polling check for 10 seconds (useful for background -> foreground wakeups)
        const pollInterval = setInterval(async () => {
            const found = await checkNotifications();
            if (found) clearInterval(pollInterval);
        }, 1000);

        const pollTimeout = setTimeout(() => {
            clearInterval(pollInterval);
        }, 10000);

        notificationListener.current = Notifications.addNotificationReceivedListener(notification => {
            console.log(" [App.js] Notification Received (Foreground):", JSON.stringify(notification));
            const data = standardizeArrivalData(notification.request.content.data);

            // Only show overlay for actual visitor arrivals
            if (data && (data.type === 'visitor_arrival' || data.is_call_priority === 'true')) {
                console.log(" [App.js] Visitor Arrival Detected - Showing Overlay");
                setArrivalData(data);
                setShowOverlay(true);
            }
        });

        responseListener.current = Notifications.addNotificationResponseReceivedListener(response => {
            const data = standardizeArrivalData(response.notification.request.content.data);
            // Only show overlay for actual visitor arrivals
            if (data && (data.type === 'visitor_arrival' || data.is_call_priority === 'true')) {
                setArrivalData(data);
                setShowOverlay(true);
            }
        });

        return () => {
            if (notificationListener.current) Notifications.removeNotificationSubscription(notificationListener.current);
            if (responseListener.current) Notifications.removeNotificationSubscription(responseListener.current);
            clearInterval(pollInterval);
            clearTimeout(pollTimeout);
        };
    }, []);

    const handleAction = async (visitId, action) => {
        if (!visitId) {
            Alert.alert('Error', 'Missing visit ID. Cannot perform action.');
            return;
        }

        try {
            console.log(`[DEBUG] Handling Visit Action: ${action} for ID: ${visitId}`);

            await Notifications.dismissAllNotificationsAsync();
            await Notifications.cancelAllScheduledNotificationsAsync();

            const idToDismiss = visitId ? visitId.toString() : '1337';
            await Notifications.dismissNotificationAsync(`visitor_arrival:${idToDismiss}`).catch(() => { });
            await Notifications.dismissNotificationAsync(idToDismiss).catch(() => { });

            const response = await apiClient.post('api/visit/status_action.php', {
                action: action,
                visit_id: visitId,
            });

            if (response.data.status === 'success') {

                setShowOverlay(false);
                setArrivalData(null);
            } else {
                Alert.alert('Error', response.data.message || 'Action failed');
                setShowOverlay(false);
            }
        } catch (error) {
            console.error("Action API Error:", error);
            Alert.alert('Error', 'Communication error with server');
            setShowOverlay(false);
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
            <NavigationContainer linking={linking}>
                <Stack.Navigator
                    initialRouteName="Login"
                    screenOptions={{
                        headerShown: false,
                        cardStyle: { backgroundColor: '#f8f9fa' }
                    }}
                >
                    <Stack.Screen name="Login" component={LoginScreen} />

                    {/* Role-Based Dashboards */}
                    {(role === 'host' || role === 'employee' || role === 'admin') && (
                        <Stack.Screen name="HostDashboard" component={HostDashboard} />
                    )}
                    {(role === 'security' || role === 'admin') && (
                        <Stack.Screen name="SecurityDashboard" component={SecurityDashboard} />
                    )}
                    {role === 'admin' && (
                        <Stack.Screen name="AdminDashboard" component={AdminDashboard} />
                    )}

                    {/* Permission-Based Screens */}
                    {(hasPermission('security_register') || hasPermission('host_invite') || role === 'admin') && (
                        <Stack.Screen name="RegisterVisitor" component={RegisterVisitor} />
                    )}
                    {(hasPermission('host_invite') || role === 'admin') && (
                        <Stack.Screen name="InviteVisitor" component={InviteVisitor} />
                    )}
                    {(hasPermission('view_employee_report') || hasPermission('host_reports') || hasPermission('admin_reports') || hasPermission('security_reports') || role === 'admin') && (
                        <Stack.Screen name="Reports" component={VisitorReports} />
                    )}

                    {(hasPermission('view_employee_report') || hasPermission('admin_reports') || hasPermission('security_reports') || role === 'admin') && (
                        <Stack.Screen name="EmployeeReport" component={EmployeeReport} />
                    )}

                    {(hasPermission('host_history') || role === 'admin') && (
                        <Stack.Screen name="MyVisitorsHistory" component={MyVisitorsHistory} />
                    )}

                    {(hasPermission('admin_employees') || role === 'admin') && (
                        <Stack.Screen name="Employees" component={ComingSoon} initialParams={{ screenName: 'Employees' }} />
                    )}

                    {(hasPermission('admin_users') || role === 'admin') && (
                        <Stack.Screen name="Permissions" component={ComingSoon} initialParams={{ screenName: 'Permissions' }} />
                    )}

                    {(hasPermission(['settings_profile', 'settings_company', 'settings_general', 'settings_departments', 'settings_access', 'settings_email']) || role === 'admin') && (
                        <Stack.Screen name="Settings" component={ComingSoon} initialParams={{ screenName: 'Settings' }} />
                    )}

                    {(hasPermission('admin_audit') || role === 'admin') && (
                        <Stack.Screen name="AuditLogs" component={ComingSoon} initialParams={{ screenName: 'Audit Logs' }} />
                    )}

                    <Stack.Screen name="Departments" component={ComingSoon} initialParams={{ screenName: 'Departments' }} />
                    <Stack.Screen name="Tenants" component={ComingSoon} initialParams={{ screenName: 'Tenants' }} />
                </Stack.Navigator>
            </NavigationContainer>

            {showOverlay && arrivalData && (
                <IncomingCallScreen
                    visible={showOverlay}
                    visitorData={{
                        name: arrivalData.name,
                        company: arrivalData.company,
                        purpose: arrivalData.purpose,
                        photo: arrivalData.photo,
                        assets_carried: arrivalData.assets_carried,
                        visit_id: arrivalData.visit_id
                    }}
                    onAccept={() => handleAction(arrivalData.visit_id, 'approve')}
                    onReject={() => handleAction(arrivalData.visit_id, 'reject')}
                />
            )}
        </View>
    );
}
