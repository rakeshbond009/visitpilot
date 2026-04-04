import React, { useState, useCallback, useEffect, useRef } from 'react';
import {
    StyleSheet,
    View,
    Text,
    ScrollView,
    TouchableOpacity,
    RefreshControl,
    StatusBar,
    SafeAreaView,
    Dimensions,
    Modal,
    Pressable,
    ActivityIndicator,
    Alert,
    Image,
    TextInput,
    Linking,
} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { useFocusEffect } from '@react-navigation/native';
import { MaterialCommunityIcons as Icon } from '@expo/vector-icons';
import apiClient from '../utils/apiClient';
import { CONFIG } from '../utils/config';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { APP_VERSION } from '../constants';
import { usePermissions } from '../context/PermissionContext';
import VisitDetailModal from '../components/VisitDetailModal';
import VisitListModal from '../components/VisitListModal';

import { checkOverlayPermission } from '../utils/notificationManager';

const { width, height } = Dimensions.get('window');

export default function SecurityDashboard({ navigation, route }) {
    const { hasPermission, permissions, refreshPermissions } = usePermissions();
    const [userData, setUserData] = useState(null);
    const [stats, setStats] = useState({
        total_today: 0,
        active: 0,
        pending: 0,
        checkin_pending: 0,
        time_saved_fmt: '0 mins'
    });
    const [aiMetrics, setAiMetrics] = useState({
        crowd_density: 0,
        avg_checkin_time: '0s',
        overstays_count: 0,
        max_capacity: 50,
        active_count: 0,
        peak_time: 'Analyzing...',
        best_time: 'Analyzing...',
        zones: { department: [], access_area: [] },
        traffic: []
    });
    const [visits, setVisits] = useState([]);
    const [refreshing, setRefreshing] = useState(false);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [scheduledVisits, setScheduledVisits] = useState([]);
    const [isMenuOpen, setIsMenuOpen] = useState(false);
    const [activeTab, setActiveTab] = useState('home'); // 'home', 'log'
    const [logView, setLogView] = useState('log'); // 'log', 'invites'

    useEffect(() => {
        if (hasPermission('security_search')) {
            setLogView('log');
        } else if (hasPermission('host_invite')) {
            setLogView('invites');
        } else if (hasPermission('host_pending')) {
            setLogView('pending');
        }
    }, [permissions]);

    const [densityView, setDensityView] = useState('department'); // 'department', 'access_area'

    // Modal & Details State
    const [modalVisible, setModalVisible] = useState(false);
    const [modalTitle, setModalTitle] = useState('');
    const [modalType, setModalType] = useState(null); // 'visits', 'overstays', 'efficiency'
    const [modalFilter, setModalFilter] = useState(null);
    const [modalSearchTerm, setModalSearchTerm] = useState('');
    const [mastersModalVisible, setMastersModalVisible] = useState(false);
    const [settingsModalVisible, setSettingsModalVisible] = useState(false);
    const [selectedVisit, setSelectedVisit] = useState(null);
    const [detailsVisible, setDetailsVisible] = useState(false);
    const [scanModalVisible, setScanModalVisible] = useState(false);
    const [scanned, setScanned] = useState(false);
    const [permission, requestPermission] = useCameraPermissions();
    const [records, setRecords] = useState({ visits: [], overstays: [] });

    // SweetAlert Modal State
    const [alertVisible, setAlertVisible] = useState(false);
    const [alertConfig, setAlertConfig] = useState({ title: '', message: '', type: 'success' }); // 'success', 'error'
    const [rejectionReason, setRejectionReason] = useState('');

    // Date Filter State
    const [filterType, setFilterType] = useState('all');
    const [filterStartDate, setFilterStartDate] = useState('');
    const [filterEndDate, setFilterEndDate] = useState('');
    const [filterModalVisible, setFilterModalVisible] = useState(false);
    const [showCustomPicker, setShowCustomPicker] = useState(false);
    const [tempStartDate, setTempStartDate] = useState('');
    const [tempEndDate, setTempEndDate] = useState('');
    const [dateState, setDateState] = useState({ showStart: false, showEnd: false, calMonth: new Date().getMonth(), calYear: new Date().getFullYear(), calTarget: 'start' });

    const applyDateFilter = (items) => {
        if (!items) return [];
        if (filterType === 'all') return items;
        const now = new Date();
        let start = new Date(); start.setHours(0, 0, 0, 0);
        let end = new Date(); end.setHours(23, 59, 59, 999);
        if (filterType === 'today') { /* already set */ }
        else if (filterType === 'yesterday') { start.setDate(start.getDate() - 1); end.setDate(end.getDate() - 1); }
        else if (filterType === 'week') { start.setDate(start.getDate() - 7); }
        else if (filterType === 'custom') {
            if (!filterStartDate) return items;
            start = new Date(filterStartDate); start.setHours(0, 0, 0, 0);
            end = filterEndDate ? new Date(filterEndDate) : new Date(filterStartDate); end.setHours(23, 59, 59, 999);
        }
        return items.filter(item => {
            let dateStr = item.created_at || item.visit_date || item.check_in_time;
            if (!dateStr) return false;
            if (typeof dateStr === 'string' && dateStr.includes(' ')) dateStr = dateStr.replace(' ', 'T');
            const itemDate = new Date(dateStr);
            if (isNaN(itemDate.getTime())) return false;
            return itemDate >= start && itemDate <= end;
        });
    };

    const toggleMenu = () => setIsMenuOpen(!isMenuOpen);

    useEffect(() => {
        if (userData?.id) {
            // Wait a bit to ensure UI is ready
            setTimeout(() => checkOverlayPermission(userData.id), 2000);
        }
    }, [userData?.id]);

    const fetchVisitDetails = async (visitId) => {
        try {
            setLoading(true);
            const response = await apiClient.get('api/visit/details.php', {
                params: { id: visitId }
            });
            if (response.data.status === 'success') {
                setSelectedVisit(response.data.data);
                setDetailsVisible(true);
            } else {
                showAlert('Error', response.data.message || 'Could not load visit details', 'error');
            }
        } catch (err) {
            console.error('Visit Details Error:', err);
            showAlert('Error', 'Connection error while loading details', 'error');
        } finally {
            setLoading(false);
        }
    };

    const showModal = (title, type, filter = null) => {
        setModalSearchTerm('');
        setModalTitle(title);
        setModalType(type);
        setModalFilter(filter);
        setModalVisible(true);
    };

    const handleRecordClick = (item, type) => {
        if (type === 'visits' || type === 'overstays') {
            fetchVisitDetails(item.id);
        }
    };

    const formatDate = (dateStr) => {
        if (!dateStr) return 'N/A';
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        const day = String(d.getDate()).padStart(2, '0');
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const year = String(d.getFullYear()).slice(-2);
        return `${day}-${month}-${year}`;
    };

    const getPhotoUrl = (url) => {
        if (!url) return null;
        if (url.startsWith('http')) return url;
        let cleanUrl = url.startsWith('/') ? url.substring(1) : url;
        return `${CONFIG.API_BASE_URL}${cleanUrl}`;
    };

    const prevVisitsRef = useRef([]);

    const checkForUpdates = (newVisits) => {
        const prevVisits = prevVisitsRef.current;

        if (prevVisits.length === 0) {
            prevVisitsRef.current = newVisits;
            return;
        }

        newVisits.forEach(newVisit => {
            const oldVisit = prevVisits.find(v => v.id === newVisit.id);
            if (oldVisit) {
                if (oldVisit.approval_status === 'pending' && newVisit.approval_status !== 'pending') {
                    showAlert(
                        'Visit Status Update',
                        `Visit for ${newVisit.visitor_name} has been ${newVisit.approval_status.toUpperCase()}.`,
                        'success'
                    );
                }
            }
        });
        prevVisitsRef.current = newVisits;
    };

    const fetchData = async () => {
        try {
            const storedUser = await AsyncStorage.getItem('userData');
            if (storedUser) {
                const user = JSON.parse(storedUser);
                setUserData(user);

                const response = await apiClient.get('security/api/get_dashboard_data.php');

                const data = response.data;

                if (data.success) {
                    setStats(data.stats || {});
                    setAiMetrics(data.ai_metrics || {});
                    const newVisits = data.visits || [];
                    setVisits(newVisits);
                    setScheduledVisits(data.scheduled_list || []);
                    checkForUpdates(newVisits);

                    // Update records for modals
                    setRecords({
                        visits: newVisits,
                        overstays: data.ai_metrics?.overstays_list || []
                    });
                    setError(null);
                } else {
                    setError(data.error || 'Failed to load dashboard');
                }
            }
        } catch (err) {
            console.error('Fetch Error:', err);
            setError('Connection Error');
        } finally {
            setLoading(false);
            setRefreshing(false);
        }
    };

    useFocusEffect(
        useCallback(() => {
            fetchData();
            const interval = setInterval(fetchData, 15000);
            return () => clearInterval(interval);
        }, [])
    );

    // Handle Open Visit from Notification
    useEffect(() => {
        const visitId = route?.params?.openVisitId;
        if (visitId) {
            console.log("[Security] Opening visit from route param:", visitId);
            fetchVisitDetails(visitId);
            // Clear the param so it doesn't re-open on refresh
            navigation.setParams({ openVisitId: null });
        }
    }, [route?.params?.openVisitId]);

    const onRefresh = async () => {
        setRefreshing(true);
        await refreshPermissions(); // Refresh permissions from server
        fetchData();
    };

    const getStatusColor = (status) => {
        switch (status) {
            case 'approved': return '#0d6efd';
            case 'checked_in': return '#198754';
            case 'rejected': return '#dc3545';
            case 'checked_out': return '#6c757d';
            case 'pending': return '#f59e0b';
            default: return '#6c757d';
        }
    };

    const renderMetricCards = () => (
        <View>
            <View style={styles.metricsGrid}>
                <TouchableOpacity
                    style={[styles.metricCard, { backgroundColor: '#3b82f6' }]}
                    onPress={() => showModal('Total Today', 'visits')}
                >
                    <Text style={styles.metricValue}>{String(stats?.total_today ?? '0')}</Text>
                    <Text style={styles.metricLabel}>Total Today</Text>
                </TouchableOpacity>
                <TouchableOpacity
                    style={[styles.metricCard, { backgroundColor: '#10b981' }]}
                    onPress={() => showModal('Currently Inside', 'visits', 'checked_in')}
                >
                    <Text style={styles.metricValue}>{String(stats?.active ?? '0')}</Text>
                    <Text style={styles.metricLabel}>Currently Inside</Text>
                </TouchableOpacity>
            </View>
            <View style={styles.metricsGrid}>
                <TouchableOpacity
                    style={[styles.metricCard, { backgroundColor: '#f59e0b' }]}
                    onPress={() => showModal('Approval Pending', 'visits', 'pending')}
                >
                    <Text style={styles.metricValue}>{String(stats?.pending ?? '0')}</Text>
                    <Text style={styles.metricLabel}>Approval Pending</Text>
                </TouchableOpacity>
                <TouchableOpacity
                    style={[styles.metricCard, { backgroundColor: '#8b5cf6' }]}
                    onPress={() => showModal('Check-in Pending', 'visits', 'approved')}
                >
                    <Text style={styles.metricValue}>{String(stats?.checkin_pending ?? '0')}</Text>
                    <Text style={styles.metricLabel}>Check-in Pending</Text>
                </TouchableOpacity>
            </View>
        </View>
    );

    const renderAiMonitor = () => {
        if (!hasPermission('admin_reports') && !hasPermission('security_reports')) return null;

        const density = aiMetrics.crowd_density || aiMetrics.density || 0;
        const densityColor = density > 80 ? '#ef4444' : (density > 50 ? '#f59e0b' : '#10b981');
        const densityText = density > 80 ? 'Critical Surge' : (density > 50 ? 'Moderate Traffic' : 'Optimal');
        const hasOverstays = aiMetrics.overstays_count > 0;

        return (
            <View style={styles.aiMonitorCard}>
                <View style={styles.aiHeader}>
                    <Text style={styles.aiTitle}>AI SECURITY MONITOR</Text>
                    <View style={styles.liveBadge}><Text style={styles.liveBadgeText}>LIVE</Text></View>
                </View>

                <Text style={styles.aiMonitorLabel}>Crowd Density Prediction</Text>
                <View style={styles.progressBarBg}>
                    <View style={[styles.progressBarFill, { width: `${density}%`, backgroundColor: densityColor }]} />
                </View>
                <Text style={[styles.densityStatus, { color: densityColor }]}>
                    <Icon name="chart-line" size={14} /> {densityText} ({aiMetrics.active_count || 0}/{aiMetrics.max_capacity || 0})
                </Text>

                <View style={styles.aiMetricsRow}>
                    <TouchableOpacity
                        style={styles.aiMetricItem}
                        onPress={() => showModal('Anomaly Alerts', 'overstays')}
                    >
                        <Icon name={hasOverstays ? "shield-alert" : "shield-check"} size={28} color={hasOverstays ? "#ef4444" : "#10b981"} />
                        <View style={styles.aiMetricInfo}>
                            <Text style={styles.aiMetricValueText}>{hasOverstays ? "Anomaly Alert" : "Perimeter Secure"}</Text>
                            <Text style={styles.aiMetricSubtext}>{hasOverstays ? `${aiMetrics.overstays_count} visitor(s) overstaying` : "No anomalies detected"}</Text>
                        </View>
                    </TouchableOpacity>
                </View>

                <View style={styles.aiMetricsRow}>
                    <TouchableOpacity style={styles.aiMetricItem} onPress={() => showModal('Efficiency Metrics', 'efficiency')}>
                        <Icon name="timer-outline" size={28} color="#3b82f6" />
                        <View style={styles.aiMetricInfo}>
                            <Text style={styles.aiMetricValueText}>Avg. Check-in Time</Text>
                            <Text style={styles.aiMetricSubtext}>~{aiMetrics.avg_checkin_time} (Optimal)</Text>
                        </View>
                    </TouchableOpacity>
                </View>
            </View>
        );
    };

    const renderVisitorLog = () => {
        if (!hasPermission('security_search')) return null;

        return (
            <View style={styles.section}>
                <View style={styles.sectionHeader}>
                    <Text style={styles.sectionTitle}>Live Visitor Log</Text>
                    <TouchableOpacity onPress={() => setActiveTab('log')}><Text style={styles.seeAllText}>View All</Text></TouchableOpacity>
                </View>
                {visits.length === 0 ? (
                    <View style={styles.emptyLog}>
                        <Text style={styles.emptyLogText}>No visitors recorded today</Text>
                    </View>
                ) : (
                    visits.slice(0, 5).map(visit => (
                        <TouchableOpacity key={visit.id} style={styles.visitRow} onPress={() => fetchVisitDetails(visit.id)}>
                            <Image
                                source={visit.visit_photo || visit.photo_path ? { uri: `${CONFIG.API_BASE_URL}${visit.visit_photo || visit.photo_path}` } : { uri: 'https://ui-avatars.com/api/?name=' + encodeURIComponent(visit.visitor_name) + '&background=random' }}
                                style={styles.visitorThumb}
                            />
                            <View style={styles.visitInfo}>
                                <Text style={styles.visitorNameText}>{visit.visitor_name}</Text>
                                <Text style={styles.visitDetailsText}>{visit.visit_code} • {visit.host_name}</Text>
                            </View>
                            <View style={[styles.statusTag, { backgroundColor: getStatusColor(visit.status) }]}>
                                <Text style={styles.statusTagText}>{visit.status.replace('_', ' ').toUpperCase()}</Text>
                            </View>
                        </TouchableOpacity>
                    ))
                )}
            </View>
        );
    };

    const renderTrafficChart = () => {
        if (!hasPermission('admin_reports') && !hasPermission('security_reports')) return null;

        const trafficData = aiMetrics.traffic || aiMetrics.hourly_traffic || [];
        if (trafficData.length === 0) {
            return (
                <View style={styles.section}>
                    <Text style={styles.sectionTitle}>Hourly Entry Traffic</Text>
                    <View style={styles.card}>
                        <Text style={{ color: '#94a3b8', textAlign: 'center', padding: 20 }}>No traffic data available for today</Text>
                    </View>
                </View>
            );
        }

        const maxCount = Math.max(...trafficData.map(t => t.count), 1);

        return (
            <View style={styles.section}>
                <Text style={styles.sectionTitle}>Hourly Entry Traffic</Text>
                <View style={styles.card}>
                    <View style={styles.chartContainer}>
                        <ScrollView horizontal showsHorizontalScrollIndicator={false}>
                            <View style={styles.barsRow}>
                                {trafficData.map((item, index) => (
                                    <View key={index} style={styles.barColumn}>
                                        <View style={styles.barWrapper}>
                                            <View style={[styles.bar, { height: `${(item.count / maxCount) * 100}%`, minHeight: item.count > 0 ? 5 : 0 }]} />
                                            {item.count > 0 && <Text style={styles.barValue}>{item.count}</Text>}
                                        </View>
                                        <Text style={styles.barLabel}>{item.label}</Text>
                                    </View>
                                ))}
                            </View>
                        </ScrollView>
                    </View>
                </View>
            </View>
        );
    };

    const renderZoneDensity = () => {
        if (!hasPermission('admin_reports') && !hasPermission('security_reports')) return null;

        if (!aiMetrics || !aiMetrics.zones) return null;
        const zones = (densityView === 'department' ? aiMetrics.zones.department : aiMetrics.zones.access_area) || [];

        return (
            <View style={styles.section}>
                <View style={styles.sectionHeader}>
                    <View>
                        <Text style={styles.sectionTitle}>Current Zone Density</Text>
                        <Text style={{ fontSize: 10, color: '#94a3b8', marginTop: 2 }}>Active Inside Now</Text>
                    </View>
                    <View style={styles.viewToggle}>
                        <TouchableOpacity
                            style={[styles.toggleBtn, densityView === 'department' && { backgroundColor: '#3b82f6' }]}
                            onPress={() => setDensityView('department')}
                        >
                            <Text style={[styles.toggleText, densityView === 'department' && { color: '#fff' }]}>Department</Text>
                        </TouchableOpacity>
                        <TouchableOpacity
                            style={[styles.toggleBtn, densityView === 'access_area' && { backgroundColor: '#3b82f6' }]}
                            onPress={() => setDensityView('access_area')}
                        >
                            <Text style={[styles.toggleText, densityView === 'access_area' && { color: '#fff' }]}>Zone Area</Text>
                        </TouchableOpacity>
                    </View>
                </View>
                <View style={styles.card}>
                    <View style={{ gap: 12 }}>
                        {zones.length === 0 ? (
                            <Text style={{ color: '#94a3b8', fontStyle: 'italic', textAlign: 'center', marginVertical: 10 }}>No active visitors in any zone</Text>
                        ) : (
                            zones.map((item, index) => {
                                const densityMax = aiMetrics.max_capacity || 50;
                                const pct = Math.min(100, (item.count / densityMax) * 100);
                                const color = pct > 80 ? '#ef4444' : (pct > 40 ? '#f59e0b' : '#10b981');
                                const status = pct > 80 ? 'High Congestion' : (pct > 40 ? 'Moderate Traffic' : 'Low Activity');

                                return (
                                    <View key={index} style={styles.zoneRow}>
                                        <View style={{ flex: 1 }}>
                                            <Text style={styles.zoneNameText}>{item.name}</Text>
                                            <Text style={[styles.zoneStatusText, { color }]}>{status}</Text>
                                        </View>
                                        <View style={styles.zoneProgressContainer}>
                                            <View style={styles.zoneCountBadge}>
                                                <Text style={styles.zoneCountTextSmall}>{item.count}</Text>
                                            </View>
                                            <View style={styles.miniProgressBar}>
                                                <View style={[styles.miniProgressFill, { width: `${pct}%`, backgroundColor: color }]} />
                                            </View>
                                        </View>
                                    </View>
                                );
                            })
                        )}
                    </View>

                    <View style={styles.bestPeakRow}>
                        <View style={styles.bestPeakItem}>
                            <Text style={styles.bestPeakLabel}>BEST SLOT</Text>
                            <View style={styles.bestPeakValueRow}>
                                <Icon name="clock-outline" size={14} color="#3b82f6" />
                                <Text style={styles.bestPeakValue}>{aiMetrics?.best_time || aiMetrics?.best_slot || '--:--'}</Text>
                            </View>
                            <Text style={styles.bestPeakSub}>Min. Waiting</Text>
                        </View>
                        <View style={styles.bestPeakItemRed}>
                            <Text style={styles.bestPeakLabelRed}>PEAK TIME</Text>
                            <View style={styles.bestPeakValueRow}>
                                <Icon name="trending-up" size={14} color="#ef4444" />
                                <Text style={styles.bestPeakValue}>{aiMetrics?.peak_time || aiMetrics?.peak_hour || '--:--'}</Text>
                            </View>
                            <Text style={styles.bestPeakSub}>Busy Hours</Text>
                        </View>
                    </View>
                </View>
            </View>
        );
    };

    const renderHome = () => (
        <View style={styles.scrollPadding}>
            {renderMetricCards()}

            {(hasPermission('admin_reports') || hasPermission('security_reports')) && (
                <View style={styles.statsSummary}>
                    <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
                        <View style={[styles.summaryItem, { flex: 1 }]}>
                            <View style={styles.iconCircle}>
                                <Icon name="flash" size={24} color="#3b82f6" />
                            </View>
                            <View style={{ flex: 1 }}>
                                <Text style={styles.summaryLabel}>Operational Efficiency</Text>
                                <Text style={styles.summaryDescription}>Total time saved by digitalizing the reception process.</Text>
                            </View>
                        </View>
                        <View style={styles.textRight}>
                            <Text style={styles.summaryValueLarge}>{stats?.time_saved_fmt || stats?.efficiency || '0 mins'}</Text>
                            <Text style={styles.timeSavedLabel}>Time Saved</Text>
                        </View>
                    </View>
                </View>
            )}

            {renderTrafficChart()}

            {renderAiMonitor()}

            {renderZoneDensity()}

            {renderVisitorLog()}
        </View>
    );

    const renderLogTab = () => (
        <View style={styles.scrollPadding}>
            {/* Segmented Control */}
            <View style={styles.segmentContainer}>
                {hasPermission('security_search') && (
                    <TouchableOpacity
                        style={[styles.segmentBtn, logView === 'log' && styles.segmentBtnActive]}
                        onPress={() => setLogView('log')}
                    >
                        <Text style={[styles.segmentText, logView === 'log' && styles.segmentTextActive]}>Visitor Log</Text>
                    </TouchableOpacity>
                )}

                {hasPermission('host_invite') && (
                    <TouchableOpacity
                        style={[styles.segmentBtn, logView === 'invites' && styles.segmentBtnActive]}
                        onPress={() => setLogView('invites')}
                    >
                        <Text style={[styles.segmentText, logView === 'invites' && styles.segmentTextActive]}>Invitations</Text>
                    </TouchableOpacity>
                )}

                {hasPermission('host_pending') && (
                    <TouchableOpacity
                        style={[styles.segmentBtn, logView === 'pending' && styles.segmentBtnActive]}
                        onPress={() => setLogView('pending')}
                    >
                        <Text style={[styles.segmentText, logView === 'pending' && styles.segmentTextActive]}>
                            Pending ({visits.filter(v => v.approval_status === 'pending' || v.status === 'pending').length})
                        </Text>
                    </TouchableOpacity>
                )}
            </View>

            {/* Date Filter Bar */}
            <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 15 }}>
                <Text style={{ fontSize: 13, color: '#64748b', fontWeight: '600' }}>
                    {filterType === 'all' ? 'All Records' : filterType === 'today' ? 'Today' : filterType === 'yesterday' ? 'Yesterday' : filterType === 'week' ? 'Last 7 Days' : 'Custom Range'}
                </Text>
                <TouchableOpacity
                    style={{ flexDirection: 'row', alignItems: 'center', backgroundColor: '#fff', paddingHorizontal: 12, paddingVertical: 6, borderRadius: 20, borderWidth: 1, borderColor: '#e2e8f0' }}
                    onPress={() => setFilterModalVisible(true)}
                >
                    <Icon name="filter-variant" size={16} color="#3b82f6" style={{ marginRight: 4 }} />
                    <Text style={{ fontSize: 12, fontWeight: '600', color: '#3b82f6' }}>Filter Date</Text>
                </TouchableOpacity>
            </View>

            {logView === 'log' && hasPermission('security_search') && (
                <>
                    <View style={styles.sectionHeader}>
                        <Text style={styles.sectionTitle}>Full Visitor Log</Text>
                    </View>
                    {applyDateFilter(visits).length === 0 ? (
                        <View style={styles.emptyLog}>
                            <Text style={styles.emptyLogText}>No visitors found for the selected period</Text>
                        </View>
                    ) : (
                        applyDateFilter(visits).map(visit => (
                            <TouchableOpacity key={visit.id} style={styles.visitRowBig} onPress={() => fetchVisitDetails(visit.id)}>
                                <Image
                                    source={visit.visit_photo || visit.photo_path ? { uri: `${CONFIG.API_BASE_URL}${visit.visit_photo || visit.photo_path}` } : { uri: 'https://ui-avatars.com/api/?name=' + encodeURIComponent(visit.visitor_name) + '&background=random' }}
                                    style={styles.visitorThumbBig}
                                />
                                <View style={styles.visitInfo}>
                                    <Text style={styles.visitorNameTextBig}>{visit.visitor_name}</Text>
                                    <Text style={styles.visitDetailsText}>Code: {visit.visit_code}</Text>
                                    <Text style={styles.visitDetailsText}>Host: {visit.host_name} ({visit.department})</Text>
                                    <View style={styles.timeTagRow}>
                                        <Text style={styles.timeTagText}>In: {visit.check_in_time ? new Date(visit.check_in_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '-'}</Text>
                                        <Text style={styles.timeTagText}>Out: {visit.check_out_time ? new Date(visit.check_out_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '-'}</Text>
                                    </View>
                                </View>
                                <View style={[styles.statusTag, { backgroundColor: getStatusColor(visit.status) }]}>
                                    <Text style={styles.statusTagText}>{visit.status.replace('_', ' ').toUpperCase()}</Text>
                                </View>
                            </TouchableOpacity>
                        ))
                    )}
                </>
            )}

            {logView === 'invites' && hasPermission('host_invite') && (
                <View>
                    <View style={styles.sectionHeader}>
                        <Text style={styles.sectionTitle}>Create Invitation</Text>
                    </View>

                    <View style={styles.card}>
                        <View style={{ alignItems: 'center', padding: 20 }}>
                            <View style={{ width: 60, height: 60, borderRadius: 30, backgroundColor: '#f5f3ff', justifyContent: 'center', alignItems: 'center', marginBottom: 15 }}>
                                <Icon name="calendar-plus" size={30} color="#8b5cf6" />
                            </View>
                            <Text style={{ fontSize: 16, fontWeight: '700', color: '#1e293b', marginBottom: 8 }}>Invite a Visitor</Text>
                            <Text style={{ fontSize: 13, color: '#64748b', textAlign: 'center', marginBottom: 20 }}>
                                Schedule a visit in advance to speed up the check-in process.
                            </Text>
                            <TouchableOpacity
                                style={{ backgroundColor: '#8b5cf6', paddingHorizontal: 20, paddingVertical: 12, borderRadius: 12, flexDirection: 'row', alignItems: 'center' }}
                                onPress={() => navigation.navigate('InviteVisitor')}
                            >
                                <Icon name="plus" size={20} color="#fff" style={{ marginRight: 8 }} />
                                <Text style={{ color: '#fff', fontWeight: '700' }}>Create New Invite</Text>
                            </TouchableOpacity>
                        </View>
                    </View>

                    <Text style={[styles.sectionTitle, { marginTop: 30 }]}>Today's Invitations</Text>
                    {applyDateFilter(visits.filter(v => v.is_invited == 1)).length === 0 ? (
                        <View style={styles.card}>
                            <Text style={{ color: '#94a3b8', textAlign: 'center', padding: 20, fontStyle: 'italic' }}>
                                No invitations found for the selected period.
                            </Text>
                        </View>
                    ) : (
                        applyDateFilter(visits.filter(v => v.is_invited == 1)).map(visit => (
                            <TouchableOpacity key={visit.id} style={styles.visitRowBig} onPress={() => fetchVisitDetails(visit.id)}>
                                <Image
                                    source={visit.visit_photo || visit.photo_path ? { uri: `${CONFIG.API_BASE_URL}${visit.visit_photo || visit.photo_path}` } : { uri: 'https://ui-avatars.com/api/?name=' + encodeURIComponent(visit.visitor_name) + '&background=random' }}
                                    style={styles.visitorThumbBig}
                                />
                                <View style={styles.visitInfo}>
                                    <Text style={styles.visitorNameTextBig}>{visit.visitor_name}</Text>
                                    <Text style={styles.visitDetailsText}>Code: {visit.visit_code}</Text>
                                    <Text style={styles.visitDetailsText}>Host: {visit.host_name}</Text>
                                    <View style={styles.timeTagRow}>
                                        <Text style={styles.timeTagText}>Scheduled: {visit.visit_date}</Text>
                                    </View>
                                </View>
                                <View style={[styles.statusTag, { backgroundColor: getStatusColor(visit.status) }]}>
                                    <Text style={styles.statusTagText}>{visit.status.replace('_', ' ').toUpperCase()}</Text>
                                </View>
                            </TouchableOpacity>
                        ))
                    )}
                </View>
            )}

            {logView === 'pending' && hasPermission('host_pending') && (
                <View>
                    <Text style={styles.sectionTitle}>Pending Approvals</Text>
                    {applyDateFilter(visits.filter(v => v.approval_status?.toLowerCase() === 'pending' || v.status?.toLowerCase() === 'pending')).length === 0 ? (
                        <View style={styles.emptyLog}>
                            <Text style={styles.emptyLogText}>No pending approvals</Text>
                        </View>
                    ) : (
                        applyDateFilter(visits.filter(v => v.approval_status?.toLowerCase() === 'pending' || v.status?.toLowerCase() === 'pending')).map(visit => (
                            <TouchableOpacity key={visit.id} style={styles.visitRowBig} onPress={() => fetchVisitDetails(visit.id)}>
                                <Image
                                    source={visit.visit_photo || visit.photo_path ? { uri: `${CONFIG.API_BASE_URL}${visit.visit_photo || visit.photo_path}` } : { uri: 'https://ui-avatars.com/api/?name=' + encodeURIComponent(visit.visitor_name) + '&background=random' }}
                                    style={styles.visitorThumbBig}
                                />
                                <View style={styles.visitInfo}>
                                    <Text style={styles.visitorNameTextBig}>{visit.visitor_name}</Text>
                                    <Text style={styles.visitDetailsText}>Code: {visit.visit_code}</Text>
                                    <Text style={styles.visitDetailsText}>Host: {visit.host_name}</Text>
                                    <View style={styles.timeTagRow}>
                                        <Text style={styles.timeTagText}>Req: {new Date(visit.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</Text>
                                    </View>
                                </View>
                                <View style={[styles.statusTag, { backgroundColor: getStatusColor('pending') }]}>
                                    <Text style={styles.statusTagText}>PENDING</Text>
                                </View>
                            </TouchableOpacity>
                        ))
                    )}
                </View>
            )}

            {/* Date Filter Modal */}
            <Modal animationType="slide" transparent visible={filterModalVisible} onRequestClose={() => setFilterModalVisible(false)}>
                <Pressable style={styles.modalOverlay} onPress={() => setFilterModalVisible(false)}>
                    <Pressable style={styles.filterModalContent} onPress={(e) => e.stopPropagation()}>
                        <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20, paddingHorizontal: 20 }}>
                            <Text style={{ fontSize: 18, fontWeight: 'bold', color: '#1e293b' }}>Filter by Date</Text>
                            <TouchableOpacity onPress={() => setFilterModalVisible(false)}>
                                <Icon name="close" size={24} color="#64748b" />
                            </TouchableOpacity>
                        </View>
                        <ScrollView style={{ paddingHorizontal: 20 }} contentContainerStyle={{ paddingBottom: 20 }}>
                            {[{ key: 'all', label: 'All Time' }, { key: 'today', label: 'Today' }, { key: 'yesterday', label: 'Yesterday' }, { key: 'week', label: 'Last 7 Days' }].map(opt => (
                                <TouchableOpacity key={opt.key} style={[styles.filterOption, filterType === opt.key && styles.filterOptionActive]} onPress={() => { setFilterType(opt.key); setFilterModalVisible(false); setShowCustomPicker(false); }}>
                                    <Text style={[styles.filterOptionText, filterType === opt.key && styles.filterOptionTextActive]}>{opt.label}</Text>
                                </TouchableOpacity>
                            ))}
                            <TouchableOpacity style={[styles.filterOption, filterType === 'custom' && styles.filterOptionActive]} onPress={() => setShowCustomPicker(!showCustomPicker)}>
                                <Text style={[styles.filterOptionText, filterType === 'custom' && styles.filterOptionTextActive]}>Custom Range</Text>
                                <Icon name={showCustomPicker ? 'chevron-up' : 'chevron-down'} size={20} color="#64748b" />
                            </TouchableOpacity>
                            {showCustomPicker && (
                                <View style={styles.customDateContainer}>
                                    <View style={styles.dateInputRow}>
                                        <View style={styles.dateInputWrapper}>
                                            <Text style={styles.dateLabel}>Start Date</Text>
                                            <TouchableOpacity style={styles.dateInputBtn} onPress={() => { const d = tempStartDate ? new Date(tempStartDate) : new Date(); setDateState({ showStart: true, showEnd: false, calMonth: d.getMonth(), calYear: d.getFullYear(), calTarget: 'start' }); }}>
                                                <Text style={styles.dateInputText}>{tempStartDate ? tempStartDate.split('-').reverse().join('-') : 'Select Date'}</Text>
                                                <Icon name="calendar" size={20} color="#64748b" />
                                            </TouchableOpacity>
                                        </View>
                                        <View style={styles.dateInputWrapper}>
                                            <Text style={styles.dateLabel}>End Date</Text>
                                            <TouchableOpacity style={styles.dateInputBtn} onPress={() => { const d = tempEndDate ? new Date(tempEndDate) : new Date(); setDateState({ showEnd: true, showStart: false, calMonth: d.getMonth(), calYear: d.getFullYear(), calTarget: 'end' }); }}>
                                                <Text style={styles.dateInputText}>{tempEndDate ? tempEndDate.split('-').reverse().join('-') : 'Select Date'}</Text>
                                                <Icon name="calendar" size={20} color="#64748b" />
                                            </TouchableOpacity>
                                        </View>
                                    </View>
                                    <TouchableOpacity style={styles.applyBtn} onPress={() => { if (!tempStartDate) { showAlert('Error', 'Please select a start date', 'error'); return; } setFilterStartDate(tempStartDate); setFilterEndDate(tempEndDate || tempStartDate); setFilterType('custom'); setFilterModalVisible(false); }}>
                                        <Text style={styles.applyBtnText}>Apply Filter</Text>
                                    </TouchableOpacity>
                                </View>
                            )}
                            {(dateState.showStart || dateState.showEnd) && (
                                <Modal transparent animationType="fade" visible={true} onRequestClose={() => setDateState({ ...dateState, showStart: false, showEnd: false })}>
                                    <Pressable style={styles.calOverlay} onPress={() => setDateState({ ...dateState, showStart: false, showEnd: false })}>
                                        <Pressable style={styles.calContainer} onPress={(e) => e.stopPropagation()}>
                                            <Text style={styles.calTitle}>{dateState.calTarget === 'start' ? 'Select Start Date' : 'Select End Date'}</Text>
                                            <View style={styles.calNav}>
                                                <TouchableOpacity onPress={() => { let m = dateState.calMonth - 1, y = dateState.calYear; if (m < 0) { m = 11; y--; } setDateState({ ...dateState, calMonth: m, calYear: y }); }}>
                                                    <Icon name="chevron-left" size={28} color="#3b82f6" />
                                                </TouchableOpacity>
                                                <Text style={styles.calMonthLabel}>{['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'][dateState.calMonth]} {dateState.calYear}</Text>
                                                <TouchableOpacity onPress={() => { let m = dateState.calMonth + 1, y = dateState.calYear; if (m > 11) { m = 0; y++; } setDateState({ ...dateState, calMonth: m, calYear: y }); }}>
                                                    <Icon name="chevron-right" size={28} color="#3b82f6" />
                                                </TouchableOpacity>
                                            </View>
                                            <View style={styles.calDaysHeader}>
                                                {['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'].map(d => (<Text key={d} style={styles.calDayLabel}>{d}</Text>))}
                                            </View>
                                            <View style={styles.calGrid}>
                                                {(() => { const firstDay = new Date(dateState.calYear, dateState.calMonth, 1).getDay(); const daysInMonth = new Date(dateState.calYear, dateState.calMonth + 1, 0).getDate(); const cells = []; for (let i = 0; i < firstDay; i++) cells.push(<View key={`e${i}`} style={styles.calCell} />); const todayStr = new Date().toISOString().split('T')[0]; const selectedStr = dateState.calTarget === 'start' ? tempStartDate : tempEndDate; for (let day = 1; day <= daysInMonth; day++) { const dateStr = `${dateState.calYear}-${String(dateState.calMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`; const isSelected = dateStr === selectedStr; const isToday = dateStr === todayStr; cells.push(<TouchableOpacity key={day} style={[styles.calCell, isSelected && styles.calCellSelected, isToday && !isSelected && styles.calCellToday]} onPress={() => { if (dateState.calTarget === 'start') setTempStartDate(dateStr); else setTempEndDate(dateStr); setDateState({ ...dateState, showStart: false, showEnd: false }); }}><Text style={[styles.calCellText, isSelected && styles.calCellTextSelected, isToday && !isSelected && styles.calCellTextToday]}>{day}</Text></TouchableOpacity>); } return cells; })()}
                                            </View>
                                            <TouchableOpacity style={styles.calCancelBtn} onPress={() => setDateState({ ...dateState, showStart: false, showEnd: false })}>
                                                <Text style={styles.calCancelText}>Cancel</Text>
                                            </TouchableOpacity>
                                        </Pressable>
                                    </Pressable>
                                </Modal>
                            )}
                        </ScrollView>
                    </Pressable>
                </Pressable>
            </Modal>
        </View>
    );

    const renderModalSearch = () => {
        if (!hasPermission('security_search')) return null;

        return (
            <View style={styles.modalSearchContainer}>
                <Icon name="magnify" size={20} color="#64748b" style={styles.modalSearchIcon} />
                <TextInput
                    style={styles.modalSearchInput}
                    placeholder="Search..."
                    value={modalSearchTerm}
                    onChangeText={setModalSearchTerm}
                    autoCapitalize="none"
                />
                {modalSearchTerm ? (
                    <TouchableOpacity onPress={() => setModalSearchTerm('')}>
                        <Icon name="close-circle" size={20} color="#94a3b8" />
                    </TouchableOpacity>
                ) : null}
            </View>
        );
    };

    const handleBarCodeScanned = async ({ type, data }) => {
        if (scanned) return;
        setScanned(true);

        try {
            console.log("Scanned QR Code:", data);

            // Call API
            const response = await apiClient.post('api/visit/status_action.php', {
                action: 'qr_process',
                code: data
            });

            console.log("API Response:", response.data);

            if (response.data.status === 'success') {
                // Action was already performed (fallback if API logic changes)
                setScanModalVisible(false);
                setSelectedVisit(response.data.data);
                setDetailsVisible(true);
                setScanned(false);
                fetchData();
            } else if (response.data.status === 'check_in') {
                // Ask for confirmation before Check-in
                setScanModalVisible(false);
                setScanned(false);
                handleAction(response.data.data.id, 'checkin');
            } else if (response.data.status === 'check_out') {
                // Ask for confirmation before Check-out
                setScanModalVisible(false);
                setScanned(false);
                handleAction(response.data.data.id, 'checkout');
            } else if (response.data.status === 'invitation') {
                const code = response.data.data?.code;
                const visitorName = response.data.data?.visitor_name || "Visitor";
                const hostName = response.data.data?.host_name || "N/A";
                const purposeText = response.data.data?.purpose || "N/A";

                setScanModalVisible(false);
                setScanned(false);

                if (hasPermission('security_register')) {
                    showAlert(
                        'Pre-Approved Invitation',
                        `Found invitation for: ${visitorName}\n\nHost: ${hostName}\nPurpose: ${purposeText}\n\nProceed to register this visitor?`,
                        'success',
                        {
                            showCancel: true,
                            confirmText: 'Register Now',
                            onConfirm: () => navigation.navigate('RegisterVisitor', { code: code })
                        }
                    );
                } else {
                    showAlert(
                        'Invitation Found',
                        `An invitation for ${visitorName} was found.\n\nPlease ask a senior security officer or admin to complete the registration at the gate.`,
                        'success'
                    );
                }
            } else {
                showAlert('QR Scan Result', response.data.message || 'The scanned QR code is currently inactive or has an invalid status.', 'error');
                setScanned(false);
            }
        } catch (error) {
            console.error("Scan Error:", error);
            showAlert('Network Error', 'Failed to process QR code. Please check your internet connection.', 'error');
            setScanned(false);
        }
    };

    const renderVisitRecords = () => {
        const filteredVisits = records.visits.filter(v => {
            const matchesSearch = !modalSearchTerm ||
                (v.visitor_name && v.visitor_name.toLowerCase().includes(modalSearchTerm.toLowerCase())) ||
                (v.host_name && v.host_name.toLowerCase().includes(modalSearchTerm.toLowerCase())) ||
                (v.visit_code && v.visit_code.includes(modalSearchTerm));

            // Fix count mismatch: Ensure filtering logic matches backend stats exactly
            let matchesFilter = true;

            // Helper: Compare dates using Local Device Time to avoid UTC timezone mismatches
            const isToday = (dateStr) => {
                if (!dateStr) return false;
                // Replace space with T for wider compatibility (e.g. iOS)
                const d = new Date(dateStr.indexOf('T') === -1 && dateStr.indexOf(' ') > -1 ? dateStr.replace(' ', 'T') : dateStr);
                const now = new Date();
                return d.getDate() === now.getDate() &&
                    d.getMonth() === now.getMonth() &&
                    d.getFullYear() === now.getFullYear();
            };

            if (modalTitle === 'Total Today') {
                // Backend: DATE(created_at) = CURDATE() OR (is_invited=1 AND visit_date = CURDATE())
                matchesFilter = isToday(v.created_at) || (v.is_invited == 1 && isToday(v.visit_date));
            } else if (modalFilter) {
                if (modalFilter === 'pending') {
                    // Backend: approval_status = 'pending'
                    matchesFilter = (v.approval_status?.toLowerCase() === 'pending' || v.status?.toLowerCase() === 'pending');
                } else if (modalTitle === 'Check-in Pending') {
                    // Backend: status = 'approved' AND (date(created_at) = CURDATE() OR (is_invited=1 AND visit_date = CURDATE()))
                    matchesFilter = (v.status === 'approved' && (isToday(v.created_at) || (v.is_invited == 1 && isToday(v.visit_date))));
                } else {
                    matchesFilter = (v.status === modalFilter);
                }
            }

            return matchesSearch && matchesFilter;
        });

        return (
            <View style={{ flex: 1 }}>
                {renderModalSearch()}
                <ScrollView style={styles.modalScroll}>
                    {filteredVisits.map((visit, idx) => (
                        <TouchableOpacity
                            key={idx}
                            style={styles.recordRow}
                            onPress={() => {
                                setModalVisible(false);
                                fetchVisitDetails(visit.id);
                            }}
                        >
                            <View style={[styles.recordStatusIndicator, { backgroundColor: visit.status === 'checked_in' ? '#10b981' : (visit.status === 'pending' ? '#f59e0b' : '#64748b') }]} />
                            <View style={styles.recordInfo}>
                                <Text style={styles.recordName}>{visit.visitor_name}</Text>
                                <View style={styles.recordMetaRow}>
                                    <Icon name="account-tie" size={14} color="#64748b" />
                                    <Text style={styles.recordSub}> {visit.host_name}</Text>
                                    <Text style={styles.recordDot}> • </Text>
                                    <Icon name="clock-outline" size={14} color="#64748b" />
                                    <Text style={styles.recordSub}> {formatDate(visit.created_at)}</Text>
                                </View>
                                {visit.purpose && (
                                    <View style={styles.recordMetaRow}>
                                        <Icon name="information-outline" size={14} color="#94a3b8" />
                                        <Text style={styles.recordPurpose} numberOfLines={1}> {visit.purpose}</Text>
                                    </View>
                                )}
                            </View>
                            <View style={styles.recordAction}>
                                <View style={[styles.miniStatusBadge, { backgroundColor: visit.status === 'checked_in' ? '#dcfce7' : (visit.status === 'pending' ? '#fef3c7' : '#f1f5f9') }]}>
                                    <Text style={[styles.miniStatusText, { color: visit.status === 'checked_in' ? '#166534' : (visit.status === 'pending' ? '#92400e' : '#475569') }]}>
                                        {visit.status?.replace('_', ' ').toUpperCase()}
                                    </Text>
                                </View>
                                <Icon name="chevron-right" size={20} color="#cbd5e1" />
                            </View>
                        </TouchableOpacity>
                    ))}
                    {filteredVisits.length === 0 && <Text style={styles.noDataText}>No records found matching your criteria.</Text>}
                </ScrollView>
            </View>
        );
    };

    const renderOverstayList = () => {
        const filteredOverstays = (records.overstays || []).filter(item => {
            return !modalSearchTerm ||
                item.visitor_name?.toLowerCase().includes(modalSearchTerm.toLowerCase()) ||
                item.host_name?.toLowerCase().includes(modalSearchTerm.toLowerCase());
        });

        const formatOverstay = (minutes) => {
            if (!minutes) return 'Overstay';
            if (minutes < 60) return `${minutes}m overstay`;
            const hours = Math.floor(minutes / 60);
            const mins = minutes % 60;
            return mins > 0 ? `${hours}h ${mins}m overstay` : `${hours}h overstay`;
        };

        return (
            <View style={{ flex: 1 }}>
                {renderModalSearch()}
                <ScrollView style={styles.modalScroll}>
                    {filteredOverstays.map((item, idx) => (
                        <TouchableOpacity
                            key={idx}
                            style={styles.detailRow}
                            onPress={() => {
                                setModalVisible(false);
                                fetchVisitDetails(item.id);
                            }}
                        >
                            <View style={styles.detailInfo}>
                                <Text style={styles.detailName}>{item.visitor_name}</Text>
                                <Text style={styles.detailSub}>Host: {item.host_name}</Text>
                                <View style={styles.overstayBadge}>
                                    <Icon name="clock-alert-outline" size={12} color="#ef4444" />
                                    <Text style={styles.overstayDurationText}>{formatOverstay(item.overstay_minutes)}</Text>
                                </View>
                            </View>
                            <View style={styles.detailActionCol}>
                                <Text style={styles.detailTime}>{new Date(item.check_in_time || item.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</Text>
                                <Text style={styles.detailDateText}>{formatDate(item.created_at)}</Text>
                            </View>
                        </TouchableOpacity>
                    ))}
                    {filteredOverstays.length === 0 && <Text style={styles.noDataText}>No overstay alerts found.</Text>}
                </ScrollView>
            </View>
        );
    };

    const renderEfficiencyModal = () => (
        <View style={styles.efficiencyList}>
            <View style={styles.efficiencyItemModal}>
                <View style={styles.effIconContainer}>
                    <Text style={styles.effIcon}>⏱️</Text>
                </View>
                <View style={styles.effDetails}>
                    <Text style={styles.effLabel}>Avg Check-in Time</Text>
                    <Text style={styles.effValue}>{aiMetrics.avg_checkin_time}</Text>
                </View>
            </View>
            <View style={styles.efficiencyItemModal}>
                <View style={styles.effIconContainer}>
                    <Text style={styles.effIcon}>📊</Text>
                </View>
                <View style={styles.effDetails}>
                    <Text style={styles.effLabel}>Peak Traffic Hour</Text>
                    <Text style={styles.effValue}>{aiMetrics.peak_time || aiMetrics.peak_hour || 'N/A'}</Text>
                </View>
            </View>
            <View style={styles.efficiencyItemModal}>
                <View style={styles.effIconContainer}>
                    <Text style={styles.effIcon}>♻️</Text>
                </View>
                <View style={styles.effDetails}>
                    <Text style={styles.effLabel}>Total Time Saved</Text>
                    <Text style={styles.effValue}>{stats.time_saved_fmt || '0 mins'}</Text>
                </View>
            </View>
            <View style={styles.efficiencyItemModal}>
                <View style={styles.effIconContainer}>
                    <Text style={styles.effIcon}>👥</Text>
                </View>
                <View style={styles.effDetails}>
                    <Text style={styles.effLabel}>Best Visit Slot</Text>
                    <Text style={styles.effValue}>{aiMetrics.best_time || aiMetrics.best_slot || 'N/A'}</Text>
                </View>
            </View>
        </View>
    );

    const showAlert = (title, message, type = 'success', options = {}) => {
        setAlertConfig({ title, message, type, ...options });
        if (options.showInput) {
            setRejectionReason('');
        }
        setAlertVisible(true);
    };

    const handleAction = async (visitId, action) => {
        if (action === 'checkin' || action === 'checkout') {
            const label = action === 'checkin' ? 'Check-In' : 'Check-Out';
            showAlert(
                'Confirm ' + label,
                `Are you sure you want to proceed with ${label} for this visitor?`,
                'warning',
                {
                    showCancel: true,
                    confirmText: 'Yes, Proceed',
                    onConfirm: () => executeAction(visitId, action)
                }
            );
        } else if (action === 'approve' || action === 'reject') {
            const label = action === 'approve' ? 'Approve' : 'Reject';
            showAlert(
                'Confirm ' + label,
                action === 'reject' ? 'Please provide a reason for rejecting this visit request:' : `Are you sure you want to ${label} this visit request?`,
                'warning',
                {
                    showCancel: true,
                    showInput: action === 'reject',
                    confirmText: 'Yes, ' + label,
                    onConfirm: (reason) => executeAction(visitId, action, reason)
                }
            );
        } else {
            executeAction(visitId, action);
        }
    };

    const executeAction = async (visitId, action, reason = null) => {
        try {
            const response = await apiClient.post('api/visit/status_action.php', {
                action: action,
                visit_id: visitId,
                reason: reason
            });

            if (response.data.status === 'success') {
                showAlert('Success', response.data.message, 'success');
                fetchData(); // Refresh data
            } else {
                showAlert('Error', response.data.message || 'Action failed', 'error');
            }
        } catch (error) {
            showAlert('Error', 'Action failed', 'error');
        }
    };

    const renderSweetAlert = () => (
        <Modal
            animationType="fade"
            transparent={true}
            visible={alertVisible}
            onRequestClose={() => setAlertVisible(false)}
        >
            <View style={styles.alertOverlay}>
                <View style={styles.alertContent}>
                    <View style={[styles.alertHeader, { backgroundColor: alertConfig.type === 'success' ? '#15803d' : (alertConfig.type === 'warning' ? '#f59e0b' : '#ef4444') }]}>
                        <Icon
                            name={alertConfig.type === 'success' ? 'check-circle' : (alertConfig.type === 'warning' ? 'help-circle' : 'alert-circle')}
                            size={32}
                            color="#fff"
                            style={{ marginRight: 10 }}
                        />
                        <Text style={styles.alertHeaderTitle}>{alertConfig.title || (alertConfig.type === 'success' ? 'Success!' : (alertConfig.type === 'warning' ? 'Confirm' : 'Error'))}</Text>
                    </View>
                    <View style={styles.alertBody}>
                        <Text style={styles.alertMessage}>{alertConfig.message}</Text>

                        {alertConfig.showInput && (
                            <TextInput
                                style={{
                                    borderWidth: 1,
                                    borderColor: '#e2e8f0',
                                    borderRadius: 10,
                                    padding: 12,
                                    marginTop: 15,
                                    minHeight: 80,
                                    color: '#1e293b',
                                    textAlignVertical: 'top',
                                    backgroundColor: '#f8fafc'
                                }}
                                placeholder="Enter reason for rejection..."
                                value={rejectionReason}
                                onChangeText={setRejectionReason}
                                multiline={true}
                                autoFocus={true}
                            />
                        )}

                        {alertConfig.showCancel ? (
                            <View style={styles.alertActionRow}>
                                <TouchableOpacity
                                    style={[styles.alertButton, styles.alertCancelButton]}
                                    onPress={() => setAlertVisible(false)}
                                >
                                    <Text style={styles.alertCancelButtonText}>Cancel</Text>
                                </TouchableOpacity>
                                <TouchableOpacity
                                    style={[styles.alertButton, styles.alertConfirmButton, { backgroundColor: alertConfig.type === 'warning' ? '#f59e0b' : '#15803d' }]}
                                    onPress={() => {
                                        if (alertConfig.showInput && !rejectionReason.trim()) {
                                            Alert.alert('Required', 'Please provide a reason');
                                            return;
                                        }
                                        setAlertVisible(false);
                                        if (alertConfig.onConfirm) alertConfig.onConfirm(rejectionReason);
                                    }}
                                >
                                    <Text style={styles.alertConfirmButtonText}>{alertConfig.confirmText || 'Yes'}</Text>
                                </TouchableOpacity>
                            </View>
                        ) : (
                            <TouchableOpacity
                                style={[styles.alertButton, { borderColor: alertConfig.type === 'success' ? '#15803d' : '#ef4444' }]}
                                onPress={() => setAlertVisible(false)}
                            >
                                <Text style={[styles.alertButtonText, { color: alertConfig.type === 'success' ? '#15803d' : '#ef4444' }]}>OK</Text>
                            </TouchableOpacity>
                        )}
                    </View>
                </View>
            </View>
        </Modal>
    );

    const renderVisitDetailsModal = () => {
        if (!selectedVisit) return null;

        return (
            <VisitDetailModal
                visible={detailsVisible}
                onClose={() => setDetailsVisible(false)}
                visit={selectedVisit}
                userRole="security"
                onAction={(id, action) => {
                    handleAction(id, action);
                    setDetailsVisible(false);
                }}
            />
        );
    };

    const renderMastersModal = () => (
        <Modal
            animationType="slide"
            transparent={true}
            visible={mastersModalVisible}
            onRequestClose={() => setMastersModalVisible(false)}
        >
            <Pressable
                style={styles.modalOverlay}
                onPress={() => setMastersModalVisible(false)}
            >
                <Pressable
                    style={styles.mgmtModalContent}
                    onPress={(e) => e.stopPropagation()}
                >
                    <View style={styles.mgmtModalHeader}>
                        <Text style={styles.mgmtModalTitle}>Management</Text>
                        <TouchableOpacity onPress={() => setMastersModalVisible(false)}>
                            <Icon name="close" size={24} color="#64748b" />
                        </TouchableOpacity>
                    </View>

                    <View style={styles.mgmtGrid}>
                        {hasPermission('host_history') && (
                            <TouchableOpacity
                                style={styles.mgmtGridItem}
                                onPress={() => {
                                    setMastersModalVisible(false);
                                    navigation.navigate('MyVisitorsHistory');
                                }}
                            >
                                <View style={[styles.mgmtIcon, { backgroundColor: '#fdf4ff' }]}>
                                    <Icon name="history" size={24} color="#a855f7" />
                                </View>
                                <Text style={styles.mgmtLabel}>Visitor History</Text>
                            </TouchableOpacity>
                        )}

                        {hasPermission('admin_employees') && (
                            <TouchableOpacity
                                style={styles.mgmtGridItem}
                                onPress={() => {
                                    setMastersModalVisible(false);
                                    navigation.navigate('Employees');
                                }}
                            >
                                <View style={[styles.mgmtIcon, { backgroundColor: '#e0f2fe' }]}>
                                    <Icon name="account-group" size={24} color="#0284c7" />
                                </View>
                                <Text style={styles.mgmtLabel}>Employees</Text>
                            </TouchableOpacity>
                        )}

                        {hasPermission('admin_users') && (
                            <TouchableOpacity
                                style={styles.mgmtGridItem}
                                onPress={() => {
                                    setMastersModalVisible(false);
                                    navigation.navigate('Permissions');
                                }}
                            >
                                <View style={[styles.mgmtIcon, { backgroundColor: '#f5f3ff' }]}>
                                    <Icon name="shield-lock" size={24} color="#7c3aed" />
                                </View>
                                <Text style={styles.mgmtLabel}>Users</Text>
                            </TouchableOpacity>
                        )}

                        {hasPermission('admin_audit') && (
                            <TouchableOpacity
                                style={styles.mgmtGridItem}
                                onPress={() => {
                                    setMastersModalVisible(false);
                                    navigation.navigate('AuditLogs');
                                }}
                            >
                                <View style={[styles.mgmtIcon, { backgroundColor: '#fef2f2' }]}>
                                    <Icon name="clipboard-text-clock" size={24} color="#dc2626" />
                                </View>
                                <Text style={styles.mgmtLabel}>Audit Logs</Text>
                            </TouchableOpacity>
                        )}

                        {(hasPermission('admin_reports') || hasPermission('security_reports')) && (
                            <TouchableOpacity
                                style={styles.mgmtGridItem}
                                onPress={() => {
                                    setMastersModalVisible(false);
                                    navigation.navigate('Reports');
                                }}
                            >
                                <View style={[styles.mgmtIcon, { backgroundColor: '#f0f9ff' }]}>
                                    <Icon name="file-chart" size={24} color="#0369a1" />
                                </View>
                                <Text style={styles.mgmtLabel}>Reports & Analytics</Text>
                            </TouchableOpacity>
                        )}

                        {(hasPermission('view_employee_report') || hasPermission('admin_reports') || hasPermission('security_reports')) && (
                            <TouchableOpacity
                                style={styles.mgmtGridItem}
                                onPress={() => {
                                    setMastersModalVisible(false);
                                    navigation.navigate('EmployeeReport');
                                }}
                            >
                                <View style={[styles.mgmtIcon, { backgroundColor: '#ecfdf5' }]}>
                                    <Icon name="account-details" size={24} color="#059669" />
                                </View>
                                <Text style={styles.mgmtLabel}>Employee-wise Report</Text>
                            </TouchableOpacity>
                        )}

                        <View style={styles.mgmtGridItem} />
                        <View style={styles.mgmtGridItem} />
                    </View>
                </Pressable>
            </Pressable>
        </Modal>
    );

    const renderSettingsModal = () => (
        <Modal
            animationType="slide"
            transparent={true}
            visible={settingsModalVisible}
            onRequestClose={() => setSettingsModalVisible(false)}
        >
            <Pressable
                style={styles.modalOverlay}
                onPress={() => setSettingsModalVisible(false)}
            >
                <Pressable
                    style={styles.mgmtModalContent}
                    onPress={(e) => e.stopPropagation()}
                >
                    <View style={styles.mgmtModalHeader}>
                        <Text style={styles.mgmtModalTitle}>System Settings</Text>
                        <TouchableOpacity onPress={() => setSettingsModalVisible(false)}>
                            <Icon name="close" size={24} color="#64748b" />
                        </TouchableOpacity>
                    </View>

                    <View style={styles.mgmtGrid}>
                        {hasPermission('settings_profile') && (
                            <TouchableOpacity
                                style={styles.mgmtGridItem}
                                onPress={() => {
                                    setSettingsModalVisible(false);
                                    navigation.navigate('Settings', { tab: 'profile' });
                                }}
                            >
                                <View style={[styles.mgmtIcon, { backgroundColor: '#f0f9ff' }]}>
                                    <Icon name="account-circle" size={24} color="#0284c7" />
                                </View>
                                <Text style={styles.mgmtLabel}>Profile</Text>
                            </TouchableOpacity>
                        )}

                        {hasPermission('settings_general') && (
                            <TouchableOpacity
                                style={styles.mgmtGridItem}
                                onPress={() => {
                                    setSettingsModalVisible(false);
                                    navigation.navigate('Settings', { tab: 'general' });
                                }}
                            >
                                <View style={[styles.mgmtIcon, { backgroundColor: '#f8fafc' }]}>
                                    <Icon name="cog" size={24} color="#475569" />
                                </View>
                                <Text style={styles.mgmtLabel}>General</Text>
                            </TouchableOpacity>
                        )}

                        {hasPermission('settings_company') && (
                            <TouchableOpacity
                                style={styles.mgmtGridItem}
                                onPress={() => {
                                    setSettingsModalVisible(false);
                                    navigation.navigate('Settings', { tab: 'company' });
                                }}
                            >
                                <View style={[styles.mgmtIcon, { backgroundColor: '#eff6ff' }]}>
                                    <Icon name="briefcase" size={24} color="#2563eb" />
                                </View>
                                <Text style={styles.mgmtLabel}>Company</Text>
                            </TouchableOpacity>
                        )}

                        {hasPermission('settings_access') && (
                            <TouchableOpacity
                                style={styles.mgmtGridItem}
                                onPress={() => {
                                    setSettingsModalVisible(false);
                                    navigation.navigate('Settings', { tab: 'access' });
                                }}
                            >
                                <View style={[styles.mgmtIcon, { backgroundColor: '#fff7ed' }]}>
                                    <Icon name="map-marker-key" size={24} color="#ea580c" />
                                </View>
                                <Text style={styles.mgmtLabel}>Access Area</Text>
                            </TouchableOpacity>
                        )}

                        {hasPermission('settings_email') && (
                            <TouchableOpacity
                                style={styles.mgmtGridItem}
                                onPress={() => {
                                    setSettingsModalVisible(false);
                                    navigation.navigate('Settings', { tab: 'email' });
                                }}
                            >
                                <View style={[styles.mgmtIcon, { backgroundColor: '#fdf2f8' }]}>
                                    <Icon name="email" size={24} color="#db2777" />
                                </View>
                                <Text style={styles.mgmtLabel}>Email Config</Text>
                            </TouchableOpacity>
                        )}

                        {hasPermission('admin_audit') && (
                            <TouchableOpacity
                                style={styles.mgmtGridItem}
                                onPress={() => {
                                    setSettingsModalVisible(false);
                                    navigation.navigate('Settings', { tab: 'info' });
                                }}
                            >
                                <View style={[styles.mgmtIcon, { backgroundColor: '#f5f3ff' }]}>
                                    <Icon name="information" size={24} color="#7c3aed" />
                                </View>
                                <Text style={styles.mgmtLabel}>System Info</Text>
                            </TouchableOpacity>
                        )}

                        <View style={styles.mgmtGridItem} />
                    </View>
                </Pressable>
            </Pressable>
        </Modal>
    );

    const renderBottomMenu = () => (
        <View style={styles.bottomTabBar}>
            <TouchableOpacity style={styles.tabItem} onPress={() => setActiveTab('home')}>
                <Icon name="shield-home" size={24} color={activeTab === 'home' ? "#3b82f6" : "#64748b"} />
                <Text style={[styles.tabLabel, activeTab === 'home' && { color: '#3b82f6' }]}>Dashboard</Text>
            </TouchableOpacity>

            {(hasPermission('security_search') || hasPermission('host_invite')) && (
                <TouchableOpacity style={styles.tabItem} onPress={() => setActiveTab('log')}>
                    <Icon name="clipboard-list" size={24} color={activeTab === 'log' ? "#3b82f6" : "#64748b"} />
                    <Text style={[styles.tabLabel, activeTab === 'log' && { color: '#3b82f6' }]}>Visitors</Text>
                </TouchableOpacity>
            )}

            <View style={{ position: 'relative', top: -25 }}>
                <TouchableOpacity
                    style={{
                        width: 56, height: 56, borderRadius: 28, backgroundColor: '#3b82f6',
                        justifyContent: 'center', alignItems: 'center',
                        shadowColor: '#3b82f6', shadowOffset: { width: 0, height: 4 }, shadowOpacity: 0.3, shadowRadius: 8, elevation: 5
                    }}
                    onPress={() => setIsMenuOpen(!isMenuOpen)}
                >
                    <Icon name={isMenuOpen ? "close" : "plus"} size={32} color="#fff" />
                </TouchableOpacity>
            </View>

            <TouchableOpacity style={styles.tabItem} onPress={() => setMastersModalVisible(true)}>
                <Icon name="grid" size={24} color="#64748b" />
                <Text style={styles.tabLabel}>Manage</Text>
            </TouchableOpacity>

            {(hasPermission(['settings_profile', 'settings_company', 'settings_general', 'settings_departments', 'settings_access', 'settings_email']) || role === 'admin') && (
                <TouchableOpacity style={styles.tabItem} onPress={() => setSettingsModalVisible(true)}>
                    <Icon name="cog" size={24} color="#64748b" />
                    <Text style={styles.tabLabel}>Settings</Text>
                </TouchableOpacity>
            )}
        </View>
    );

    const renderFloatingMenu = () => {
        if (!isMenuOpen) return null;

        return (
            <>
                <Pressable
                    style={styles.fabOverlay}
                    onPress={() => setIsMenuOpen(false)}
                />

                <View style={[styles.fabContainer, { bottom: 100, right: 0, left: 0, alignItems: 'center' }]}>
                    <View style={styles.fabActions}>
                        {hasPermission('security_register') && (
                            <TouchableOpacity
                                style={styles.fabSubButton}
                                onPress={() => {
                                    setIsMenuOpen(false);
                                    navigation.navigate('RegisterVisitor');
                                }}
                            >
                                <Text style={styles.fabLabelText}>New Visitor</Text>
                                <View style={[styles.fabIconWrapper, { backgroundColor: '#10b981' }]}>
                                    <Icon name="account-plus" size={20} color="#fff" />
                                </View>
                            </TouchableOpacity>
                        )}

                        {hasPermission('security_scan') && (
                            <TouchableOpacity
                                style={styles.fabSubButton}
                                onPress={() => {
                                    setIsMenuOpen(false);
                                    setScanned(false);
                                    setScanModalVisible(true);
                                }}
                            >
                                <Text style={styles.fabLabelText}>Scan QR</Text>
                                <View style={[styles.fabIconWrapper, { backgroundColor: '#ec4899' }]}>
                                    <Icon name="qrcode-scan" size={20} color="#fff" />
                                </View>
                            </TouchableOpacity>
                        )}

                        {hasPermission('host_invite') && (
                            <TouchableOpacity
                                style={styles.fabSubButton}
                                onPress={() => {
                                    setIsMenuOpen(false);
                                    navigation.navigate('InviteVisitor');
                                }}
                            >
                                <Text style={styles.fabLabelText}>New Invite</Text>
                                <View style={[styles.fabIconWrapper, { backgroundColor: '#8b5cf6' }]}>
                                    <Icon name="calendar-plus" size={20} color="#fff" />
                                </View>
                            </TouchableOpacity>
                        )}
                    </View>
                </View>
            </>
        );
    };

    if (loading && !refreshing) {
        return (
            <View style={styles.loadingContainer}>
                <ActivityIndicator size="large" color="#3b82f6" />
                <Text style={styles.loadingText}>Loading Security Dashboard...</Text>
            </View>
        );
    }

    return (
        <SafeAreaView style={styles.container}>
            <StatusBar barStyle="dark-content" />
            <View style={styles.header}>
                <View style={{ flex: 1 }}>
                    <Text style={styles.greeting}>Security Portal</Text>
                    <Text style={styles.userName}>{userData?.full_name || 'Officer'}</Text>
                    <Text style={{ fontSize: 9, color: '#94a3b8', fontWeight: '800', marginTop: 2 }}>{APP_VERSION}</Text>
                </View>
                <TouchableOpacity style={styles.logoutBtn} onPress={async () => { await AsyncStorage.removeItem('userData'); navigation.replace('Login'); }}>
                    <Text style={styles.logoutText}>Logout</Text>
                </TouchableOpacity>
            </View>

            <ScrollView contentContainerStyle={styles.scrollContent} refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} />} showsVerticalScrollIndicator={false}>
                {error && (
                    <View style={styles.errorBanner}>
                        <Text style={styles.errorText}>{error}</Text>
                        <TouchableOpacity onPress={onRefresh}><Text style={styles.retryText}>Retry</Text></TouchableOpacity>
                    </View>
                )}

                {activeTab === 'home' ? renderHome() : renderLogTab()}

                <View style={{ height: 100 }} />
            </ScrollView>

            {renderBottomMenu()}
            {renderFloatingMenu()}

            {renderMastersModal()}
            {renderSettingsModal()}

            {/* Scan QR Modal */}
            <Modal
                animationType="slide"
                transparent={true}
                visible={scanModalVisible}
                onRequestClose={() => setScanModalVisible(false)}
            >
                <View style={styles.modalOverlay}>
                    <View style={[styles.modalContent, { height: '80%' }]}>
                        <View style={styles.modalHeader}>
                            <Text style={styles.modalTitle}>Scan Visitor Pass</Text>
                            <TouchableOpacity onPress={() => setScanModalVisible(false)}>
                                <Icon name="close" size={24} color="#1e293b" />
                            </TouchableOpacity>
                        </View>
                        <View style={{ flex: 1, backgroundColor: '#000', borderRadius: 10, overflow: 'hidden' }}>
                            {!permission ? (
                                <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center' }}>
                                    <ActivityIndicator size="large" color="#fff" />
                                </View>
                            ) : !permission.granted ? (
                                <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center', padding: 20 }}>
                                    <Text style={{ color: '#fff', textAlign: 'center', marginBottom: 20 }}>Camera permission is required</Text>
                                    <TouchableOpacity
                                        style={{ backgroundColor: '#2563eb', padding: 10, borderRadius: 5 }}
                                        onPress={requestPermission}
                                    >
                                        <Text style={{ color: '#fff' }}>Grant Permission</Text>
                                    </TouchableOpacity>
                                </View>
                            ) : (
                                <CameraView
                                    style={{ flex: 1 }}
                                    facing="back"
                                    onBarcodeScanned={scanned ? undefined : handleBarCodeScanned}
                                    barcodeScannerSettings={{
                                        barcodeTypes: ["qr"],
                                    }}
                                >
                                    <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center' }}>
                                        <View style={{ width: 250, height: 250, borderWidth: 2, borderColor: '#00ff00', backgroundColor: 'transparent' }} />
                                        <Text style={{ color: '#fff', marginTop: 20, backgroundColor: 'rgba(0,0,0,0.5)', padding: 5, borderRadius: 5 }}>Align QR Code within frame</Text>
                                    </View>
                                </CameraView>
                            )}
                        </View>
                    </View>
                </View>
            </Modal>

            {/* Data List Modal */}
            <Modal
                animationType="slide"
                transparent={true}
                visible={modalVisible && modalType !== 'visits' && modalType !== 'overstays'}
                onRequestClose={() => setModalVisible(false)}
            >
                <View style={styles.modalOverlay}>
                    <View style={styles.modalContent}>
                        <View style={styles.modalHeader}>
                            <Text style={styles.modalTitle}>{modalTitle}</Text>
                            <TouchableOpacity onPress={() => setModalVisible(false)}>
                                <Icon name="close" size={24} color="#1e293b" />
                            </TouchableOpacity>
                        </View>
                        <View style={styles.modalBody}>
                            {modalType === 'efficiency' && renderEfficiencyModal()}
                        </View>
                    </View>
                </View>
            </Modal>

            <VisitListModal
                visible={modalVisible && (modalType === 'visits' || modalType === 'overstays')}
                onClose={() => setModalVisible(false)}
                title={modalTitle}
                color={modalType === 'overstays' ? '#ef4444' : '#3b82f6'}
                visits={modalTitle === 'Check-in Pending' ? scheduledVisits : (() => {
                    if (modalType === 'overstays') return records.overstays || [];

                    const isToday = (dateStr) => {
                        if (!dateStr) return false;
                        const d = new Date(dateStr.indexOf('T') === -1 && dateStr.indexOf(' ') > -1 ? dateStr.replace(' ', 'T') : dateStr);
                        const now = new Date();
                        return d.getDate() === now.getDate() &&
                            d.getMonth() === now.getMonth() &&
                            d.getFullYear() === now.getFullYear();
                    };

                    return (records.visits || []).filter(v => {
                        if (modalTitle === 'Total Today') {
                            return isToday(v.created_at) || (v.is_invited == 1 && isToday(v.visit_date));
                        }
                        if (modalFilter === 'pending') {
                            return (v.approval_status?.toLowerCase() === 'pending' || v.status?.toLowerCase() === 'pending');
                        }
                        if (modalTitle === 'Check-in Pending') {
                            return false; // Handled by separate list
                        }
                        if (modalFilter) {
                            return v.status === modalFilter;
                        }
                        return true;
                    });
                })()}
                onVisitPress={(visit) => {
                    fetchVisitDetails(visit.id);
                }}
            />

            {renderSweetAlert()}
            {renderVisitDetailsModal()}
        </SafeAreaView>
    );
}

