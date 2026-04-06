import React, { useEffect, useState } from 'react';
import { View, Text, StyleSheet, Image, ScrollView, ActivityIndicator, TouchableOpacity, Alert, Linking, Platform } from 'react-native';
import { MaterialCommunityIcons, FontAwesome5 } from '@expo/vector-icons';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { LinearGradient } from 'expo-linear-gradient';

const VisitDetailScreen = ({ route, navigation }) => {
    const { visit_id } = route.params;
    const [visit, setVisit] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetchVisitDetail();
    }, [visit_id]);

    const fetchVisitDetail = async () => {
        try {
            const baseUrl = await AsyncStorage.getItem('baseUrl');
            const token = await AsyncStorage.getItem('userToken');
            const tenant = await AsyncStorage.getItem('userTenant');

            const response = await fetch(`${baseUrl}/host/api/get_visit_detail.php?visit_id=${visit_id}`, {
                headers: { 'Authorization': `Bearer ${token}`, 'X-Tenant-ID': tenant }
            });
            const res = await response.json();
            if (res.success) {
                setVisit(res.data);
            } else {
                Alert.alert("Error", res.message || "Failed to load visit details.");
                navigation.goBack();
            }
        } catch (error) {
            console.error("Detail Fetch Error:", error);
            Alert.alert("Error", "Could not connect to the server.");
        } finally {
            setLoading(false);
        }
    };

    if (loading) {
        return (
            <View style={styles.center}>
                <ActivityIndicator size="large" color="#0066cc" />
                <Text style={{ marginTop: 10 }}>Loading details...</Text>
            </View>
        );
    }

    const getStatusColor = (status) => {
        switch (status?.toLowerCase()) {
            case 'approved': return '#28a745';
            case 'pending': return '#ffc107';
            case 'rejected': return '#dc3545';
            case 'completed': return '#007bff';
            default: return '#6c757d';
        }
    };

    return (
        <ScrollView style={styles.container} contentContainerStyle={{ paddingBottom: 50 }}>
            {/* Header with Background */}
            <LinearGradient colors={['#004e92', '#000428']} style={styles.header}>
                <TouchableOpacity onPress={() => navigation.goBack()} style={styles.backBtn}>
                    <MaterialCommunityIcons name="arrow-left" size={28} color="white" />
                </TouchableOpacity>
                <Text style={styles.headerTitle}>Visit Detail</Text>
                <TouchableOpacity style={styles.shareBtn} onPress={() => {}}>
                    <MaterialCommunityIcons name="share-variant" size={24} color="white" />
                </TouchableOpacity>
            </LinearGradient>

            {/* Profile Section */}
            <View style={styles.profileSection}>
                <Image 
                    source={visit?.visitor_photo ? { uri: visit.visitor_photo } : require('../../assets/placeholder.png')} 
                    style={styles.avatar} 
                />
                <Text style={styles.visitorName}>{visit?.visitor_name || "Unknown Visitor"}</Text>
                <View style={[styles.statusBadge, { backgroundColor: getStatusColor(visit?.status) }]}>
                    <Text style={styles.statusText}>{visit?.status?.toUpperCase() || "PENDING"}</Text>
                </View>
            </View>

            {/* Info Cards */}
            <View style={styles.infoContainer}>
                <DetailItem icon="phone" label="Mobile" value={visit?.visitor_mobile} color="#007bff" />
                <DetailItem icon="account-tie" label="Host" value={visit?.host_name || "N/A"} color="#6c5ce7" />
                <DetailItem icon="office-building" label="Department" value={visit?.department_name || "General"} color="#00b894" />
                <DetailItem icon="calendar-clock" label="Visit Time" value={visit?.checkin_time || visit?.visit_date} color="#e17055" />
                <DetailItem icon="comment-text" label="Purpose" value={visit?.purpose || "Business Meeting"} color="#fdcb6e" />
                <DetailItem icon="briefcase" label="Assets" value={visit?.assets_carried || "None"} color="#a29bfe" />
            </View>

            {/* Actions */}
            <View style={styles.actionRow}>
                <TouchableOpacity style={[styles.actionBtn, {backgroundColor: '#28a745'}]} onPress={() => Linking.openURL(`tel:${visit.visitor_mobile}`)}>
                    <MaterialCommunityIcons name="phone" size={22} color="white" />
                    <Text style={styles.actionBtnText}>Call Visitor</Text>
                </TouchableOpacity>
                <TouchableOpacity style={[styles.actionBtn, {backgroundColor: '#25D366'}]} onPress={() => Linking.openURL(`whatsapp://send?phone=${visit.visitor_mobile}`)}>
                    <MaterialCommunityIcons name="whatsapp" size={22} color="white" />
                    <Text style={styles.actionBtnText}>WhatsApp</Text>
                </TouchableOpacity>
            </View>
        </ScrollView>
    );
};

const DetailItem = ({ icon, label, value, color }) => (
    <View style={styles.detailCard}>
        <View style={[styles.iconBox, { backgroundColor: color + '22' }]}>
            <MaterialCommunityIcons name={icon} size={24} color={color} />
        </View>
        <View style={styles.detailText}>
            <Text style={styles.detailLabel}>{label}</Text>
            <Text style={styles.detailValue}>{value || "Not specified"}</Text>
        </View>
    </View>
);

const styles = StyleSheet.create({
    container: { flex: 1, backgroundColor: '#f8f9fa' },
    center: { flex: 1, justifyContent: 'center', alignItems: 'center' },
    header: { height: 120, justifyContent: 'center', paddingHorizontal: 20, paddingTop: 40, flexDirection: 'row', alignItems: 'center' },
    backBtn: { position: 'absolute', left: 20, top: 55 },
    shareBtn: { position: 'absolute', right: 20, top: 55 },
    headerTitle: { color: 'white', fontSize: 20, fontWeight: 'bold' },
    profileSection: { alignItems: 'center', marginTop: -40, marginBottom: 20 },
    avatar: { width: 100, height: 100, borderRadius: 50, borderWidth: 4, borderColor: 'white', backgroundColor: '#eee' },
    visitorName: { fontSize: 24, fontWeight: 'bold', marginTop: 10, color: '#333' },
    statusBadge: { paddingHorizontal: 15, paddingVertical: 5, borderRadius: 20, marginTop: 8 },
    statusText: { color: 'white', fontSize: 12, fontWeight: 'bold' },
    infoContainer: { paddingHorizontal: 20 },
    detailCard: { flexDirection: 'row', backgroundColor: 'white', padding: 15, borderRadius: 15, marginBottom: 12, alignItems: 'center', elevation: 2, shadowColor: '#000', shadowOffset: { width: 0, height: 2 }, shadowOpacity: 0.1, shadowRadius: 4 },
    iconBox: { width: 45, height: 45, borderRadius: 12, justifyContent: 'center', alignItems: 'center', marginRight: 15 },
    detailText: { flex: 1 },
    detailLabel: { fontSize: 12, color: '#888', marginBottom: 2 },
    detailValue: { fontSize: 16, color: '#333', fontWeight: '500' },
    actionRow: { flexDirection: 'row', justifyContent: 'space-between', paddingHorizontal: 20, marginTop: 20 },
    actionBtn: { flex: 0.48, height: 50, borderRadius: 12, flexDirection: 'row', justifyContent: 'center', alignItems: 'center', elevation: 3 },
    actionBtnText: { color: 'white', fontWeight: 'bold', marginLeft: 8 }
});

export default VisitDetailScreen;
