import React, { useState, useEffect } from 'react';
import { View, Text, StyleSheet, ScrollView, Image, TouchableOpacity, ActivityIndicator, Alert, SafeAreaView } from 'react-native';
import { MaterialCommunityIcons as Icon } from '@expo/vector-icons';
import apiClient from '../utils/apiClient';
import { CONFIG } from '../utils/config';

export default function VisitDetailScreen({ navigation, route }) {
    const { visit_id } = route.params;
    const [visit, setVisit] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetchDetail();
    }, [visit_id]);

    const fetchDetail = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get(`host/api/get_visit_detail.php?id=${visit_id}`);
            if (response.data.success) {
                setVisit(response.data.visit);
            } else {
                Alert.alert("Error", "Could not load visit details.");
                navigation.goBack();
            }
        } catch (e) {
            console.error("Detail Fetch Error:", e);
            Alert.alert("Error", "Network connection failed.");
            navigation.goBack();
        } finally {
            setLoading(false);
        }
    };

    const getPhotoUrl = (url) => {
        if (!url) return null;
        if (url.startsWith('http')) return url;
        let cleanUrl = url.replace(/^(\.\.\/)+/, '');
        if (cleanUrl.startsWith('/')) cleanUrl = cleanUrl.substring(1);
        return `${CONFIG.API_BASE_URL}${cleanUrl}`;
    };

    if (loading) {
        return (
            <View style={styles.center}>
                <ActivityIndicator size="large" color="#0d6efd" />
            </View>
        );
    }

    if (!visit) return null;

    const photoUri = getPhotoUrl(visit.visit_photo || visit.photo_path || visit.photo_url);

    return (
        <SafeAreaView style={styles.container}>
            <View style={styles.header}>
                <TouchableOpacity onPress={() => navigation.goBack()} style={styles.backBtn}>
                    <Icon name="arrow-left" size={24} color="#333" />
                </TouchableOpacity>
                <Text style={styles.headerTitle}>Visit Information</Text>
                <View style={{ width: 40 }} />
            </View>

            <ScrollView contentContainerStyle={styles.scroll}>
                <View style={styles.card}>
                    <View style={styles.mainInfo}>
                        <Image
                            source={photoUri ? { uri: photoUri } : { uri: `https://ui-avatars.com/api/?name=${encodeURIComponent(visit.visitor_name || 'V')}&background=random` }}
                            style={styles.photo}
                        />
                        <View style={styles.basic}>
                            <Text style={styles.name}>{visit.visitor_name}</Text>
                            <Text style={styles.mobile}>{visit.visitor_mobile || visit.mobile}</Text>
                            <View style={[styles.badge, { backgroundColor: getStatusColor(visit.status) }]}>
                                <Text style={styles.badgeText}>{(visit.status || '').toUpperCase()}</Text>
                            </View>
                        </View>
                    </View>

                    <View style={styles.grid}>
                        <DetailItem label="Purpose" value={visit.purpose} icon="information" />
                        <DetailItem label="Host" value={visit.host_name} icon="account" />
                        <DetailItem label="Department" value={visit.department} icon="office-building" />
                        <DetailItem label="Time" value={new Date(visit.created_at).toLocaleString()} icon="clock" />
                        {visit.check_in_time && <DetailItem label="Check-In" value={new Date(visit.check_in_time).toLocaleString()} icon="login" />}
                    </View>
                </View>
            </ScrollView>
        </SafeAreaView>
    );
}

function DetailItem({ label, value, icon }) {
    return (
        <View style={styles.item}>
            <Icon name={icon} size={20} color="#666" style={{ marginRight: 10 }} />
            <View>
                <Text style={styles.label}>{label}</Text>
                <Text style={styles.value}>{value || '-'}</Text>
            </View>
        </View>
    );
}

function getStatusColor(status) {
    if (!status) return '#6c757d';
    switch (status.toLowerCase()) {
        case 'approved': return '#0d6efd';
        case 'checked_in': return '#198754';
        case 'rejected': return '#dc3545';
        default: return '#6c757d';
    }
}

const styles = StyleSheet.create({
    container: { flex: 1, backgroundColor: '#f5f5f5' },
    center: { flex: 1, justifyContent: 'center', alignItems: 'center' },
    header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', padding: 15, backgroundColor: '#fff', borderBottomWidth: 1, borderBottomColor: '#eee' },
    headerTitle: { fontSize: 18, fontWeight: 'bold' },
    backBtn: { padding: 5 },
    scroll: { padding: 15 },
    card: { backgroundColor: '#fff', borderRadius: 12, padding: 20, elevation: 2 },
    mainInfo: { flexDirection: 'row', marginBottom: 25 },
    photo: { width: 80, height: 80, borderRadius: 40, marginRight: 15 },
    basic: { justifyContent: 'center' },
    name: { fontSize: 20, fontWeight: 'bold' },
    mobile: { color: '#666', marginVertical: 3 },
    badge: { paddingHorizontal: 10, paddingVertical: 4, borderRadius: 6, alignSelf: 'flex-start' },
    badgeText: { color: '#fff', fontSize: 11, fontWeight: 'bold' },
    grid: { borderTopWidth: 1, borderTopColor: '#f0f0f0', paddingTop: 20 },
    item: { flexDirection: 'row', alignItems: 'center', marginBottom: 15 },
    label: { fontSize: 12, color: '#999' },
    value: { fontSize: 15, fontWeight: '500', color: '#333' }
});
