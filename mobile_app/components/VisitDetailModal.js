import React, { useRef } from 'react';
import { View, Text, Modal, TouchableOpacity, ScrollView, Image, StyleSheet } from 'react-native';
import { CONFIG } from '../utils/config';

const VisitDetailModal = ({ visible, onClose, visit, onAction }) => {
    const scrollRef = useRef(null);
    if (!visit) return null;

    const getPhotoUrl = (url) => {
        if (!url) return null;
        if (url.startsWith('http')) return url;
        let cleanUrl = url.startsWith('/') ? url.substring(1) : url;
        return `${CONFIG.API_BASE_URL}${cleanUrl}`;
    };

    const getStatusColor = (status) => {
        switch (status) {
            case 'pending': return '#f59e0b';
            case 'approved': return '#10b981';
            case 'checked_in': return '#3b82f6';
            case 'checked_out': return '#64748b';
            case 'rejected': return '#ef4444';
            default: return '#94a3b8';
        }
    };

    const formatDate = (dateStr) => {
        if (!dateStr) return 'N/A';
        try {
            const date = new Date(dateStr);
            return date.toLocaleString('en-IN', {
                day: '2-digit',
                month: 'short',
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            });
        } catch (e) {
            return dateStr;
        }
    };

    const photoUri = getPhotoUrl(visit.photo_url || visit.visit_photo || visit.photo_path);
    const [passModalVisible, setPassModalVisible] = React.useState(false);

    return (
        <>
            <Modal
                animationType="slide"
                transparent={true}
                visible={visible}
                onRequestClose={onClose}
            >
                <View style={styles.modalOverlay}>
                    <View style={styles.detailsModalView}>
                        <View style={styles.modalHeader}>
                            <Text style={styles.modalTitle}>Visit Details</Text>
                            <TouchableOpacity onPress={onClose}>
                                <Text style={styles.closeBtn}>✕</Text>
                            </TouchableOpacity>
                        </View>

                        <ScrollView ref={scrollRef} style={styles.detailsContainer} showsVerticalScrollIndicator={false}>
                            <View style={styles.detailsHeader}>
                                <View style={styles.photoContainer}>
                                    <Image
                                        source={photoUri ? { uri: photoUri } : { uri: `https://ui-avatars.com/api/?name=${encodeURIComponent(visit.visitor_name || 'V')}&background=random` }}
                                        style={styles.visitorPhoto}
                                        resizeMode="cover"
                                    />
                                </View>
                                <View style={styles.detailsBasic}>
                                    <Text style={styles.detailsName}>{visit.visitor_name}</Text>
                                    <Text style={styles.detailsMobile}>{visit.mobile}</Text>
                                    <View style={[styles.statusBadgeModal, { backgroundColor: getStatusColor(visit.status) }]}>
                                        <Text style={styles.statusBadgeTextModal}>{(visit.status || '').toUpperCase().replace('_', ' ')}</Text>
                                    </View>
                                </View>
                            </View>

                            <View style={styles.detailsCard}>
                                <Text style={styles.detailsSectionTitle}>Visit Information</Text>
                                <View style={{ flexDirection: 'row', flexWrap: 'wrap' }}>
                                    {[
                                        { label: 'Host Name', value: visit.host_name },
                                        { label: 'Department', value: visit.department },
                                        { label: 'Purpose', value: visit.purpose },
                                        { label: 'Visit Code', value: visit.visit_code ? `#${visit.visit_code}` : null },
                                        { label: 'Company', value: visit.company },
                                        { label: 'Email', value: visit.email },
                                        { label: 'ID Type', value: visit.id_proof_type },
                                        { label: 'ID Number', value: visit.id_proof_number },
                                    ].map((item, index) => (
                                        <View key={index} style={styles.detailsItem}>
                                            <Text style={styles.detailsLabel}>{item.label}</Text>
                                            <Text style={styles.detailsValue}>{item.value || '-'}</Text>
                                        </View>
                                    ))}
                                </View>
                            </View>

                            <View style={styles.detailsCard}>
                                <Text style={styles.detailsSectionTitle}>Timeline</Text>
                                <View style={styles.timelineItem}>
                                    <View style={[styles.timelineDot, { backgroundColor: '#3b82f6' }]} />
                                    <View>
                                        <Text style={styles.timelineTitle}>Registered</Text>
                                        <Text style={styles.timelineDate}>{formatDate(visit.created_at)}</Text>
                                    </View>
                                </View>
                                {visit.check_in_time && (
                                    <View style={styles.timelineItem}>
                                        <View style={[styles.timelineDot, { backgroundColor: '#10b981' }]} />
                                        <View>
                                            <Text style={styles.timelineTitle}>Checked In</Text>
                                            <Text style={styles.timelineDate}>{formatDate(visit.check_in_time)}</Text>
                                        </View>
                                    </View>
                                )}
                                {visit.check_out_time && (
                                    <View style={styles.timelineItem}>
                                        <View style={[styles.timelineDot, { backgroundColor: '#64748b' }]} />
                                        <View>
                                            <Text style={styles.timelineTitle}>Checked Out</Text>
                                            <Text style={styles.timelineDate}>{formatDate(visit.check_out_time)}</Text>
                                        </View>
                                    </View>
                                )}
                            </View>

                            {onAction && (
                                <View style={styles.actionsContainer}>
                                    {visit.visit_code && (
                                        <TouchableOpacity 
                                            style={[styles.modalActionBtn, { backgroundColor: '#1e293b', marginBottom: 12, flex: 0, width: '100%', flexDirection: 'row', justifyContent: 'center', alignItems: 'center' }]} 
                                            onPress={() => setPassModalVisible(true)}
                                        >
                                            <Text style={styles.modalActionText}>View Digital Pass</Text>
                                        </TouchableOpacity>
                                    )}
                                    
                                    <View style={{ flexDirection: 'row', gap: 10, width: '100%' }}>
                                        {visit.status === 'pending' && (
                                            <>
                                                <TouchableOpacity 
                                                    style={[styles.modalActionBtn, styles.rejectBtn, { flex: 1 }]} 
                                                    onPress={() => onAction(visit.id, 'reject')}
                                                >
                                                    <Text style={styles.modalActionText}>Reject</Text>
                                                </TouchableOpacity>
                                                <TouchableOpacity 
                                                    style={[styles.modalActionBtn, styles.approveBtn, { flex: 1 }]} 
                                                    onPress={() => onAction(visit.id, 'approve')}
                                                >
                                                    <Text style={styles.modalActionText}>Approve</Text>
                                                </TouchableOpacity>
                                            </>
                                        )}
                                        {visit.status === 'approved' && (
                                            <TouchableOpacity 
                                                style={[styles.modalActionBtn, styles.checkInBtn, { flex: 1 }]} 
                                                onPress={() => onAction(visit.id, 'checkin')}
                                            >
                                                <Text style={styles.modalActionText}>Check-In Visitor</Text>
                                            </TouchableOpacity>
                                        )}
                                        {visit.status === 'checked_in' && (
                                            <TouchableOpacity 
                                                style={[styles.modalActionBtn, styles.checkOutBtn, { flex: 1 }]} 
                                                onPress={() => onAction(visit.id, 'checkout')}
                                            >
                                                <Text style={styles.modalActionText}>Check-Out Visitor</Text>
                                            </TouchableOpacity>
                                        )}
                                    </View>
                                </View>
                            )}

                            {visit.rejection_reason && (
                                <View style={[styles.detailsCard, { backgroundColor: '#fef2f2' }]}>
                                    <Text style={[styles.detailsSectionTitle, { color: '#ef4444' }]}>Rejection Reason</Text>
                                    <Text style={[styles.detailsValue, { color: '#dc2626' }]}>{visit.rejection_reason}</Text>
                                </View>
                            )}

                            <View style={{ height: 40 }} />
                        </ScrollView>
                    </View>
                </View>
            </Modal>

            <Modal
                animationType="fade"
                transparent={true}
                visible={passModalVisible}
                onRequestClose={() => setPassModalVisible(false)}
            >
                <View style={[styles.modalOverlay, { backgroundColor: 'rgba(0,0,0,0.85)', justifyContent: 'center' }]}>
                    <View style={[styles.detailsCard, { margin: 25, padding: 30, alignItems: 'center', width: '85%', alignSelf: 'center' }]}>
                        <View style={{ width: '100%', flexDirection: 'row', justifyContent: 'space-between', marginBottom: 20 }}>
                            <Text style={[styles.modalTitle, { color: '#3b82f6' }]}>Entry Pass</Text>
                            <TouchableOpacity onPress={() => setPassModalVisible(false)}>
                                <Text style={{ fontSize: 24, color: '#64748b' }}>✕</Text>
                            </TouchableOpacity>
                        </View>
                        
                        <View style={{ alignItems: 'center', backgroundColor: '#f8fafc', padding: 25, borderRadius: 20, width: '100%' }}>
                            {visit.visit_code && (
                                <Image
                                    source={{ uri: `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${encodeURIComponent(visit.visit_code)}` }}
                                    style={{ width: 220, height: 220, marginBottom: 20 }}
                                />
                            )}
                            <Text style={{ fontSize: 26, fontWeight: '900', color: '#1e293b', letterSpacing: 2 }}>{visit.visit_code || '---'}</Text>
                            <Text style={{ fontSize: 12, color: '#64748b', marginTop: 10, fontWeight: '700' }}>SCAN TO CHECK-IN / CHECK-OUT</Text>
                        </View>

                        <View style={{ marginTop: 25, width: '100%' }}>
                            <Text style={{ fontSize: 18, fontWeight: '800', color: '#1e293b', textAlign: 'center' }}>{visit.visitor_name}</Text>
                            <Text style={{ fontSize: 14, color: '#64748b', textAlign: 'center', marginTop: 5 }}>Host: {visit.host_name || '-'}</Text>
                        </View>

                        <TouchableOpacity 
                            style={[styles.modalActionBtn, { backgroundColor: '#3b82f6', marginTop: 30, width: '100%', flex: 0 }]}
                            onPress={() => setPassModalVisible(false)}
                        >
                            <Text style={styles.modalActionText}>Done</Text>
                        </TouchableOpacity>
                    </View>
                </View>
            </Modal>
        </>
    );
            </View>
        </Modal>
    );
};

