import React, { createContext, useState, useEffect, useContext, useMemo, useCallback } from 'react';
import AsyncStorage from '@react-native-async-storage/async-storage';
import apiClient from '../utils/apiClient';

const PermissionContext = createContext();

export const PermissionProvider = ({ children }) => {
    const [permissions, setPermissions] = useState([]);
    const [role, setRole] = useState(null);
    const [loading, setLoading] = useState(true);

    // Optimize lookups with Set (O(1))
    const permissionSet = useMemo(() => new Set(permissions), [permissions]);

    // Load permissions from storage on startup
    useEffect(() => {
        const loadPermissions = async () => {
            try {
                const storedPermissions = await AsyncStorage.getItem('user_permissions');
                const storedRole = await AsyncStorage.getItem('user_role');

                if (storedPermissions) {
                    setPermissions(JSON.parse(storedPermissions));
                }
                if (storedRole) {
                    setRole(storedRole);
                }
            } catch (error) {
                console.error('Failed to load permissions:', error);
            } finally {
                setLoading(false);
            }
        };

        loadPermissions();
    }, []);

    const updatePermissions = async (newPermissions, newRole) => {
        try {
            await AsyncStorage.setItem('user_permissions', JSON.stringify(newPermissions));
            await AsyncStorage.setItem('user_role', newRole);
            setPermissions(newPermissions);
            setRole(newRole);
        } catch (error) {
            console.error('Failed to save permissions:', error);
        }
    };

    const refreshPermissions = useCallback(async () => {
        try {
            const response = await apiClient.get('api/auth/verify_session.php');
            if (response.data && response.data.status === 'success') {
                const { permissions: newPermissions, role: newRole } = response.data.data;
                await updatePermissions(newPermissions, newRole);
                return true;
            }
        } catch (error) {
            console.error('Failed to refresh permissions:', error);
        }
        return false;
    }, []);

    const clearPermissions = async () => {
        try {
            await AsyncStorage.removeItem('user_permissions');
            await AsyncStorage.removeItem('user_role');
            setPermissions([]);
            setRole(null);
        } catch (error) {
            console.error('Failed to clear permissions:', error);
        }
    };

    const hasPermission = useCallback((permissionKey) => {
        // Admin always has access
        if (role === 'admin') return true;

        // If permissionKey is null or empty, allow access (public page)
        if (!permissionKey) return true;

        // Check if permission exists in the list
        // Some permissions might be array of strings (OR condition)
        if (Array.isArray(permissionKey)) {
            return permissionKey.some(key => permissionSet.has(key));
        }

        return permissionSet.has(permissionKey);
    }, [role, permissionSet]);

    return (
        <PermissionContext.Provider value={{
            permissions,
            role,
            hasPermission,
            updatePermissions,
            refreshPermissions,
            clearPermissions,
            loading
        }}>
            {children}
        </PermissionContext.Provider>
    );
};

export const usePermissions = () => {
    const context = useContext(PermissionContext);
    if (!context) {
        throw new Error('usePermissions must be used within a PermissionProvider');
    }
    return context;
};
