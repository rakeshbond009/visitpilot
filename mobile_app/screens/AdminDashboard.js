import React, { useState, useCallback, useEffect } from 'react';
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
    Linking,
    TextInput,
    Platform
} from 'react-native';
import axios from 'axios';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { useFocusEffect } from '@react-navigation/native';
import { MaterialCommunityIcons as Icon } from '@expo/vector-icons';
import apiClient from '../utils/apiClient';
import { CONFIG } from '../utils/config';
import { usePermissions } from '../context/PermissionContext';
import VisitDetailModal from '../components/VisitDetailModal';
import VisitListModal from '../components/VisitListModal';


import { checkOverlayPermission } from '../utils/notificationManager';

const { width, height } = Dimensions.get('window');

export default function AdminDashboard({ navigation }) {
    const { refreshPermissions } = usePermissions();
    const [userData, setUserData] = useState(null);
    const [activeTab, setActiveTab] = useState('home'); // 'home', 'visitors'
    const [visitorView, setVisitorView] = useState('log'); // 'log', 'invites', 'pending'

    // Visitor Lists
    const [pendingVisits, setPendingVisits] = useState([]);
    const [todayVisits, setTodayVisits] = useState([]);
    const [activeInvites, setActiveInvites] = useState([]);

    const [stats, setStats] = useState({
        total_employees: 0,
        total_visits: 0,
        today_visits: 0,
        time_saved: '0 mins',
    });
    const [trends, setTrends] = useState({ labels: [], data: [] });
    const [aiInsights, setAiInsights] = useState({
        prediction_tomorrow: 0,
        crowd_density: 0,
        active_visitors: 0,
        overstay_count: 0,
        overstay_list: []
    });
    const [efficiency, setEfficiency] = useState({
        avg_checkin_time: '0 mins',
        peak_hour: 'N/A',
        total_time_saved: '0 mins',
        satisfaction: '0%'
    });
    const [recentActivity, setRecentActivity] = useState([]);
    const [mostVisitedHosts, setMostVisitedHosts] = useState([]);
    const [zones, setZones] = useState({ department: [], access_area: [] });
    const [records, setRecords] = useState({ employees: [], visits: [] });
    const [refreshing, setRefreshing] = useState(false);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [zoneViewMode, setZoneViewMode] = useState('department'); // 'department' or 'access_area'
    const [isMenuOpen, setIsMenuOpen] = useState(false); // FAB menu state
    const [mastersModalVisible, setMastersModalVisible] = useState(false); // Masters modal state
    const [reportsModalVisible, setReportsModalVisible] = useState(false); // Reports modal state
    const [settingsModalVisible, setSettingsModalVisible] = useState(false); // Settings modal state
    const [visitorsModalVisible, setVisitorsModalVisible] = useState(false); // Visitors modal state
    const [searchQuery, setSearchQuery] = useState(''); // Search query for visitors
    const [isSearching, setIsSearching] = useState(false); // Search mode state

    // Modal State
    const [modalVisible, setModalVisible] = useState(false);
    const [modalTitle, setModalTitle] = useState('');
    const [modalType, setModalType] = useState(null); // 'employees', 'visits', 'overstays', 'efficiency'
    const [modalFilter, setModalFilter] = useState(null);
    const [modalSearchTerm, setModalSearchTerm] = useState('');
    const [selectedVisit, setSelectedVisit] = useState(null);
    const [detailsVisible, setDetailsVisible] = useState(false);
    const [selectedEmployee, setSelectedEmployee] = useState(null);
    const [employeeDetailsVisible, setEmployeeDetailsVisible] = useState(false);

    // SweetAlert Modal State
    const [alertVisible, setAlertVisible] = useState(false);
    const [alertConfig, setAlertConfig] = useState({ title: '', message: '', type: 'success' }); // 'success', 'error'

    // Filter State
    const [filterModalVisible, setFilterModalVisible] = useState(false);
    const [filterType, setFilterType] = useState('all'); // 'all', 'today', 'yesterday', 'week', 'custom'
    const [filterStartDate, setFilterStartDate] = useState(null);
    const [filterEndDate, setFilterEndDate] = useState(null);

    // Custom Date Picker State
    const [showCustomPicker, setShowCustomPicker] = useState(false);
    const [tempStartDate, setTempStartDate] = useState('');
    const [tempEndDate, setTempEndDate] = useState('');
    const [dateState, setDateState] = useState({ showStart: false, showEnd: false });

    const applyDateFilter = (items) => {
        if (!items) return [];
        if (filterType === 'all') return items;

        const now = new Date();
        const todayStr = now.toISOString().split('T')[0];

        // Helper to get date object set to midnight local time
        const getDateAtMidnight = (dateStr) => {
            const d = new Date(dateStr);
            d.setHours(0, 0, 0, 0);
            return d;
        };

        // Current boundaries
        let start = new Date();
        start.setHours(0, 0, 0, 0);

        let end = new Date();
        end.setHours(23, 59, 59, 999);

        if (filterType === 'today') {
            // Already set to today
        } else if (filterType === 'yesterday') {
            start.setDate(start.getDate() - 1);
            end.setDate(end.getDate() - 1);
        } else if (filterType === 'week') {
            start.setDate(start.getDate() - 7);
        } else if (filterType === 'custom') {
            if (!filterStartDate) return items;
            // Parse custom dates (YYYY-MM-DD)
            start = new Date(filterStartDate);
            start.setHours(0, 0, 0, 0);

            end = filterEndDate ? new Date(filterEndDate) : new Date(filterStartDate);
            end.setHours(23, 59, 59, 999);
        }

        return items.filter(item => {
            let dateStr = item.created_at || item.visit_date;
            if (!dateStr) return false;

            // Normalize date string for parsing
            if (typeof dateStr === 'string' && dateStr.includes(' ')) {
                dateStr = dateStr.replace(' ', 'T');
            }

            const itemDate = new Date(dateStr);
            if (isNaN(itemDate.getTime())) return false;

            return itemDate >= start && itemDate <= end;
        });
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

        // Clean the URL: remove leading slash if present
        let cleanUrl = url.startsWith('/') ? url.substring(1) : url;

        // Photos are relative to the root, which matches API_BASE_URL now
        return `${CONFIG.API_BASE_URL}${cleanUrl}`;
    };

    const fetchVisitDetails = async (visitId) => {
        try {
            const response = await apiClient.get('api/visit/details.php', {
                params: { id: visitId }
            });
            if (response.data.status === 'success') {
                setSelectedVisit(response.data.data);
                setDetailsVisible(true);
            }
        } catch (err) {
            console.error('Visit Details Error:', err);
            showAlert('Error', 'Could not load visit details', 'error');
        }
    };

    const handleRecordClick = (item, type) => {
        if (type === 'visits') {
            fetchVisitDetails(item.id);
        } else if (type === 'employees') {
            setSelectedEmployee(item);
            setEmployeeDetailsVisible(true);
        }
    };

    const fetchData = async () => {
        try {
            const storedUser = await AsyncStorage.getItem('userData');
            if (storedUser) {
                const user = JSON.parse(storedUser);
                setUserData(user);

                const response = await apiClient.get('api/dashboard/stats.php', {
                    params: {
                        role: 'admin'
                    }
                });

                const result = response.data;
                if (result.status === 'success') {
                    const data = result.data;
                    console.log('Stats Data Received:', data.total_visits);
                    setStats({
                        total_employees: data.total_employees || 0,
                        total_visits: data.total_visits || 0,
                        today_visits: data.today_visitors || 0,
                        time_saved: data.time_saved || '0 mins',
                        max_capacity: data.max_capacity || 50,
                    });

                    // Update trends if available
                    if (data.trends) {
                        setTrends(data.trends);
                    }

                    // Update AI Insights if available
                    if (data.ai_insights) {
                        setAiInsights(data.ai_insights);
                    }

                    // Update Efficiency if available
                    if (data.efficiency) {
                        setEfficiency(data.efficiency);
                    }

                    // Update Recent Activity if available
                    if (data.recent_activity) {
                        setRecentActivity(data.recent_activity);
                    }

                    // Update Most Visited Hosts if available
                    if (data.most_visited_hosts) {
                        setMostVisitedHosts(data.most_visited_hosts);
                    }

                    // Update Zones if available
                    if (data.zones) {
                        setZones(data.zones);
                    }

                    // Update Records for modals & New Visitor Tab
                    if (data.records) {
                        setRecords(data.records);

                        // Process for Visitor Tab
                        if (data.records.visits) {
                            const allVisits = data.records.visits;

                            // 1. Pending
                            setPendingVisits(allVisits.filter(v => v.status === 'pending'));

                            // 2. Today (Use server-side record)
                            setTodayVisits(data.records.today_visits || []);

                            // 3. Invites - Admin API might not return future invites in 'visits' list
                            // We will assume they might be there with is_invited=1 or status='invited'
                            setActiveInvites(allVisits.filter(v => v.is_invited == 1 && v.status === 'registered'));
                        }
                    }

                    setError(null);
                } else {
                    setError(result.message || 'Failed to load statistics');
                }
            }
        } catch (err) {
            console.error('Fetch Error:', err);
            if (err.response) {
                console.error('Fetch Error Response:', err.response.data);
                if (err.response.status === 401) {
                    await AsyncStorage.removeItem('userData');
                    navigation.replace('Login');
                } else if (err.response.status === 404) {
                    setError(`API Endpoint Not Found (404). URL: ${err.config?.baseURL || ''}${err.config?.url || ''}`);
                } else {
                    const serverMsg = typeof err.response.data === 'object' ? JSON.stringify(err.response.data) : err.response.data;
                    setError(`Server Error ${err.response.status}: ${serverMsg}`);
                }
            } else if (err.request) {
                console.error('Fetch Error Request:', err.request);
                setError('Connection Error: No response from server. Check your network.');
            } else {
                setError(err.message || 'Network error occurred');
            }
        } finally {
            setLoading(false);
            setRefreshing(false);
        }
    };

    useEffect(() => {
        if (userData?.id) {
            // Wait a bit to ensure UI is ready
            setTimeout(() => checkOverlayPermission(userData.id), 2000);
        }
    }, [userData?.id]);

    useFocusEffect(
        useCallback(() => {
            fetchData();
            const interval = setInterval(fetchData, 30000); // Admin data updates less frequently
            return () => clearInterval(interval);
        }, [])
    );

    const onRefresh = async () => {
        setRefreshing(true);
        await refreshPermissions();
        fetchData();
    };

    const showModal = (title, type, filter = null) => {
        setModalSearchTerm('');
        setModalTitle(title);
        setModalType(type);
        setModalFilter(filter);
        setModalVisible(true);
    };

    const renderTrendChart = () => {
        const hasData = trends.data && trends.data.length > 0 && Math.max(...trends.data) > 0;
        const maxVal = hasData ? Math.max(...trends.data, 1) : 1;
        const chartHeight = 150;

        return (
            <View style={styles.chartContainer}>
                <Text style={styles.cardTitle}>Visitor Traffic Trends</Text>
                {hasData ? (
                    <View style={styles.chartRow}>
                        {trends.data.map((val, idx) => (
                            <View key={idx} style={styles.chartBarCol}>
                                <View style={[styles.chartBar, { height: (val / maxVal) * chartHeight }]} />
                                <Text style={styles.chartLabel}>{trends.labels[idx]}</Text>
                            </View>
                        ))}
                    </View>
                ) : (
                    <View style={styles.emptyChart}>
                        <Icon name="chart-line-variant" size={40} color="#e2e8f0" />
                        <Text style={styles.noDataText}>No traffic data for the last 7 days</Text>
                    </View>
                )}
            </View>
        );
    };

    const renderMostVisitedHosts = () => {
        const hasData = mostVisitedHosts && mostVisitedHosts.length > 0;

        return (
            <View style={styles.card}>
                <Text style={styles.cardTitle}>Most Visited Hosts</Text>
                {hasData ? (
                    mostVisitedHosts.map((host, idx) => (
                        <View key={idx} style={styles.hostRow}>
                            <View style={styles.hostInfo}>
                                <Text style={styles.hostNameMain}>{host.name}</Text>
                                <Text style={styles.hostVisitCount}>{host.visit_count} visits</Text>
                            </View>
                            <View style={styles.hostRank}>
                                <Text style={styles.hostRankText}>#{idx + 1}</Text>
                            </View>
                        </View>
                    ))
                ) : (
                    <Text style={styles.noDataText}>No visit data available</Text>
                )}
            </View>
        );
    };

    const getDensityColor = (density) => {
        if (density > 80) return '#ef4444';
        if (density > 50) return '#f59e0b';
        return '#10b981';
    };

    const renderOverstayAlerts = () => {
        const hasAlerts = aiInsights.overstay_count > 0;

        return (
            <TouchableOpacity
                style={[styles.card, styles.overstayCard, !hasAlerts && styles.noOverstayCard]}
                onPress={() => hasAlerts && showModal('Overstay Details', 'overstays')}
            >
                <View style={styles.alertHeader}>
                    <Text style={[styles.alertTitle, !hasAlerts && styles.noAlertTitle]}>
                        {hasAlerts ? '⚠️ Overstay Alerts' : '✅ No Overstay Alerts'}
                    </Text>
                    {hasAlerts && (
                        <View style={styles.badge}>
                            <Text style={styles.badgeText}>{aiInsights.overstay_count}</Text>
                        </View>
                    )}
                </View>
                <Text style={[styles.alertDesc, !hasAlerts && styles.noAlertDesc]}>
                    {hasAlerts
                        ? 'Some visitors have exceeded the standard 8-hour stay limit.'
                        : 'All visitors are within their expected stay duration.'}
                </Text>
            </TouchableOpacity>
        );
    };

    const renderModalSearch = () => (
        <View style={styles.modalSearchContainer}>
            <Icon name="magnify" size={24} color="#64748b" style={styles.modalSearchIcon} />
            <TextInput
                style={styles.modalSearchInput}
                placeholder={`Search ${modalTitle}...`}
                value={modalSearchTerm}
                onChangeText={setModalSearchTerm}
                placeholderTextColor="#94a3b8"
            />
            {modalSearchTerm ? (
                <TouchableOpacity onPress={() => setModalSearchTerm('')}>
                    <Icon name="close-circle" size={20} color="#94a3b8" />
                </TouchableOpacity>
            ) : null}
        </View>
    );

    const renderOverstayList = () => {
        const filteredOverstays = aiInsights.overstay_list.filter(item => {
            return !modalSearchTerm ||
                item.visitor_name?.toLowerCase().includes(modalSearchTerm.toLowerCase()) ||
                item.host_name?.toLowerCase().includes(modalSearchTerm.toLowerCase());
        });

        const formatOverstay = (minutes) => {
            if (!minutes) return '8h+';
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
                                handleRecordClick(item, 'visits');
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
                                <Text style={styles.detailTime}>{new Date(item.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</Text>
                                <Text style={styles.detailDateText}>{formatDate(item.created_at)}</Text>
                            </View>
                        </TouchableOpacity>
                    ))}
                    {filteredOverstays.length === 0 && <Text style={styles.noDataText}>No overstay alerts found matching your search.</Text>}
                </ScrollView>
            </View>
        );
    };

    const renderRecentActivity = () => (
        <View style={styles.activityCard}>
            <View style={styles.cardHeaderRow}>
                <Text style={styles.cardTitle}>Recent Visitor Activity</Text>
                <TouchableOpacity onPress={() => showModal('Visit History', renderVisitRecords())}>
                    <Text style={styles.viewAllText}>View All</Text>
                </TouchableOpacity>
            </View>
            {recentActivity.length > 0 ? (
                recentActivity.map((item, idx) => (
                    <TouchableOpacity
                        key={idx}
                        style={styles.activityItem}
                        onPress={() => handleRecordClick(item, 'visits')}
                    >
                        <View style={styles.activityPhotoContainer}>
                            {item.photo_url ? (
                                <Image
                                    source={{ uri: getPhotoUrl(item.photo_url) }}
                                    style={styles.miniVisitorPhoto}
                                    resizeMode="cover"
                                    onError={(e) => console.log('Image Load Error:', e.nativeEvent.error, getPhotoUrl(item.photo_url))}
                                />
                            ) : (
                                <View style={styles.activityIconContainer}>
                                    <Icon
                                        name={item.status === 'checked_in' ? 'account-arrow-right' : 'account-arrow-left'}
                                        size={22}
                                        color={item.status === 'checked_in' ? '#10b981' : '#6366f1'}
                                    />
                                </View>
                            )}
                        </View>
                        <View style={styles.activityInfo}>
                            <View style={styles.activityTopRow}>
                                <Text style={styles.visitorName} numberOfLines={1}>{item.visitor_name}</Text>
                                <Text style={styles.activityTime}>{new Date(item.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</Text>
                            </View>
                            <View style={styles.activityBottomRow}>
                                <Text style={styles.hostName} numberOfLines={1}>Host: {item.host_name}</Text>
                                <View style={[styles.miniStatusBadge, { backgroundColor: getStatusColor(item.status) }]}>
                                    <Text style={[styles.miniStatusText, { color: '#ffffff' }]}>
                                        {(item.status || 'UNKNOWN').replace('_', ' ').toUpperCase()}
                                    </Text>
                                </View>
                            </View>
                        </View>
                    </TouchableOpacity>
                ))
            ) : (
                <Text style={styles.noDataText}>No recent activity</Text>
            )}
        </View>
    );

    const renderEfficiencyMetrics = () => (
        <View style={styles.efficiencyCard}>
            <View style={styles.cardHeaderRow}>
                <Text style={styles.cardTitle}>Efficiency Metrics</Text>
                <View style={styles.satisfactionBadge}>
                    <Text style={styles.satisfactionText}>{efficiency.satisfaction} Happy</Text>
                </View>
            </View>
            <View style={styles.efficiencyList}>
                <View style={styles.efficiencyItem}>
                    <View style={styles.effIconContainer}>
                        <Text style={styles.effIcon}>⏱️</Text>
                    </View>
                    <View style={styles.effDetails}>
                        <Text style={styles.effLabel}>Avg Check-in Time</Text>
                        <Text style={styles.effValue}>{efficiency.avg_checkin_time}</Text>
                    </View>
                </View>
                <View style={styles.efficiencyItem}>
                    <View style={styles.effIconContainer}>
                        <Text style={styles.effIcon}>📊</Text>
                    </View>
                    <View style={styles.effDetails}>
                        <Text style={styles.effLabel}>Peak Traffic Hour</Text>
                        <Text style={styles.effValue}>{efficiency.peak_hour}</Text>
                    </View>
                </View>
                <View style={styles.efficiencyItem}>
                    <View style={styles.effIconContainer}>
                        <Text style={styles.effIcon}>♻️</Text>
                    </View>
                    <View style={styles.effDetails}>
                        <Text style={styles.effLabel}>Total Time Saved</Text>
                        <Text style={styles.effValue}>{efficiency.total_time_saved}</Text>
                    </View>
                </View>
            </View>
        </View>
    );

    const renderEmployeeRecords = () => {
        const filteredEmployees = records.employees.filter(emp => {
            return !modalSearchTerm ||
                emp.name?.toLowerCase().includes(modalSearchTerm.toLowerCase()) ||
                emp.department?.toLowerCase().includes(modalSearchTerm.toLowerCase()) ||
                emp.mobile?.includes(modalSearchTerm);
        });

        return (
            <View style={{ flex: 1 }}>
                {renderModalSearch()}
                <ScrollView style={styles.modalScroll} showsVerticalScrollIndicator={false}>
                    {filteredEmployees.map((emp, idx) => (
                        <TouchableOpacity
                            key={idx}
                            style={styles.employeeCard}
                            onPress={() => handleRecordClick(emp, 'employees')}
                        >
                            <View style={styles.employeeMainInfo}>
                                <View style={[styles.recordAvatar, { backgroundColor: emp.status === 'active' ? '#e0f2fe' : '#f1f5f9' }]}>
                                    <Text style={[styles.avatarText, { color: emp.status === 'active' ? '#0284c7' : '#64748b' }]}>
                                        {emp.name.charAt(0).toUpperCase()}
                                    </Text>
                                    <View style={[styles.statusDot, { backgroundColor: emp.status === 'active' ? '#10b981' : '#cbd5e1' }]} />
                                </View>
                                <View style={styles.employeeDetails}>
                                    <Text style={styles.employeeName}>{emp.name}</Text>
                                    <Text style={styles.employeeDept}>{emp.department}</Text>
                                    <Text style={styles.employeeRole}>{emp.role || 'Staff'}</Text>
                                </View>
                                <View style={[styles.miniStatusBadge, { backgroundColor: emp.status === 'active' ? '#dcfce7' : '#f1f5f9' }]}>
                                    <Text style={[styles.miniStatusText, { color: emp.status === 'active' ? '#166534' : '#64748b' }]}>
                                        {(emp.status || 'active').toUpperCase()}
                                    </Text>
                                </View>
                            </View>

                            <View style={styles.employeeContactRow}>
                                <TouchableOpacity style={styles.contactItem} onPress={() => showAlert('Call', `Calling ${emp.mobile}`, 'success')}>
                                    <Icon name="phone" size={18} color="#3b82f6" />
                                    <Text style={styles.contactText}>{emp.mobile}</Text>
                                </TouchableOpacity>
                                <TouchableOpacity style={styles.contactItem} onPress={() => showAlert('Email', `Emailing ${emp.email}`, 'success')}>
                                    <Icon name="email" size={18} color="#3b82f6" />
                                    <Text style={styles.contactText} numberOfLines={1}>{emp.email}</Text>
                                </TouchableOpacity>
                            </View>
                        </TouchableOpacity>
                    ))}
                    {filteredEmployees.length === 0 && (
                        <View style={styles.emptyState}>
                            <Icon name="account-off-outline" size={48} color="#cbd5e1" />
                            <Text style={styles.noDataText}>No employees found matching your search.</Text>
                        </View>
                    )}
                    <View style={{ height: 20 }} />
                </ScrollView>
            </View>
        );
    };

    const renderEmployeeDetailsModal = () => {
        if (!selectedEmployee) return null;
        const emp = selectedEmployee;

        // Find visits hosted by this employee
        const hostVisits = (records.visits || []).filter(v => v.host_name === emp.name).slice(0, 5);

        return (
            <Modal
                animationType="slide"
                transparent={false}
                visible={employeeDetailsVisible}
                onRequestClose={() => setEmployeeDetailsVisible(false)}
            >
                <SafeAreaView style={{ flex: 1, backgroundColor: '#f8fafc' }}>
                    <View style={[styles.fullModalHeader, { backgroundColor: '#ef4444' }]}>
                        <TouchableOpacity onPress={() => setEmployeeDetailsVisible(false)} style={styles.fullModalBack}>
                            <Icon name="arrow-left" size={24} color="#fff" />
                        </TouchableOpacity>
                        <Text style={styles.fullModalTitleText}>Employee Profile</Text>
                    </View>

                    <ScrollView style={{ flex: 1 }}>
                        <View style={styles.detailProfileSection}>
                            <View style={[styles.detailAvatar, { backgroundColor: '#fee2e2' }]}>
                                <Text style={styles.detailAvatarText}>{emp.name.charAt(0).toUpperCase()}</Text>
                            </View>
                            <Text style={styles.detailName}>{emp.name}</Text>
                            <View style={[styles.miniStatusBadge, { backgroundColor: emp.status === 'active' ? '#dcfce7' : '#f1f5f9', marginTop: 8 }]}>
                                <Text style={[styles.miniStatusText, { color: emp.status === 'active' ? '#166534' : '#64748b' }]}>
                                    {(emp.status || 'active').toUpperCase()}
                                </Text>
                            </View>
                        </View>

                        <View style={styles.detailInfoCard}>
                            <Text style={styles.detailSectionTitle}>Work Information</Text>
                            <View style={styles.detailInfoRow}>
                                <Icon name="office-building" size={20} color="#64748b" />
                                <View style={styles.detailInfoContent}>
                                    <Text style={styles.detailLabel}>Department</Text>
                                    <Text style={styles.detailValue}>{emp.department}</Text>
                                </View>
                            </View>
                            <View style={styles.detailInfoRow}>
                                <Icon name="account-tie" size={20} color="#64748b" />
                                <View style={styles.detailInfoContent}>
                                    <Text style={styles.detailLabel}>Role / Designation</Text>
                                    <Text style={styles.detailValue}>{emp.role || 'Staff'}</Text>
                                </View>
                            </View>
                        </View>

                        <View style={styles.detailInfoCard}>
                            <Text style={styles.detailSectionTitle}>Contact Details</Text>
                            <TouchableOpacity style={styles.detailInfoRow} onPress={() => showAlert('Call', `Calling ${emp.mobile}`)}>
                                <Icon name="phone" size={20} color="#3b82f6" />
                                <View style={styles.detailInfoContent}>
                                    <Text style={styles.detailLabel}>Mobile Number</Text>
                                    <Text style={[styles.detailValue, { color: '#3b82f6' }]}>{emp.mobile}</Text>
                                </View>
                            </TouchableOpacity>
                            <TouchableOpacity style={styles.detailInfoRow} onPress={() => showAlert('Email', `Emailing ${emp.email}`)}>
                                <Icon name="email" size={20} color="#3b82f6" />
                                <View style={styles.detailInfoContent}>
                                    <Text style={styles.detailLabel}>Email Address</Text>
                                    <Text style={[styles.detailValue, { color: '#3b82f6' }]}>{emp.email}</Text>
                                </View>
                            </TouchableOpacity>
                        </View>

                        <View style={styles.detailInfoCard}>
                            <Text style={styles.detailSectionTitle}>Hosted Visits (Recent)</Text>
                            {hostVisits.length > 0 ? (
                                hostVisits.map((v, i) => (
                                    <View key={i} style={styles.minimalVisitRow}>
                                        <View style={[styles.statusDot, { backgroundColor: v.status === 'checked_in' ? '#10b981' : '#cbd5e1', marginRight: 10 }]} />
                                        <Text style={styles.minimalVisitName}>{v.visitor_name}</Text>
                                        <Text style={styles.minimalVisitDate}>{v.created_at?.split(' ')[0]}</Text>
                                    </View>
                                ))
                            ) : (
                                <Text style={styles.noDataTextSmall}>No recent visits hosted.</Text>
                            )}
                        </View>
                        <View style={{ height: 40 }} />
                    </ScrollView>
                </SafeAreaView>
            </Modal>
        );
    };

    const renderVisitDetails = () => {
        if (!selectedVisit) return null;
        const v = selectedVisit;
        const statusColors = {
            'pending': '#f59e0b',
            'approved': '#10b981',
            'checked_in': '#3b82f6',
            'checked_out': '#64748b',
            'rejected': '#ef4444'
        };

        return (
            <View style={{ flex: 1 }}>
                <View style={styles.detailsHeader}>
                    <View style={styles.detailsHeaderTop}>
                        <Text style={styles.detailsHeaderTitle}>Visit Details</Text>
                        <TouchableOpacity onPress={() => setDetailsVisible(false)}>
                            <Icon name="close" size={24} color="#fff" />
                        </TouchableOpacity>
                    </View>

                    <View style={styles.visitorMainInfo}>
                        <View style={styles.photoContainer}>
                            {v.photo_url ? (
                                <Image
                                    source={{ uri: getPhotoUrl(v.photo_url) }}
                                    style={styles.visitorPhoto}
                                    resizeMode="cover"
                                    onError={(e) => console.log('Detail Image Load Error:', e.nativeEvent.error, getPhotoUrl(v.photo_url))}
                                />
                            ) : (
                                <View style={[styles.visitorPhoto, { backgroundColor: 'rgba(255,255,255,0.2)', justifyContent: 'center', alignItems: 'center' }]}>
                                    <Icon name="account" size={40} color="#fff" />
                                </View>
                            )}
                        </View>
                        <View style={styles.detailsBasic}>
                            <Text style={styles.detailsName}>{v.visitor_name}</Text>
                            <Text style={styles.detailsMobile}>{v.mobile}</Text>
                            <View style={[styles.statusBadgeModal, { backgroundColor: statusColors[v.status] || '#64748b' }]}>
                                <Text style={styles.statusBadgeTextModal}>{v.status?.toUpperCase()?.replace('_', ' ')}</Text>
                            </View>
                        </View>
                    </View>
                </View>

                <ScrollView style={styles.detailsContent} showsVerticalScrollIndicator={false}>
                    <View style={styles.detailsCard}>
                        <Text style={styles.detailsSectionTitle}>Visit Information</Text>
                        <View style={styles.detailsGrid}>
                            <View style={styles.detailsItem}>
                                <Text style={styles.detailsLabel}>Host Name</Text>
                                <Text style={styles.detailsValue}>{v.host_name}</Text>
                            </View>
                            <View style={styles.detailsItem}>
                                <Text style={styles.detailsLabel}>Department</Text>
                                <Text style={styles.detailsValue}>{v.department}</Text>
                            </View>
                            <View style={styles.detailsItem}>
                                <Text style={styles.detailsLabel}>Purpose</Text>
                                <Text style={styles.detailsValue}>{v.purpose}</Text>
                            </View>
                            <View style={styles.detailsItem}>
                                <Text style={styles.detailsLabel}>Visit Code</Text>
                                <Text style={styles.detailsValue}>#{v.visit_code}</Text>
                            </View>
                            <View style={styles.detailsItem}>
                                <Text style={styles.detailsLabel}>Access Area</Text>
                                <Text style={styles.detailsValue}>{v.access_area || 'N/A'}</Text>
                            </View>
                            <View style={styles.detailsItem}>
                                <Text style={styles.detailsLabel}>Assets Carried</Text>
                                <Text style={styles.detailsValue}>{v.assets_carried || 'None'}</Text>
                            </View>
                        </View>
                    </View>

                    <View style={styles.detailsCard}>
                        <Text style={styles.detailsSectionTitle}>Identity Verification</Text>
                        <View style={styles.detailsGrid}>
                            <View style={styles.detailsItem}>
                                <Text style={styles.detailsLabel}>ID Type</Text>
                                <Text style={styles.detailsValue}>{v.id_proof_type || 'N/A'}</Text>
                            </View>
                            <View style={styles.detailsItem}>
                                <Text style={styles.detailsLabel}>ID Number</Text>
                                <Text style={styles.detailsValue}>{v.id_proof_number || 'N/A'}</Text>
                            </View>
                        </View>
                    </View>

                    <View style={styles.detailsCard}>
                        <Text style={styles.detailsSectionTitle}>Timeline</Text>
                        <View style={styles.timelineItem}>
                            <View style={[styles.timelineDot, { backgroundColor: '#3b82f6' }]} />
                            <View>
                                <Text style={styles.timelineTitle}>Registered</Text>
                                <Text style={styles.timelineDate}>{formatDate(v.created_at)} {new Date(v.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</Text>
                            </View>
                        </View>
                        {v.check_in_time && (
                            <View style={styles.timelineItem}>
                                <View style={[styles.timelineDot, { backgroundColor: '#10b981' }]} />
                                <View>
                                    <Text style={styles.timelineTitle}>Checked In</Text>
                                    <Text style={styles.timelineDate}>{formatDate(v.check_in_time)} {new Date(v.check_in_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</Text>
                                </View>
                            </View>
                        )}
                        {v.check_out_time && (
                            <View style={styles.timelineItem}>
                                <View style={[styles.timelineDot, { backgroundColor: '#64748b' }]} />
                                <View>
                                    <Text style={styles.timelineTitle}>Checked Out</Text>
                                    <Text style={styles.timelineDate}>{formatDate(v.check_out_time)} {new Date(v.check_out_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</Text>
                                </View>
                            </View>
                        )}
                    </View>
                    <View style={{ height: 40 }} />
                </ScrollView>

                {/* Action Footer - Updated to match Security Dashboard */}
                <View style={styles.detailsFooter}>
                    {v.status === 'pending' && (
                        <View style={{ flexDirection: 'row', gap: 10, marginBottom: 12 }}>
                            <TouchableOpacity
                                style={[styles.actionButton, { backgroundColor: '#10b981', flex: 1, marginBottom: 0 }]}
                                onPress={() => {
                                    handleAction(v.id, 'approve');
                                    setDetailsVisible(false);
                                }}
                            >
                                <Icon name="check" size={20} color="#fff" />
                                <Text style={styles.actionButtonText}>Approve</Text>
                            </TouchableOpacity>
                            <TouchableOpacity
                                style={[styles.actionButton, { backgroundColor: '#ef4444', flex: 1, marginBottom: 0 }]}
                                onPress={() => {
                                    handleAction(v.id, 'reject');
                                    setDetailsVisible(false);
                                }}
                            >
                                <Icon name="close" size={20} color="#fff" />
                                <Text style={styles.actionButtonText}>Reject</Text>
                            </TouchableOpacity>
                        </View>
                    )}

                    {(v.status === 'approved' || v.status === 'checked_in' || v.status === 'checked_out') && (
                        <TouchableOpacity
                            style={[styles.actionButton, { backgroundColor: '#3b82f6', marginBottom: 12 }]}
                            onPress={() => {
                                setDetailsVisible(true);
                            }}
                        >
                            <Icon name="ticket-account" size={20} color="#fff" />
                            <Text style={styles.actionButtonText}>View Pass</Text>
                        </TouchableOpacity>
                    )}

                    {v.status === 'approved' && (
                        <TouchableOpacity
                            style={[styles.actionButton, { backgroundColor: '#10b981' }]}
                            onPress={() => {
                                handleAction(v.id, 'checkin');
                                setDetailsVisible(false);
                            }}
                        >
                            <Icon name="login" size={20} color="#fff" />
                            <Text style={styles.actionButtonText}>Check In Visitor</Text>
                        </TouchableOpacity>
                    )}

                    {v.status === 'checked_in' && (
                        <TouchableOpacity
                            style={[styles.actionButton, { backgroundColor: '#64748b' }]}
                            onPress={() => {
                                handleAction(v.id, 'checkout');
                                setDetailsVisible(false);
                            }}
                        >
                            <Icon name="logout" size={20} color="#fff" />
                            <Text style={styles.actionButtonText}>Check Out Visitor</Text>
                        </TouchableOpacity>
                    )}
                </View>
            </View >
        );
    };



    // Updated renderVisitRecords to fix Today's Visits bug
    const renderVisitRecords = (filter = null) => {
        // Use todayVisits state for "Today's Visits" modal which is computed in fetchData
        const baseRecords = (modalTitle === "Today's Visits") ? (todayVisits || []) : records.visits;

        const filteredVisits = baseRecords.filter(v => {
            const matchesFilter = !filter || v.status === filter;
            const matchesSearch = !modalSearchTerm ||
                v.visitor_name?.toLowerCase().includes(modalSearchTerm.toLowerCase()) ||
                v.host_name?.toLowerCase().includes(modalSearchTerm.toLowerCase());
            return matchesFilter && matchesSearch;
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
                                handleRecordClick(visit, 'visits');
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
                                        {visit.status.replace('_', ' ').toUpperCase()}
                                    </Text>
                                </View>
                                <Icon name="chevron-right" size={20} color="#cbd5e1" />
                            </View>
                        </TouchableOpacity>
                    ))}
                    {filteredVisits.length === 0 && <Text style={styles.noDataText}>No records found matching your search.</Text>}
                </ScrollView>
            </View>
        );
    };

    const renderZoneDensity = () => {
        const selectedZones = zones[zoneViewMode] || [];

        return (
            <View style={styles.zoneCard}>
                <View style={styles.cardHeaderRow}>
                    <View>
                        <Text style={styles.cardTitle}>Zone Density</Text>
                        <Text style={{ fontSize: 10, color: '#94a3b8', marginTop: 2 }}>Currently Checked-In Only</Text>
                    </View>
                    <View style={styles.toggleContainer}>
                        <TouchableOpacity
                            style={[styles.toggleBtn, zoneViewMode === 'department' && styles.toggleBtnActive]}
                            onPress={() => setZoneViewMode('department')}
                        >
                            <Text style={[styles.toggleText, zoneViewMode === 'department' && styles.toggleTextActive]}>Dept</Text>
                        </TouchableOpacity>
                        <TouchableOpacity
                            style={[styles.toggleBtn, zoneViewMode === 'access_area' && styles.toggleBtnActive]}
                            onPress={() => setZoneViewMode('access_area')}
                        >
                            <Text style={[styles.toggleText, zoneViewMode === 'access_area' && styles.toggleTextActive]}>Area</Text>
                        </TouchableOpacity>
                    </View>
                </View>

                <View style={styles.zoneList}>
                    {selectedZones.length > 0 ? (
                        selectedZones.map((zone, idx) => {
                            const pct = Math.min(100, zone.density || 0);
                            const color = getDensityColor(pct);
                            const status = pct > 80 ? 'High Congestion' : (pct > 40 ? 'Moderate Traffic' : 'Low Activity');

                            return (
                                <View key={idx} style={styles.zoneRow}>
                                    <View style={styles.zoneInfo}>
                                        <Text style={styles.zoneName}>{zone.name}</Text>
                                        <Text style={[styles.zoneStatus, { color }]}>{status}</Text>
                                    </View>
                                    <View style={styles.zoneCountBadge}>
                                        <Text style={styles.zoneCountText}>{zone.count}</Text>
                                    </View>
                                </View>
                            );
                        })
                    ) : (
                        <Text style={styles.noDataText}>No active visitors in any zone.</Text>
                    )}
                </View>
            </View>
        );
    };

    const showAlert = (title, message, type = 'success', options = {}) => {
        setAlertConfig({ title, message, type, ...options });
        setAlertVisible(true);
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
                `Are you sure you want to ${label} this visit request?`,
                'warning',
                {
                    showCancel: true,
                    confirmText: 'Yes, ' + label,
                    onConfirm: () => executeAction(visitId, action)
                }
            );
        } else {
            executeAction(visitId, action);
        }
    };

    const executeAction = async (visitId, action) => {
        try {
            const response = await apiClient.post('api/visit/status_action.php', {
                action: action,
                visit_id: visitId,
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

    const getStatusColor = (status) => {
        const colors = {
            'pending': '#f59e0b',
            'approved': '#10b981',
            'checked_in': '#3b82f6',
            'checked_out': '#64748b',
            'rejected': '#ef4444',
            'completed': '#64748b',
            'cancelled': '#ef4444'
        };
        return colors[status?.toLowerCase()] || '#64748b';
    };

    const renderVisitCard = (item, type) => {
        // Match Security Dashboard Design
        const isPending = type === 'pending';
        const status = item.status || 'registered';

        return (
            <TouchableOpacity
                key={item.id}
                style={styles.visitRowBig}
                onPress={() => fetchVisitDetails(item.id)}
            >
                <Image
                    source={item.photo_path || item.visit_photo || item.visitor_photo ?
                        { uri: getPhotoUrl(item.photo_path || item.visit_photo || item.visitor_photo) } :
                        { uri: 'https://ui-avatars.com/api/?name=' + encodeURIComponent(item.visitor_name || 'Visitor') + '&background=random' }
                    }
                    style={styles.visitorThumbBig}
                    onError={() => { }}
                />
                <View style={styles.visitInfo}>
                    <Text style={styles.visitorNameTextBig}>{item.visitor_name}</Text>
                    {item.visit_code && <Text style={styles.visitDetailsText}>Code: {item.visit_code}</Text>}
                    <Text style={styles.visitDetailsText}>Host: {item.host_name || 'N/A'}</Text>
                    <View style={styles.timeTagRow}>
                        {status === 'pending' ? (
                            <Text style={styles.timeTagText}>Req: {new Date(item.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</Text>
                        ) : (
                            <>
                                <Text style={styles.timeTagText}>In: {item.check_in_time ? new Date(item.check_in_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '-'}</Text>
                                <Text style={styles.timeTagText}>Out: {item.check_out_time ? new Date(item.check_out_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '-'}</Text>
                            </>
                        )}
                    </View>
                </View>
                <View style={[styles.statusTag, { backgroundColor: getStatusColor(status) }]}>
                    <Text style={styles.statusTagText}>{status.replace('_', ' ').toUpperCase()}</Text>
                </View>
            </TouchableOpacity>
        );
    };

    const renderVisitors = () => {
        return (
            <View style={{ padding: 15 }}>
                <View style={styles.segmentContainer}>
                    <TouchableOpacity
                        style={[styles.segmentBtn, visitorView === 'log' && styles.segmentBtnActive]}
                        onPress={() => setVisitorView('log')}
                    >
                        <Text style={[styles.segmentText, visitorView === 'log' && styles.segmentTextActive]}>Visitor Log</Text>
                    </TouchableOpacity>

                    <TouchableOpacity
                        style={[styles.segmentBtn, visitorView === 'invites' && styles.segmentBtnActive]}
                        onPress={() => setVisitorView('invites')}
                    >
                        <Text style={[styles.segmentText, visitorView === 'invites' && styles.segmentTextActive]}>Invitations</Text>
                    </TouchableOpacity>

                    <TouchableOpacity
                        style={[styles.segmentBtn, visitorView === 'pending' && styles.segmentBtnActive]}
                        onPress={() => setVisitorView('pending')}
                    >
                        <Text style={[styles.segmentText, visitorView === 'pending' && styles.segmentTextActive]}>
                            Pending ({pendingVisits.length})
                        </Text>
                    </TouchableOpacity>
                </View>

                {/* Date Filter Bar */}
                <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 15 }}>
                    <Text style={{ fontSize: 13, color: '#64748b', fontWeight: '600' }}>
                        {filterType === 'all' ? 'All Records' :
                            filterType === 'today' ? 'Today' :
                                filterType === 'yesterday' ? 'Yesterday' :
                                    filterType === 'week' ? 'Last 7 Days' : 'Custom Range'}
                    </Text>
                    <TouchableOpacity
                        style={{ flexDirection: 'row', alignItems: 'center', backgroundColor: '#fff', paddingHorizontal: 12, paddingVertical: 6, borderRadius: 20, borderWidth: 1, borderColor: '#e2e8f0' }}
                        onPress={() => setFilterModalVisible(true)}
                    >
                        <Icon name="filter-variant" size={16} color="#3b82f6" style={{ marginRight: 4 }} />
                        <Text style={{ fontSize: 12, fontWeight: '600', color: '#3b82f6' }}>Filter Date</Text>
                    </TouchableOpacity>
                </View>

                {visitorView === 'log' && (
                    <>
                        <View style={styles.cardHeaderRow}>
                            <Text style={styles.sectionTitle}>Visitor Log</Text>
                        </View>
                        {applyDateFilter(filterType === 'today' && todayVisits.length > 0 ? todayVisits : records.visits).length === 0 ? (
                            <View style={styles.emptyContainer}>
                                <Icon name="account-off" size={60} color="#cbd5e1" />
                                <Text style={styles.emptyTitle}>No Visitors Found</Text>
                                <Text style={styles.emptyText}>No visitors found for the selected period.</Text>
                            </View>
                        ) : (
                            applyDateFilter(filterType === 'today' && todayVisits.length > 0 ? todayVisits : records.visits).map(item => renderVisitCard(item, 'today'))
                        )}

                        {(todayVisits.length < records.visits.length) && (
                            <TouchableOpacity
                                style={{ alignItems: 'center', marginTop: 20 }}
                                onPress={() => showModal('All Visits', 'visits')}
                            >
                                <Text style={{ color: '#3b82f6', fontWeight: '700' }}>View All History</Text>
                            </TouchableOpacity>
                        )}
                    </>
                )}

                {visitorView === 'invites' && (
                    <>
                        <View style={styles.cardHeaderRow}>
                            <Text style={styles.sectionTitle}>Active Invitations</Text>
                        </View>
                        {applyDateFilter(activeInvites).length === 0 ? (
                            <View style={styles.emptyContainer}>
                                <Icon name="calendar-blank" size={60} color="#cbd5e1" />
                                <Text style={styles.emptyTitle}>No Active Invites</Text>
                                <Text style={styles.emptyText}>No registered future invitations found.</Text>
                            </View>
                        ) : (
                            applyDateFilter(activeInvites).map(item => renderVisitCard(item, 'invites'))
                        )}
                    </>
                )}

                {visitorView === 'pending' && (
                    <View style={styles.section}>
                        <Text style={[styles.sectionTitle, { color: '#f59e0b', marginBottom: 15 }]}>Pending Approvals</Text>
                        {applyDateFilter(pendingVisits).length === 0 ? (
                            <Text style={styles.noDataText}>No pending approvals.</Text>
                        ) : (
                            applyDateFilter(pendingVisits).map(item => renderVisitCard(item, 'pending'))
                        )}
                    </View>
                )}
            </View>
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

                        <TouchableOpacity
                            style={styles.mgmtGridItem}
                            onPress={() => {
                                setMastersModalVisible(false);
                                navigation.navigate('Departments');
                            }}
                        >
                            <View style={[styles.mgmtIcon, { backgroundColor: '#f0fdf4' }]}>
                                <Icon name="office-building" size={24} color="#16a34a" />
                            </View>
                            <Text style={styles.mgmtLabel}>Depts</Text>
                        </TouchableOpacity>

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
                            <Text style={styles.mgmtLabel}>Users & Perms</Text>
                        </TouchableOpacity>

                        <TouchableOpacity
                            style={styles.mgmtGridItem}
                            onPress={() => {
                                setMastersModalVisible(false);
                                navigation.navigate('Tenants');
                            }}
                        >
                            <View style={[styles.mgmtIcon, { backgroundColor: '#fff7ed' }]}>
                                <Icon name="domain" size={24} color="#ea580c" />
                            </View>
                            <Text style={styles.mgmtLabel}>Tenants</Text>
                        </TouchableOpacity>

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
                            <Text style={styles.mgmtLabel}>Logs</Text>
                        </TouchableOpacity>

                        <View style={styles.mgmtGridItem} />
                        <View style={styles.mgmtGridItem} />
                    </View>
                </Pressable>
            </Pressable>
        </Modal>
    );

    const renderReportsModal = () => (
        <Modal
            animationType="slide"
            transparent={true}
            visible={reportsModalVisible}
            onRequestClose={() => setReportsModalVisible(false)}
        >
            <Pressable
                style={styles.modalOverlay}
                onPress={() => setReportsModalVisible(false)}
            >
                <Pressable
                    style={styles.mgmtModalContent}
                    onPress={(e) => e.stopPropagation()}
                >
                    <View style={styles.mgmtModalHeader}>
                        <Text style={styles.mgmtModalTitle}>Reports & Logs</Text>
                        <TouchableOpacity onPress={() => setReportsModalVisible(false)}>
                            <Icon name="close" size={24} color="#64748b" />
                        </TouchableOpacity>
                    </View>

                    <View style={styles.mgmtGrid}>
                        <TouchableOpacity
                            style={styles.mgmtGridItem}
                            onPress={() => {
                                setReportsModalVisible(false);
                                navigation.navigate('Reports');
                            }}
                        >
                            <View style={[styles.mgmtIcon, { backgroundColor: '#f0f9ff' }]}>
                                <Icon name="file-chart" size={24} color="#0369a1" />
                            </View>
                            <Text style={styles.mgmtLabel}>Reports & Analytics</Text>
                        </TouchableOpacity>

                        <TouchableOpacity
                            style={styles.mgmtGridItem}
                            onPress={() => {
                                setReportsModalVisible(false);
                                navigation.navigate('EmployeeReport');
                            }}
                        >
                            <View style={[styles.mgmtIcon, { backgroundColor: '#ecfdf5' }]}>
                                <Icon name="account-details" size={24} color="#059669" />
                            </View>
                            <Text style={styles.mgmtLabel}>Employee-wise Report</Text>
                        </TouchableOpacity>

                        <TouchableOpacity
                            style={styles.mgmtGridItem}
                            onPress={() => {
                                setReportsModalVisible(false);
                                navigation.navigate('AuditLogs');
                            }}
                        >
                            <View style={[styles.mgmtIcon, { backgroundColor: '#fef2f2' }]}>
                                <Icon name="clipboard-text-clock" size={24} color="#dc2626" />
                            </View>
                            <Text style={styles.mgmtLabel}>Logs</Text>
                        </TouchableOpacity>

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
                            <Text style={styles.mgmtLabel}>Personal Profile</Text>
                        </TouchableOpacity>
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
                            <Text style={styles.mgmtLabel}>Company Profile</Text>
                        </TouchableOpacity>

                        <TouchableOpacity
                            style={styles.mgmtGridItem}
                            onPress={() => {
                                setSettingsModalVisible(false);
                                navigation.navigate('Settings', { tab: 'general' });
                            }}
                        >
                            <View style={[styles.mgmtIcon, { backgroundColor: '#f8fafc' }]}>
                                <Icon name="list-status" size={24} color="#475569" />
                            </View>
                            <Text style={styles.mgmtLabel}>General / Purposes</Text>
                        </TouchableOpacity>

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

                        <View style={styles.mgmtGridItem} />
                        <View style={styles.mgmtGridItem} />
                    </View>
                </Pressable>
            </Pressable>
        </Modal>
    );

    const renderVisitorsModal = () => (
        <Modal
            animationType="slide"
            transparent={true}
            visible={visitorsModalVisible}
            onRequestClose={() => setVisitorsModalVisible(false)}
        >
            <Pressable
                style={styles.modalOverlay}
                onPress={() => setVisitorsModalVisible(false)}
            >
                <Pressable
                    style={styles.mgmtModalContent}
                    onPress={(e) => e.stopPropagation()}
                >
                    <View style={styles.mgmtModalHeader}>
                        <Text style={styles.mgmtModalTitle}>Visitors</Text>
                        <TouchableOpacity onPress={() => setVisitorsModalVisible(false)}>
                            <Icon name="close" size={24} color="#64748b" />
                        </TouchableOpacity>
                    </View>

                    <View style={styles.mgmtGrid}>
                        <TouchableOpacity
                            style={styles.mgmtGridItem}
                            onPress={() => {
                                setVisitorsModalVisible(false);
                                showModal('Pending Visitors', 'visits', 'pending');
                            }}
                        >
                            <View style={[styles.mgmtIcon, { backgroundColor: '#fff7ed' }]}>
                                <Icon name="clock-outline" size={24} color="#ea580c" />
                            </View>
                            <Text style={styles.mgmtLabel}>Pending</Text>
                        </TouchableOpacity>

                        <TouchableOpacity
                            style={styles.mgmtGridItem}
                            onPress={() => {
                                setVisitorsModalVisible(false);
                                showModal('My History', 'visits');
                            }}
                        >
                            <View style={[styles.mgmtIcon, { backgroundColor: '#f0f9ff' }]}>
                                <Icon name="history" size={24} color="#0369a1" />
                            </View>
                            <Text style={styles.mgmtLabel}>Visitor Log</Text>
                        </TouchableOpacity>

                        <TouchableOpacity
                            style={styles.mgmtGridItem}
                            onPress={() => {
                                setVisitorsModalVisible(false);
                                navigation.navigate('InviteVisitor');
                            }}
                        >
                            <View style={[styles.mgmtIcon, { backgroundColor: '#f5f3ff' }]}>
                                <Icon name="email-send" size={24} color="#7c3aed" />
                            </View>
                            <Text style={styles.mgmtLabel}>Invitations</Text>
                        </TouchableOpacity>

                        <View style={styles.mgmtGridItem} />
                        <View style={styles.mgmtGridItem} />
                        <View style={styles.mgmtGridItem} />
                    </View>
                </Pressable>
            </Pressable>
        </Modal>
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
                        <TouchableOpacity
                            style={styles.fabSubButton}
                            onPress={() => {
                                setIsMenuOpen(false);
                                navigation.navigate('InviteVisitor');
                            }}
                        >
                            <Text style={styles.fabLabel}>Invite</Text>
                            <View style={[styles.fabIconWrapper, { backgroundColor: '#8b5cf6' }]}>
                                <Icon name="email-send" size={20} color="#fff" />
                            </View>
                        </TouchableOpacity>

                        <TouchableOpacity
                            style={styles.fabSubButton}
                            onPress={() => {
                                setIsMenuOpen(false);
                                navigation.navigate('ScanQR');
                            }}
                        >
                            <Text style={styles.fabLabel}>Scan QR</Text>
                            <View style={[styles.fabIconWrapper, { backgroundColor: '#ec4899' }]}>
                                <Icon name="qrcode-scan" size={20} color="#fff" />
                            </View>
                        </TouchableOpacity>

                        <TouchableOpacity
                            style={styles.fabSubButton}
                            onPress={() => {
                                setIsMenuOpen(false);
                                navigation.navigate('RegisterVisitor');
                            }}
                        >
                            <Text style={styles.fabLabel}>New Visit</Text>
                            <View style={[styles.fabIconWrapper, { backgroundColor: '#10b981' }]}>
                                <Icon name="account-plus" size={20} color="#fff" />
                            </View>
                        </TouchableOpacity>
                    </View>
                </View>
            </>
        );
    };

    const renderBottomMenu = () => (
        <View style={styles.bottomTabBar}>
            <TouchableOpacity style={styles.tabItem} onPress={() => setActiveTab('home')}>
                <Icon name="view-dashboard" size={24} color={activeTab === 'home' ? "#3b82f6" : "#64748b"} />
                <Text style={[styles.tabLabel, activeTab === 'home' && { color: '#3b82f6' }]}>Home</Text>
            </TouchableOpacity>

            <TouchableOpacity style={styles.tabItem} onPress={() => setActiveTab('visitors')}>
                <Icon name="account-group" size={24} color={activeTab === 'visitors' ? "#3b82f6" : "#64748b"} />
                <Text style={[styles.tabLabel, activeTab === 'visitors' && { color: '#3b82f6' }]}>Visitors</Text>
            </TouchableOpacity>

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

            <TouchableOpacity style={styles.tabItem} onPress={() => setSettingsModalVisible(true)}>
                <Icon name="cog" size={24} color="#64748b" />
                <Text style={styles.tabLabel}>Settings</Text>
            </TouchableOpacity>
        </View>
    );



    return (
        <SafeAreaView style={styles.container}>
            <StatusBar barStyle="dark-content" />

            {/* Stats Data Modal (Full Screen Fade like HostDashboard) */}
            <Modal
                animationType="fade"
                transparent={false}
                visible={modalVisible && modalType !== 'visits' && modalType !== 'overstays'}
                onRequestClose={() => setModalVisible(false)}
            >
                <SafeAreaView style={{ flex: 1, backgroundColor: '#f8fafc' }}>
                    <View style={[styles.fullModalHeader, {
                        backgroundColor: modalType === 'employees' ? '#ef4444' : '#8b5cf6'
                    }]}>
                        <TouchableOpacity onPress={() => setModalVisible(false)} style={styles.fullModalBack}>
                            <Icon name="arrow-left" size={24} color="#fff" />
                        </TouchableOpacity>
                        <Text style={styles.fullModalTitleText}>{modalTitle}</Text>
                    </View>

                    <View style={{ flex: 1 }}>
                        {modalType === 'employees' && renderEmployeeRecords()}
                        {modalType === 'efficiency' && (
                            <ScrollView style={styles.modalScroll}>
                                <Text style={styles.modalBody}>
                                    Overall efficiency is calculated based on {efficiency.satisfaction} visitor satisfaction and {stats.time_saved} saved across the organization.
                                </Text>
                            </ScrollView>
                        )}
                    </View>
                </SafeAreaView>
            </Modal>

            <VisitListModal
                visible={modalVisible && (modalType === 'visits' || modalType === 'overstays')}
                onClose={() => setModalVisible(false)}
                title={modalTitle}
                color={modalFilter === 'today' ? '#3b82f6' : (modalType === 'overstays' ? '#ef4444' : '#8b5cf6')}
                visits={(() => {
                    if (modalType === 'overstays') return aiInsights.overstay_list || [];
                    if (modalTitle === "Today's Visits") return todayVisits || [];
                    return records.visits || [];
                })()}
                onVisitPress={(visit) => {
                    handleRecordClick(visit, 'visits');
                }}
            />

            {/* Visit Details Modal */}
            <VisitDetailModal
                visible={detailsVisible}
                onClose={() => setDetailsVisible(false)}
                visit={selectedVisit}
                userRole="admin"
                onAction={(id, action) => {
                    handleAction(id, action);
                    setDetailsVisible(false);
                }}
            />

            {renderSweetAlert()}
            {renderMastersModal()}
            {renderReportsModal()}
            {renderSettingsModal()}
            {renderVisitorsModal()}
            {renderEmployeeDetailsModal()}

            {/* Filter Modal */}
            <Modal
                animationType="fade"
                transparent={true}
                visible={filterModalVisible}
                onRequestClose={() => setFilterModalVisible(false)}
            >
                <Pressable style={styles.modalOverlay} onPress={() => setFilterModalVisible(false)}>
                    <Pressable style={styles.filterModalContent} onPress={e => e.stopPropagation()}>
                        <View style={styles.modalHeader}>
                            <Text style={styles.modalTitle}>Filter by Date</Text>
                            <TouchableOpacity onPress={() => setFilterModalVisible(false)}>
                                <Icon name="close" size={24} color="#64748b" />
                            </TouchableOpacity>
                        </View>
                        <ScrollView style={{ paddingHorizontal: 20 }} contentContainerStyle={{ paddingBottom: 20 }}>
                            <TouchableOpacity
                                style={[styles.filterOption, filterType === 'all' && styles.filterOptionActive]}
                                onPress={() => { setFilterType('all'); setFilterModalVisible(false); setShowCustomPicker(false); }}
                            >
                                <Text style={[styles.filterOptionText, filterType === 'all' && styles.filterOptionTextActive]}>All Time</Text>
                            </TouchableOpacity>
                            <TouchableOpacity
                                style={[styles.filterOption, filterType === 'today' && styles.filterOptionActive]}
                                onPress={() => { setFilterType('today'); setFilterModalVisible(false); setShowCustomPicker(false); }}
                            >
                                <Text style={[styles.filterOptionText, filterType === 'today' && styles.filterOptionTextActive]}>Today</Text>
                            </TouchableOpacity>
                            <TouchableOpacity
                                style={[styles.filterOption, filterType === 'yesterday' && styles.filterOptionActive]}
                                onPress={() => { setFilterType('yesterday'); setFilterModalVisible(false); setShowCustomPicker(false); }}
                            >
                                <Text style={[styles.filterOptionText, filterType === 'yesterday' && styles.filterOptionTextActive]}>Yesterday</Text>
                            </TouchableOpacity>
                            <TouchableOpacity
                                style={[styles.filterOption, filterType === 'week' && styles.filterOptionActive]}
                                onPress={() => { setFilterType('week'); setFilterModalVisible(false); setShowCustomPicker(false); }}
                            >
                                <Text style={[styles.filterOptionText, filterType === 'week' && styles.filterOptionTextActive]}>Last 7 Days</Text>
                            </TouchableOpacity>

                            <TouchableOpacity
                                style={[styles.filterOption, filterType === 'custom' && styles.filterOptionActive]}
                                onPress={() => setShowCustomPicker(!showCustomPicker)}
                            >
                                <Text style={[styles.filterOptionText, filterType === 'custom' && styles.filterOptionTextActive]}>Custom Range</Text>
                                <Icon name={showCustomPicker ? "chevron-up" : "chevron-down"} size={20} color="#64748b" />
                            </TouchableOpacity>

                            {showCustomPicker && (
                                <View style={styles.customDateContainer}>
                                    <View style={styles.dateInputRow}>
                                        <View style={styles.dateInputWrapper}>
                                            <Text style={styles.dateLabel}>Start Date</Text>
                                            <TouchableOpacity
                                                style={styles.dateInputBtn}
                                                onPress={() => {
                                                    const d = tempStartDate ? new Date(tempStartDate) : new Date();
                                                    setDateState({ showStart: true, showEnd: false, calMonth: d.getMonth(), calYear: d.getFullYear(), calTarget: 'start' });
                                                }}
                                            >
                                                <Text style={styles.dateInputText}>{tempStartDate ? tempStartDate.split('-').reverse().join('-') : 'Select Date'}</Text>
                                                <Icon name="calendar" size={20} color="#64748b" />
                                            </TouchableOpacity>
                                        </View>
                                        <View style={styles.dateInputWrapper}>
                                            <Text style={styles.dateLabel}>End Date</Text>
                                            <TouchableOpacity
                                                style={styles.dateInputBtn}
                                                onPress={() => {
                                                    const d = tempEndDate ? new Date(tempEndDate) : new Date();
                                                    setDateState({ showEnd: true, showStart: false, calMonth: d.getMonth(), calYear: d.getFullYear(), calTarget: 'end' });
                                                }}
                                            >
                                                <Text style={styles.dateInputText}>{tempEndDate ? tempEndDate.split('-').reverse().join('-') : 'Select Date'}</Text>
                                                <Icon name="calendar" size={20} color="#64748b" />
                                            </TouchableOpacity>
                                        </View>
                                    </View>
                                    <TouchableOpacity
                                        style={styles.applyBtn}
                                        onPress={() => {
                                            if (!tempStartDate) {
                                                showAlert('Error', 'Please select a start date', 'error');
                                                return;
                                            }

                                            setFilterStartDate(tempStartDate);
                                            setFilterEndDate(tempEndDate || tempStartDate);
                                            setFilterType('custom');
                                            setFilterModalVisible(false);
                                        }}
                                    >
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
                                                <TouchableOpacity onPress={() => {
                                                    let m = dateState.calMonth - 1, y = dateState.calYear;
                                                    if (m < 0) { m = 11; y--; }
                                                    setDateState({ ...dateState, calMonth: m, calYear: y });
                                                }}>
                                                    <Icon name="chevron-left" size={28} color="#3b82f6" />
                                                </TouchableOpacity>
                                                <Text style={styles.calMonthLabel}>
                                                    {['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'][dateState.calMonth]} {dateState.calYear}
                                                </Text>
                                                <TouchableOpacity onPress={() => {
                                                    let m = dateState.calMonth + 1, y = dateState.calYear;
                                                    if (m > 11) { m = 0; y++; }
                                                    setDateState({ ...dateState, calMonth: m, calYear: y });
                                                }}>
                                                    <Icon name="chevron-right" size={28} color="#3b82f6" />
                                                </TouchableOpacity>
                                            </View>
                                            <View style={styles.calDaysHeader}>
                                                {['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'].map(d => (
                                                    <Text key={d} style={styles.calDayLabel}>{d}</Text>
                                                ))}
                                            </View>
                                            <View style={styles.calGrid}>
                                                {(() => {
                                                    const firstDay = new Date(dateState.calYear, dateState.calMonth, 1).getDay();
                                                    const daysInMonth = new Date(dateState.calYear, dateState.calMonth + 1, 0).getDate();
                                                    const cells = [];
                                                    for (let i = 0; i < firstDay; i++) cells.push(<View key={`e${i}`} style={styles.calCell} />);
                                                    const todayStr = new Date().toISOString().split('T')[0];
                                                    const selectedStr = dateState.calTarget === 'start' ? tempStartDate : tempEndDate;
                                                    for (let day = 1; day <= daysInMonth; day++) {
                                                        const dateStr = `${dateState.calYear}-${String(dateState.calMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                                                        const isSelected = dateStr === selectedStr;
                                                        const isToday = dateStr === todayStr;
                                                        cells.push(
                                                            <TouchableOpacity
                                                                key={day}
                                                                style={[styles.calCell, isSelected && styles.calCellSelected, isToday && !isSelected && styles.calCellToday]}
                                                                onPress={() => {
                                                                    if (dateState.calTarget === 'start') {
                                                                        setTempStartDate(dateStr);
                                                                    } else {
                                                                        setTempEndDate(dateStr);
                                                                    }
                                                                    setDateState({ ...dateState, showStart: false, showEnd: false });
                                                                }}
                                                            >
                                                                <Text style={[styles.calCellText, isSelected && styles.calCellTextSelected, isToday && !isSelected && styles.calCellTextToday]}>{day}</Text>
                                                            </TouchableOpacity>
                                                        );
                                                    }
                                                    return cells;
                                                })()}
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

            <View style={styles.header}>
                <View style={{ flex: 1 }}>
                    {isSearching ? (
                        <View style={styles.searchContainer}>
                            <Icon name="magnify" size={20} color="#64748b" style={styles.searchIcon} />
                            <TextInput
                                style={styles.searchInput}
                                placeholder="Search Visitors..."
                                value={searchQuery}
                                onChangeText={setSearchQuery}
                                autoFocus
                            />
                            <TouchableOpacity onPress={() => {
                                setIsSearching(false);
                                setSearchQuery('');
                            }}>
                                <Icon name="close-circle" size={20} color="#94a3b8" />
                            </TouchableOpacity>
                        </View>
                    ) : (
                        <>
                            <Text style={styles.greeting}>Admin Portal (v2)</Text>
                            <Text style={styles.userName}>{userData?.full_name || 'Administrator'}</Text>
                        </>
                    )}
                </View>
                {!isSearching && (
                    <View style={styles.headerActions}>
                        <TouchableOpacity
                            style={styles.searchBtn}
                            onPress={() => setIsSearching(true)}
                        >
                            <Icon name="magnify" size={24} color="#3b82f6" />
                        </TouchableOpacity>
                        <TouchableOpacity
                            style={styles.logoutBtn}
                            onPress={async () => {
                                await AsyncStorage.removeItem('userData');
                                navigation.replace('Login');
                            }}
                        >
                            <Text style={styles.logoutText}>Logout</Text>
                        </TouchableOpacity>
                    </View>
                )}
            </View>

            <ScrollView
                contentContainerStyle={styles.scrollContent}
                refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} />}
            >
                {loading ? (
                    <View style={{ padding: 50, alignItems: 'center', justifyContent: 'center' }}>
                        <ActivityIndicator size="large" color="#3b82f6" />
                        <Text style={{ marginTop: 15, color: '#64748b', fontSize: 16 }}>Loading Dashboard...</Text>
                    </View>
                ) : searchQuery ? (
                    <View style={styles.card}>
                        <Text style={styles.cardTitle}>Search Results</Text>
                        {records.visits
                            .filter(v =>
                                v.visitor_name.toLowerCase().includes(searchQuery.toLowerCase()) ||
                                v.host_name.toLowerCase().includes(searchQuery.toLowerCase()) ||
                                v.mobile?.includes(searchQuery)
                            )
                            .map((visit, idx) => (
                                <TouchableOpacity
                                    key={idx}
                                    style={styles.recordRow}
                                    onPress={() => handleRecordClick(visit, 'visits')}
                                >
                                    <View style={[styles.recordStatusIndicator, { backgroundColor: visit.status === 'checked_in' ? '#10b981' : '#64748b' }]} />
                                    <View style={styles.recordInfo}>
                                        <Text style={styles.recordName}>{visit.visitor_name}</Text>
                                        <Text style={styles.recordSub}>{visit.host_name} • {formatDate(visit.created_at)}</Text>
                                    </View>
                                    <View style={styles.recordAction}>
                                        <Text style={styles.recordStatusBadge}>{visit.status.replace('_', ' ').toUpperCase()}</Text>
                                    </View>
                                </TouchableOpacity>
                            ))
                        }
                        {records.visits.filter(v =>
                            v.visitor_name.toLowerCase().includes(searchQuery.toLowerCase()) ||
                            v.host_name.toLowerCase().includes(searchQuery.toLowerCase()) ||
                            v.mobile?.includes(searchQuery)
                        ).length === 0 && (
                                <Text style={styles.noDataText}>No visitors match your search.</Text>
                            )}
                    </View>
                ) : (
                    error || (!stats.total_employees && !recentActivity.length) ? (
                        <View style={styles.noDataContainer}>
                            <Text style={styles.noDataTitle}>{error ? 'Connection Error' : 'No Data Found'}</Text>
                            <Text style={styles.noDataDesc}>
                                {error || "We couldn't retrieve any analytics. Please ensure the server is reachable and data exists."}
                            </Text>
                            <TouchableOpacity style={styles.retryBtn} onPress={onRefresh}>
                                <Text style={styles.retryText}>Retry</Text>
                            </TouchableOpacity>
                        </View>
                    ) : (
                        <>
                            {activeTab === 'home' && (
                                <>
                                    {/* Stat Cards */}
                                    <View style={styles.statsGrid}>
                                        <View style={{ flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', width: '100%' }}>
                                            <TouchableOpacity
                                                style={[styles.statCard, { backgroundColor: '#ef4444', width: '31%' }]}
                                                onPress={() => showModal('Employee Records', 'employees')}
                                            >
                                                <Text style={styles.statValue}>{stats.total_employees}</Text>
                                                <Text style={styles.statLabel}>Total Employees</Text>
                                            </TouchableOpacity>
                                            <TouchableOpacity
                                                style={[styles.statCard, { backgroundColor: '#8b5cf6', width: '31%' }]}
                                                onPress={() => showModal('Visit Records', 'visits')}
                                            >
                                                <Text style={styles.statValue}>{stats.total_visits}</Text>
                                                <Text style={styles.statLabel}>Total Visits</Text>
                                            </TouchableOpacity>
                                            <TouchableOpacity
                                                style={[styles.statCard, { backgroundColor: '#3b82f6', width: '31%' }]}
                                                onPress={() => showModal('Today\'s Visits', 'visits', 'today')}
                                            >
                                                <Text style={styles.statValue}>{stats.today_visits}</Text>
                                                <Text style={styles.statLabel}>Today's Visits</Text>
                                            </TouchableOpacity>
                                        </View>
                                    </View>

                                    {/* Overstay Alerts */}
                                    {renderOverstayAlerts()}

                                    {/* Efficiency Metrics */}
                                    {renderEfficiencyMetrics()}

                                    {/* Zone Density */}
                                    {renderZoneDensity()}

                                    {/* Trends Chart */}
                                    {renderTrendChart()}

                                    {/* Most Visited Hosts */}
                                    {renderMostVisitedHosts()}

                                    {/* AI Insights */}
                                    <View style={[styles.card, styles.aiCard]}>
                                        <View style={styles.aiHeader}>
                                            <View style={styles.aiTitleRow}>
                                                <Icon name="auto-fix" size={24} color="#fff" />
                                                <Text style={styles.aiCardTitle}>AI Insights</Text>
                                            </View>
                                            <Icon name="robot-outline" size={40} color="rgba(255,255,255,0.2)" />
                                        </View>

                                        <View style={styles.aiSection}>
                                            <Text style={styles.aiSectionLabel}>PREDICTION FOR TOMORROW</Text>
                                            <View style={styles.predictionRow}>
                                                <Text style={styles.aiLargeValue}>~{aiInsights.prediction_tomorrow}</Text>
                                                <View style={styles.predictionBadge}>
                                                    <Icon name="arrow-up" size={12} color="#3b82f6" />
                                                    <Text style={styles.predictionPercent}>{aiInsights.prediction_change || '+10%'}</Text>
                                                </View>
                                            </View>
                                            <Text style={styles.aiSubText}>Based on historical weekday patterns.</Text>
                                        </View>

                                        <View style={styles.aiSection}>
                                            <Text style={styles.aiSectionLabel}>CROWD DENSITY (LIVE)</Text>
                                            <View style={styles.densityProgressContainer}>
                                                <View style={[styles.densityProgressBar, { width: `${aiInsights.crowd_density}%`, backgroundColor: getDensityColor(aiInsights.crowd_density) }]} />
                                            </View>
                                            <View style={styles.densityStatusRow}>
                                                <Icon name="chart-line" size={16} color="#fff" />
                                                <Text style={styles.densityStatusText}>
                                                    {aiInsights.crowd_density < 30 ? 'Optimal' : (aiInsights.crowd_density < 70 ? 'Moderate' : 'High')} ({aiInsights.active_visitors || 0}/{stats.max_capacity || 50})
                                                </Text>
                                            </View>
                                        </View>

                                        {aiInsights.overstay_count > 0 && (
                                            <View style={styles.anomalyAlert}>
                                                <Text style={styles.anomalyTitle}>Anomaly Alert</Text>
                                                <Text style={styles.anomalySubText}>{aiInsights.overstay_count} visitor(s) overstaying.</Text>
                                            </View>
                                        )}
                                    </View>

                                    {/* Recent Activity */}
                                    {renderRecentActivity()}

                                    <View style={{ height: 100 }} />
                                </>
                            )}

                            {activeTab === 'visitors' && renderVisitors()}
                        </>
                    )
                )}
            </ScrollView>

            {/* FAB & Bottom Menu */}
            {renderFloatingMenu()}
            {renderBottomMenu()}
        </SafeAreaView>
    );
}

const styles = StyleSheet.create({
    fullModalHeader: {
        flexDirection: 'row',
        alignItems: 'center',
        paddingHorizontal: 20,
        paddingVertical: 18,
        elevation: 4,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.2,
        shadowRadius: 3,
    },
    fullModalBack: {
        padding: 5,
        marginRight: 10,
    },
    fullModalTitleText: {
        color: '#fff',
        fontSize: 20,
        fontWeight: 'bold',
    },
    modalSearchContainer: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: '#f1f5f9',
        borderRadius: 12,
        marginHorizontal: 16,
        marginTop: 10,
        marginBottom: 5,
        paddingHorizontal: 12,
        height: 40,
    },
    modalSearchIcon: {
        marginRight: 8,
    },
    modalSearchInput: {
        flex: 1,
        fontSize: 14,
        color: '#1e293b',
        paddingVertical: 8,
    },
    chartContainer: {
        backgroundColor: '#fff',
        borderRadius: 16,
        padding: 16,
        marginBottom: 16,
        elevation: 2,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.1,
        shadowRadius: 4,
    },
    chartRow: {
        flexDirection: 'row',
        alignItems: 'flex-end',
        justifyContent: 'space-between',
        height: 180,
        paddingTop: 10,
    },
    chartBarCol: {
        alignItems: 'center',
        flex: 1,
    },
    chartBar: {
        width: 12,
        backgroundColor: '#3b82f6',
        borderRadius: 6,
        marginBottom: 8,
    },
    chartLabel: {
        fontSize: 10,
        color: '#64748b',
        fontWeight: '500',
    },
    emptyChart: {
        height: 150,
        justifyContent: 'center',
        alignItems: 'center',
        backgroundColor: '#f8fafc',
        borderRadius: 12,
        borderWidth: 1,
        borderColor: '#e2e8f0',
        borderStyle: 'dashed',
    },
    overstayCard: {
        backgroundColor: '#fff1f2',
        borderWidth: 1,
        borderColor: '#fecdd3',
    },
    noOverstayCard: {
        backgroundColor: '#f0fdf4',
        borderColor: '#dcfce7',
    },
    alertHeader: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        marginBottom: 8,
    },
    alertTitle: {
        fontSize: 16,
        fontWeight: 'bold',
        color: '#991b1b',
    },
    noAlertTitle: {
        color: '#166534',
    },
    alertDesc: {
        fontSize: 13,
        color: '#b91c1c',
        lineHeight: 18,
    },
    noAlertDesc: {
        color: '#15803d',
    },
    badge: {
        backgroundColor: '#ef4444',
        paddingHorizontal: 8,
        paddingVertical: 2,
        borderRadius: 10,
    },
    badgeText: {
        color: '#fff',
        fontSize: 12,
        fontWeight: 'bold',
    },
    modalBody: {
        fontSize: 14,
        color: '#475569',
        lineHeight: 20,
        padding: 20,
    },
    modalScroll: {
        maxHeight: height * 0.7,
        padding: 16,
    },
    noDataText: {
        fontSize: 14,
        color: '#94a3b8',
        textAlign: 'center',
        marginTop: 10,
    },
    container: {
        flex: 1,
        backgroundColor: '#f8fafc',
    },
    loadingContainer: {
        flex: 1,
        justifyContent: 'center',
        alignItems: 'center',
        backgroundColor: '#f8fafc',
    },
    loadingText: {
        marginTop: 10,
        color: '#64748b',
        fontSize: 16,
    },
    noDataContainer: {
        padding: 40,
        alignItems: 'center',
        justifyContent: 'center',
    },
    noDataTitle: {
        fontSize: 20,
        fontWeight: 'bold',
        color: '#1e293b',
        marginBottom: 8,
    },
    noDataDesc: {
        fontSize: 14,
        color: '#64748b',
        textAlign: 'center',
        marginBottom: 20,
    },
    retryBtn: {
        backgroundColor: '#3b82f6',
        paddingHorizontal: 24,
        paddingVertical: 12,
        borderRadius: 8,
    },
    retryText: {
        color: '#fff',
        fontWeight: '600',
    },
    header: {
        flexDirection: 'row',
        padding: 20,
        backgroundColor: '#fff',
        alignItems: 'center',
        borderBottomWidth: 1,
        borderBottomColor: '#f1f5f9'
    },
    headerActions: {
        flexDirection: 'row',
        alignItems: 'center',
    },
    searchBtn: {
        marginRight: 15,
        padding: 5,
    },
    searchContainer: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: '#f1f5f9',
        borderRadius: 12,
        paddingHorizontal: 12,
        height: 45,
    },
    searchIcon: {
        marginRight: 8,
    },
    searchInput: {
        flex: 1,
        fontSize: 16,
        color: '#1e293b',
        paddingVertical: 8,
    },
    greeting: {
        fontSize: 14,
        color: '#64748b'
    },
    userName: {
        fontSize: 20,
        fontWeight: 'bold',
        color: '#1e293b'
    },
    logoutBtn: {
        backgroundColor: '#fee2e2',
        paddingHorizontal: 12,
        paddingVertical: 6,
        borderRadius: 20
    },
    logoutText: {
        color: '#ef4444',
        fontWeight: '600',
        fontSize: 12
    },
    scrollContent: {
        padding: 16,
    },
    cardHeaderRow: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        marginBottom: 15,
    },
    satisfactionBadge: {
        backgroundColor: '#dcfce7',
        paddingHorizontal: 8,
        paddingVertical: 4,
        borderRadius: 12,
    },
    satisfactionText: {
        fontSize: 11,
        fontWeight: 'bold',
        color: '#166534',
    },
    zoneList: {
        marginTop: 5,
    },
    statsGrid: {
        flexDirection: 'row',
        flexWrap: 'wrap',
        justifyContent: 'space-between',
        marginBottom: 20,
    },
    statCard: {
        width: (width - 40) / 3 - 7,
        padding: 15,
        borderRadius: 16,
        elevation: 2,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.1,
        shadowRadius: 4,
        alignItems: 'center'
    },
    statValue: { fontSize: 24, fontWeight: 'bold', color: '#fff' },
    statLabel: { fontSize: 12, color: 'rgba(255,255,255,0.8)', marginTop: 4, textAlign: 'center' },
    cardTitle: {
        fontSize: 16,
        fontWeight: 'bold',
        color: '#1e293b',
        marginBottom: 15,
    },
    efficiencyList: {
        marginTop: 5,
    },
    efficiencyItem: {
        flexDirection: 'row',
        alignItems: 'center',
        marginBottom: 15,
        backgroundColor: '#f8fafc',
        padding: 12,
        borderRadius: 12,
    },
    effIconContainer: {
        width: 40,
        height: 40,
        borderRadius: 20,
        backgroundColor: '#ffffff',
        justifyContent: 'center',
        alignItems: 'center',
        marginRight: 15,
        elevation: 1,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 1 },
        shadowOpacity: 0.1,
        shadowRadius: 1,
    },
    effIcon: {
        fontSize: 20,
    },
    effDetails: {
        flex: 1,
    },
    effLabel: {
        fontSize: 12,
        color: '#64748b',
        marginBottom: 2,
    },
    effValue: {
        fontSize: 16,
        fontWeight: 'bold',
        color: '#1e293b',
    },
    zoneInfo: {
        flex: 1,
    },
    zoneStatus: {
        fontSize: 12,
        fontWeight: '500',
    },
    zoneCountBadge: {
        backgroundColor: '#f1f5f9',
        paddingHorizontal: 10,
        paddingVertical: 4,
        borderRadius: 12,
        borderWidth: 1,
        borderColor: '#e2e8f0',
    },
    zoneCountText: {
        fontSize: 12,
        fontWeight: 'bold',
        color: '#475569',
    },
    zoneRow: {
        flexDirection: 'row',
        alignItems: 'center',
        paddingVertical: 12,
        borderBottomWidth: 1,
        borderBottomColor: '#f1f5f9',
    },
    zoneName: {
        fontSize: 15,
        fontWeight: '600',
        color: '#1e293b',
        marginBottom: 2,
    },
    toggleContainer: {
        flexDirection: 'row',
        backgroundColor: '#f1f5f9',
        borderRadius: 20,
        padding: 2,
    },
    toggleBtn: {
        paddingHorizontal: 15,
        paddingVertical: 6,
        borderRadius: 18,
    },
    toggleBtnActive: {
        backgroundColor: '#3b82f6',
    },
    toggleText: {
        fontSize: 12,
        fontWeight: '600',
        color: '#64748b',
    },
    toggleTextActive: {
        color: '#ffffff',
    },
    efficiencyCard: {
        backgroundColor: '#fff',
        borderRadius: 16,
        padding: 16,
        marginBottom: 16,
        elevation: 2,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.1,
        shadowRadius: 4,
    },
    zoneCard: {
        backgroundColor: '#fff',
        borderRadius: 16,
        padding: 16,
        marginBottom: 16,
        elevation: 2,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.1,
        shadowRadius: 4,
    },
    aiCard: {
        backgroundColor: '#f8fafc',
        borderWidth: 1,
        borderColor: '#e2e8f0',
    },
    aiCardTitle: {
        fontSize: 16,
        fontWeight: 'bold',
        color: '#1e293b',
        marginBottom: 15,
    },
    aiGrid: {
        flexDirection: 'row',
        justifyContent: 'space-between',
    },
    aiItem: {
        flex: 1,
        backgroundColor: '#fff',
        padding: 15,
        borderRadius: 12,
        marginHorizontal: 4,
        alignItems: 'center',
        elevation: 1,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 1 },
        shadowOpacity: 0.05,
        shadowRadius: 2,
    },
    aiValue: {
        fontSize: 20,
        fontWeight: 'bold',
        color: '#3b82f6',
        marginBottom: 4,
    },
    aiLabel: {
        fontSize: 10,
        color: '#64748b',
        textAlign: 'center',
    },
    card: {
        backgroundColor: '#fff',
        borderRadius: 16,
        padding: 16,
        marginBottom: 16,
        elevation: 2,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.1,
        shadowRadius: 4,
    },
    activityCard: {
        backgroundColor: '#f0f9ff',
        borderRadius: 16,
        padding: 16,
        marginBottom: 16,
        elevation: 2,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.1,
        shadowRadius: 4,
        borderWidth: 1,
        borderColor: '#e0f2fe',
    },
    viewAllText: {
        fontSize: 12,
        color: '#3b82f6',
        fontWeight: '600',
    },
    activityItem: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: '#fff',
        padding: 12,
        borderRadius: 12,
        marginBottom: 8,
        borderWidth: 1,
        borderColor: '#f1f5f9',
    },
    activityPhotoContainer: {
        width: 44,
        height: 44,
        borderRadius: 22,
        backgroundColor: '#f1f5f9',
        justifyContent: 'center',
        alignItems: 'center',
        marginRight: 12,
        overflow: 'hidden',
    },
    miniVisitorPhoto: {
        width: '100%',
        height: '100%',
    },
    activityIconContainer: {
        width: '100%',
        height: '100%',
        justifyContent: 'center',
        alignItems: 'center',
    },
    activityInfo: {
        flex: 1,
    },
    activityTopRow: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        marginBottom: 2,
    },
    visitorName: {
        fontSize: 15,
        fontWeight: 'bold',
        color: '#1e293b',
        flex: 1,
        marginRight: 8,
    },
    activityTime: {
        fontSize: 11,
        color: '#64748b',
        fontWeight: '500',
    },
    activityBottomRow: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
    },
    hostName: {
        fontSize: 13,
        color: '#64748b',
        flex: 1,
    },
    miniStatusBadge: {
        paddingHorizontal: 6,
        paddingVertical: 2,
        borderRadius: 4,
        marginLeft: 8,
    },
    miniStatusText: {
        fontSize: 10,
        fontWeight: 'bold',
    },
    hostRow: {
        flexDirection: 'row',
        alignItems: 'center',
        paddingVertical: 10,
        borderBottomWidth: 1,
        borderBottomColor: '#f1f5f9',
    },
    hostInfo: {
        flex: 1,
    },
    hostNameMain: {
        fontSize: 14,
        fontWeight: '600',
        color: '#1e293b',
    },
    hostVisitCount: {
        fontSize: 12,
        color: '#64748b',
        marginTop: 2,
    },
    hostRank: {
        backgroundColor: '#f1f5f9',
        width: 30,
        height: 30,
        borderRadius: 15,
        justifyContent: 'center',
        alignItems: 'center',
    },
    hostRankText: {
        fontSize: 12,
        fontWeight: 'bold',
        color: '#475569',
    },
    visitorPhoto: {
        width: 80,
        height: 80,
        borderRadius: 40,
    },
    zoneCard: {
        backgroundColor: '#f0fdf4',
        borderRadius: 16,
        padding: 16,
        marginBottom: 16,
        elevation: 2,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.1,
        shadowRadius: 4,
        borderWidth: 1,
        borderColor: '#dcfce7',
    },
    efficiencyCard: {
        backgroundColor: '#f5f3ff', // Light purple background
        borderRadius: 16,
        padding: 16,
        marginBottom: 16,
        elevation: 2,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.1,
        shadowRadius: 4,
        borderWidth: 1,
        borderColor: '#ede9fe',
    },
    bottomTabBar: {
        position: 'absolute',
        bottom: 0,
        left: 0,
        right: 0,
        height: 85,
        backgroundColor: '#ffffff',
        flexDirection: 'row',
        justifyContent: 'space-around',
        alignItems: 'center',
        borderTopWidth: 1,
        borderTopColor: '#e2e8f0',
        paddingBottom: 25,
        elevation: 100, // Very high elevation
        zIndex: 9999, // Very high z-index
        shadowColor: '#000',
        shadowOffset: { width: 0, height: -4 },
        shadowOpacity: 0.1,
        shadowRadius: 8,
    },
    tabItem: {
        alignItems: 'center',
        justifyContent: 'center',
        flex: 1,
    },
    tabLabel: {
        fontSize: 10,
        fontWeight: '600',
        color: '#64748b',
        marginTop: 4,
    },
    fabContainer: {
        position: 'absolute',
        bottom: 110,
        right: 20,
        alignItems: 'flex-end',
        zIndex: 10000, // Keep FAB above the tab bar
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
    fabOverlay: {
        ...StyleSheet.absoluteFillObject,
        backgroundColor: 'rgba(15, 23, 42, 0.5)',
        zIndex: 99,
    },
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
    cardHeaderRow: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        marginBottom: 16,
    },
    satisfactionBadge: {
        backgroundColor: '#dcfce7',
        paddingHorizontal: 8,
        paddingVertical: 4,
        borderRadius: 12,
    },
    satisfactionText: {
        color: '#166534',
        fontSize: 10,
        fontWeight: 'bold',
    },
    efficiencyGrid: {
        flexDirection: 'row',
        justifyContent: 'space-between',
    },
    effItem: {
        flex: 1,
        alignItems: 'center',
    },
    chartContainer: {
        backgroundColor: '#fff',
        borderRadius: 16,
        padding: 16,
        marginBottom: 16,
    },
    chartRow: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'flex-end',
        height: 180,
        paddingBottom: 20,
    },
    chartBarCol: {
        alignItems: 'center',
        flex: 1,
    },
    chartBar: {
        width: 12,
        backgroundColor: '#3b82f6',
        borderRadius: 6,
    },
    chartLabel: {
        fontSize: 10,
        color: '#64748b',
        marginTop: 8,
        position: 'absolute',
        bottom: -20,
    },
    aiCard: {
        backgroundColor: '#3b82f6',
        borderRadius: 24,
        padding: 24,
    },
    aiHeader: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'flex-start',
        marginBottom: 20,
    },
    aiTitleRow: {
        flexDirection: 'row',
        alignItems: 'center',
    },
    aiCardTitle: {
        fontSize: 22,
        fontWeight: 'bold',
        color: '#fff',
        marginLeft: 8,
    },
    aiSection: {
        marginBottom: 24,
    },
    aiSectionLabel: {
        fontSize: 12,
        fontWeight: '800',
        color: 'rgba(255,255,255,0.7)',
        letterSpacing: 1,
        marginBottom: 12,
    },
    predictionRow: {
        flexDirection: 'row',
        alignItems: 'center',
        marginBottom: 8,
    },
    aiLargeValue: {
        fontSize: 48,
        fontWeight: 'bold',
        color: '#fff',
        marginRight: 12,
    },
    predictionBadge: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: '#fff',
        paddingHorizontal: 12,
        paddingVertical: 6,
        borderRadius: 20,
    },
    predictionPercent: {
        fontSize: 14,
        fontWeight: 'bold',
        color: '#3b82f6',
        marginLeft: 4,
    },
    aiSubText: {
        fontSize: 14,
        color: 'rgba(255,255,255,0.8)',
    },
    densityProgressContainer: {
        height: 6,
        backgroundColor: 'rgba(255,255,255,0.2)',
        borderRadius: 3,
        marginBottom: 12,
        overflow: 'hidden',
    },
    densityProgressBar: {
        height: '100%',
        borderRadius: 3,
    },
    densityStatusRow: {
        flexDirection: 'row',
        alignItems: 'center',
    },
    densityStatusText: {
        fontSize: 16,
        fontWeight: 'bold',
        color: '#fff',
        marginLeft: 8,
    },
    anomalyAlert: {
        backgroundColor: 'rgba(255,255,255,0.1)',
        borderRadius: 16,
        padding: 20,
        borderWidth: 1,
        borderColor: 'rgba(255,255,255,0.2)',
    },
    anomalyTitle: {
        fontSize: 18,
        fontWeight: 'bold',
        color: '#fff',
        marginBottom: 4,
    },
    anomalySubText: {
        fontSize: 14,
        color: 'rgba(255,255,255,0.8)',
    },
    aiGrid: {
        flexDirection: 'row',
        justifyContent: 'space-between',
    },
    zoneBarContainer: {
        flex: 1,
        height: 8,
        backgroundColor: '#f1f5f9',
        borderRadius: 4,
        marginHorizontal: 12,
    },
    zoneBar: {
        height: '100%',
        backgroundColor: '#3b82f6',
        borderRadius: 4,
    },
    zoneCount: {
        width: 20,
        fontSize: 12,
        fontWeight: '600',
        color: '#1e293b',
        textAlign: 'right',
    },
    mapPlaceholder: {
        padding: 10,
        alignItems: 'center',
    },
    mapContainer: {
        flexDirection: 'row',
        flexWrap: 'wrap',
        justifyContent: 'center',
        width: '100%',
        marginBottom: 10,
    },
    mapZone: {
        width: 60,
        height: 60,
        margin: 5,
        borderRadius: 8,
        justifyContent: 'center',
        alignItems: 'center',
    },
    mapZoneText: {
        color: '#fff',
        fontSize: 10,
        fontWeight: 'bold',
    },
    mapZoneCount: {
        color: '#fff',
        fontSize: 14,
        fontWeight: 'bold',
    },
    mapHint: {
        fontSize: 10,
        color: '#94a3b8',
        fontStyle: 'italic',
    },
    overstayCard: {
        backgroundColor: '#fff1f2',
        borderLeftWidth: 4,
        borderLeftColor: '#ef4444',
    },
    alertHeader: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        marginBottom: 8,
    },
    alertTitle: {
        fontSize: 16,
        fontWeight: 'bold',
        color: '#991b1b',
    },
    badge: {
        backgroundColor: '#ef4444',
        borderRadius: 12,
        paddingHorizontal: 8,
        paddingVertical: 2,
    },
    badgeText: {
        color: '#fff',
        fontSize: 12,
        fontWeight: 'bold',
    },
    alertDesc: {
        fontSize: 13,
        color: '#b91c1c',
    },
    activityRow: {
        flexDirection: 'row',
        alignItems: 'center',
        paddingVertical: 12,
        borderBottomWidth: 1,
        borderBottomColor: '#f1f5f9',
    },
    activityName: {
        fontSize: 14,
        fontWeight: '600',
        color: '#1e293b',
    },
    activitySub: {
        fontSize: 11,
        color: '#64748b',
        marginTop: 2,
    },
    modalOverlay: {
        flex: 1,
        backgroundColor: 'rgba(0, 0, 0, 0.5)',
        justifyContent: 'flex-end',
    },
    modalView: {
        backgroundColor: '#fff',
        borderTopLeftRadius: 24,
        borderTopRightRadius: 24,
        padding: 20,
        maxHeight: height * 0.8,
        minHeight: height * 0.4,
    },
    modalHeader: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        padding: 20,
        borderBottomWidth: 1,
        borderBottomColor: '#f1f5f9',
    },
    detailsModalView: {
        width: '100%',
        height: '90%',
        backgroundColor: '#fff',
        borderTopLeftRadius: 25,
        borderTopRightRadius: 25,
        marginTop: 'auto',
    },
    detailsHeader: {
        backgroundColor: '#3b82f6',
        padding: 20,
        paddingTop: 15,
    },
    detailsHeaderTop: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        marginBottom: 15,
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
        marginRight: 15,
        width: 80,
        height: 80,
        borderRadius: 40,
        backgroundColor: 'rgba(255,255,255,0.2)',
        justifyContent: 'center',
        alignItems: 'center',
        borderWidth: 3,
        borderColor: 'rgba(255,255,255,0.5)',
        overflow: 'hidden',
    },
    visitorPhoto: {
        width: '100%',
        height: '100%',
        borderRadius: 40,
    },
    detailsBasic: {
        flex: 1,
    },
    detailsName: {
        fontSize: 22,
        fontWeight: 'bold',
        color: '#fff',
    },
    detailsMobile: {
        fontSize: 16,
        color: 'rgba(255,255,255,0.8)',
        marginBottom: 5,
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
    modalTitle: {
        fontSize: 18,
        fontWeight: 'bold',
        color: '#1e293b',
    },
    closeBtn: {
        fontSize: 20,
        color: '#64748b',
        padding: 5,
    },
    modalScroll: {
        marginBottom: 20,
    },
    modalBody: {
        fontSize: 15,
        color: '#475569',
        lineHeight: 22,
    },
    recordRow: {
        flexDirection: 'row',
        alignItems: 'center',
        paddingVertical: 12,
        borderBottomWidth: 1,
        borderBottomColor: '#f1f5f9',
    },
    recordAvatar: {
        width: 48,
        height: 48,
        borderRadius: 24,
        backgroundColor: '#e2e8f0',
        justifyContent: 'center',
        alignItems: 'center',
        marginRight: 15,
        position: 'relative',
    },
    avatarText: {
        fontSize: 18,
        fontWeight: 'bold',
        color: '#475569',
    },
    employeeCard: {
        backgroundColor: '#fff',
        borderRadius: 16,
        padding: 16,
        marginBottom: 12,
        borderWidth: 1,
        borderColor: '#f1f5f9',
        elevation: 1,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 1 },
        shadowOpacity: 0.05,
        shadowRadius: 2,
    },
    employeeMainInfo: {
        flexDirection: 'row',
        alignItems: 'center',
        marginBottom: 16,
    },
    employeeDetails: {
        flex: 1,
    },
    employeeName: {
        fontSize: 16,
        fontWeight: 'bold',
        color: '#1e293b',
        marginBottom: 2,
    },
    employeeDept: {
        fontSize: 13,
        color: '#64748b',
    },
    employeeRole: {
        fontSize: 12,
        color: '#94a3b8',
        marginTop: 2,
    },
    employeeContactRow: {
        flexDirection: 'row',
        borderTopWidth: 1,
        borderTopColor: '#f1f5f9',
        paddingTop: 12,
        justifyContent: 'space-between',
    },
    contactItem: {
        flexDirection: 'row',
        alignItems: 'center',
        flex: 1,
    },
    contactText: {
        fontSize: 13,
        color: '#3b82f6',
        marginLeft: 8,
        fontWeight: '500',
    },
    statusDot: {
        position: 'absolute',
        bottom: 2,
        right: 2,
        width: 12,
        height: 12,
        borderRadius: 6,
        borderWidth: 2,
        borderColor: '#fff',
    },
    recordStatusIndicator: {
        width: 4,
        height: 30,
        borderRadius: 2,
        marginRight: 12,
    },
    emptyState: {
        alignItems: 'center',
        justifyContent: 'center',
        padding: 40,
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
        marginTop: 2,
    },
    recordMetaRow: {
        flexDirection: 'row',
        alignItems: 'center',
        marginTop: 2,
    },
    recordDot: {
        fontSize: 11,
        color: '#cbd5e1',
        marginHorizontal: 4,
    },
    recordPurpose: {
        fontSize: 11,
        color: '#94a3b8',
        fontStyle: 'italic',
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
    recordAction: {
        marginLeft: 8,
    },
    recordStatusBadge: {
        fontSize: 10,
        color: '#3b82f6',
        backgroundColor: '#eff6ff',
        paddingHorizontal: 8,
        paddingVertical: 2,
        borderRadius: 10,
        textTransform: 'uppercase',
        fontWeight: 'bold',
    },
    noDataText: {
        textAlign: 'center',
        color: '#94a3b8',
        marginTop: 20,
        fontSize: 13,
    },
    detailRow: {
        flexDirection: 'row',
        justifyContent: 'space-between',
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
        marginTop: 2,
    },
    detailTime: {
        fontSize: 11,
        color: '#ef4444',
        fontWeight: 'bold',
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
    detailActionCol: {
        alignItems: 'flex-end',
    },
    detailDateText: {
        fontSize: 10,
        color: '#94a3b8',
        marginTop: 2,
    },
    // New Visitor Interface Styles - Matching Security Dashboard
    visitRowBig: { flexDirection: 'row', alignItems: 'center', backgroundColor: '#fff', padding: 15, borderRadius: 20, marginBottom: 12, elevation: 1 },
    visitorThumbBig: { width: 60, height: 60, borderRadius: 30, marginRight: 15 },
    visitInfo: { flex: 1 },
    visitorNameTextBig: { fontSize: 17, fontWeight: '800', color: '#1e293b' },
    visitDetailsText: { fontSize: 12, color: '#64748b', marginTop: 2 },
    timeTagRow: { flexDirection: 'row', gap: 10, marginTop: 8 },
    timeTagText: { fontSize: 11, fontWeight: '600', color: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.1)', paddingHorizontal: 8, paddingVertical: 2, borderRadius: 6 },
    statusTag: { paddingHorizontal: 10, paddingVertical: 4, borderRadius: 10, alignSelf: 'center' },
    statusTagText: { fontSize: 9, fontWeight: '800', color: '#fff' },

    // Existing styles ...
    segmentContainer: { flexDirection: 'row', backgroundColor: '#e2e8f0', borderRadius: 12, padding: 4, marginBottom: 20 },
    segmentBtn: { flex: 1, paddingVertical: 8, alignItems: 'center', borderRadius: 10 },
    segmentBtnActive: { backgroundColor: '#fff', elevation: 2, shadowColor: '#000', shadowOffset: { width: 0, height: 1 }, shadowOpacity: 0.1, shadowRadius: 1 },
    segmentText: { fontSize: 13, fontWeight: '600', color: '#64748b' },
    segmentTextActive: { color: '#3b82f6', fontWeight: '700' },
    section: { marginBottom: 25 },
    sectionTitle: { fontSize: 19, fontWeight: '800', color: '#1e293b' },
    emptyContainer: { paddingVertical: 60, alignItems: 'center', justifyContent: 'center' },
    emptyTitle: { fontSize: 22, fontWeight: '900', color: '#1e293b', marginTop: 20 },
    emptyText: { color: '#64748b', fontSize: 15, textAlign: 'center', marginTop: 12, lineHeight: 24, paddingHorizontal: 40 },
    cardHeader: { flexDirection: 'row', alignItems: 'center' },
    headerInfo: { flex: 1, marginLeft: 15 },
    badgeRow: { flexDirection: 'row', marginTop: 6, gap: 6 },
    inviteBadge: { backgroundColor: '#eef2ff', paddingHorizontal: 8, paddingVertical: 3, borderRadius: 6 },
    inviteBadgeText: { fontSize: 10, color: '#4f46e5', fontWeight: '800' },
    statusBadge: { paddingHorizontal: 8, paddingVertical: 3, borderRadius: 6 },
    statusBadgeText: { fontSize: 10, color: '#fff', fontWeight: '800' },
    timeContainer: { backgroundColor: '#f8fafc', paddingHorizontal: 10, paddingVertical: 5, borderRadius: 10 },
    timeText: { fontSize: 11, color: '#64748b', fontWeight: '700' },
    cardActions: { flexDirection: 'row', gap: 15, marginTop: 15, borderTopWidth: 1, borderTopColor: '#f1f5f9', paddingTop: 15 },
    actionButton: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', padding: 15, borderRadius: 12, gap: 10 },
    rejectButton: { backgroundColor: '#fee2e2', borderWidth: 0 },
    approveButton: { backgroundColor: '#10b981', elevation: 2, shadowColor: '#10b981', shadowOffset: { width: 0, height: 2 }, shadowOpacity: 0.2, shadowRadius: 3 },
    actionButtonText: { color: '#fff', fontSize: 16, fontWeight: '700' },
    // Re-use existing avatar (width 50, height 50)
    avatar: { width: 50, height: 50, borderRadius: 25, backgroundColor: '#f1f5f9' },

    // Missing Styles
    detailsFooter: { padding: 20, backgroundColor: '#fff', borderTopWidth: 1, borderTopColor: '#f1f5f9' },
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
    modalHeader: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        paddingHorizontal: 20,
        paddingBottom: 15,
        borderBottomWidth: 1,
        borderBottomColor: '#f1f5f9',
        paddingTop: 20,
    },
    modalTitle: {
        fontSize: 18,
        fontWeight: 'bold',
        color: '#1e293b',
    },
    filterModalContent: {
        backgroundColor: '#fff',
        borderTopLeftRadius: 24,
        borderTopRightRadius: 24,
        paddingBottom: 30,
        maxHeight: '85%', // Increased from 50% to allow full expansion
        width: '100%', // Ensure full width
    },
    filterOption: {
        paddingVertical: 16,
        paddingHorizontal: 20,
        borderBottomWidth: 1,
        borderBottomColor: '#f1f5f9',
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
    },
    filterOptionActive: {
        backgroundColor: '#eff6ff',
        borderLeftWidth: 4,
        borderLeftColor: '#3b82f6',
    },
    filterOptionText: {
        fontSize: 16,
        color: '#475569',
        fontWeight: '500',
    },
    filterOptionTextActive: {
        color: '#3b82f6',
        fontWeight: 'bold',
    },
    customDateContainer: {
        marginTop: 10,
        backgroundColor: '#f8fafc',
        padding: 15,
        borderRadius: 12,
    },
    dateInputRow: {
        flexDirection: 'row',
        gap: 10,
        marginBottom: 15,
    },
    dateInputWrapper: {
        flex: 1,
    },
    dateLabel: {
        fontSize: 12,
        color: '#64748b',
        marginBottom: 5,
        fontWeight: '500',
    },
    dateInput: {
        backgroundColor: '#fff',
        borderWidth: 1,
        borderColor: '#e2e8f0',
        borderRadius: 8,
        paddingHorizontal: 12,
        paddingVertical: 8,
        fontSize: 14,
        color: '#1e293b',
    },
    dateInputBtn: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        backgroundColor: '#fff',
        borderWidth: 1,
        borderColor: '#e2e8f0',
        borderRadius: 8,
        paddingHorizontal: 12,
        paddingVertical: 8,
    },
    dateInputText: {
        fontSize: 14,
        color: '#1e293b',
    },
    applyBtn: {
        backgroundColor: '#3b82f6',
        paddingVertical: 10,
        borderRadius: 8,
        alignItems: 'center',
    },
    applyBtnText: {
        color: '#fff',
        fontWeight: 'bold',
        fontSize: 14,
    },
    calOverlay: {
        flex: 1,
        backgroundColor: 'rgba(0,0,0,0.5)',
        justifyContent: 'center',
        alignItems: 'center',
    },
    calContainer: {
        backgroundColor: '#fff',
        borderRadius: 16,
        padding: 20,
        width: '90%',
        maxWidth: 360,
        elevation: 10,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 4 },
        shadowOpacity: 0.3,
        shadowRadius: 8,
    },
    calTitle: {
        fontSize: 16,
        fontWeight: 'bold',
        color: '#1e293b',
        textAlign: 'center',
        marginBottom: 15,
    },
    calNav: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        marginBottom: 15,
    },
    calMonthLabel: {
        fontSize: 16,
        fontWeight: '700',
        color: '#1e293b',
    },
    calDaysHeader: {
        flexDirection: 'row',
        justifyContent: 'space-around',
        marginBottom: 8,
    },
    calDayLabel: {
        width: 40,
        textAlign: 'center',
        fontSize: 12,
        fontWeight: '600',
        color: '#94a3b8',
    },
    calGrid: {
        flexDirection: 'row',
        flexWrap: 'wrap',
    },
    calCell: {
        width: '14.28%',
        aspectRatio: 1,
        justifyContent: 'center',
        alignItems: 'center',
    },
    calCellSelected: {
        backgroundColor: '#3b82f6',
        borderRadius: 20,
    },
    calCellToday: {
        borderWidth: 1,
        borderColor: '#3b82f6',
        borderRadius: 20,
    },
    calCellText: {
        fontSize: 14,
        color: '#1e293b',
    },
    calCellTextSelected: {
        color: '#fff',
        fontWeight: 'bold',
    },
    calCellTextToday: {
        color: '#3b82f6',
        fontWeight: '600',
    },
    calCancelBtn: {
        marginTop: 15,
        paddingVertical: 10,
        alignItems: 'center',
    },
    calCancelText: {
        color: '#64748b',
        fontSize: 14,
        fontWeight: '600',
    },
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
    alertConfirmButtonText: { color: '#fff', fontSize: 15, fontWeight: 'bold' },
    // Employee Detail Styles
    detailProfileSection: { alignItems: 'center', padding: 30, backgroundColor: '#fff', borderBottomWidth: 1, borderBottomColor: '#f1f5f9' },
    detailAvatar: { width: 100, height: 100, borderRadius: 50, justifyContent: 'center', alignItems: 'center', marginBottom: 15, elevation: 4, shadowColor: '#000', shadowOffset: { width: 0, height: 2 }, shadowOpacity: 0.1, shadowRadius: 4 },
    detailAvatarText: { fontSize: 40, fontWeight: 'bold', color: '#ef4444' },
    detailName: { fontSize: 24, fontWeight: '800', color: '#1e293b' },
    detailInfoCard: { backgroundColor: '#fff', marginHorizontal: 20, marginTop: 20, borderRadius: 16, padding: 20, elevation: 2, shadowColor: '#000', shadowOffset: { width: 0, height: 1 }, shadowOpacity: 0.05, shadowRadius: 2 },
    detailSectionTitle: { fontSize: 14, fontWeight: '700', color: '#64748b', textTransform: 'uppercase', marginBottom: 15, letterSpacing: 1 },
    detailInfoRow: { flexDirection: 'row', alignItems: 'center', marginBottom: 15, gap: 15 },
    detailInfoContent: { flex: 1 },
    detailLabel: { fontSize: 12, color: '#94a3b8', marginBottom: 2 },
    detailValue: { fontSize: 16, color: '#1e293b', fontWeight: '600' },
    minimalVisitRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: '#f8fafc' },
    minimalVisitName: { flex: 1, fontSize: 14, fontWeight: '600', color: '#334155' },
    minimalVisitDate: { fontSize: 12, color: '#94a3b8' },
    noDataTextSmall: { fontSize: 13, color: '#94a3b8', fontStyle: 'italic', textAlign: 'center', padding: 10 }
});
