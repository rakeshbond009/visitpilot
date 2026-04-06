import React, { useState, useEffect, useCallback } from 'react';
import {
    StyleSheet,
    View,
    Text,
    ScrollView,
    TouchableOpacity,
    SafeAreaView,
    ActivityIndicator,
    Alert,
    Dimensions,
    Image,
    TextInput,
    Modal,
    Pressable,
    StatusBar,
    RefreshControl,
} from 'react-native';
import { MaterialCommunityIcons as Icon } from '@expo/vector-icons';
import { useFocusEffect } from '@react-navigation/native';
import VisitDetailModal from '../components/VisitDetailModal';
import apiClient from '../utils/apiClient';
import { CONFIG } from '../utils/config';

const { width } = Dimensions.get('window');

const statusColors = {
    'pending': '#f59e0b',
    'approved': '#10b981',
    'rejected': '#ef4444',
    'checked_in': '#3b82f6',
    'checked_out': '#64748b',
    'expired': '#94a3b8',
    'completed': '#64748b',
    'canceled': '#94a3b8',
};

const getStatusColor = (status) => statusColors[status?.toLowerCase()] || '#64748b';

export default function MyVisitorsHistory({ navigation, route }) {
    const [visits, setVisits] = useState([]);
    const [stats, setStats] = useState({ total: 0, active: 0, completed: 0, pending: 0, approved: 0, rejected: 0 });
    const [loading, setLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState(null);
    const [searchTerm, setSearchTerm] = useState('');
    const [page, setPage] = useState(1);
    const [totalPages, setTotalPages] = useState(1);
    const [totalCount, setTotalCount] = useState(0);

    // Filter states
    const [filterType, setFilterType] = useState('all');
    const [filterStartDate, setFilterStartDate] = useState('');
    const [filterEndDate, setFilterEndDate] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [filterModalVisible, setFilterModalVisible] = useState(false);
    const [showCustomPicker, setShowCustomPicker] = useState(false);
    const [tempStartDate, setTempStartDate] = useState('');
    const [tempEndDate, setTempEndDate] = useState('');
    const [dateState, setDateState] = useState({ showStart: false, showEnd: false, calMonth: new Date().getMonth(), calYear: new Date().getFullYear(), calTarget: 'start' });

    // Visit Details Modal
    const [selectedVisit, setSelectedVisit] = useState(null);
    const [detailModalVisible, setDetailModalVisible] = useState(false);

    const getDateRange = useCallback(() => {
        const now = new Date();
        let start = '', end = '';

        if (filterType === 'today') {
            start = end = now.toISOString().split('T')[0];
        } else if (filterType === 'yesterday') {
            const d = new Date(now);
            d.setDate(d.getDate() - 1);
            start = end = d.toISOString().split('T')[0];
        } else if (filterType === 'week') {
            const d = new Date(now);
            d.setDate(d.getDate() - 7);
            start = d.toISOString().split('T')[0];
            end = now.toISOString().split('T')[0];
        } else if (filterType === 'month') {
            const d = new Date(now);
            d.setDate(d.getDate() - 30);
            start = d.toISOString().split('T')[0];
            end = now.toISOString().split('T')[0];
        } else if (filterType === 'custom') {
            start = filterStartDate;
            end = filterEndDate || filterStartDate;
        }
        // 'all' returns empty strings = no date filter
        return { start, end };
    }, [filterType, filterStartDate, filterEndDate]);

    const fetchData = useCallback(async (pageNum = 1) => {
        try {
            if (pageNum === 1) setLoading(true);
            const { start, end } = getDateRange();
            let url = `host/api/get_visitor_history.php?page=${pageNum}`;
            if (start) url += `&start_date=${start}`;
            if (end) url += `&end_date=${end}`;
            if (searchTerm) url += `&search=${encodeURIComponent(searchTerm)}`;
            if (statusFilter) url += `&status=${statusFilter}`;

            const response = await apiClient.get(url, { timeout: 15000 });
            const data = response.data;

            if (data.success) {
                if (pageNum === 1) {
                    setVisits(data.visits || []);
                } else {
                    setVisits(prev => [...prev, ...(data.visits || [])]);
                }
                setStats(data.stats || {});
                setPage(data.page || 1);
                setTotalPages(data.pages || 1);
                setTotalCount(data.total || 0);
                setError(null);
            } else {
                setError(data.error || 'Failed to load history');
            }
        } catch (err) {
            console.error('History Fetch Error:', err);
            if (err.response?.status === 401) {
                navigation.replace('Login');
                return;
            }
            setError('Unable to connect to server');
        } finally {
            setLoading(false);
            setRefreshing(false);
        }
    }, [getDateRange, searchTerm, statusFilter, navigation]);

    const fetchVisitDetails = async (visitId) => {
        try {
            setLoading(true);
            const response = await apiClient.get('api/visit/details.php', {
                params: { id: visitId }
            });
            if (response.data.status === 'success') {
                setSelectedVisit(response.data.data);
                setDetailModalVisible(true);
            } else {
                Alert.alert('Error', response.data.message || 'Could not load visit details');
            }
        } catch (err) {
            console.error('Visit Details Error:', err);
            Alert.alert('Error', 'Connection error while loading details');
        } finally {
            setLoading(false);
        }
    };

    useFocusEffect(
        useCallback(() => {
            fetchData(1);
        }, [fetchData])
    );

    useEffect(() => {
        if (route.params?.visit_id) {
            fetchVisitDetails(route.params.visit_id);
            // Clear params to avoid duplicate opening
            navigation.setParams({ visit_id: null });
        }
    }, [route.params?.visit_id]);

    const onRefresh = () => {
        setRefreshing(true);
        setPage(1);
        fetchData(1);
    };

    const loadMore = () => {
        if (page < totalPages && !loading) {
            fetchData(page + 1);
        }
    };

    const formatDate = (dateStr) => {
        if (!dateStr) return '-';
        const d = new Date(dateStr.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
    };

    const formatTime = (dateStr) => {
        if (!dateStr) return '-';
        const d = new Date(dateStr.replace(' ', 'T'));
        if (isNaN(d.getTime())) return '-';
        return d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
    };

    const getPhotoUrl = (path) => {
        if (!path) return null;
        if (path.startsWith('http')) return path;
        return `${CONFIG.API_BASE_URL}${path}`;
    };

    const renderStatCards = () => (
        <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.statsRow} contentContainerStyle={{ paddingRight: 15 }}>
            {[
                { label: 'Total', value: stats.total, icon: 'account-group', color: '#3b82f6', bg: '#eff6ff' },
                { label: 'Active', value: stats.active, icon: 'account-check', color: '#10b981', bg: '#ecfdf5' },
                { label: 'Completed', value: stats.completed, icon: 'check-circle', color: '#64748b', bg: '#f1f5f9' },
                { label: 'Pending', value: stats.pending, icon: 'clock-outline', color: '#f59e0b', bg: '#fffbeb' },
                { label: 'Rejected', value: stats.rejected, icon: 'close-circle', color: '#ef4444', bg: '#fef2f2' },
            ].map((s, i) => (
                <TouchableOpacity key={i} style={[styles.statCard, { borderLeftColor: s.color, borderLeftWidth: 3 }]}
                    onPress={() => {
                        if (s.label === 'Total') { setStatusFilter(''); }
                        else if (s.label === 'Active') { setStatusFilter('checked_in'); }
                        else if (s.label === 'Completed') { setStatusFilter('checked_out'); }
                        else if (s.label === 'Pending') { setStatusFilter('pending'); }
                        else if (s.label === 'Rejected') { setStatusFilter('rejected'); }
                    }}
                >
                    <View style={[styles.statIconCircle, { backgroundColor: s.bg }]}>
                        <Icon name={s.icon} size={20} color={s.color} />
                    </View>
                    <Text style={styles.statValue}>{s.value}</Text>
                    <Text style={styles.statLabel}>{s.label}</Text>
                </TouchableOpacity>
            ))}
        </ScrollView>
    );

    const renderVisitCard = (visit) => {
        const photoUrl = getPhotoUrl(visit.visit_photo || visit.photo_path);
        const displayStatus = visit.status === 'checked_in' ? 'CHECKED IN' :
            visit.status === 'checked_out' ? 'CHECKED OUT' :
                (visit.approval_status || visit.status || '').toUpperCase().replace('_', ' ');
        const cardStatusColor = getStatusColor(visit.status === 'pending' ? visit.approval_status : visit.status);

        return (
            <TouchableOpacity
                key={visit.id}
                style={styles.visitCard}
                onPress={() => { setSelectedVisit(visit); setDetailModalVisible(true); }}
                activeOpacity={0.7}
            >
                <View style={[styles.visitCardAccent, { backgroundColor: cardStatusColor }]} />
                <View style={styles.visitCardBody}>
                    <View style={styles.visitCardTop}>
                        <Image
                            source={photoUrl ? { uri: photoUrl } : { uri: `https://ui-avatars.com/api/?name=${encodeURIComponent(visit.visitor_name || 'V')}&background=random` }}
                            style={styles.visitAvatar}
                        />
                        <View style={styles.visitCardInfo}>
                            <Text style={styles.visitorName} numberOfLines={1}>{visit.visitor_name}</Text>
                            <Text style={styles.visitMeta} numberOfLines={1}>
                                <Icon name="phone" size={12} color="#94a3b8" /> {visit.mobile || '-'}
                            </Text>
                            {visit.company ? (
                                <Text style={styles.visitMeta} numberOfLines={1}>
                                    <Icon name="domain" size={12} color="#94a3b8" /> {visit.company}
                                </Text>
                            ) : null}
                        </View>
                        <View style={[styles.statusBadge, { backgroundColor: cardStatusColor + '15' }]}>
                            <Text style={[styles.statusBadgeText, { color: cardStatusColor }]}>{displayStatus}</Text>
                        </View>
                    </View>
                    <View style={styles.visitCardBottom}>
                        <View style={styles.visitMetaItem}>
                            <Icon name="calendar" size={14} color="#94a3b8" />
                            <Text style={styles.visitMetaText}>{formatDate(visit.created_at)}</Text>
                        </View>
                        <View style={styles.visitMetaItem}>
                            <Icon name="account-tie" size={14} color="#94a3b8" />
                            <Text style={styles.visitMetaText} numberOfLines={1}>{visit.host_name || '-'}</Text>
                        </View>
                        <View style={styles.visitMetaItem}>
                            <Icon name="qrcode" size={14} color="#94a3b8" />
                            <Text style={styles.visitMetaText}>{visit.visit_code || '-'}</Text>
                        </View>
                    </View>
                    {(visit.check_in_time || visit.check_out_time) && (
                        <View style={styles.visitTimeRow}>
                            <View style={styles.timeChip}>
                                <Icon name="login" size={12} color="#10b981" />
                                <Text style={[styles.timeChipText, { color: '#10b981' }]}> In: {formatTime(visit.check_in_time)}</Text>
                            </View>
                            <View style={styles.timeChip}>
                                <Icon name="logout" size={12} color="#ef4444" />
                                <Text style={[styles.timeChipText, { color: '#ef4444' }]}> Out: {formatTime(visit.check_out_time)}</Text>
                            </View>
                        </View>
                    )}
                </View>
            </TouchableOpacity>
        );
    };

    const renderDetailModal = () => (
        <VisitDetailModal
            visible={detailModalVisible}
            onClose={() => setDetailModalVisible(false)}
            visit={selectedVisit}
        />
    );

    const renderCalendarModal = () => {
        if (!dateState.showStart && !dateState.showEnd) return null;
        return (
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
                                        <TouchableOpacity key={day} style={[styles.calCell, isSelected && styles.calCellSelected, isToday && !isSelected && styles.calCellToday]}
                                            onPress={() => {
                                                if (dateState.calTarget === 'start') setTempStartDate(dateStr);
                                                else setTempEndDate(dateStr);
                                                setDateState({ ...dateState, showStart: false, showEnd: false });
                                            }}>
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
        );
    };

    const renderFilterModal = () => (
        <Modal animationType="slide" transparent visible={filterModalVisible} onRequestClose={() => setFilterModalVisible(false)}>
            <Pressable style={styles.modalOverlay} onPress={() => setFilterModalVisible(false)}>
                <Pressable style={styles.filterModalContent} onPress={(e) => e.stopPropagation()}>
                    <View style={styles.filterModalHeader}>
                        <Text style={styles.filterModalTitle}>Filter by Date</Text>
                        <TouchableOpacity onPress={() => setFilterModalVisible(false)}>
                            <Icon name="close" size={24} color="#64748b" />
                        </TouchableOpacity>
                    </View>
                    <ScrollView style={{ paddingHorizontal: 20 }} contentContainerStyle={{ paddingBottom: 20 }}>
                        {[
                            { key: 'all', label: 'All Time' },
                            { key: 'today', label: 'Today' },
                            { key: 'yesterday', label: 'Yesterday' },
                            { key: 'week', label: 'Last 7 Days' },
                            { key: 'month', label: 'Last 30 Days' },
                        ].map(opt => (
                            <TouchableOpacity key={opt.key} style={[styles.filterOption, filterType === opt.key && styles.filterOptionActive]}
                                onPress={() => { setFilterType(opt.key); setFilterModalVisible(false); setShowCustomPicker(false); setPage(1); }}>
                                <Text style={[styles.filterOptionText, filterType === opt.key && styles.filterOptionTextActive]}>{opt.label}</Text>
                                {filterType === opt.key && <Icon name="check" size={20} color="#3b82f6" />}
                            </TouchableOpacity>
                        ))}
                        <TouchableOpacity style={[styles.filterOption, filterType === 'custom' && styles.filterOptionActive]}
                            onPress={() => setShowCustomPicker(!showCustomPicker)}>
                            <Text style={[styles.filterOptionText, filterType === 'custom' && styles.filterOptionTextActive]}>Custom Range</Text>
                            <Icon name={showCustomPicker ? 'chevron-up' : 'chevron-down'} size={20} color="#64748b" />
                        </TouchableOpacity>
                        {showCustomPicker && (
                            <View style={styles.customDateContainer}>
                                <View style={styles.dateInputRow}>
                                    <View style={styles.dateInputWrapper}>
                                        <Text style={styles.dateLabel}>Start Date</Text>
                                        <TouchableOpacity style={styles.dateInputBtn} onPress={() => {
                                            const d = tempStartDate ? new Date(tempStartDate) : new Date();
                                            setDateState({ showStart: true, showEnd: false, calMonth: d.getMonth(), calYear: d.getFullYear(), calTarget: 'start' });
                                        }}>
                                            <Text style={styles.dateInputText}>{tempStartDate ? tempStartDate.split('-').reverse().join('-') : 'Select Date'}</Text>
                                            <Icon name="calendar" size={20} color="#64748b" />
                                        </TouchableOpacity>
                                    </View>
                                    <View style={styles.dateInputWrapper}>
                                        <Text style={styles.dateLabel}>End Date</Text>
                                        <TouchableOpacity style={styles.dateInputBtn} onPress={() => {
                                            const d = tempEndDate ? new Date(tempEndDate) : new Date();
                                            setDateState({ showEnd: true, showStart: false, calMonth: d.getMonth(), calYear: d.getFullYear(), calTarget: 'end' });
                                        }}>
                                            <Text style={styles.dateInputText}>{tempEndDate ? tempEndDate.split('-').reverse().join('-') : 'Select Date'}</Text>
                                            <Icon name="calendar" size={20} color="#64748b" />
                                        </TouchableOpacity>
                                    </View>
                                </View>
                                <TouchableOpacity style={styles.applyBtn} onPress={() => {
                                    if (!tempStartDate) { Alert.alert('Error', 'Please select a start date'); return; }
                                    setFilterStartDate(tempStartDate);
                                    setFilterEndDate(tempEndDate || tempStartDate);
                                    setFilterType('custom');
                                    setFilterModalVisible(false);
                                    setPage(1);
                                }}>
                                    <Text style={styles.applyBtnText}>Apply Filter</Text>
                                </TouchableOpacity>
                            </View>
                        )}
                    </ScrollView>
                </Pressable>
            </Pressable>
            {renderCalendarModal()}
        </Modal>
    );

    const getFilterLabel = () => {
        if (filterType === 'all') return 'All Time';
        if (filterType === 'today') return 'Today';
        if (filterType === 'yesterday') return 'Yesterday';
        if (filterType === 'week') return 'Last 7 Days';
        if (filterType === 'month') return 'Last 30 Days';
        if (filterType === 'custom') return 'Custom Range';
        return 'All Time';
    };

    return (
        <SafeAreaView style={styles.safeArea}>
            <StatusBar barStyle="dark-content" backgroundColor="#f8fafc" />

            {/* Header */}
            <View style={styles.header}>
                <TouchableOpacity style={styles.backBtn} onPress={() => navigation.goBack()}>
                    <Icon name="arrow-left" size={24} color="#1e293b" />
                </TouchableOpacity>
                <Text style={styles.headerTitle}>My Visitors History</Text>
                <TouchableOpacity style={styles.filterIconBtn} onPress={() => setFilterModalVisible(true)}>
                    <Icon name="filter-variant" size={24} color={filterType !== 'all' ? '#3b82f6' : '#64748b'} />
                </TouchableOpacity>
            </View>

            {/* Search Bar */}
            <View style={styles.searchContainer}>
                <Icon name="magnify" size={20} color="#94a3b8" style={{ marginRight: 8 }} />
                <TextInput
                    style={styles.searchInput}
                    placeholder="Search visitor name, phone or code..."
                    placeholderTextColor="#94a3b8"
                    value={searchTerm}
                    onChangeText={setSearchTerm}
                    onSubmitEditing={() => { setPage(1); fetchData(1); }}
                    returnKeyType="search"
                />
                {searchTerm ? (
                    <TouchableOpacity onPress={() => { setSearchTerm(''); setPage(1); }}>
                        <Icon name="close-circle" size={20} color="#94a3b8" />
                    </TouchableOpacity>
                ) : null}
            </View>

            {/* Filter & Status Bar */}
            <View style={styles.filterBar}>
                <TouchableOpacity style={styles.filterChip} onPress={() => setFilterModalVisible(true)}>
                    <Icon name="calendar-range" size={14} color="#3b82f6" />
                    <Text style={styles.filterChipText}>{getFilterLabel()}</Text>
                    <Icon name="chevron-down" size={14} color="#3b82f6" />
                </TouchableOpacity>

                {statusFilter ? (
                    <TouchableOpacity style={[styles.filterChip, { backgroundColor: '#fef2f2', borderColor: '#fecaca' }]}
                        onPress={() => { setStatusFilter(''); setPage(1); }}>
                        <Text style={[styles.filterChipText, { color: '#ef4444' }]}>{statusFilter.replace('_', ' ').toUpperCase()}</Text>
                        <Icon name="close" size={14} color="#ef4444" />
                    </TouchableOpacity>
                ) : null}

                <Text style={styles.resultCount}>{totalCount} results</Text>
            </View>

            {/* Stats */}
            {renderStatCards()}

            {/* Content */}
            <ScrollView
                style={styles.content}
                contentContainerStyle={{ paddingBottom: 30 }}
                refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} />}
            >
                {loading && visits.length === 0 ? (
                    <View style={styles.centerContainer}>
                        <ActivityIndicator size="large" color="#3b82f6" />
                        <Text style={styles.loadingText}>Loading visitor history...</Text>
                    </View>
                ) : error ? (
                    <View style={styles.centerContainer}>
                        <Icon name="alert-circle-outline" size={60} color="#ef4444" />
                        <Text style={styles.errorText}>{error}</Text>
                        <TouchableOpacity style={styles.retryBtn} onPress={() => fetchData(1)}>
                            <Text style={styles.retryBtnText}>Retry</Text>
                        </TouchableOpacity>
                    </View>
                ) : visits.length === 0 ? (
                    <View style={styles.centerContainer}>
                        <Icon name="history" size={60} color="#cbd5e1" />
                        <Text style={styles.emptyTitle}>No Visit History</Text>
                        <Text style={styles.emptyText}>No visitor records found for the selected filters.</Text>
                    </View>
                ) : (
                    <>
                        {visits.map(visit => renderVisitCard(visit))}
                        {page < totalPages && (
                            <TouchableOpacity style={styles.loadMoreBtn} onPress={loadMore}>
                                <Text style={styles.loadMoreText}>Load More ({totalCount - visits.length} remaining)</Text>
                            </TouchableOpacity>
                        )}
                    </>
                )}
            </ScrollView>

            {renderFilterModal()}
            {renderDetailModal()}
        </SafeAreaView>
    );
}