const styles = StyleSheet.create({
    container: { flex: 1, backgroundColor: '#f8fafc' },
    loadingContainer: { flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: '#f8fafc' },
    loadingText: { marginTop: 15, color: '#64748b', fontWeight: '600' },
    header: { flexDirection: 'row', padding: 20, backgroundColor: '#fff', alignItems: 'center', borderBottomWidth: 1, borderBottomColor: '#f1f5f9' },
    greeting: { fontSize: 13, color: '#64748b', fontWeight: '500' },
    userName: { fontSize: 20, fontWeight: '800', color: '#1e293b' },
    logoutBtn: { backgroundColor: '#fee2e2', paddingHorizontal: 12, paddingVertical: 6, borderRadius: 20 },
    logoutText: { color: '#ef4444', fontWeight: '700', fontSize: 12 },
    scrollContent: { paddingBottom: 100 },
    scrollPadding: { paddingHorizontal: 15, paddingTop: 15 },
    metricsGrid: { flexDirection: 'row', gap: 10, marginBottom: 15 },
    metricCard: { flex: 1, padding: 18, borderRadius: 20, alignItems: 'center', elevation: 2 },
    metricValue: { fontSize: 24, fontWeight: '800', color: '#fff' },
    metricLabel: { fontSize: 11, color: 'rgba(255,255,255,0.9)', marginTop: 4, fontWeight: '700', textTransform: 'uppercase' },
    statsSummary: { backgroundColor: '#fff', padding: 15, borderRadius: 20, marginBottom: 20, elevation: 1 },
    summaryItem: { flexDirection: 'row', alignItems: 'center', gap: 12 },
    summaryLabel: { fontSize: 14, color: '#1e293b', fontWeight: '800' },
    summaryDescription: { fontSize: 11, color: '#64748b', marginTop: 2 },
    summaryValueLarge: { fontSize: 22, fontWeight: '800', color: '#3b82f6' },
    iconCircle: { width: 48, height: 48, borderRadius: 24, backgroundColor: 'rgba(59, 130, 246, 0.1)', justifyContent: 'center', alignItems: 'center', marginRight: 12 },
    summaryValue: { fontSize: 18, fontWeight: '800', color: '#1e293b' },
    textRight: { alignItems: 'flex-end' },
    timeSavedLabel: { fontSize: 10, color: '#64748b', fontWeight: '700', textTransform: 'uppercase' },
    chartContainer: { height: 220, paddingTop: 20 },
    barsRow: { flexDirection: 'row', alignItems: 'flex-end', paddingHorizontal: 10, gap: 15 },
    barColumn: { alignItems: 'center', width: 45 },
    barWrapper: { height: 150, width: 25, backgroundColor: '#f1f5f9', borderRadius: 12.5, justifyContent: 'flex-end', overflow: 'hidden' },
    bar: { width: '100%', backgroundColor: '#3b82f6', borderRadius: 12.5 },
    barValue: { position: 'absolute', top: -20, fontSize: 10, fontWeight: '700', color: '#1e293b' },
    barLabel: { fontSize: 10, color: '#64748b', marginTop: 10, fontWeight: '600' },
    section: { marginBottom: 20 },
    sectionHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingHorizontal: 5, marginBottom: 12 },
    sectionTitle: { fontSize: 18, fontWeight: '800', color: '#1e293b' },
    viewToggle: { flexDirection: 'row', backgroundColor: '#f1f5f9', borderRadius: 20, p: 2 },
    toggleBtn: { paddingHorizontal: 12, paddingVertical: 4, borderRadius: 15 },
    toggleText: { fontSize: 11, fontWeight: '700' },
    card: { backgroundColor: '#fff', borderRadius: 20, padding: 15, elevation: 1 },
    zoneRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
    zoneNameText: { fontSize: 14, fontWeight: '700', color: '#1e293b' },
    zoneStatusText: { fontSize: 11, fontWeight: '600', marginTop: 2 },
    zoneProgressContainer: { flexDirection: 'row', alignItems: 'center', gap: 10 },
    zoneCountBadge: { backgroundColor: '#f8fafc', borderWidth: 1, borderColor: '#f1f5f9', paddingHorizontal: 8, paddingVertical: 2, borderRadius: 10 },
    zoneCountTextSmall: { fontSize: 11, fontWeight: '700', color: '#475569' },
    miniProgressBar: { width: 60, height: 6, backgroundColor: '#f1f5f9', borderRadius: 3, overflow: 'hidden' },
    miniProgressFill: { height: '100%', borderRadius: 3 },
    bestPeakRow: { flexDirection: 'row', gap: 12, marginTop: 20, borderTopWidth: 1, borderTopColor: '#f1f5f9', paddingTop: 15 },
    bestPeakItem: { flex: 1, backgroundColor: 'rgba(59, 130, 246, 0.05)', borderRadius: 12, padding: 10, borderWidth: 1, borderColor: 'rgba(59, 130, 246, 0.1)' },
    bestPeakItemRed: { flex: 1, backgroundColor: 'rgba(239, 68, 68, 0.05)', borderRadius: 12, padding: 10, borderWidth: 1, borderColor: 'rgba(239, 68, 68, 0.1)' },
    bestPeakLabel: { fontSize: 10, fontWeight: '800', color: '#3b82f6', marginBottom: 4 },
    bestPeakLabelRed: { fontSize: 10, fontWeight: '800', color: '#ef4444', marginBottom: 4 },
    bestPeakValueRow: { flexDirection: 'row', alignItems: 'center', gap: 4 },
    bestPeakValue: { fontSize: 13, fontWeight: '700', color: '#1e293b' },
    bestPeakSub: { fontSize: 10, color: '#64748b', marginTop: 2 },
    aiMonitorCard: { backgroundColor: '#1e293b', borderRadius: 24, padding: 20, marginBottom: 20, elevation: 5 },
    aiHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 },
    aiTitle: { color: '#94a3b8', fontSize: 11, fontWeight: '900', letterSpacing: 1.5 },
    liveBadge: { backgroundColor: '#10b981', paddingHorizontal: 8, paddingVertical: 2, borderRadius: 5 },
    liveBadgeText: { color: '#fff', fontSize: 10, fontWeight: '900' },
    aiMonitorLabel: { color: '#f8fafc', fontSize: 14, fontWeight: '700', marginBottom: 10 },
    progressBarBg: { height: 10, backgroundColor: 'rgba(255,255,255,0.1)', borderRadius: 5, marginBottom: 8 },
    progressBarFill: { height: 10, borderRadius: 5 },
    densityStatus: { fontSize: 13, fontWeight: '800', marginBottom: 20 },
    aiMetricsRow: { backgroundColor: 'rgba(255,255,255,0.05)', borderRadius: 15, padding: 12, marginBottom: 10, borderWidth: 1, borderColor: 'rgba(255,255,255,0.1)' },
    aiMetricItem: { flexDirection: 'row', alignItems: 'center', gap: 12 },
    aiMetricInfo: { flex: 1 },
    aiMetricValueText: { color: '#fff', fontSize: 15, fontWeight: '700' },
    aiMetricSubtext: { color: '#94a3b8', fontSize: 12 },
    actionGrid: { flexDirection: 'row', gap: 12, marginBottom: 25 },

    // Segmented Control Styles
    segmentContainer: { flexDirection: 'row', backgroundColor: '#e2e8f0', borderRadius: 12, padding: 4, marginBottom: 20 },
    segmentBtn: { flex: 1, paddingVertical: 8, alignItems: 'center', borderRadius: 10 },
    segmentBtnActive: { backgroundColor: '#fff', elevation: 2, shadowColor: '#000', shadowOffset: { width: 0, height: 1 }, shadowOpacity: 0.1, shadowRadius: 1 },
    segmentText: { fontSize: 13, fontWeight: '600', color: '#64748b' },
    segmentTextActive: { color: '#3b82f6', fontWeight: '700' },
    actionBtn: { flex: 1, backgroundColor: '#fff', padding: 18, borderRadius: 20, alignItems: 'center', elevation: 2, shadowColor: '#000', shadowOffset: { width: 0, height: 2 }, shadowOpacity: 0.05, shadowRadius: 4 },
    actionBtnLabel: { fontSize: 14, fontWeight: '700', color: '#475569', marginTop: 8 },
    section: { marginBottom: 20 },
    sectionHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 15 },
    sectionTitle: { fontSize: 18, fontWeight: '800', color: '#1e293b' },
    seeAllText: { fontSize: 13, color: '#3b82f6', fontWeight: '700' },
    visitRow: { flexDirection: 'row', alignItems: 'center', backgroundColor: '#fff', padding: 12, borderRadius: 16, marginBottom: 10, elevation: 1 },
    visitorThumb: { width: 45, height: 45, borderRadius: 22.5, marginRight: 12 },
    visitInfo: { flex: 1 },
    visitorNameText: { fontSize: 15, fontWeight: '700', color: '#1e293b' },
    visitDetailsText: { fontSize: 12, color: '#64748b', marginTop: 2 },
    statusTag: { paddingHorizontal: 10, paddingVertical: 4, borderRadius: 10 },
    statusTagText: { fontSize: 9, fontWeight: '800', color: '#fff' },
    emptyLog: { padding: 40, alignItems: 'center' },
    emptyLogText: { color: '#94a3b8', fontSize: 14, fontWeight: '500' },
    visitRowBig: { flexDirection: 'row', alignItems: 'center', backgroundColor: '#fff', padding: 15, borderRadius: 20, marginBottom: 12, elevation: 1 },
    visitorThumbBig: { width: 60, height: 60, borderRadius: 30, marginRight: 15 },
    visitorNameTextBig: { fontSize: 17, fontWeight: '800', color: '#1e293b' },
    timeTagRow: { flexDirection: 'row', gap: 10, marginTop: 8 },
    timeTagText: { fontSize: 11, fontWeight: '600', color: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.1)', paddingHorizontal: 8, paddingVertical: 2, borderRadius: 6 },
    detailsFooter: { padding: 20, backgroundColor: '#fff', borderTopWidth: 1, borderTopColor: '#f1f5f9' },
    actionButton: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', padding: 15, borderRadius: 12, gap: 10 },
    actionButtonText: { color: '#fff', fontSize: 16, fontWeight: '700' },
    bottomTabBar: { position: 'absolute', bottom: 0, left: 0, right: 0, height: 85, backgroundColor: '#fff', flexDirection: 'row', justifyContent: 'space-around', alignItems: 'center', borderTopWidth: 1, borderTopColor: '#f1f5f9', paddingBottom: 25 },
    tabItem: { alignItems: 'center' },
    tabLabel: { fontSize: 11, color: '#64748b', marginTop: 5, fontWeight: '700' },
    fabOverlay: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, backgroundColor: 'rgba(0,0,0,0.5)', zIndex: 5 },
    fabContainer: { position: 'absolute', bottom: 110, right: 20, alignItems: 'flex-end', zIndex: 10000 },
    fabMainButton: { width: 64, height: 64, borderRadius: 32, backgroundColor: '#3b82f6', justifyContent: 'center', alignItems: 'center', elevation: 8 },
    fabMainButtonActive: { backgroundColor: '#1e293b' },
    fabActions: { marginBottom: 15, alignItems: 'center', gap: 12 },
    fabSubButton: { flexDirection: 'row', alignItems: 'center', backgroundColor: '#fff', padding: 10, borderRadius: 25, elevation: 4 },
    fabLabelText: { marginRight: 12, fontWeight: '700', fontSize: 15, color: '#1e293b' },
    fabIconWrapper: { width: 40, height: 40, borderRadius: 20, justifyContent: 'center', alignItems: 'center' },
    errorBanner: { backgroundColor: '#ef4444', padding: 10, margin: 10, borderRadius: 10, flexDirection: 'row', justifyContent: 'space-between' },
    errorText: { color: '#fff', fontWeight: 'bold' },
    retryText: { color: '#fff', textDecorationLine: 'underline' },
    modalOverlay: {
        flex: 1,
        backgroundColor: 'rgba(0,0,0,0.5)',
        justifyContent: 'flex-end',
    },
    modalContent: {
        backgroundColor: '#fff',
        borderTopLeftRadius: 20,
        borderTopRightRadius: 20,
        height: height * 0.8,
        paddingTop: 20,
    },
    modalHeader: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        paddingHorizontal: 20,
        paddingBottom: 15,
        borderBottomWidth: 1,
        borderBottomColor: '#f1f5f9',
    },
    modalTitle: {
        fontSize: 18,
        fontWeight: 'bold',
        color: '#1e293b',
    },
    modalBody: {
        flex: 1,
    },
    modalSearchContainer: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: '#f1f5f9',
        margin: 15,
        paddingHorizontal: 12,
        borderRadius: 10,
        height: 45,
    },
    modalSearchIcon: {
        marginRight: 8,
    },
    modalSearchInput: {
        flex: 1,
        fontSize: 15,
        color: '#1e293b',
    },
    modalScroll: {
        flex: 1,
        paddingHorizontal: 15,
    },
    detailRow: {
        flexDirection: 'row',
        alignItems: 'center',
        paddingVertical: 12,
        borderBottomWidth: 1,
        borderBottomColor: '#f1f5f9',
    },
    detailInfo: {
        flex: 1,
    },
    detailName: {
        fontSize: 14,
        fontWeight: '600',
        color: '#1e293b',
    },
    detailSub: {
        fontSize: 11,
        color: '#64748b',
    },
    detailActionCol: {
        alignItems: 'flex-end',
        marginLeft: 8,
    },
    detailTime: {
        fontSize: 11,
        color: '#ef4444',
        fontWeight: 'bold',
    },
    detailDateText: {
        fontSize: 10,
        color: '#94a3b8',
        marginTop: 2,
    },
    noDataText: {
        textAlign: 'center',
        color: '#94a3b8',
        marginTop: 20,
        fontSize: 13,
    },
    recordRow: {
        flexDirection: 'row',
        alignItems: 'center',
        paddingVertical: 12,
        borderBottomWidth: 1,
        borderBottomColor: '#f1f5f9',
    },
    recordInfo: {
        flex: 1,
    },
    recordName: {
        fontSize: 14,
        fontWeight: '600',
        color: '#1e293b',
    },
    recordSub: {
        fontSize: 11,
        color: '#64748b',
    },
    recordAction: {
        flexDirection: 'row',
        alignItems: 'center',
        marginLeft: 8,
    },
    overstayBadge: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: '#fee2e2',
        paddingHorizontal: 8,
        paddingVertical: 2,
        borderRadius: 10,
        marginTop: 4,
        alignSelf: 'flex-start',
    },
    overstayDurationText: {
        fontSize: 10,
        color: '#ef4444',
        fontWeight: 'bold',
        marginLeft: 4,
    },
    miniStatusBadge: {
        paddingHorizontal: 8,
        paddingVertical: 2,
        borderRadius: 8,
        marginRight: 8,
    },
    miniStatusText: {
        fontSize: 9,
        fontWeight: 'bold',
    },
    efficiencyList: {
        padding: 15,
    },
    efficiencyItemModal: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: '#f8fafc',
        padding: 15,
        borderRadius: 12,
        marginBottom: 12,
        borderWidth: 1,
        borderBottomColor: '#f1f5f9',
    },
    effIconContainer: {
        width: 40,
        height: 40,
        borderRadius: 20,
        backgroundColor: '#fff',
        justifyContent: 'center',
        alignItems: 'center',
        marginRight: 15,
        elevation: 1,
    },
    effIcon: {
        fontSize: 20,
    },
    effDetails: {
        flex: 1,
    },
    effLabel: {
        fontSize: 14,
        color: '#64748b',
    },
    effValue: {
        fontSize: 16,
        fontWeight: 'bold',
        color: '#1e293b',
    },
    detailsHeader: {
        backgroundColor: '#3b82f6',
        padding: 20,
        paddingTop: 10,
    },
    detailsHeaderTop: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        marginBottom: 20,
    },
    detailsHeaderTitle: {
        fontSize: 20,
        fontWeight: 'bold',
        color: '#fff',
    },
    visitorMainInfo: {
        flexDirection: 'row',
        alignItems: 'center',
    },
    photoContainer: {
        width: 80,
        height: 80,
        borderRadius: 40,
        overflow: 'hidden',
        borderWidth: 3,
        borderColor: 'rgba(255,255,255,0.5)',
        marginRight: 15,
        backgroundColor: 'rgba(255,255,255,0.2)',
        justifyContent: 'center',
        alignItems: 'center',
    },
    visitorPhoto: {
        width: '100%',
        height: '100%',
    },
    detailsBasic: {
        flex: 1,
    },
    detailsName: {
        fontSize: 22,
        fontWeight: 'bold',
        color: '#fff',
        marginBottom: 2,
    },
    detailsMobile: {
        fontSize: 16,
        color: 'rgba(255,255,255,0.8)',
        marginBottom: 8,
    },
    statusBadgeModal: {
        paddingHorizontal: 12,
        paddingVertical: 4,
        borderRadius: 12,
        alignSelf: 'flex-start',
    },
    statusBadgeTextModal: {
        color: '#fff',
        fontSize: 12,
        fontWeight: 'bold',
    },
    detailsContent: {
        padding: 20,
    },
    detailsCard: {
        backgroundColor: '#ffffff',
        margin: 15,
        borderRadius: 15,
        padding: 15,
        elevation: 2,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 1 },
        shadowOpacity: 0.1,
        shadowRadius: 2,
    },
    detailsSectionTitle: {
        fontSize: 16,
        fontWeight: 'bold',
        color: '#1e293b',
        marginBottom: 12,
    },
    detailsGrid: {
        flexDirection: 'row',
        flexWrap: 'wrap',
    },
    detailsItem: {
        width: '50%',
        marginBottom: 15,
    },
    detailsLabel: {
        fontSize: 12,
        color: '#64748b',
        marginBottom: 4,
    },
    detailsValue: {
        fontSize: 14,
        fontWeight: '600',
        color: '#1e293b',
    },
    timelineItem: {
        flexDirection: 'row',
        marginBottom: 15,
    },
    timelineDot: {
        width: 12,
        height: 12,
        borderRadius: 6,
        marginTop: 4,
        marginRight: 12,
    },
    timelineTitle: {
        fontSize: 14,
        fontWeight: 'bold',
        color: '#1e293b',
    },
    timelineDate: {
        fontSize: 12,
        color: '#64748b',
    },
    recordStatusIndicator: { width: 4, height: 30, borderRadius: 2, marginRight: 12 },
    recordMetaRow: { flexDirection: 'row', alignItems: 'center', marginTop: 2 },
    recordDot: { fontSize: 11, color: '#cbd5e1', marginHorizontal: 4 },
    recordPurpose: { fontSize: 11, color: '#94a3b8', fontStyle: 'italic' },
    checkinPendingCard: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        backgroundColor: '#fff',
        padding: 15,
        borderRadius: 20,
        marginBottom: 20,
        elevation: 1,
        borderWidth: 1,
        borderColor: '#f1f5f9'
    },
    checkinPendingContent: {
        flexDirection: 'row',
        alignItems: 'center'
    },
    checkinPendingLabel: {
        fontSize: 12,
        color: '#64748b',
        fontWeight: '600',
        marginBottom: 2
    },
    checkinPendingValue: {
        fontSize: 18,
        fontWeight: '800',
        color: '#1e293b'
    },
    // Missing Detail Modal Styles
    detailsHeader: { backgroundColor: '#3b82f6', padding: 20, borderBottomLeftRadius: 30, borderBottomRightRadius: 30, marginBottom: 20 },
    detailsHeaderTop: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20, marginTop: 10 },
    detailsHeaderTitle: { fontSize: 18, fontWeight: '800', color: '#fff' },
    visitorMainInfo: { flexDirection: 'row', alignItems: 'center' },
    photoContainer: { marginRight: 20 },
    visitorPhoto: { width: 80, height: 80, borderRadius: 40, borderWidth: 4, borderColor: 'rgba(255,255,255,0.3)' },
    detailsBasic: { flex: 1 },
    detailsName: { fontSize: 22, fontWeight: '800', color: '#fff', marginBottom: 4 },
    detailsMobile: { fontSize: 14, color: 'rgba(255,255,255,0.8)', marginBottom: 8, fontWeight: '600' },
    statusBadgeModal: { alignSelf: 'flex-start', paddingHorizontal: 10, paddingVertical: 4, borderRadius: 12 },
    statusBadgeTextModal: { fontSize: 11, fontWeight: '800', color: '#fff' },
    detailsContent: { paddingHorizontal: 20 },
    detailsCard: { backgroundColor: '#fff', borderRadius: 24, padding: 20, marginBottom: 15, elevation: 1, borderWidth: 1, borderColor: '#f1f5f9' },
    detailsSectionTitle: { fontSize: 15, fontWeight: '800', color: '#1e293b', marginBottom: 15 },
    detailsGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 20 },
    detailsItem: { width: '45%' },
    detailsLabel: { fontSize: 11, color: '#94a3b8', marginBottom: 4, fontWeight: '600', textTransform: 'uppercase' },
    detailsValue: { fontSize: 15, fontWeight: '700', color: '#0f172a' },
    timelineItem: { flexDirection: 'row', alignItems: 'flex-start', marginBottom: 20, marginLeft: 5 },
    timelineDot: { width: 14, height: 14, borderRadius: 7, marginTop: 4, marginRight: 15, borderWidth: 3, borderColor: '#fff', elevation: 2 },
    timelineTitle: { fontSize: 15, fontWeight: '700', color: '#1e293b' },
    timelineDate: { fontSize: 12, color: '#64748b', fontWeight: '500' },
    // FAB Styles
    fabContainer: {
        position: 'absolute',
        bottom: 100, // Adjusted to sit above bottom tab bar
        right: 20,
        alignItems: 'center',
        zIndex: 10,
    },
    fabOverlay: {
        position: 'absolute',
        top: 0,
        bottom: 0,
        left: 0,
        right: 0,
        backgroundColor: 'rgba(0,0,0,0.5)',
        zIndex: 5,
    },
    fabActions: {
        marginBottom: 10,
        alignItems: 'flex-end',
    },
    fabSubButton: {
        flexDirection: 'row',
        alignItems: 'center',
        marginBottom: 12,
    },
    fabLabelText: {
        backgroundColor: '#fff',
        paddingHorizontal: 10,
        paddingVertical: 4,
        borderRadius: 4,
        marginRight: 8,
        fontSize: 12,
        fontWeight: '600',
        color: '#1e293b',
        elevation: 2,
    },
    fabIconWrapper: {
        width: 40,
        height: 40,
        borderRadius: 20,
        justifyContent: 'center',
        alignItems: 'center',
        elevation: 4,
    },
    fabMainButton: {
        width: 56,
        height: 56,
        borderRadius: 28,
        backgroundColor: '#3b82f6',
        justifyContent: 'center',
        alignItems: 'center',
        elevation: 5,
        shadowColor: '#3b82f6',
        shadowOffset: { width: 0, height: 4 },
        shadowOpacity: 0.3,
        shadowRadius: 8,
    },
    fabMainButtonActive: {
        backgroundColor: '#1e293b',
    },
    // Management Modal Styles
    mgmtModalContent: {
        backgroundColor: '#fff',
        borderTopLeftRadius: 24,
        borderTopRightRadius: 24,
        padding: 24,
        paddingBottom: 40,
        maxHeight: '80%',
    },
    mgmtModalHeader: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        marginBottom: 24,
    },
    mgmtModalTitle: {
        fontSize: 20,
        fontWeight: 'bold',
        color: '#1e293b',
    },
    mgmtGrid: {
        flexDirection: 'row',
        flexWrap: 'wrap',
        justifyContent: 'space-between',
    },
    mgmtGridItem: {
        width: '23%',
        alignItems: 'center',
        marginBottom: 20,
    },
    mgmtIcon: {
        width: 48,
        height: 48,
        borderRadius: 14,
        justifyContent: 'center',
        alignItems: 'center',
        marginBottom: 8,
    },
    mgmtLabel: {
        fontSize: 11,
        color: '#475569',
        fontWeight: '600',
        textAlign: 'center',
    },
    modalOverlay: {
        flex: 1,
        backgroundColor: 'rgba(0,0,0,0.5)',
        justifyContent: 'flex-end',
    },
    filterModalContent: { backgroundColor: '#fff', borderTopLeftRadius: 24, borderTopRightRadius: 24, paddingTop: 20, paddingBottom: 30, maxHeight: '70%' },
    filterOption: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingVertical: 14, paddingHorizontal: 15, borderRadius: 12, marginBottom: 5 },
    filterOptionActive: { backgroundColor: '#eff6ff' },
    filterOptionText: { fontSize: 15, color: '#334155', fontWeight: '500' },
    filterOptionTextActive: { color: '#3b82f6', fontWeight: 'bold' },
    customDateContainer: { marginTop: 10, backgroundColor: '#f8fafc', padding: 15, borderRadius: 12 },
    dateInputRow: { flexDirection: 'row', gap: 10, marginBottom: 15 },
    dateInputWrapper: { flex: 1 },
    dateLabel: { fontSize: 12, color: '#64748b', marginBottom: 5, fontWeight: '500' },
    dateInputBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', backgroundColor: '#fff', borderWidth: 1, borderColor: '#e2e8f0', borderRadius: 8, paddingHorizontal: 12, paddingVertical: 8 },
    dateInputText: { fontSize: 14, color: '#1e293b' },
    applyBtn: { backgroundColor: '#3b82f6', paddingVertical: 10, borderRadius: 8, alignItems: 'center' },
    applyBtnText: { color: '#fff', fontWeight: 'bold', fontSize: 14 },
    calOverlay: { flex: 1, backgroundColor: 'rgba(0,0,0,0.5)', justifyContent: 'center', alignItems: 'center' },
    calContainer: { backgroundColor: '#fff', borderRadius: 16, padding: 20, width: '90%', maxWidth: 360, elevation: 10 },
    calTitle: { fontSize: 16, fontWeight: 'bold', color: '#1e293b', textAlign: 'center', marginBottom: 15 },
    calNav: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 15 },
    calMonthLabel: { fontSize: 16, fontWeight: '700', color: '#1e293b' },
    calDaysHeader: { flexDirection: 'row', justifyContent: 'space-around', marginBottom: 8 },
    calDayLabel: { width: 40, textAlign: 'center', fontSize: 12, fontWeight: '600', color: '#94a3b8' },
    calGrid: { flexDirection: 'row', flexWrap: 'wrap' },
    calCell: { width: '14.28%', aspectRatio: 1, justifyContent: 'center', alignItems: 'center' },
    calCellSelected: { backgroundColor: '#3b82f6', borderRadius: 20 },
    calCellToday: { borderWidth: 1, borderColor: '#3b82f6', borderRadius: 20 },
    calCellText: { fontSize: 14, color: '#1e293b' },
    calCellTextSelected: { color: '#fff', fontWeight: 'bold' },
    calCellTextToday: { color: '#3b82f6', fontWeight: '600' },
    calCancelBtn: { marginTop: 15, paddingVertical: 10, alignItems: 'center' },
    calCancelText: { color: '#64748b', fontSize: 14, fontWeight: '600' },

    // SweetAlert Styles
    alertOverlay: { flex: 1, backgroundColor: 'rgba(0,0,0,0.5)', justifyContent: 'center', alignItems: 'center', padding: 20 },
    alertContent: { backgroundColor: '#fff', width: '100%', maxWidth: 340, borderRadius: 15, overflow: 'hidden', elevation: 10 },
    alertHeader: { flexDirection: 'row', alignItems: 'center', padding: 15, paddingHorizontal: 20 },
    alertHeaderTitle: { color: '#fff', fontSize: 18, fontWeight: 'bold' },
    alertBody: { padding: 25, alignItems: 'center' },
    alertMessage: { fontSize: 16, color: '#334155', textAlign: 'center', marginBottom: 25, lineHeight: 22 },
    alertActionRow: { flexDirection: 'row', gap: 12, width: '100%', justifyContent: 'center' },
    alertButton: { paddingVertical: 10, paddingHorizontal: 25, borderRadius: 25, borderWidth: 1.5, minWidth: 100, alignItems: 'center' },
    alertButtonText: { fontSize: 16, fontWeight: 'bold' },
    alertCancelButton: { borderColor: '#cbd5e1', backgroundColor: '#f8fafc' },
    alertCancelButtonText: { color: '#64748b', fontSize: 15, fontWeight: 'bold' },
    alertConfirmButton: { borderWidth: 0, elevation: 2 },
    alertConfirmButtonText: { color: '#fff', fontSize: 15, fontWeight: 'bold' }
});
