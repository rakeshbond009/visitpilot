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

export default apiClient;