const styles = StyleSheet.create({
    safeArea: { flex: 1, backgroundColor: '#f8fafc' },

    header: {
        flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
        paddingHorizontal: 16, paddingVertical: 14,
        backgroundColor: '#fff', borderBottomWidth: 1, borderBottomColor: '#e2e8f0',
    },
    backBtn: { width: 40, height: 40, borderRadius: 12, backgroundColor: '#f1f5f9', justifyContent: 'center', alignItems: 'center' },
    headerTitle: { fontSize: 18, fontWeight: '700', color: '#1e293b' },
    filterIconBtn: { width: 40, height: 40, borderRadius: 12, backgroundColor: '#f1f5f9', justifyContent: 'center', alignItems: 'center' },

    searchContainer: {
        flexDirection: 'row', alignItems: 'center',
        backgroundColor: '#fff', marginHorizontal: 16, marginTop: 12,
        paddingHorizontal: 14, borderRadius: 12, borderWidth: 1, borderColor: '#e2e8f0',
        height: 44,
    },
    searchInput: { flex: 1, fontSize: 14, color: '#1e293b' },

    filterBar: {
        flexDirection: 'row', alignItems: 'center', paddingHorizontal: 16, paddingVertical: 10, gap: 8,
    },
    filterChip: {
        flexDirection: 'row', alignItems: 'center', gap: 4,
        backgroundColor: '#eff6ff', paddingHorizontal: 10, paddingVertical: 6,
        borderRadius: 20, borderWidth: 1, borderColor: '#bfdbfe',
    },
    filterChipText: { fontSize: 12, fontWeight: '600', color: '#3b82f6' },
    resultCount: { marginLeft: 'auto', fontSize: 12, color: '#94a3b8', fontWeight: '500' },

    statsRow: { paddingLeft: 16, marginBottom: 10, flexGrow: 0 },
    statCard: {
        backgroundColor: '#fff', borderRadius: 12, paddingVertical: 12, paddingHorizontal: 14,
        marginRight: 10, minWidth: 100, maxWidth: 110, height: 110, alignItems: 'center', justifyContent: 'center',
        elevation: 1, shadowColor: '#000', shadowOffset: { width: 0, height: 1 }, shadowOpacity: 0.05, shadowRadius: 2,
    },
    statIconCircle: { width: 36, height: 36, borderRadius: 18, justifyContent: 'center', alignItems: 'center', marginBottom: 6 },
    statValue: { fontSize: 20, fontWeight: 'bold', color: '#1e293b' },
    statLabel: { fontSize: 11, color: '#64748b', fontWeight: '500', marginTop: 2 },

    content: { flex: 1, paddingHorizontal: 16, paddingTop: 10 },

    visitCard: {
        flexDirection: 'row', backgroundColor: '#fff', borderRadius: 14, marginBottom: 10,
        elevation: 2, shadowColor: '#000', shadowOffset: { width: 0, height: 1 }, shadowOpacity: 0.06, shadowRadius: 3,
        overflow: 'hidden',
    },
    visitCardAccent: { width: 4 },
    visitCardBody: { flex: 1, padding: 14 },
    visitCardTop: { flexDirection: 'row', alignItems: 'center' },
    visitAvatar: { width: 44, height: 44, borderRadius: 22, backgroundColor: '#e2e8f0', marginRight: 12 },
    visitCardInfo: { flex: 1, marginRight: 8 },
    visitorName: { fontSize: 15, fontWeight: '700', color: '#1e293b' },
    visitMeta: { fontSize: 12, color: '#64748b', marginTop: 2 },
    statusBadge: { paddingHorizontal: 10, paddingVertical: 4, borderRadius: 20 },
    statusBadgeText: { fontSize: 10, fontWeight: 'bold', letterSpacing: 0.5 },
    visitCardBottom: { flexDirection: 'row', marginTop: 10, gap: 12, flexWrap: 'wrap' },
    visitMetaItem: { flexDirection: 'row', alignItems: 'center', gap: 4 },
    visitMetaText: { fontSize: 12, color: '#64748b' },
    visitTimeRow: { flexDirection: 'row', marginTop: 8, gap: 10 },
    timeChip: { flexDirection: 'row', alignItems: 'center', backgroundColor: '#f8fafc', paddingHorizontal: 8, paddingVertical: 3, borderRadius: 6 },
    timeChipText: { fontSize: 11, fontWeight: '600' },

    centerContainer: { alignItems: 'center', justifyContent: 'center', paddingVertical: 60 },
    loadingText: { marginTop: 15, color: '#64748b', fontSize: 14 },
    errorText: { marginTop: 10, color: '#ef4444', fontSize: 14, textAlign: 'center' },
    retryBtn: { marginTop: 15, backgroundColor: '#3b82f6', paddingHorizontal: 24, paddingVertical: 10, borderRadius: 8 },
    retryBtnText: { color: '#fff', fontWeight: 'bold' },
    emptyTitle: { marginTop: 15, fontSize: 16, fontWeight: '700', color: '#1e293b' },
    emptyText: { marginTop: 5, color: '#64748b', textAlign: 'center', paddingHorizontal: 20 },

    loadMoreBtn: { backgroundColor: '#eff6ff', paddingVertical: 12, borderRadius: 10, alignItems: 'center', marginTop: 5 },
    loadMoreText: { color: '#3b82f6', fontWeight: '600', fontSize: 13 },

    // Filter Modal
    modalOverlay: { flex: 1, backgroundColor: 'rgba(0,0,0,0.5)', justifyContent: 'flex-end' },
    filterModalContent: { backgroundColor: '#fff', borderTopLeftRadius: 24, borderTopRightRadius: 24, paddingTop: 20, paddingBottom: 30, maxHeight: '70%' },
    filterModalHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20, paddingHorizontal: 20 },
    filterModalTitle: { fontSize: 18, fontWeight: 'bold', color: '#1e293b' },
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

    // Calendar
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

    // Detail Modal (HostDashboard version)
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
});
