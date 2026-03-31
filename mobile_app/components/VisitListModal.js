import React, { useState } from 'react';
import {
    View, Text, Modal, TouchableOpacity, ScrollView, Image,
    StyleSheet, SafeAreaView, StatusBar, TextInput
} from 'react-native';
import { MaterialCommunityIcons as Icon } from '@expo/vector-icons';
import { CONFIG } from '../utils/config';

/**
 * VisitListModal — Full-screen SafeAreaView list of visits.
 * Used by Admin, Host, and Security dashboards for stat-card click views.
 *
 * Props:
 *   visible         {boolean}
 *   onClose         {() => void}
 *   title           {string}  — e.g. "Today's Visits"
 *   color           {string}  — header color, default '#10b981'
 *   visits          {array}   — list of visit objects
 *   onVisitPress    {(visit) => void} — called when a card row is tapped
 */
const VisitListModal = ({ visible, onClose, title = "Visits", color = '#10b981', visits = [], onVisitPress }) => {
    const [search, setSearch] = useState('');

    const getPhotoUrl = (url) => {
        if (!url) return null;
        if (url.startsWith('http')) return url;
        let clean = url.startsWith('/') ? url.substring(1) : url;
        return `${CONFIG.API_BASE_URL}${clean}`;
    };

    const getStatusColor = (status) => {
        if (!status) return '#6c757d';
        switch (status.toLowerCase()) {
            case 'approved': return '#0d6efd';
            case 'checked_in': return '#198754';
            case 'rejected': return '#dc3545';
            case 'checked_out': return '#212529';
            case 'pending': return '#f59e0b';
            case 'completed': return '#212529';
            default: return '#6c757d';
        }
    };

    const fmt = (t) => {
        if (!t) return '-';
        return new Date(t).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    };

    const filtered = visits.filter(v => {
        if (!search.trim()) return true;
        const s = search.toLowerCase();
        return (v.visitor_name || '').toLowerCase().includes(s) ||
            (v.mobile || '').includes(s) ||
            (v.visit_code || '').toLowerCase().includes(s);
    });

    const renderCard = (item, idx) => {
        const photo = getPhotoUrl(item.photo_path || item.visit_photo || item.visitor_photo);
        const avatarUri = photo
            ? { uri: photo }
            : { uri: `https://ui-avatars.com/api/?name=${encodeURIComponent(item.visitor_name || 'V')}&background=random` };

        return (
            <TouchableOpacity
                key={`${item.id}-${idx}`}
                style={styles.card}
                activeOpacity={0.75}
                onPress={() => onVisitPress && onVisitPress(item)}
            >
                <Image source={avatarUri} style={styles.avatar} />
                <View style={styles.info}>
                    <Text style={styles.name}>{item.visitor_name}</Text>
                    <Text style={styles.purpose}>{item.purpose || 'General Visit'}</Text>

                    {/* Badges row */}
                    <View style={styles.badgeRow}>
                        {item.is_invited == 1 && (
                            <View style={styles.inviteBadge}>
                                <Text style={styles.inviteBadgeText}>Invitation</Text>
                            </View>
                        )}
                        <View style={[styles.statusBadge, { backgroundColor: getStatusColor(item.status) }]}>
                            <Text style={styles.statusText}>{(item.status || '').replace(/_/g, ' ').toUpperCase()}</Text>
                        </View>
                    </View>

                    {/* In/Out row */}
                    <View style={styles.timeRow}>
                        <Text style={[styles.timeTag, styles.timeTagIn]}>
                            In: {fmt(item.check_in_time)}
                        </Text>
                        <Text style={[styles.timeTag, styles.timeTagOut]}>
                            Out: {fmt(item.check_out_time)}
                        </Text>
                    </View>
                </View>

                {/* Created time on right */}
                <Text style={styles.createdTime}>
                    {item.created_at ? new Date(item.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''}
                </Text>
            </TouchableOpacity>
        );
    };

    return (
        <Modal
            animationType="slide"
            transparent={false}
            visible={visible}
            onRequestClose={onClose}
        >
            <SafeAreaView style={styles.safeArea}>
                <StatusBar barStyle="light-content" backgroundColor={color} />

                {/* Header */}
                <View style={[styles.header, { backgroundColor: color }]}>
                    <TouchableOpacity onPress={onClose} style={styles.backBtn}>
                        <Icon name="arrow-left" size={26} color="#fff" />
                    </TouchableOpacity>
                    <Text style={styles.headerTitle}>{title}</Text>
                    <View style={{ width: 40 }} />
                </View>

                {/* Search */}
                <View style={styles.searchContainer}>
                    <Icon name="magnify" size={20} color="#94a3b8" style={{ marginRight: 8 }} />
                    <TextInput
                        style={styles.searchInput}
                        placeholder="Search visitor name or mobile..."
                        placeholderTextColor="#94a3b8"
                        value={search}
                        onChangeText={setSearch}
                    />
                    {search.length > 0 && (
                        <TouchableOpacity onPress={() => setSearch('')}>
                            <Icon name="close-circle" size={18} color="#94a3b8" />
                        </TouchableOpacity>
                    )}
                </View>

                {/* List */}
                <ScrollView
                    style={styles.list}
                    contentContainerStyle={{ paddingBottom: 30 }}
                    showsVerticalScrollIndicator={false}
                >
                    {filtered.length === 0 ? (
                        <View style={styles.empty}>
                            <Icon name="account-off" size={60} color="#cbd5e1" />
                            <Text style={styles.emptyTitle}>No Visitors Found</Text>
                            <Text style={styles.emptyText}>
                                {search.trim() ? 'No results for your search.' : 'No visits to display.'}
                            </Text>
                        </View>
                    ) : (
                        filtered.map((v, i) => renderCard(v, i))
                    )}
                </ScrollView>
            </SafeAreaView>
        </Modal>
    );
};

const styles = StyleSheet.create({
    safeArea: { flex: 1, backgroundColor: '#f1f5f9' },

    header: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        paddingHorizontal: 16,
        paddingVertical: 16,
    },
    backBtn: { width: 40, alignItems: 'flex-start' },
    headerTitle: { fontSize: 20, fontWeight: '900', color: '#fff' },

    searchContainer: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: '#fff',
        marginHorizontal: 16,
        marginVertical: 12,
        borderRadius: 14,
        paddingHorizontal: 14,
        paddingVertical: 10,
        borderWidth: 1,
        borderColor: '#e2e8f0',
        elevation: 2,
    },
    searchInput: { flex: 1, fontSize: 14, color: '#1e293b', fontWeight: '500' },

    list: { flex: 1, paddingHorizontal: 12 },

    card: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: '#fff',
        borderRadius: 18,
        padding: 14,
        marginBottom: 10,
        elevation: 2,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.07,
        shadowRadius: 5,
    },
    avatar: { width: 54, height: 54, borderRadius: 27, marginRight: 12, backgroundColor: '#e2e8f0' },

    info: { flex: 1 },
    name: { fontSize: 16, fontWeight: '800', color: '#1e293b', marginBottom: 2 },
    purpose: { fontSize: 12, color: '#64748b', fontWeight: '600', marginBottom: 6 },

    badgeRow: { flexDirection: 'row', gap: 6, flexWrap: 'wrap', marginBottom: 6 },
    inviteBadge: { backgroundColor: '#eff6ff', paddingHorizontal: 8, paddingVertical: 3, borderRadius: 8 },
    inviteBadgeText: { fontSize: 10, color: '#3b82f6', fontWeight: '800' },
    statusBadge: { paddingHorizontal: 8, paddingVertical: 3, borderRadius: 8 },
    statusText: { fontSize: 10, color: '#fff', fontWeight: '900' },

    timeRow: { flexDirection: 'row', gap: 8 },
    timeTag: { fontSize: 11, fontWeight: '700', paddingHorizontal: 8, paddingVertical: 3, borderRadius: 8 },
    timeTagIn: { backgroundColor: '#eff6ff', color: '#3b82f6' },
    timeTagOut: { backgroundColor: '#f8fafc', color: '#64748b' },

    createdTime: { fontSize: 11, color: '#94a3b8', fontWeight: '600', alignSelf: 'flex-start' },

    empty: { alignItems: 'center', marginTop: 80 },
    emptyTitle: { fontSize: 18, fontWeight: '800', color: '#334155', marginTop: 16 },
    emptyText: { fontSize: 13, color: '#94a3b8', marginTop: 6, textAlign: 'center' },
});

export default VisitListModal;
