import React, { useState, useEffect, useCallback, useRef } from 'react';
import {
    StyleSheet,
    View,
    Text,
    FlatList,
    TouchableOpacity,
    RefreshControl,
    Image,
    Alert,
    StatusBar,
    SafeAreaView,
    Dimensions,
    ScrollView,
    ActivityIndicator,
    Modal,
    Pressable,
    TextInput,
    Linking,
} from 'react-native';
import axios from 'axios';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { useFocusEffect } from '@react-navigation/native';
import VisitDetailModal from '../components/VisitDetailModal';
import VisitListModal from '../components/VisitListModal';
import { MaterialCommunityIcons as Icon } from '@expo/vector-icons';
import apiClient from '../utils/apiClient';
import { CONFIG } from '../utils/config';
import { checkOverlayPermission } from '../utils/notificationManager';
import { usePermissions } from '../context/PermissionContext';

const { width, height } = Dimensions.get('window');

// Status Colors Constant
const statusColors = {
    'pending': '#f59e0b',
    'approved': '#10b981',
    'rejected': '#ef4444',
    'checked_in': '#3b82f6',
    'checked_out': '#64748b',
    'expired': '#94a3b8',
    'completed': '#64748b'
};

const getStatusColor = (status) => {
    return statusColors[status?.toLowerCase()] || '#64748b';
};

const getPhotoUrl = (path) => {
    if (!path) return `https://ui-avatars.com/api/?name=Visitor&background=random`;
    if (path.startsWith('http')) return path;
    return `${CONFIG.API_BASE_URL}${path}`;
};

