import 'react-native-gesture-handler';
import { registerRootComponent } from 'expo';
import React, { useEffect, useRef, useState } from 'react';
import { View, Alert, Linking, Platform, ActivityIndicator } from 'react-native';
import { NavigationContainer, createNavigationContainerRef } from '@react-navigation/native';
import { createStackNavigator } from '@react-navigation/stack';
import * as Notifications from 'expo-notifications';
import * as TaskManager from 'expo-task-manager';

// Components
import IncomingCallScreen from './components/IncomingCallScreen';

const BACKGROUND_NOTIFICATION_TASK = 'BACKGROUND_NOTIFICATION_TASK';

// For Overlay permission (Appear on top)
import { NativeModules, AppState } from 'react-native';
const OverlayPermissionModule = NativeModules?.OverlayPermissionModule;

console.log("[App.js] Native Module Hook:", {
    exists: !!OverlayPermissionModule,
    wakeUp: !!OverlayPermissionModule?.wakeUpApp
});

import AsyncStorage from '@react-native-async-storage/async-storage';

const standardizeArrivalData = (raw) => {
    if (!raw) return null;
    
    // Support nested data structures from various notification sources
    let data = raw.notification?.request?.content?.data || raw.notification?.data || raw;
    
    // Deep extract: handle JSON string data
    if (typeof data === 'string' && data.includes('{')) {
        try { data = JSON.parse(data); } catch (e) { }
    }

    // 1. Extract visit_id with high priority
    let vid = data.visit_id || data.visitId || data.id;
    if (!vid && data.body && typeof data.body === 'string' && data.body.includes('{')) {
        try { 
            const pb = JSON.parse(data.body); 
            vid = pb.visit_id || pb.visitId || pb.id;
            data = { ...data, ...pb };
        } catch (e) { }
    }

    const standardized = {
        ...data,
        visit_id: vid || "N/A",
        visitor_name: data.visitor_name || data.name || data.title || "Visitor",
        name: data.visitor_name || data.name || data.title || "Visitor",
        photo: data.visitor_photo || data.photo_url || data.photo || null,
        purpose: data.purpose || data.reason || data.body || "General Visit",
        assets_carried: data.assets_carried || data.asset || "None",
        type: data.type || "visitor_arrival",
        is_call_priority: data.is_call_priority || (data.type === 'visitor_arrival' ? 'true' : 'false')
    };

    console.log("[App.js] Standardized Notification:", JSON.stringify(standardized).substring(0, 50));
    return standardized;
};

