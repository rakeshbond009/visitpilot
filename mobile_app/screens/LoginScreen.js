import React, { useState, useEffect } from 'react';
import {
    StyleSheet,
    View,
    Text,
    TextInput,
    TouchableOpacity,
    KeyboardAvoidingView,
    Platform,
    ActivityIndicator,
    Alert,
    Image,
} from 'react-native';
import axios from 'axios';
import AsyncStorage from '@react-native-async-storage/async-storage';
import apiClient from '../utils/apiClient';
import { usePermissions } from '../context/PermissionContext';

export default function LoginScreen({ navigation }) {
    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [tenantKey, setTenantKey] = useState(''); // Default empty, user must enter
    const [loading, setLoading] = useState(true);
    const { updatePermissions } = usePermissions();

    useEffect(() => {
        checkExistingSession();
    }, []);

    const checkExistingSession = async () => {
        try {
            const storedUser = await AsyncStorage.getItem('userData');
            if (storedUser) {
                const userData = JSON.parse(storedUser);

                // Verify session with server
                try {
                    const response = await apiClient.get('api/auth/verify_session.php', {
                        timeout: 3000
                    });

                    if (response.data.status === 'success') {
                        const verifiedData = response.data.data;
                        if (verifiedData.permissions) {
                            await updatePermissions(verifiedData.permissions, verifiedData.role);
                        }
                        navigateBasedOnRole(userData);
                        return;
                    }
                } catch (verifyError) {
                    console.log('Session verification failed, requiring re-login');
                    await AsyncStorage.removeItem('userData');
                }
            }
        } catch (error) {
            console.error('Session Check Error:', error);
        } finally {
            setLoading(false);
        }
    };

    const navigateBasedOnRole = (userData) => {
        if (userData.role === 'admin') {
            navigation.replace('AdminDashboard');
        } else if (userData.role === 'host' || userData.role === 'employee') {
            navigation.replace('HostDashboard');
        } else if (userData.role === 'security') {
            navigation.replace('SecurityDashboard');
        } else {
            Alert.alert('Access Denied', 'Your role does not have mobile access.');
        }
    };

    const handleLogin = async () => {
        if (!username || !password || !tenantKey) {
            Alert.alert('Error', 'Please enter Organization Code, Username, and Password');
            return;
        }

        setLoading(true);
        // CRITICAL SYNC TEST
        //Alert.alert('Sync Check', 'Running HandleLogin UPDATED V3');

        try {
            // Clear any existing session before logging in
            // await AsyncStorage.removeItem('userData'); // Wait, if we remove userData, we lose the session context? No, we are building it.

            // Get FCM token for push notifications
            let fcmToken = null;
            try {
                // Ensure we import it properly
                const { registerForPushNotificationsAsync } = require('../utils/notificationManager');
                // Force a fresh registration attempt to ensure we get the token
                fcmToken = await registerForPushNotificationsAsync();
                console.log("Login with FCM Token:", fcmToken);
            } catch (tokenError) {
                console.log("Failed to get token for login:", tokenError);
            }

            // Construct payload
            const loginPayload = {
                username: username,
                password: password,
                fcm_token: fcmToken || "" // Ensure it's not null/undefined if possible, but empty string is better than null for some APIs
            };

            console.log("Sending Login Payload:", JSON.stringify(loginPayload));

            // Pass tenant in URL so db.php can switch context before login script runs
            const response = await apiClient.post(`api/auth/login.php?tenant=${encodeURIComponent(tenantKey)}`,
                loginPayload,
                {
                    timeout: 15000
                });

            const result = response.data;

            if (result.status === 'success') {
                const userData = result.data;
                // Important: Add tenant key to user data
                userData.tenant = tenantKey;

                // Save complete user data
                await AsyncStorage.setItem('userData', JSON.stringify(userData));

                // Update permissions context
                if (userData.permissions) {
                    await updatePermissions(userData.permissions, userData.role);
                }

                // Force check permissions for this new user immediately after login success
                try {
                    const { checkOverlayPermission } = require('../utils/notificationManager');
                    // We call it here, but also HostDashboard calls it. 
                    // Calling it here ensures it runs even if navigation is slow.
                    if (userData.id) {
                        // Reset the flag for this user if it was never set? 
                        // No, just check.
                        setTimeout(() => checkOverlayPermission(userData.id), 500);
                    }
                } catch (e) {
                    console.log("Post-login permission check failed:", e);
                }

                navigateBasedOnRole(userData);
            } else {
                Alert.alert('Login Failed', result.message || 'Invalid credentials');
            }
        } catch (error) {
            console.error('Login Error:', error);
            let errorMessage = 'Unable to connect to server. Please check your connection.';
            if (error.code === 'ECONNABORTED') {
                errorMessage = 'Connection timed out. Please try again.';
            } else if (error.response) {
                // The server responded with a status code outside the 2xx range
                const serverMsg = error.response.data?.message || error.response.data || 'Internal Server Error';
                errorMessage = `Server Error (${error.response.status}): ${typeof serverMsg === 'string' ? serverMsg : JSON.stringify(serverMsg)}`;
            } else if (error.request) {
                // The request was made but no response was received
                errorMessage = 'No response from server. Please check your internet or server status.';
            } else {
                // Something happened in setting up the request
                errorMessage = `Request Error: ${error.message}`;
            }
            Alert.alert('Error', errorMessage);
        } finally {
            setLoading(false);
        }
    };

    if (loading && !username) {
        return (
            <View style={[styles.container, styles.center]}>
                <ActivityIndicator size="large" color="#0d6efd" />
            </View>
        );
    }

    return (
        <KeyboardAvoidingView
            behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
            style={styles.container}
        >
            <View style={styles.innerContainer}>
                <View style={styles.header}>
                    <Text style={styles.title}>VisitPilot</Text>
                    <Text style={styles.subtitle}>Visitor Management System</Text>
                </View>

                <View style={styles.form}>
                    <Text style={styles.label}>Organization Code (Tenant ID)</Text>
                    <TextInput
                        style={styles.input}
                        placeholder="e.g. empire, shiddhi"
                        value={tenantKey}
                        onChangeText={setTenantKey}
                        autoCapitalize="none"
                    />

                    <Text style={styles.label}>Username</Text>
                    <TextInput
                        style={styles.input}
                        placeholder="Enter your username"
                        value={username}
                        onChangeText={setUsername}
                        autoCapitalize="none"
                    />

                    <Text style={styles.label}>Password</Text>
                    <TextInput
                        style={styles.input}
                        placeholder="Enter your password"
                        value={password}
                        onChangeText={setPassword}
                        secureTextEntry
                    />

                    <TouchableOpacity
                        style={styles.loginButton}
                        onPress={handleLogin}
                        disabled={loading}
                    >
                        {loading ? (
                            <ActivityIndicator color="#fff" />
                        ) : (
                            <Text style={styles.loginButtonText}>LOGIN</Text>
                        )}
                    </TouchableOpacity>
                </View>

                <View style={styles.footer}>
                    <Text style={styles.footerText}>© 2026 VisitPilot. A CodePilotx Architecture.All Rights Reserved.</Text>
                </View>
            </View>
        </KeyboardAvoidingView>
    );
}