export default function HostDashboard({ navigation }) {
    const [userData, setUserData] = useState(null);
    const [activeTab, setActiveTab] = useState('home'); // 'home', 'visitors'
    const [visitorView, setVisitorView] = useState('log'); // 'log', 'invites', 'pending'
    const [pendingVisits, setPendingVisits] = useState([]);
    const [todayVisits, setTodayVisits] = useState([]);
    const [activeInvites, setActiveInvites] = useState([]);
    const [stats, setStats] = useState({ pending: 0, today: 0, invites: 0, completed: 0, avg_time: '0m', scheduled_today: 0 });
    const [aiSuggestion, setAiSuggestion] = useState(null);
    const [refreshing, setRefreshing] = useState(false);
    const [loading, setLoading] = useState(true);
    const [isMenuOpen, setIsMenuOpen] = useState(false);
    const [error, setError] = useState(null);
    const [insights, setInsights] = useState([]);
    const [frequentVisitors, setFrequentVisitors] = useState([]);
    const [selectedVisit, setSelectedVisit] = useState(null);
    const [detailModalVisible, setDetailModalVisible] = useState(false);
    const [detailsLoading, setDetailsLoading] = useState(false);
    const [settingsModalVisible, setSettingsModalVisible] = useState(false);
    const [mastersModalVisible, setMastersModalVisible] = useState(false);

    // SweetAlert Modal State
    const [alertVisible, setAlertVisible] = useState(false);
    const [alertConfig, setAlertConfig] = useState({ title: '', message: '', type: 'success' }); // 'success', 'error', 'warning'

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
            let dateStr = item.created_at || item.visit_date;
            if (!dateStr) return false;
            if (typeof dateStr === 'string' && dateStr.includes(' ')) dateStr = dateStr.replace(' ', 'T');
            const itemDate = new Date(dateStr);
            if (isNaN(itemDate.getTime())) return false;
            return itemDate >= start && itemDate <= end;
        });
    };

    // Generic Data Modal State
    const [dataModalVisible, setDataModalVisible] = useState(false);
    const [dataModalTitle, setDataModalTitle] = useState('');
    const [dataModalType, setDataModalType] = useState(''); // 'pending', 'today', 'invites'
    const [modalSearchTerm, setModalSearchTerm] = useState('');

    const { hasPermission, refreshPermissions } = usePermissions();

    // Initialize visitorView based on permissions
    useEffect(() => {
        if (hasPermission('host_history')) {
            setVisitorView('log');
        } else if (hasPermission('host_invite')) {
            setVisitorView('invites');
        } else if (hasPermission('host_pending')) {
            setVisitorView('pending');
        }
    }, [hasPermission]);

    const notifiedVisitIds = useRef(new Set());

    const toggleMenu = () => setIsMenuOpen(!isMenuOpen);

    useEffect(() => {
        const checkPerms = async () => {
            if (userData?.id) {
                // Wait a bit to ensure UI is ready
                setTimeout(() => checkOverlayPermission(userData.id), 2000);
            }
        };
        checkPerms();
    }, [userData?.id]);

    const fetchData = async () => {
        try {
            const storedUser = await AsyncStorage.getItem('userData');
            if (storedUser) {
                const user = JSON.parse(storedUser);
                if (!user.session_id) {
                    await AsyncStorage.removeItem('userData');
                    navigation.replace('Login');
                    return;
                }
                setUserData(user);

                // Use the same API as Web Dashboard
                const response = await apiClient.get('host/api/get_dashboard_data.php', {
                    timeout: 20000
                });

                const result = response.data;
                if (result.success) {
                    // Alert for new pending visits
                    if (result.pending_list && result.pending_list.length > 0) {
                        const newVisits = result.pending_list.filter(nv => !notifiedVisitIds.current.has(nv.id));

                        // If this is the first load, don't notify for everything, just mark them as "seen"
                        if (loading) {
                            result.pending_list.forEach(v => notifiedVisitIds.current.add(v.id));
                        } else {
                            newVisits.forEach(visit => {
                                notifiedVisitIds.current.add(visit.id);
                                // REMOVED: Alert.alert here because it conflicts with the high-priority full-screen UI
                            });
                        }
                    }

                    setStats({
                        pending: result.pending_count || 0,
                        today: result.today_count || 0,
                        invites: result.invite_count || 0,
                        completed: result.completed_meetings || 0,
                        avg_time: result.avg_meeting_time || '0m',
                        scheduled_today: result.scheduled_today || 0
                    });
                    setPendingVisits(result.pending_list || []);
                    setTodayVisits(result.today_visitors || []);
                    setActiveInvites(result.active_invites || []);

                    if (result.best_time) {
                        setAiSuggestion({ best_slot: result.best_time });
                    } else if (result.ai_suggestion) {
                        setAiSuggestion(typeof result.ai_suggestion === 'string' ? { best_slot: result.ai_suggestion } : result.ai_suggestion);
                    } else {
                        setAiSuggestion({ best_slot: 'Analyzing...' });
                    }

                    setInsights(result.insights || []);
                    setFrequentVisitors(result.frequent_visitors || []);

                    setError(null);
                } else {
                    setError(result.error || 'Failed to load dashboard');
                }
            }
        } catch (error) {
            let errorMessage = 'Unable to connect to server.';
            if (error.response?.status === 404) {
                errorMessage = 'API endpoint not found on server (404).';
            } else if (error.response?.status === 401) {
                // Suppress console error for 401 to avoid Red Box, just redirect
                await AsyncStorage.removeItem('userData');
                navigation.replace('Login');
                return;
            } else {
                console.error('Host Fetch Error:', error);
            }
            setError(errorMessage);
        } finally {
            setLoading(false);
            setRefreshing(false);
        }
    };

    useFocusEffect(
        useCallback(() => {
            fetchData();
            const interval = setInterval(fetchData, 10000);
            return () => clearInterval(interval);
        }, [loading])
    );

    const fetchVisitDetails = async (visitId) => {
        try {
            setDetailsLoading(true);
            const response = await apiClient.get('api/visit/details.php', {
                params: { id: visitId }
            });
            if (response.data.status === 'success') {
                setSelectedVisit(response.data.data);
                setDetailModalVisible(true);
            } else {
                showAlert('Error', response.data.message || 'Could not load details', 'error');
            }
        } catch (err) {
            console.error('Visit Details Error:', err);
            showAlert('Error', 'Could not load visit details', 'error');
        } finally {
            setDetailsLoading(false);
        }
    };

    const showAlert = (title, message, type = 'success', options = {}) => {
        setAlertConfig({ title, message, type, ...options });
        setAlertVisible(true);
    };

    const handleAction = async (visitId, action, mobile, name) => {
        const label = action === 'approve' ? 'Approve' : (action === 'reject' ? 'Reject' : action);
        
        showAlert(
            'Confirm ' + label,
            `Are you sure you want to proceed with ${label} for this visit?`,
            'warning',
            {
                showCancel: true,
                confirmText: 'Yes, Proceed',
                onConfirm: () => executeAction(visitId, action)
            }
        );
    };

    const executeAction = async (visitId, action) => {
        try {
            const response = await apiClient.post('api/visit/status_action.php', {
                action: action,
                visit_id: visitId,
            });

            if (response.data.status === 'success') {
                showAlert('Success', response.data.message, 'success');
                fetchData();
            } else {
                showAlert('Error', response.data.message || 'Action failed', 'error');
            }
        } catch (error) {
            showAlert('Error', 'Action failed', 'error');
        }
    };

    const onRefresh = async () => {
        setRefreshing(true);
        await refreshPermissions(); // Refresh permissions from server
        fetchData();
    };

    const showDataModal = (title, type) => {
        setModalSearchTerm('');
        setDataModalTitle(title);
        setDataModalType(type);
        setDataModalVisible(true);
    };

    const renderVisitCard = (item, type) => {
        const isPending = type === 'pending';
        const isInvite = item.is_invited == 1;
        const status = item.status || 'registered';

        return (
            <TouchableOpacity
                key={item.id}
                style={styles.card}
                onPress={() => fetchVisitDetails(item.id)}
            >
                <View style={styles.cardHeader}>
                    <Image
                        source={{ uri: getPhotoUrl(item.photo_path || item.visit_photo || item.visitor_photo) }}
                        style={styles.avatar}
                        onError={(e) => console.log('Image Load Error')}
                    />
                    <View style={styles.headerInfo}>
                        <Text style={styles.visitorName}>{item.visitor_name}</Text>
                        <Text style={styles.purpose}>{item.purpose || 'General Visit'}</Text>
                        <View style={styles.badgeRow}>
                            {isInvite && (
                                <View style={styles.inviteBadge}>
                                    <Text style={styles.inviteBadgeText}>Invitation</Text>
                                </View>
                            )}
                            <View style={[styles.statusBadge, { backgroundColor: getStatusColor(status) }]}>
                                <Text style={styles.statusBadgeText}>{status.replace('_', ' ').toUpperCase()}</Text>
                            </View>
                        </View>
                        {/* In/Out time row — matches Security & Admin */}
                        <View style={{ flexDirection: 'row', marginTop: 6, gap: 8 }}>
                            {type === 'invites' ? (
                                <Text style={{ fontSize: 11, color: '#64748b', fontWeight: '600' }}>
                                    📅 {item.visit_date ? new Date(item.visit_date).toLocaleDateString([], { day: '2-digit', month: 'short' }) : '-'}
                                </Text>
                            ) : (
                                <>
                                    <Text style={{ fontSize: 11, color: '#3b82f6', fontWeight: '700', backgroundColor: '#eff6ff', paddingHorizontal: 8, paddingVertical: 3, borderRadius: 8 }}>
                                        In: {item.check_in_time ? new Date(item.check_in_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '-'}
                                    </Text>
                                    <Text style={{ fontSize: 11, color: '#64748b', fontWeight: '700', backgroundColor: '#f8fafc', paddingHorizontal: 8, paddingVertical: 3, borderRadius: 8 }}>
                                        Out: {item.check_out_time ? new Date(item.check_out_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '-'}
                                    </Text>
                                </>
                            )}
                        </View>
                    </View>
                    <View style={styles.timeContainer}>
                        <Text style={styles.timeText}>
                            {new Date(item.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                        </Text>
                    </View>
                </View>

                {isPending && (
                    <View style={styles.cardActions}>
                        <TouchableOpacity
                            style={[styles.actionButton, styles.rejectButton]}
                            onPress={(e) => { e.stopPropagation(); handleAction(item.id, 'reject', item.mobile, item.visitor_name); }}
                        >
                            <Icon name="close-circle-outline" size={20} color="#ef4444" style={{ marginRight: 6 }} />
                            <Text style={[styles.actionButtonText, { color: '#ef4444' }]}>REJECT</Text>
                        </TouchableOpacity>
                        <TouchableOpacity
                            style={[styles.actionButton, styles.approveButton]}
                            onPress={(e) => { e.stopPropagation(); handleAction(item.id, 'approve', item.mobile, item.visitor_name); }}
                        >
                            <Icon name="check-circle-outline" size={20} color="#fff" style={{ marginRight: 6 }} />
                            <Text style={[styles.actionButtonText, { color: '#fff' }]}>APPROVE</Text>
                        </TouchableOpacity>
                    </View>
                )}
            </TouchableOpacity>
        );
    };


    const getStatusColor = (status) => {
        switch (status) {
            case 'approved': return '#0d6efd';
            case 'checked_in': return '#198754';
            case 'rejected': return '#dc3545';
            case 'checked_out': return '#212529';
            default: return '#6c757d';
        }
    };

    const renderHome = () => (
        <View style={styles.tabContent}>
            {/* STATS OVERVIEW - 2x2 Grid (Security Style) */}
            <View style={styles.metricsGrid}>
                <TouchableOpacity
                    style={[styles.statCard, styles.statGreen]}
                    onPress={() => showDataModal("Today's Visits", 'today')}
                >
                    <Text style={styles.statValue}>{stats.today || 0}</Text>
                    <Text style={styles.statLabel}>Total Today</Text>
                </TouchableOpacity>

                {hasPermission('host_pending') ? (
                    <TouchableOpacity
                        style={[styles.statCard, styles.statOrange]}
                        onPress={() => showDataModal('Pending Approvals', 'pending')}
                    >
                        <Text style={styles.statValue}>{stats.pending || 0}</Text>
                        <Text style={styles.statLabel}>Pending</Text>
                    </TouchableOpacity>
                ) : <View style={[styles.statCard, styles.statDisabled]} />}
            </View>

            <View style={styles.metricsGrid}>
                {hasPermission('host_invite') ? (
                    <TouchableOpacity
                        style={[styles.statCard, styles.statBlue]}
                        onPress={() => showDataModal('Active Invites', 'invites')}
                    >
                        <Text style={styles.statValue}>{stats.invites || 0}</Text>
                        <Text style={styles.statLabel}>Next Invites</Text>
                    </TouchableOpacity>
                ) : <View style={[styles.statCard, styles.statDisabled]} />}

                <TouchableOpacity
                    style={[styles.statCard, styles.statPurple]}
                    onPress={() => showDataModal('Check-in Pending', 'scheduled')}
                >
                    <Text style={styles.statValue}>{stats.scheduled_today || 0}</Text>
                    <Text style={styles.statLabel}>Check-in Pending</Text>
                </TouchableOpacity>
            </View>

            {/* MY PRODUCTIVITY */}
            {(hasPermission('host_reports') || hasPermission('admin_reports') || hasPermission('view_employee_report')) && (
                <View style={styles.card}>
                    <Text style={styles.cardHeaderTitle}>MY PRODUCTIVITY</Text>
                    <View style={styles.prodGrid}>
                        <View style={styles.prodBox}>
                            <Text style={styles.prodValueBig}>{stats.completed || 0}</Text>
                            <Text style={styles.prodLabelSmall}>TOTAL MEETINGS</Text>
                        </View>
                        <View style={styles.dividerVertical} />
                        <View style={styles.prodBox}>
                            <Text style={styles.prodValueBig}>{stats.avg_time || '0m'}</Text>
                            <Text style={styles.prodLabelSmall}>AVG. TIME</Text>
                        </View>
                    </View>
                </View>
            )}

            {/* Scheduled Summary & Upcoming Invites */}
            {hasPermission('host_invite') && (
                <View style={styles.sectionMobile}>
                    <View style={styles.sectionHeaderMobile}>
                        <View style={{ flexDirection: 'row', alignItems: 'center' }}>
                            <Icon name="calendar-star" size={22} color="#3b82f6" />
                            <Text style={[styles.sectionTitleMobile, { marginLeft: 10 }]}>Upcoming Invites</Text>
                        </View>
                        <TouchableOpacity onPress={() => setActiveTab('invites')}>
                            <Text style={styles.seeAllMobile}>VIEW ALL</Text>
                        </TouchableOpacity>
                    </View>
                    <View style={styles.activityListCont}>
                        {activeInvites.length === 0 ? (
                            <View style={styles.miniEmptyWeb}>
                                <Text style={{ color: '#94a3b8' }}>No scheduled invites.</Text>
                            </View>
                        ) : (
                            activeInvites.slice(0, 3).map(item => renderVisitCard(item, 'invites'))
                        )}
                    </View>
                </View>
            )}

            {/* Recent Activity */}
            {hasPermission('host_history') && (
                <View style={styles.sectionMobile}>
                    <View style={styles.sectionHeaderMobile}>
                        <View style={{ flexDirection: 'row', alignItems: 'center' }}>
                            <Icon name="clock-outline" size={22} color="#3b82f6" />
                            <Text style={[styles.sectionTitleMobile, { marginLeft: 10 }]}>Recent Activity</Text>
                        </View>
                        <TouchableOpacity onPress={() => setActiveTab('visitors')}>
                            <Text style={styles.seeAllMobile}>SEE ALL</Text>
                        </TouchableOpacity>
                    </View>
                    <View style={styles.activityListCont}>
                        {todayVisits.length === 0 ? (
                            <Text style={styles.emptyTableTextWeb}>No activity recorded today.</Text>
                        ) : (
                            todayVisits.slice(0, 3).map(item => renderVisitCard(item, 'today'))
                        )}
                    </View>
                </View>
            )}

            {/* VISITOR INSIGHTS */}
            {hasPermission(['admin_reports', 'view_employee_report']) && (
                <View style={styles.card}>
                    <Text style={styles.cardHeaderTitle}>VISITOR INSIGHTS</Text>
                    <View style={styles.insightRow}>
                        <View style={styles.chartMockContainer}>
                            {/* Simulate a multi-color donut */}
                            <View style={[styles.chartSegment, { borderColor: '#3b82f6', borderTopColor: 'transparent', borderLeftColor: 'transparent', transform: [{ rotate: '45deg' }] }]} />
                            <View style={[styles.chartSegment, { borderColor: '#10b981', borderTopColor: 'transparent', borderRightColor: 'transparent', transform: [{ rotate: '-45deg' }] }]} />
                            <View style={[styles.chartSegment, { borderColor: '#f59e0b', borderBottomColor: 'transparent', borderLeftColor: 'transparent', transform: [{ rotate: '-45deg' }] }]} />
                            <View style={[styles.chartSegment, { borderColor: '#8b5cf6', borderBottomColor: 'transparent', borderRightColor: 'transparent', transform: [{ rotate: '45deg' }] }]} />
                            <Icon name="chart-donut" size={30} color="#3b82f6" style={styles.chartCenterIcon} />
                        </View>
                        <View style={styles.insightMainText}>
                            <Text style={styles.insightHighlight}>Distribution</Text>
                            <Text style={styles.insightSubText}>
                                Overall visit distribution by category and purpose code.
                            </Text>
                        </View>
                    </View>
                    <View style={styles.insightBreakdown}>
                        {(insights.length > 0 ? insights : [{ purpose: 'General', count: 0 }]).slice(0, 4).map((item, index) => (
                            <View key={index} style={styles.insightBreakdownItem}>
                                <View style={[styles.insightDot, { backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6'][index % 4] }]} />
                                <Text style={styles.insightBreakdownText}>{item.purpose}: <Text style={{ fontWeight: '900', color: '#1e293b' }}>{item.count}</Text></Text>
                            </View>
                        ))}
                    </View>
                </View>
            )}

            {/* AI SMART SCHEDULER */}
            {hasPermission('host_invite') && (
                <View style={[styles.card, styles.aiSchedulerCard]}>
                    <View style={styles.aiHeader}>
                        <Text style={styles.aiTitleWeb}>AI SMART SCHEDULER</Text>
                        <Icon name="lightbulb-outline" size={24} color="rgba(255,255,255,0.8)" />
                    </View>
                    <Text style={styles.aiValueBig}>Best Slot: {aiSuggestion?.best_slot || 'Analyzing...'}</Text>
                    <Text style={styles.aiDescSmall}>
                        Based on reception traffic, your visitors will experience the fastest check-in at {aiSuggestion?.best_slot || 'this time'} today.
                    </Text>
                    <TouchableOpacity
                        style={{ backgroundColor: '#fff', alignSelf: 'flex-start', paddingHorizontal: 20, paddingVertical: 10, borderRadius: 12, marginTop: 15, flexDirection: 'row', alignItems: 'center' }}
                        onPress={() => navigation.navigate('InviteVisitor')}
                    >
                        <Icon name="calendar-plus" size={18} color="#10b981" />
                        <Text style={{ color: '#10b981', fontWeight: '900', marginLeft: 8 }}>Schedule Now</Text>
                    </TouchableOpacity>
                </View>
            )}

            {/* Frequent Visitors */}
            {hasPermission('host_history') && (
                <View style={styles.sectionMobile}>
                    <View style={styles.sectionHeaderMobile}>
                        <View style={{ flexDirection: 'row', alignItems: 'center' }}>
                            <Icon name="account-group" size={22} color="#3b82f6" />
                            <Text style={[styles.sectionTitleMobile, { marginLeft: 10 }]}>Frequent Visitors</Text>
                        </View>
                    </View>
                    {frequentVisitors.length > 0 ? (
                        <View style={styles.rankingList}>
                            {frequentVisitors.slice(0, 5).map((fv, idx) => (
                                <TouchableOpacity key={idx} style={styles.rankRow}>
                                    <View style={styles.rankNumberContainer}>
                                        {idx === 0 ? (
                                            <Icon name="medal" size={24} color="#f59e0b" />
                                        ) : (
                                            <Text style={styles.rankNumber}>{idx + 1}</Text>
                                        )}
                                    </View>
                                    <Image
                                        source={{ uri: getPhotoUrl(fv.photo_path) }}
                                        style={styles.rankAvatar}
                                    />
                                    <View style={styles.rankInfo}>
                                        <View style={styles.rankNameRow}>
                                            <Text style={styles.rankName}>{fv.name}</Text>
                                            <View style={styles.rankBadge}>
                                                <Text style={styles.rankBadgeText}>{fv.visit_count} Visits</Text>
                                            </View>
                                        </View>
                                        <View style={styles.rankBarContainer}>
                                            <View style={[styles.rankBarFill, { width: `${Math.min(100, (fv.visit_count / (frequentVisitors[0]?.visit_count || 1)) * 100)}%` }]} />
                                        </View>
                                    </View>
                                </TouchableOpacity>
                            ))}
                        </View>
                    ) : (
                        <View style={styles.miniEmptyWeb}>
                            <Text style={{ color: '#94a3b8', fontWeight: '600' }}>No recurring visitors yet.</Text>
                        </View>
                    )}
                </View>
            )}
        </View>
    );

    const renderVisitors = () => {
        // Determine available tabs based on permissions
        const showPending = hasPermission('host_pending') && pendingVisits.length > 0;
        const showInvites = hasPermission('host_invite');

        // Auto-switch to pending if it's the only thing or high priority? 
        // No, user wants "actual pages". Default to 'log' (History/Today).

        return (
            <View style={styles.tabContent}>
                {/* Segmented Control */}
                <View style={styles.segmentContainer}>
                    {hasPermission('host_history') && (
                        <TouchableOpacity
                            style={[styles.segmentBtn, visitorView === 'log' && styles.segmentBtnActive]}
                            onPress={() => setVisitorView('log')}
                        >
                            <Text style={[styles.segmentText, visitorView === 'log' && styles.segmentTextActive]}>Visitor Log</Text>
                        </TouchableOpacity>
                    )}

                    {showInvites && (
                        <TouchableOpacity
                            style={[styles.segmentBtn, visitorView === 'invites' && styles.segmentBtnActive]}
                            onPress={() => setVisitorView('invites')}
                        >
                            <Text style={[styles.segmentText, visitorView === 'invites' && styles.segmentTextActive]}>Invitations</Text>
                        </TouchableOpacity>
                    )}

                    {showPending && (
                        <TouchableOpacity
                            style={[styles.segmentBtn, visitorView === 'pending' && styles.segmentBtnActive]}
                            onPress={() => setVisitorView('pending')}
                        >
                            <Text style={[styles.segmentText, visitorView === 'pending' && styles.segmentTextActive]}>
                                Pending ({pendingVisits.length})
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

                {visitorView === 'log' && hasPermission('host_history') && (
                    <>
                        <View style={styles.sectionHeaderRow}>
                            <Text style={styles.sectionTitle}>Visitor Log</Text>
                        </View>
                        {applyDateFilter(todayVisits).length === 0 ? (
                            <View style={styles.emptyContainer}>
                                <Icon name="account-off" size={60} color="#cbd5e1" />
                                <Text style={styles.emptyTitle}>No Visitors Found</Text>
                                <Text style={styles.emptyText}>No visitors found for the selected period.</Text>
                            </View>
                        ) : (
                            applyDateFilter(todayVisits).map(item => renderVisitCard(item, 'today'))
                        )}
                    </>
                )}

                {visitorView === 'invites' && showInvites && (
                    <>
                        <View style={styles.sectionHeaderRow}>
                            <Text style={styles.sectionTitle}>Active Invitations</Text>
                            <TouchableOpacity style={styles.btnSmall} onPress={() => navigation.navigate('InviteVisitor')}>
                                <Icon name="plus" size={16} color="#fff" />
                                <Text style={styles.btnSmallText}>New Invite</Text>
                            </TouchableOpacity>
                        </View>
                        {applyDateFilter(activeInvites).length === 0 ? (
                            <View style={styles.emptyContainer}>
                                <Icon name="calendar-blank" size={60} color="#cbd5e1" />
                                <Text style={styles.emptyTitle}>No Active Invites</Text>
                                <Text style={styles.emptyText}>No invitations found for the selected period.</Text>
                            </View>
                        ) : (
                            applyDateFilter(activeInvites).map(item => renderVisitCard(item, 'invites'))
                        )}
                    </>
                )}

                {visitorView === 'pending' && showPending && (
                    <View style={[styles.section, { marginBottom: 20 }]}>
                        <Text style={[styles.sectionTitle, { color: '#f59e0b' }]}>Pending Requests</Text>
                        {applyDateFilter(pendingVisits).map(item => renderVisitCard(item, 'pending'))}
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
                                        <TouchableOpacity style={styles.applyBtn} onPress={() => { if (!tempStartDate) { Alert.alert('Error', 'Please select a start date'); return; } setFilterStartDate(tempStartDate); setFilterEndDate(tempEndDate || tempStartDate); setFilterType('custom'); setFilterModalVisible(false); }}>
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
                                        setAlertVisible(false);
                                        if (alertConfig.onConfirm) alertConfig.onConfirm();
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

                        {(hasPermission('admin_audit') || hasPermission('admin_reports')) && (
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

                        {(hasPermission('admin_reports')) && (
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

                        {(hasPermission('view_employee_report') || hasPermission('admin_reports')) && (
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
                <Icon name="view-dashboard" size={24} color={activeTab === 'home' ? "#3b82f6" : "#64748b"} />
                <Text style={[styles.tabLabel, activeTab === 'home' && { color: '#3b82f6' }]}>Home</Text>
            </TouchableOpacity>

            {(hasPermission('host_history') || hasPermission('host_pending') || hasPermission('host_invite')) && (
                <TouchableOpacity style={styles.tabItem} onPress={() => setActiveTab('visitors')}>
                    <Icon name="account-group" size={24} color={activeTab === 'visitors' ? "#3b82f6" : "#64748b"} />
                    <Text style={[styles.tabLabel, activeTab === 'visitors' && { color: '#3b82f6' }]}>Visitors</Text>
                </TouchableOpacity>
            )}

            <View style={{ position: 'relative', top: -25 }}>
                <TouchableOpacity
                    style={styles.fabMainButton}
                    onPress={() => setIsMenuOpen(!isMenuOpen)}
                >
                    <Icon name={isMenuOpen ? "close" : "plus"} size={32} color="#fff" />
                </TouchableOpacity>
            </View>

            {(hasPermission('admin_employees') || hasPermission('admin_users') || hasPermission('admin_audit') || hasPermission('admin_reports') || hasPermission('view_employee_report')) && (
                <TouchableOpacity style={styles.tabItem} onPress={() => setMastersModalVisible(true)}>
                    <Icon name="grid" size={24} color="#64748b" />
                    <Text style={styles.tabLabel}>Manage</Text>
                </TouchableOpacity>
            )}

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
                        {hasPermission('host_invite') && (
                            <TouchableOpacity style={styles.fabSubButton} onPress={() => { setIsMenuOpen(false); navigation.navigate('InviteVisitor'); }}>
                                <Text style={styles.fabLabel}>Invite</Text>
                                <View style={[styles.fabIconWrapper, { backgroundColor: '#8b5cf6' }]}><Icon name="email-send" size={20} color="#fff" /></View>
                            </TouchableOpacity>
                        )}
                        {hasPermission('security_register') && (
                            <TouchableOpacity style={styles.fabSubButton} onPress={() => { setIsMenuOpen(false); navigation.navigate('RegisterVisitor'); }}>
                                <Text style={styles.fabLabel}>New Visit</Text>
                                <View style={[styles.fabIconWrapper, { backgroundColor: '#10b981' }]}><Icon name="account-plus" size={20} color="#fff" /></View>
                            </TouchableOpacity>
                        )}
                    </View>
                </View>
            </>
        );
    };

    const renderVisitDetailsModal = () => (
        <VisitDetailModal
            visible={detailModalVisible}
            onClose={() => setDetailModalVisible(false)}
            visit={selectedVisit}
            onAction={(id, action) => {
                handleAction(id, action);
                setDetailModalVisible(false);
            }}
        />
    );

    if (loading && !refreshing) {
        return (
            <View style={styles.loadingContainer}>
                <ActivityIndicator size="large" color="#3b82f6" />
                <Text style={styles.loadingText}>Synchronizing Dashboard...</Text>
            </View>
        );
    }

    return (
        <SafeAreaView style={styles.container}>
            <StatusBar barStyle="dark-content" />
            <View style={styles.header}>
                <View style={{ flex: 1 }}>
                    <Text style={styles.greeting}>Host Portal</Text>
                    <Text style={styles.userName}>{userData?.full_name || 'Host User'}</Text>
                </View>
                <TouchableOpacity style={styles.logoutBtn} onPress={async () => { await AsyncStorage.clear(); navigation.replace('Login'); }}>
                    <Text style={styles.logoutText}>Logout</Text>
                </TouchableOpacity>
            </View>
            <ScrollView
                style={{ flex: 1 }}
                contentContainerStyle={{ paddingBottom: 150 }}
                showsVerticalScrollIndicator={false}
                refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} colors={['#3b82f6']} />}
            >
                {activeTab === 'home' && renderHome()}
                {activeTab === 'visitors' && renderVisitors()}
                {activeTab === 'invites' && renderInvites()}
            </ScrollView>

            <VisitListModal
                visible={dataModalVisible}
                onClose={() => setDataModalVisible(false)}
                title={dataModalTitle}
                color={dataModalType === 'pending' ? '#f59e0b' : (dataModalType === 'invites' ? '#3b82f6' : (dataModalType === 'scheduled' ? '#8b5cf6' : '#10b981'))}
                visits={(() => {
                    if (dataModalType === 'pending') return pendingVisits;
                    if (dataModalType === 'today') return todayVisits;
                    if (dataModalType === 'invites') return activeInvites;
                    if (dataModalType === 'scheduled') {
                        const d = new Date();
                        const todayStr = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
                        return activeInvites.filter(item => item.visit_date === todayStr);
                    }
                    return [];
                })()}
                onVisitPress={(visit) => fetchVisitDetails(visit.id)}
            />

            {renderBottomMenu()}
            {renderFloatingMenu()}
            {renderVisitDetailsModal()}
            {renderMastersModal()}
            {renderSettingsModal()}
            {renderSweetAlert()}
        </SafeAreaView >
    );
}

const styles = StyleSheet.create({
    container: { flex: 1, backgroundColor: '#f8fafc' },
    loadingContainer: { flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: '#f8fafc' },
    loadingText: { marginTop: 15, color: '#64748b', fontWeight: '500' },
    header: { flexDirection: 'row', padding: 20, backgroundColor: '#fff', alignItems: 'center', borderBottomWidth: 1, borderBottomColor: '#f1f5f9' },
    greeting: { fontSize: 13, color: '#64748b', fontWeight: '500' },
    userName: { fontSize: 22, fontWeight: '800', color: '#1e293b' },
    logoutBtn: { backgroundColor: '#fee2e2', paddingHorizontal: 12, paddingVertical: 6, borderRadius: 20 },
    logoutText: { color: '#ef4444', fontWeight: '700', fontSize: 13 },
    errorBanner: { backgroundColor: '#ef4444', flexDirection: 'row', alignItems: 'center', padding: 12, margin: 15, borderRadius: 12, gap: 10 },
    errorText: { color: '#fff', flex: 1, fontSize: 13, fontWeight: '600' },
    retryText: { color: '#fff', fontWeight: '800', fontSize: 13, textDecorationLine: 'underline' },
    retryText: { color: '#fff', fontWeight: '800', fontSize: 13, textDecorationLine: 'underline' },
    tabContent: { padding: 15 },

    // STATS GRID (Aliagned with Security Dashboard)
    metricsGrid: { flexDirection: 'row', gap: 10, marginBottom: 15 },
    statCard: { flex: 1, padding: 20, borderRadius: 20, alignItems: 'center', justifyContent: 'center', elevation: 4, shadowColor: '#000', shadowOffset: { width: 0, height: 3 }, shadowOpacity: 0.12, shadowRadius: 6 },
    statOrange: { backgroundColor: '#f59e0b' },
    statGreen: { backgroundColor: '#10b981' },
    statBlue: { backgroundColor: '#3b82f6' },
    statPurple: { backgroundColor: '#8b5cf6' },
    statValue: { fontSize: 26, fontWeight: '800', color: '#fff' },
    statLabel: { fontSize: 12, color: 'rgba(255,255,255,0.95)', fontWeight: '700', marginTop: 4 },

    aiCard: { backgroundColor: '#10b981', padding: 18, borderRadius: 20, marginBottom: 20, elevation: 4, shadowColor: '#10b981', shadowOffset: { width: 0, height: 4 }, shadowOpacity: 0.2, shadowRadius: 8 },
    aiHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 10 },
    aiTitle: { color: 'rgba(255,255,255,0.9)', fontSize: 12, fontWeight: '800', textTransform: 'uppercase', letterSpacing: 1 },
    aiText: { color: '#fff', fontSize: 15, lineHeight: 22 },
    aiHighlight: { fontWeight: '900', fontSize: 17 },
    metricsGrid: { flexDirection: 'row', gap: 10, marginBottom: 20 },
    metricCard: { flex: 1, padding: 20, borderRadius: 20, alignItems: 'center', elevation: 2, shadowColor: '#000', shadowOffset: { width: 0, height: 2 }, shadowOpacity: 0.1, shadowRadius: 4 },
    metricValue: { fontSize: 26, fontWeight: '800', color: '#fff' },
    metricLabel: { fontSize: 12, color: 'rgba(255,255,255,0.9)', marginTop: 4, fontWeight: '600' },
    productCard: { backgroundColor: '#f1f5f9', padding: 18, borderRadius: 20, marginBottom: 25, borderWidth: 1, borderColor: '#e2e8f0' },
    sectionTitleSmall: { fontSize: 11, fontWeight: '800', color: '#64748b', marginBottom: 15, letterSpacing: 1.5 },
    productRow: { flexDirection: 'row', justifyContent: 'space-around' },
    productItem: { alignItems: 'center' },
    productValue: { fontSize: 22, fontWeight: '800', color: '#1e293b' },
    productLabel: { fontSize: 12, color: '#64748b', fontWeight: '500', marginTop: 4 },
    section: { marginBottom: 25 },
    sectionHeaderRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 15 },
    sectionTitle: { fontSize: 19, fontWeight: '800', color: '#1e293b' },
    seeAllText: { color: '#3b82f6', fontWeight: '700', fontSize: 14 },
    btnSmall: { backgroundColor: '#3b82f6', flexDirection: 'row', alignItems: 'center', paddingHorizontal: 12, paddingVertical: 6, borderRadius: 15 },
    btnSmallText: { color: '#fff', fontSize: 12, fontWeight: '700', marginLeft: 4 },
    card: { backgroundColor: '#fff', borderRadius: 20, padding: 16, marginBottom: 15, elevation: 1, shadowColor: '#000', shadowOffset: { width: 0, height: 1 }, shadowOpacity: 0.05, shadowRadius: 2, borderWidth: 1, borderColor: '#f1f5f9' },
    cardHeader: { flexDirection: 'row', alignItems: 'center' },
    avatar: { width: 50, height: 50, borderRadius: 25, backgroundColor: '#f1f5f9' },
    headerInfo: { flex: 1, marginLeft: 15 },
    visitorName: { fontSize: 17, fontWeight: '700', color: '#1e293b' },
    purpose: { fontSize: 13, color: '#64748b' },
    badgeRow: { flexDirection: 'row', marginTop: 6, gap: 6 },
    inviteBadge: { backgroundColor: '#eef2ff', paddingHorizontal: 8, paddingVertical: 3, borderRadius: 6 },
    inviteBadgeText: { fontSize: 10, color: '#4f46e5', fontWeight: '800' },
    statusBadge: { paddingHorizontal: 8, paddingVertical: 3, borderRadius: 6 },
    statusBadgeText: { fontSize: 10, color: '#fff', fontWeight: '800' },
    timeContainer: { backgroundColor: '#f8fafc', paddingHorizontal: 10, paddingVertical: 5, borderRadius: 10 },
    timeText: { fontSize: 11, color: '#64748b', fontWeight: '700' },
    cardActions: { flexDirection: 'row', gap: 15, marginTop: 15, borderTopWidth: 1, borderTopColor: '#f1f5f9', paddingTop: 15 },
    // ACTIONS
    actionButton: { flex: 1, flexDirection: 'row', justifyContent: 'center', alignItems: 'center', paddingVertical: 12, borderRadius: 12 },
    rejectButton: { backgroundColor: '#fee2e2', borderWidth: 0 },
    approveButton: { backgroundColor: '#10b981', elevation: 2, shadowColor: '#10b981', shadowOffset: { width: 0, height: 2 }, shadowOpacity: 0.2, shadowRadius: 3 },
    actionButtonText: { fontWeight: '800', fontSize: 13, letterSpacing: 0.5 },

    // MODAL STYLES (Admin Dashboard Patterns)
    modalOverlay: { flex: 1, backgroundColor: 'rgba(0,0,0,0.5)', justifyContent: 'flex-end' },
    detailsModalView: { width: '100%', height: '90%', backgroundColor: '#f8fafc', borderTopLeftRadius: 25, borderTopRightRadius: 25, marginTop: 'auto' },
    detailsContainer: { flex: 1 },
    modalHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', padding: 20, borderBottomWidth: 1, borderBottomColor: '#f1f5f9', backgroundColor: '#fff', borderTopLeftRadius: 25, borderTopRightRadius: 25 },
    modalTitle: { fontSize: 18, fontWeight: '800', color: '#1e293b' },
    closeBtn: { fontSize: 20, color: '#64748b', padding: 5 },
    detailsHeader: { flexDirection: 'row', padding: 20, backgroundColor: '#3b82f6', alignItems: 'center' },

    photoContainer: { width: 85, height: 85, borderRadius: 43, backgroundColor: 'rgba(255,255,255,0.2)', justifyContent: 'center', alignItems: 'center', marginRight: 20, borderWidth: 3, borderColor: 'rgba(255,255,255,0.5)' },
    photoPlaceholder: { fontSize: 40 },
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

    // EMPTY STATES
    emptyContainer: { paddingVertical: 60, alignItems: 'center', justifyContent: 'center' },
    emptyTitle: { fontSize: 22, fontWeight: '900', color: '#1e293b', marginTop: 20 },
    emptyText: { color: '#64748b', fontSize: 15, textAlign: 'center', marginTop: 12, lineHeight: 24, paddingHorizontal: 40 },

    // NAVIGATION
    bottomTabBar: { position: 'absolute', bottom: 0, left: 0, right: 0, height: 85, backgroundColor: '#fff', flexDirection: 'row', justifyContent: 'space-around', alignItems: 'center', borderTopWidth: 1, borderTopColor: '#f1f5f9', paddingBottom: 25 },
    tabItem: { alignItems: 'center', paddingHorizontal: 20 },
    tabLabel: { fontSize: 11, color: '#94a3b8', marginTop: 6, fontWeight: '700' },

    // FAB
    fabContainer: {
        position: 'absolute',
        bottom: 110,
        right: 20,
        alignItems: 'flex-end',
        zIndex: 10000,
    },
    fabMainButton: {
        width: 64,
        height: 64,
        borderRadius: 32,
        backgroundColor: '#3b82f6',
        justifyContent: 'center',
        alignItems: 'center',
        elevation: 30,
        shadowColor: '#3b82f6',
        shadowOffset: { width: 0, height: 4 },
        shadowOpacity: 0.3,
        shadowRadius: 6,
    },
    fabMainButtonActive: {
        backgroundColor: '#1e293b',
        transform: [{ rotate: '0deg' }],
    },
    fabActions: {
        position: 'absolute',
        bottom: 70,
        alignItems: 'center',
        width: 150,
    },
    fabSubButton: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'flex-end',
        marginBottom: 16,
        width: '100%',
    },
    fabLabel: {
        backgroundColor: '#1e293b',
        color: '#fff',
        paddingHorizontal: 10,
        paddingVertical: 4,
        borderRadius: 6,
        fontSize: 12,
        fontWeight: '600',
        marginRight: 10,
        overflow: 'hidden',
    },
    fabIconWrapper: {
        width: 40,
        height: 40,
        borderRadius: 20,
        justifyContent: 'center',
        alignItems: 'center',
        elevation: 4,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.2,
        shadowRadius: 3,
    },
    fabOverlay: {
        ...StyleSheet.absoluteFillObject,
        backgroundColor: 'rgba(15, 23, 42, 0.5)',
        zIndex: 99,
    },

    card: { backgroundColor: '#fff', borderRadius: 20, padding: 20, marginBottom: 18, elevation: 3, shadowColor: '#000', shadowOffset: { width: 0, height: 3 }, shadowOpacity: 0.08, shadowRadius: 8 },
    aiSchedulerCard: { backgroundColor: '#10b981' },
    aiHeader: { flexDirection: 'row', alignItems: 'center', marginBottom: 15, gap: 10 },
    aiTitleWeb: { color: 'rgba(255,255,255,0.85)', fontSize: 11, fontWeight: '900', letterSpacing: 1.5 },
    aiValueBig: { color: '#fff', fontSize: 22, fontWeight: '900' },
    aiDescSmall: { color: 'rgba(255,255,255,0.95)', fontSize: 13, marginTop: 10, lineHeight: 20 },

    cardHeaderTitle: { fontSize: 11, fontWeight: '900', color: '#94a3b8', textTransform: 'uppercase', letterSpacing: 1.5, marginBottom: 18 },
    insightRow: { flexDirection: 'row', alignItems: 'center' },
    prodGrid: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingVertical: 10 },
    prodBox: { flex: 1, alignItems: 'center' },
    prodValueBig: { fontSize: 26, fontWeight: '900', color: '#1e293b' },
    prodLabelSmall: { fontSize: 10, color: '#94a3b8', fontWeight: '800', marginTop: 5 },
    dividerVertical: { width: 1, height: 40, backgroundColor: '#f1f5f9' },
    sectionMobile: { marginBottom: 25 },
    sectionHeaderMobile: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 15 },
    sectionTitleMobile: { fontSize: 18, fontWeight: '800', color: '#1e293b' },
    seeAllMobile: { fontSize: 12, fontWeight: '900', color: '#3b82f6', letterSpacing: 0.5 },
    activityListCont: { gap: 0 },
    emptyTableTextWeb: { textAlign: 'center', color: '#94a3b8', padding: 20, fontStyle: 'italic' },
    doughnutPlaceholder: { width: 64, height: 64, borderRadius: 32, backgroundColor: '#eff6ff', justifyContent: 'center', alignItems: 'center', marginRight: 20 },
    insightMainText: { flex: 1 },
    insightHighlight: { fontSize: 18, fontWeight: '900', color: '#1e293b' },
    insightSubText: { fontSize: 13, color: '#64748b', marginTop: 4 },
    frequentCircleCard: { alignItems: 'center', marginRight: 20, width: 80 },
    frequentCircleAvatar: { width: 60, height: 60, borderRadius: 30, backgroundColor: '#f1f5f9', marginBottom: 8 },
    frequentCircleName: { fontSize: 12, fontWeight: '700', color: '#1e293b', textAlign: 'center' },
    frequentCircleCount: { fontSize: 10, color: '#64748b', marginTop: 2 },
    miniEmptyWeb: { padding: 20, alignItems: 'center', backgroundColor: '#fff', borderRadius: 15, borderWidth: 1, borderColor: '#f1f5f9', borderStyle: 'dashed' },
    doughnutPlaceholder: { width: 64, height: 64, borderRadius: 32, backgroundColor: '#f8fafc', justifyContent: 'center', alignItems: 'center', borderWidth: 2, borderColor: '#eff6ff' },
    insightMainText: { marginLeft: 18, flex: 1 },
    insightHighlight: { fontSize: 16, fontWeight: '900', color: '#1e293b' },
    insightSubText: { fontSize: 13, color: '#64748b', marginTop: 4 },
    insightBreakdown: {
        marginTop: 15,
        paddingTop: 15,
        borderTopWidth: 1,
        borderTopColor: '#f1f5f9',
        flexDirection: 'row',
        flexWrap: 'wrap',
        gap: 12
    },
    insightBreakdownItem: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: '#f8fafc',
        paddingHorizontal: 10,
        paddingVertical: 6,
        borderRadius: 10,
    },
    insightDot: {
        width: 8,
        height: 8,
        borderRadius: 4,
        marginRight: 6
    },
    insightBreakdownText: {
        fontSize: 11,
        color: '#64748b',
        fontWeight: '700'
    },

    prodGrid: { flexDirection: 'row', alignItems: 'center', paddingTop: 8 },
    prodBox: { flex: 1, alignItems: 'center' },
    prodValueBig: { fontSize: 26, fontWeight: '900', color: '#1e293b' },
    prodLabelSmall: { fontSize: 10, color: '#94a3b8', fontWeight: '800', marginTop: 6 },
    dividerVertical: { width: 1, height: 50, backgroundColor: '#f1f5f9' },

    sectionMobile: { marginBottom: 25 },
    sectionHeaderMobile: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 15 },
    sectionTitleMobile: { fontSize: 20, fontWeight: '900', color: '#1e293b' },
    seeAllMobile: { fontSize: 13, color: '#3b82f6', fontWeight: '900' },
    createBtnMobile: { fontSize: 13, color: '#10b981', fontWeight: '900' },

    chartMockContainer: { width: 70, height: 70, position: 'relative', justifyContent: 'center', alignItems: 'center', marginRight: 20 },
    chartSegment: { position: 'absolute', width: '100%', height: '100%', borderRadius: 35, borderWidth: 6, borderColor: 'transparent' },
    chartCenterIcon: { zIndex: 10 },

    rankingList: { backgroundColor: '#fff', borderRadius: 24, padding: 10, elevation: 2, shadowColor: '#000', shadowOffset: { width: 0, height: 2 }, shadowOpacity: 0.05, shadowRadius: 5 },
    rankRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 12, paddingHorizontal: 10, borderBottomWidth: 1, borderBottomColor: '#f8fafc' },
    rankNumberContainer: { width: 30, alignItems: 'center' },
    rankNumber: { fontSize: 16, fontWeight: '800', color: '#94a3b8' },
    rankAvatar: { width: 44, height: 44, borderRadius: 22, backgroundColor: '#f1f5f9', marginLeft: 10 },
    rankInfo: { flex: 1, marginLeft: 15 },
    rankNameRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 },
    rankName: { fontSize: 14, fontWeight: '700', color: '#1e293b' },
    rankBadge: { backgroundColor: '#eff6ff', paddingHorizontal: 8, paddingVertical: 3, borderRadius: 8 },
    rankBadgeText: { fontSize: 10, color: '#3b82f6', fontWeight: '800' },
    rankBarContainer: { height: 4, backgroundColor: '#f1f5f9', borderRadius: 2, overflow: 'hidden' },
    rankBarFill: { height: '100%', backgroundColor: '#3b82f6', borderRadius: 2 },

    // Segmented Control Styles
    segmentContainer: { flexDirection: 'row', backgroundColor: '#e2e8f0', borderRadius: 12, padding: 4, marginBottom: 20 },
    segmentBtn: { flex: 1, paddingVertical: 8, alignItems: 'center', borderRadius: 10 },
    segmentBtnActive: { backgroundColor: '#fff', elevation: 2, shadowColor: '#000', shadowOffset: { width: 0, height: 1 }, shadowOpacity: 0.1, shadowRadius: 1 },
    segmentText: { fontSize: 13, fontWeight: '600', color: '#64748b' },
    segmentTextActive: { color: '#3b82f6', fontWeight: '700' },

    miniEmptyWeb: { padding: 30, backgroundColor: '#f8fafc', borderRadius: 24, alignItems: 'center', borderStyle: 'dashed', borderWidth: 2, borderColor: '#e2e8f0' },
    activityListCont: { backgroundColor: '#fff', borderRadius: 24, paddingVertical: 5, overflow: 'hidden', elevation: 2, shadowColor: '#000', shadowOffset: { width: 0, height: 2 }, shadowOpacity: 0.05, shadowRadius: 4 },
    emptyTableTextWeb: { padding: 40, textAlign: 'center', color: '#94a3b8', fontWeight: '500' },

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