TaskManager.defineTask(BACKGROUND_NOTIFICATION_TASK, async ({ data, error }) => {
    if (error) {
        console.error("[BG Task] Error:", error);
        return;
    }

    console.log("[BG Task] Raw Data:", JSON.stringify(data).substring(0, 50));
    const standardized = standardizeArrivalData(data);
    
    if (standardized && (standardized.type === 'visitor_arrival' || standardized.is_call_priority === 'true')) {
        console.log("[BG Task] Priority arrival detected. Waking up...");

        try {
            await AsyncStorage.setItem('pending_arrival_call', JSON.stringify(standardized));
            console.log("[BG Task] Saved to storage for app to find.");
        } catch (e) {
            console.error("[BG Task] Storage Error:", e);
        }

        if (OverlayPermissionModule && OverlayPermissionModule.wakeUpApp) {
            OverlayPermissionModule.wakeUpApp();
            console.log("[BG Task] wakeUpApp() called.");
        }

        await Notifications.scheduleNotificationAsync({
            content: {
                title: standardized.visitor_name ? "Visitor Arrived: " + standardized.visitor_name : "Visitor Arrival",
                body: standardized.purpose || "A visitor is waiting at the gate",
                data: standardized,
                categoryIdentifier: 'visitor_arrival',
                sound: true,
                priority: Notifications.AndroidNotificationPriority.MAX,
            },
            trigger: null,
            channelId: 'vms_urgent_alerts_v2',
        });
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
const navigationRef = createNavigationContainerRef();

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

    // Robust check for pending arrivals
    const checkNotifications = async (source = "manual") => {
        try {
            console.log(`[App.js] Checking for arrivals from source: ${source}`);
            
            // 1. Check for last response (if user tapped)
            const response = await Notifications.getLastNotificationResponseAsync();
            if (response) {
                const data = standardizeArrivalData(response.notification.request.content.data);
                if (data && (data.type === 'visitor_arrival' || data.is_call_priority === 'true')) {
                    console.log("[App.js] Found arrival in LastResponse");
                    setArrivalData(data);
                    setShowOverlay(true);
                    return true;
                }
            }

            // 2. CHECK PERSISTENT STORAGE (Set by BG Task)
            const stored = await AsyncStorage.getItem('pending_arrival_call');
            if (stored) {
                console.log("[App.js] Found pending arrival in storage:", stored.substring(0, 50));
                const payload = JSON.parse(stored);
                const data = standardizeArrivalData(payload);

                if (data) {
                    // Only remove if we actually got valid data to show
                    await AsyncStorage.removeItem('pending_arrival_call');
                    setArrivalData(data);
                    setShowOverlay(true);
                    console.log("[App.js] Overlay triggered from storage");
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
                    console.log("[App.js] Overlay triggered from presented notification");
                    return true;
                }
            }
        } catch (err) {
            console.log("[App.js] Check Notifications Error:", err);
        }
        return false;
    };

    useEffect(() => {
        // Register background task first
        try {
            if (Platform.OS === 'android') {
                TaskManager.isTaskRegisteredAsync(BACKGROUND_NOTIFICATION_TASK).then(registered => {
                    if (!registered) {
                        console.log("[App.js] Registering Background Task...");
                        Notifications.registerTaskAsync(BACKGROUND_NOTIFICATION_TASK);
                    }
                });
            }
        } catch (e) {
            console.log("Task Manager Error:", e);
        }

        // Defer token registration
        setTimeout(() => {
            registerForPushNotificationsAsync(3, 2000).then(token => {
                if (token) updateTokenOnServer(token);
            });
        }, 5000);

        // 1. Initial check on mount
        checkNotifications("mount");

        // 2. Poll for the first 30 seconds after any launch/mount
        let pollCount = 0;
        const pollInterval = setInterval(async () => {
            const found = await checkNotifications("poll_" + pollCount);
            pollCount++;
            if (found || pollCount > 30) clearInterval(pollInterval);
        }, 2000);

        // 3. LISTEN FOR APP STATE CHANGES (CRITICAL for background -> foreground)
        const appStateListener = AppState.addEventListener('change', nextAppState => {
            if (nextAppState === 'active') {
                console.log("[App.js] App became active - re-scanning for arrivals");
                checkNotifications("appstate_active");
            }
        });

        notificationListener.current = Notifications.addNotificationReceivedListener(notification => {
            console.log("[App.js] Foreground Notif Received:", notification.request.content.data?.type);
            const data = standardizeArrivalData(notification.request.content.data);
            if (data && (data.type === 'visitor_arrival' || data.is_call_priority === 'true')) {
                setArrivalData(data);
                setShowOverlay(true);
            }
        });

        responseListener.current = Notifications.addNotificationResponseReceivedListener(response => {
            const data = standardizeArrivalData(response.notification.request.content.data);
            if (data && (data.type === 'visitor_arrival' || data.is_call_priority === 'true')) {
                setArrivalData(data);
                setShowOverlay(true);
            } else if (data && data.type === 'visit_update') {
                const visitId = data.visitId || data.visit_id;
                if (visitId && navigationRef.isReady()) {
                    let target = 'HostDashboard';
                    if (role === 'admin') target = 'AdminDashboard';
                    else if (role === 'security') target = 'SecurityDashboard';
                    navigationRef.navigate(target, { openVisitId: visitId, timestamp: Date.now() });
                }
            }
        });

        return () => {
            if (notificationListener.current) Notifications.removeNotificationSubscription(notificationListener.current);
            if (responseListener.current) Notifications.removeNotificationSubscription(responseListener.current);
            appStateListener.remove();
            clearInterval(pollInterval);
        };
    }, [role]);

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
            <NavigationContainer linking={linking} ref={navigationRef}>
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
registerRootComponent(App);