const styles = StyleSheet.create({
    container: {
        flex: 1,
        backgroundColor: '#f8f9fa',
    },
    center: {
        justifyContent: 'center',
        alignItems: 'center',
    },
    innerContainer: {
        flex: 1,
        padding: 30,
        justifyContent: 'center',
    },
    header: {
        alignItems: 'center',
        marginBottom: 50,
    },
    title: {
        fontSize: 48,
        fontWeight: 'bold',
        color: '#0d6efd',
        letterSpacing: 2,
    },
    subtitle: {
        fontSize: 16,
        color: '#6c757d',
        marginTop: 5,
    },
    form: {
        backgroundColor: '#fff',
        padding: 25,
        borderRadius: 20,
        elevation: 4,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.1,
        shadowRadius: 10,
    },
    label: {
        fontSize: 14,
        fontWeight: '600',
        color: '#495057',
        marginBottom: 8,
    },
    input: {
        backgroundColor: '#f1f3f5',
        borderRadius: 12,
        padding: 15,
        fontSize: 16,
        marginBottom: 20,
        borderWidth: 1,
        borderColor: '#dee2e6',
    },
    loginButton: {
        backgroundColor: '#0d6efd',
        borderRadius: 12,
        padding: 16,
        alignItems: 'center',
        marginTop: 10,
    },
    loginButtonText: {
        color: '#fff',
        fontSize: 16,
        fontWeight: 'bold',
        letterSpacing: 1,
    },
    footer: {
        marginTop: 40,
        alignItems: 'center',
    },
    footerText: {
        fontSize: 12,
        color: '#adb5bd',
    },
});
