import axios from 'axios';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { CONFIG } from './config';

const apiClient = axios.create({
    baseURL: CONFIG.API_BASE_URL,
    timeout: 15000,
    withCredentials: true,
});

// Request interceptor to add headers
apiClient.interceptors.request.use(
    async (config) => {
        try {
            const storedUser = await AsyncStorage.getItem('userData');
            if (storedUser) {
                const user = JSON.parse(storedUser);

                // Add Session ID to headers
                if (user.session_id) {
                    config.headers['X-Session-ID'] = user.session_id;
                }

                // Add tenant to params if not present
                if (user.tenant && !config.params?.tenant) {
                    config.params = {
                        ...config.params,
                        tenant: user.tenant
                    };
                }
            }
        } catch (error) {
            console.error('API Client Request Error:', error);
        }
        return config;
    },
    (error) => {
        return Promise.reject(error);
    }
);

// Response interceptor for global error handling
apiClient.interceptors.response.use(
    (response) => response,
    async (error) => {
        if (error.response && error.response.status === 401) {
            // Handle unauthorized access (session expired)
            await AsyncStorage.removeItem('userData');
            // We can't directly use navigation here, but the screens check for userData
        }
        return Promise.reject(error);
    }
);

export const logout = async (navigation) => {
    try {
        const lastToken = await AsyncStorage.getItem('last_fcm_token');
        const cachedToken = await AsyncStorage.getItem('cached_fcm_token');
        const fcmToken = lastToken || cachedToken;

        console.log("[Logout] Attempting server logout with token:", fcmToken ? fcmToken.substring(0, 15) + "..." : "none");

        // Use a timeout to ensure logout doesn't hang the UI if network is poor
        await apiClient.post('api/auth/logout.php', { fcm_token: fcmToken }, { timeout: 5000 });
    } catch (e) {
        console.log('[Logout] Server-side logout failed or timed out:', e.message);
    } finally {
        // Always clear local state regardless of server success
        await AsyncStorage.removeItem('userData');
        if (navigation) {
            navigation.replace('Login');
        }
    }
};

export default apiClient;