const styles = StyleSheet.create({
    modalOverlay: { flex: 1, backgroundColor: 'rgba(0,0,0,0.5)', justifyContent: 'flex-end' },
    detailsModalView: { width: '100%', height: '90%', backgroundColor: '#f8fafc', borderTopLeftRadius: 25, borderTopRightRadius: 25, marginTop: 'auto' },
    detailsContainer: { flex: 1 },
    modalHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', padding: 20, borderBottomWidth: 1, borderBottomColor: '#f1f5f9', backgroundColor: '#fff', borderTopLeftRadius: 25, borderTopRightRadius: 25 },
    modalTitle: { fontSize: 18, fontWeight: '800', color: '#1e293b' },
    closeBtn: { fontSize: 20, color: '#64748b', padding: 5 },
    detailsHeader: { flexDirection: 'row', padding: 20, backgroundColor: '#3b82f6', alignItems: 'center' },

    photoContainer: { width: 85, height: 85, borderRadius: 43, backgroundColor: 'rgba(255,255,255,0.2)', justifyContent: 'center', alignItems: 'center', marginRight: 20, borderWidth: 3, borderColor: 'rgba(255,255,255,0.5)' },
    visitorPhoto: { width: '100%', height: '100%', borderRadius: 40 },
    detailsBasic: { flex: 1 },
    detailsName: { fontSize: 24, fontWeight: '900', color: '#ffffff' },
    detailsMobile: { fontSize: 16, color: 'rgba(255,255,255,0.85)', marginBottom: 10 },
    statusBadgeModal: { alignSelf: 'flex-start', paddingHorizontal: 12, paddingVertical: 5, borderRadius: 12 },
    statusBadgeTextModal: { color: '#ffffff', fontSize: 11, fontWeight: '900', textTransform: 'uppercase' },

    detailsCard: { backgroundColor: '#ffffff', margin: 15, borderRadius: 20, padding: 20, elevation: 4, shadowColor: '#000', shadowOffset: { width: 0, height: 2 }, shadowOpacity: 0.1, shadowRadius: 6 },
    detailsSectionTitle: { fontSize: 12, fontWeight: '800', color: '#3b82f6', textTransform: 'uppercase', marginBottom: 20, letterSpacing: 1.5 },
    detailsGrid: { flexDirection: 'row', flexWrap: 'wrap' },
    detailsItem: { width: '50%', marginBottom: 20 },
    detailsLabel: { fontSize: 11, color: '#94a3b8', marginBottom: 5, fontWeight: '700' },
    detailsValue: { fontSize: 15, fontWeight: '700', color: '#1e293b' },

    timelineItem: { flexDirection: 'row', marginBottom: 20, alignItems: 'center' },
    timelineDot: { width: 12, height: 12, borderRadius: 6, marginRight: 15 },
    timelineTitle: { fontSize: 15, fontWeight: '800', color: '#1e293b' },
    timelineDate: { fontSize: 13, color: '#64748b' },

    passCard: { backgroundColor: '#fff', margin: 15, borderRadius: 20, padding: 20, alignItems: 'center', borderStyle: 'dashed', borderWidth: 2, borderColor: '#e2e8f0' },
    qrContainer: { padding: 10, alignItems: 'center' },
    qrCode: { width: 180, height: 180, marginBottom: 15 },
    passInfo: { alignItems: 'center' },
    passLabel: { fontSize: 12, color: '#94a3b8', fontWeight: '800', letterSpacing: 1.5 },
    passCode: { fontSize: 24, fontWeight: '900', color: '#1e293b', marginTop: 5 },

    actionsContainer: { flexDirection: 'column', padding: 15, gap: 10 },
    modalActionBtn: { flex: 1, paddingVertical: 15, borderRadius: 15, alignItems: 'center', justifyContent: 'center', elevation: 3, shadowColor: '#000', shadowOffset: { width: 0, height: 2 }, shadowOpacity: 0.2, shadowRadius: 3 },
    approveBtn: { backgroundColor: '#10b981' },
    rejectBtn: { backgroundColor: '#ef4444' },
    checkInBtn: { backgroundColor: '#3b82f6' },
    checkOutBtn: { backgroundColor: '#1e293b' },
    modalActionText: { color: '#fff', fontWeight: '800', fontSize: 14, textTransform: 'uppercase' },
});

export default VisitDetailModal;
