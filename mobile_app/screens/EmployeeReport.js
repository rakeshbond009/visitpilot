import React, { useState, useEffect, useCallback } from 'react';
import {
    StyleSheet, View, Text, ScrollView, TouchableOpacity,
    RefreshControl, SafeAreaView, StatusBar, ActivityIndicator,
    Dimensions, TextInput, Modal, Pressable, Platform
} from 'react-native';
import { MaterialCommunityIcons as Icon } from '@expo/vector-icons';
import { LineChart, BarChart, PieChart } from 'react-native-chart-kit';
import apiClient from '../utils/apiClient';

const screenWidth = Dimensions.get('window').width;

const statusColors = {
    pending: '#f59e0b',
    approved: '#3b82f6',
    checked_in: '#10b981',
    checked_out: '#64748b',
    rejected: '#ef4444',
};

const chartColors = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#E74C3C', '#2ECC71', '#3498DB', '#F39C12'];

const TAB_KEYS = ['host', 'month', 'day', 'perf', 'analytics'];
const TAB_LABELS = { host: 'By Host', month: 'By Month', day: 'By Date', perf: 'Performance', analytics: 'Analytics' };

const MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

// Helper: format date as DD-MM-YYYY
function fmtDate(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const yyyy = d.getFullYear();
    return `${dd}-${mm}-${yyyy}`;
}

// Helper: format month as MMM-YY
function fmtMonth(monthStr) {
    if (!monthStr) return '-';
    const parts = monthStr.split('-');
    if (parts.length >= 2) {
        const mIdx = parseInt(parts[1], 10) - 1;
        const yr = parts[0].slice(-2);
        return `${MONTHS_SHORT[mIdx]}-${yr}`;
    }
    return monthStr;
}

// Helper: format day as DD-MMM-YY
function fmtDay(dayStr) {
    if (!dayStr) return '-';
    const d = new Date(dayStr);
    if (isNaN(d.getTime())) return dayStr;
    const dd = String(d.getDate()).padStart(2, '0');
    return `${dd}-${MONTHS_SHORT[d.getMonth()]}-${String(d.getFullYear()).slice(-2)}`;
}

const chartConfig = {
    backgroundColor: '#ffffff',
    backgroundGradientFrom: '#ffffff',
    backgroundGradientTo: '#ffffff',
    decimalCount: 0,
    color: (opacity = 1) => `rgba(67, 97, 238, ${opacity})`,
    labelColor: (opacity = 1) => `rgba(100, 116, 139, ${opacity})`,
    style: { borderRadius: 16 },
    propsForDots: { r: '4', strokeWidth: '2', stroke: '#4361ee' },
    propsForBackgroundLines: { strokeDasharray: '', stroke: '#f1f5f9', strokeWidth: 1 },
};

// ===== Custom Calendar Date Picker Component =====
function CalendarPicker({ visible, onClose, onSelect, selectedDate, title }) {
    const [viewDate, setViewDate] = useState(selectedDate ? new Date(selectedDate) : new Date());

    useEffect(() => {
        if (visible && selectedDate) {
            setViewDate(new Date(selectedDate));
        }
    }, [visible, selectedDate]);

    const year = viewDate.getFullYear();
    const month = viewDate.getMonth();

    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();

    const prevMonth = () => setViewDate(new Date(year, month - 1, 1));
    const nextMonth = () => setViewDate(new Date(year, month + 1, 1));

    const days = [];
    for (let i = 0; i < firstDay; i++) days.push(null);
    for (let d = 1; d <= daysInMonth; d++) days.push(d);

    const selParts = selectedDate ? selectedDate.split('-') : [];
    const selYear = selParts[0] ? parseInt(selParts[0]) : null;
    const selMonth = selParts[1] ? parseInt(selParts[1]) - 1 : null;
    const selDay = selParts[2] ? parseInt(selParts[2]) : null;

    const todayDate = new Date();
    const isToday = (d) => d === todayDate.getDate() && month === todayDate.getMonth() && year === todayDate.getFullYear();
    const isSelected = (d) => d === selDay && month === selMonth && year === selYear;

    return (
        <Modal visible={visible} transparent animationType="fade" onRequestClose={onClose}>
            <Pressable style={styles.calOverlay} onPress={onClose}>
                <Pressable style={styles.calContainer} onPress={e => e.stopPropagation()}>
                    <Text style={styles.calTitle}>{title || 'Select Date'}</Text>

                    {/* Month/Year Navigation */}
                    <View style={styles.calNav}>
                        <TouchableOpacity onPress={prevMonth} style={styles.calNavBtn}>
                            <Icon name="chevron-left" size={24} color="#1e293b" />
                        </TouchableOpacity>
                        <Text style={styles.calNavText}>{MONTHS_SHORT[month]} {year}</Text>
                        <TouchableOpacity onPress={nextMonth} style={styles.calNavBtn}>
                            <Icon name="chevron-right" size={24} color="#1e293b" />
                        </TouchableOpacity>
                    </View>

                    {/* Weekday headers */}
                    <View style={styles.calWeekRow}>
                        {['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'].map(d => (
                            <Text key={d} style={styles.calWeekDay}>{d}</Text>
                        ))}
                    </View>

                    {/* Days grid */}
                    <View style={styles.calGrid}>
                        {days.map((d, idx) => (
                            <TouchableOpacity
                                key={idx}
                                style={[
                                    styles.calDay,
                                    d && isSelected(d) && styles.calDaySelected,
                                    d && isToday(d) && !isSelected(d) && styles.calDayToday,
                                ]}
                                onPress={() => {
                                    if (d) {
                                        const iso = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                                        onSelect(iso);
                                        onClose();
                                    }
                                }}
                                disabled={!d}
                            >
                                <Text style={[
                                    styles.calDayText,
                                    d && isSelected(d) && styles.calDayTextSelected,
                                    d && isToday(d) && !isSelected(d) && styles.calDayTextToday,
                                ]}>
                                    {d || ''}
                                </Text>
                            </TouchableOpacity>
                        ))}
                    </View>

                    <TouchableOpacity style={styles.calCloseBtn} onPress={onClose}>
                        <Text style={styles.calCloseBtnText}>Cancel</Text>
                    </TouchableOpacity>
                </Pressable>
            </Pressable>
        </Modal>
    );
}

// ===== Main Screen =====
export default function EmployeeReport({ navigation }) {
    const [loading, setLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);
    const [activeTab, setActiveTab] = useState('host');
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);

    // Filters
    const [startDate, setStartDate] = useState(formatDateISO(new Date()));
    const [endDate, setEndDate] = useState(formatDateISO(new Date()));
    const [filterPreset, setFilterPreset] = useState('today');
    const [searchTerm, setSearchTerm] = useState('');

    // Calendar picker state
    const [showStartCal, setShowStartCal] = useState(false);
    const [showEndCal, setShowEndCal] = useState(false);

    function formatDateISO(d) {
        return d.toISOString().split('T')[0];
    }

    function applyPreset(preset) {
        setFilterPreset(preset);
        const now = new Date();
        let s = new Date(); s.setHours(0, 0, 0, 0);
        let e = new Date(); e.setHours(23, 59, 59, 999);

        if (preset === 'today') { /* already set */ }
        else if (preset === 'yesterday') { s.setDate(s.getDate() - 1); e.setDate(e.getDate() - 1); }
        else if (preset === 'week') { s.setDate(s.getDate() - 7); }
        else if (preset === 'month') { s.setDate(1); }
        else if (preset === '3months') { s.setMonth(s.getMonth() - 3); }
        else if (preset === 'year') { s = new Date(now.getFullYear(), 0, 1); }

        setStartDate(formatDateISO(s));
        setEndDate(formatDateISO(e));
    }

    const fetchReport = useCallback(async () => {
        try {
            setError(null);
            const response = await apiClient.get(`api/visit/employee_report.php?start_date=${startDate}&end_date=${endDate}`);
            if (response.data?.status === 'success') {
                const d = response.data.data;
                setData(d);
            } else {
                setError(response.data?.message || 'Failed to load report');
            }
        } catch (err) {
            console.error('Report fetch error:', err);
            setError('Failed to connect to server');
        } finally {
            setLoading(false);
            setRefreshing(false);
        }
    }, [startDate, endDate]);

    useEffect(() => {
        setLoading(true);
        fetchReport();
    }, [fetchReport]);

    const onRefresh = () => { setRefreshing(true); fetchReport(); };

    // --- Empty state helper ---
    const renderEmpty = (icon, message) => (
        <View style={styles.emptyBox}>
            <Icon name={icon} size={40} color="#cbd5e1" />
            <Text style={styles.emptyText}>{message}</Text>
        </View>
    );

    // --- Render Pivot Table ---
    const renderPivotTable = (pivotData, dimensionLabel, formatFn) => {
        if (!pivotData || pivotData.length === 0) {
            return renderEmpty('table-off', 'No data for selected period');
        }

        const filtered = searchTerm
            ? pivotData.filter(r => r.name?.toLowerCase().includes(searchTerm.toLowerCase()))
            : pivotData;

        return (
            <ScrollView horizontal showsHorizontalScrollIndicator={false}>
                <View>
                    <View style={[styles.tableRow, styles.tableHeader]}>
                        <Text style={[styles.tableCell, styles.tableCellName, styles.tableHeaderText]}>{dimensionLabel}</Text>
                        <Text style={[styles.tableCell, styles.tableHeaderText, { backgroundColor: '#f59e0b' }]}>Pending</Text>
                        <Text style={[styles.tableCell, styles.tableHeaderText, { backgroundColor: '#3b82f6' }]}>Approved</Text>
                        <Text style={[styles.tableCell, styles.tableHeaderText, { backgroundColor: '#10b981' }]}>In</Text>
                        <Text style={[styles.tableCell, styles.tableHeaderText, { backgroundColor: '#64748b' }]}>Out</Text>
                        <Text style={[styles.tableCell, styles.tableHeaderText, { backgroundColor: '#ef4444' }]}>Rejected</Text>
                        <Text style={[styles.tableCell, styles.tableHeaderText, { backgroundColor: '#1e293b' }]}>Total</Text>
                    </View>
                    {filtered.map((row, idx) => (
                        <View key={idx} style={[styles.tableRow, idx % 2 === 0 ? styles.tableRowEven : null]}>
                            <Text style={[styles.tableCell, styles.tableCellName]} numberOfLines={1}>
                                {formatFn ? formatFn(row.name) : row.name}
                            </Text>
                            <Text style={styles.tableCell}>{row.pending || '-'}</Text>
                            <Text style={styles.tableCell}>{row.approved || '-'}</Text>
                            <Text style={styles.tableCell}>{row.checked_in || '-'}</Text>
                            <Text style={styles.tableCell}>{row.checked_out || '-'}</Text>
                            <Text style={styles.tableCell}>{row.rejected || '-'}</Text>
                            <Text style={[styles.tableCell, { fontWeight: '900' }]}>{row.total}</Text>
                        </View>
                    ))}
                </View>
            </ScrollView>
        );
    };

    // --- Render Performance ---
    const renderPerformance = () => {
        const perf = data?.performance;
        if (!perf || perf.length === 0) {
            return renderEmpty('chart-timeline-variant', 'No performance data for selected period');
        }

        return perf.map((stat, idx) => (
            <View key={idx} style={styles.perfCard}>
                <View style={styles.perfHeader}>
                    <Icon name="account-tie" size={20} color="#3b82f6" />
                    <Text style={styles.perfName}>{stat.name}</Text>
                    <View style={styles.perfBadge}>
                        <Text style={styles.perfBadgeText}>{stat.total} visits</Text>
                    </View>
                </View>
                <View style={styles.perfMetrics}>
                    <View style={styles.perfMetricItem}>
                        <Text style={styles.perfMetricValue}>{stat.avg_duration ? `${stat.avg_duration}m` : '-'}</Text>
                        <Text style={styles.perfMetricLabel}>Avg Duration</Text>
                    </View>
                    <View style={styles.perfMetricItem}>
                        <Text style={styles.perfMetricValue}>{stat.avg_lead_time ? `${stat.avg_lead_time}m` : '-'}</Text>
                        <Text style={styles.perfMetricLabel}>Lead Time</Text>
                    </View>
                    <View style={styles.perfMetricItem}>
                        <View style={styles.rateBar}>
                            <View style={[styles.rateBarFill, { width: `${stat.approval_rate}%`, backgroundColor: '#10b981' }]} />
                        </View>
                        <Text style={styles.perfMetricLabel}>{stat.approval_rate}% Approved</Text>
                    </View>
                    <View style={styles.perfMetricItem}>
                        <View style={styles.rateBar}>
                            <View style={[styles.rateBarFill, { width: `${stat.rejection_rate}%`, backgroundColor: '#ef4444' }]} />
                        </View>
                        <Text style={styles.perfMetricLabel}>{stat.rejection_rate}% Rejected</Text>
                    </View>
                </View>
            </View>
        ));
    };

    // --- Render Analytics Charts ---
    const renderTrendChart = () => {
        const trend = data?.trend_data;
        if (!trend || trend.length === 0) {
            return (
                <View style={styles.chartCard}>
                    <Text style={styles.chartTitle}>📈 Daily Visit Trend</Text>
                    {renderEmpty('chart-line', 'No trend data available. Try a wider date range.')}
                </View>
            );
        }

        const labels = trend.map(t => {
            const d = new Date(t.label);
            if (isNaN(d.getTime())) return t.label;
            return `${String(d.getDate()).padStart(2, '0')}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getFullYear()).slice(-2)}`;
        });
        const values = trend.map(t => t.value);
        // Ensure at least one non-zero value for chart rendering
        const safeValues = values.some(v => v > 0) ? values : values.map(() => 0);

        return (
            <View style={styles.chartCard}>
                <Text style={styles.chartTitle}>Daily Visit Trend</Text>
                <ScrollView horizontal showsHorizontalScrollIndicator={false}>
                    <LineChart
                        data={{
                            labels: labels,
                            datasets: [{ data: safeValues.length > 0 ? safeValues : [0] }]
                        }}
                        width={Math.max(screenWidth - 50, labels.length * 60)}
                        height={320}
                        chartConfig={{
                            ...chartConfig,
                            color: (opacity = 1) => `rgba(67, 97, 238, ${opacity})`,
                            fillShadowGradient: '#4361ee',
                            fillShadowGradientOpacity: 0.2,
                            propsForLabels: {
                                fontSize: 10,
                                fontWeight: '600'
                            },
                        }}
                        bezier
                        style={[styles.chart, { marginVertical: 8, borderRadius: 16 }]}
                        segments={8}
                        verticalLabelRotation={60}
                        xLabelsOffset={-10}
                        withInnerLines={true}
                        withOuterLines={true}
                        withDots={true}
                        fromZero
                    />
                </ScrollView>
            </View>
        );
    };

    const renderHourlyChart = () => {
        const hourly = data?.hourly_data;
        if (!hourly || hourly.length === 0) {
            return (
                <View style={styles.chartCard}>
                    <Text style={styles.chartTitle}>⏰ Peak Traffic (Hour of Day)</Text>
                    {renderEmpty('clock-outline', 'No hourly data available. Try a wider date range.')}
                </View>
            );
        }

        const labels = hourly.map(h => h.label);
        const values = hourly.map(h => h.value);
        const allZero = values.every(v => v === 0);

        if (allZero) {
            return (
                <View style={styles.chartCard}>
                    <Text style={styles.chartTitle}>⏰ Peak Traffic (Hour of Day)</Text>
                    {renderEmpty('clock-outline', 'No traffic data for this period.')}
                </View>
            );
        }

        return (
            <View style={styles.chartCard}>
                <Text style={styles.chartTitle}>⏰ Peak Traffic (Hour of Day)</Text>
                <ScrollView horizontal showsHorizontalScrollIndicator={false}>
                    <BarChart
                        data={{
                            labels: labels,
                            datasets: [{ data: values }]
                        }}
                        width={Math.max(screenWidth - 50, labels.length * 45)}
                        height={220}
                        chartConfig={{
                            ...chartConfig,
                            color: (opacity = 1) => `rgba(76, 201, 240, ${opacity})`,
                            barPercentage: 0.6,
                        }}
                        style={styles.chart}
                        withInnerLines={true}
                        fromZero
                        showValuesOnTopOfBars
                    />
                </ScrollView>
            </View>
        );
    };

    const renderDeptChart = () => {
        const dept = data?.dept_stats;
        if (!dept || dept.length === 0) {
            return (
                <View style={styles.chartCard}>
                    <Text style={styles.chartTitle}>🏢 Visits by Department</Text>
                    {renderEmpty('domain', 'No department data available.')}
                </View>
            );
        }

        const pieData = dept.map((d, idx) => ({
            name: d.name,
            count: d.count,
            color: chartColors[idx % chartColors.length],
            legendFontColor: '#1e293b',
            legendFontSize: 12,
        }));

        return (
            <View style={styles.chartCard}>
                <Text style={styles.chartTitle}>🏢 Visits by Department</Text>
                <PieChart
                    data={pieData}
                    width={screenWidth - 50}
                    height={200}
                    chartConfig={chartConfig}
                    accessor="count"
                    backgroundColor="transparent"
                    paddingLeft="10"
                    style={styles.chart}
                />
            </View>
        );
    };

    const renderPurposeChart = () => {
        const ps = data?.purpose_stats;
        if (!ps || ps.length === 0) {
            return (
                <View style={styles.chartCard}>
                    <Text style={styles.chartTitle}>🎯 Purpose Breakdown</Text>
                    {renderEmpty('target', 'No purpose data available.')}
                </View>
            );
        }

        const pieData = ps.map((p, idx) => ({
            name: p.purpose || 'Unknown',
            count: parseInt(p.count),
            color: chartColors[(idx + 3) % chartColors.length],
            legendFontColor: '#1e293b',
            legendFontSize: 12,
        }));

        return (
            <View style={styles.chartCard}>
                <Text style={styles.chartTitle}>🎯 Purpose Breakdown</Text>
                <PieChart
                    data={pieData}
                    width={screenWidth - 50}
                    height={200}
                    chartConfig={chartConfig}
                    accessor="count"
                    backgroundColor="transparent"
                    paddingLeft="10"
                    style={styles.chart}
                />
            </View>
        );
    };

    // --- Top Visitors ---
    const renderTopVisitors = () => {
        const tv = data?.top_visitors;
        if (!tv || tv.length === 0) {
            return (
                <View style={styles.chartCard}>
                    <Text style={styles.chartTitle}>🏆 Top 10 Frequent Visitors</Text>
                    {renderEmpty('account-group', 'No visitor data available.')}
                </View>
            );
        }

        return (
            <View style={styles.chartCard}>
                <Text style={styles.chartTitle}>🏆 Top 10 Frequent Visitors</Text>
                {tv.map((v, idx) => (
                    <View key={idx} style={styles.topVisitorRow}>
                        <View style={styles.topVisitorRank}>
                            <Text style={styles.topVisitorRankText}>#{idx + 1}</Text>
                        </View>
                        <View style={{ flex: 1 }}>
                            <Text style={styles.topVisitorName}>{v.name}</Text>
                            <Text style={styles.topVisitorHosts}>{v.hosts}</Text>
                        </View>
                        <View style={styles.topVisitorCountBadge}>
                            <Text style={styles.topVisitorCountText}>{v.visit_count}</Text>
                        </View>
                    </View>
                ))}
            </View>
        );
    };

    if (loading && !refreshing) {
        return (
            <SafeAreaView style={styles.container}>
                <View style={styles.loadingContainer}>
                    <ActivityIndicator size="large" color="#059669" />
                    <Text style={styles.loadingText}>Loading Report...</Text>
                </View>
            </SafeAreaView>
        );
    }

    return (
        <SafeAreaView style={styles.container}>
            <StatusBar barStyle="light-content" />

            {/* Calendar Pickers */}
            <CalendarPicker
                visible={showStartCal}
                onClose={() => setShowStartCal(false)}
                selectedDate={startDate}
                title="Select Start Date"
                onSelect={(d) => { setStartDate(d); setFilterPreset('custom'); }}
            />
            <CalendarPicker
                visible={showEndCal}
                onClose={() => setShowEndCal(false)}
                selectedDate={endDate}
                title="Select End Date"
                onSelect={(d) => { setEndDate(d); setFilterPreset('custom'); }}
            />

            {/* Header */}
            <View style={styles.header}>
                <TouchableOpacity onPress={() => navigation.goBack()} style={styles.backBtn}>
                    <Icon name="arrow-left" size={22} color="#fff" />
                </TouchableOpacity>
                <View style={{ flex: 1 }}>
                    <Text style={styles.headerTitle}>Employee-wise Report</Text>
                    <Text style={styles.headerSubtitle}>{fmtDate(startDate)} — {fmtDate(endDate)}</Text>
                </View>
            </View>

            {/* Date Presets */}
            <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.presetBar} contentContainerStyle={{ paddingHorizontal: 12 }}>
                {[
                    { key: 'today', label: 'Today' },
                    { key: 'yesterday', label: 'Yesterday' },
                    { key: 'week', label: 'Last 7 Days' },
                    { key: 'month', label: 'This Month' },
                    { key: '3months', label: '3 Months' },
                    { key: 'year', label: 'This Year' },
                ].map(p => (
                    <TouchableOpacity
                        key={p.key}
                        style={[styles.presetChip, filterPreset === p.key && styles.presetChipActive]}
                        onPress={() => applyPreset(p.key)}
                    >
                        <Text style={[styles.presetChipText, filterPreset === p.key && styles.presetChipTextActive]}>{p.label}</Text>
                    </TouchableOpacity>
                ))}
            </ScrollView>

            {/* Custom Date Range Picker */}
            <View style={styles.datePickerRow}>
                <TouchableOpacity style={styles.datePickerBtn} onPress={() => setShowStartCal(true)}>
                    <Icon name="calendar-start" size={18} color="#059669" />
                    <Text style={styles.datePickerText}>{fmtDate(startDate)}</Text>
                </TouchableOpacity>
                <Text style={styles.datePickerSep}>to</Text>
                <TouchableOpacity style={styles.datePickerBtn} onPress={() => setShowEndCal(true)}>
                    <Icon name="calendar-end" size={18} color="#059669" />
                    <Text style={styles.datePickerText}>{fmtDate(endDate)}</Text>
                </TouchableOpacity>
            </View>

            {/* Search */}
            <View style={styles.searchBar}>
                <Icon name="magnify" size={20} color="#94a3b8" />
                <TextInput
                    style={styles.searchInput}
                    placeholder="Search by name, host..."
                    placeholderTextColor="#94a3b8"
                    value={searchTerm}
                    onChangeText={setSearchTerm}
                />
                {searchTerm.length > 0 && (
                    <TouchableOpacity onPress={() => setSearchTerm('')}>
                        <Icon name="close-circle" size={18} color="#94a3b8" />
                    </TouchableOpacity>
                )}
            </View>

            {/* Tabs */}
            <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.tabBar} contentContainerStyle={{ paddingHorizontal: 12 }}>
                {TAB_KEYS.map(key => (
                    <TouchableOpacity
                        key={key}
                        style={[styles.tab, activeTab === key && styles.tabActive]}
                        onPress={() => setActiveTab(key)}
                    >
                        <Text style={[styles.tabText, activeTab === key && styles.tabTextActive]}>{TAB_LABELS[key]}</Text>
                    </TouchableOpacity>
                ))}
            </ScrollView>

            {/* Content */}
            <ScrollView
                style={{ flex: 1 }}
                contentContainerStyle={{ padding: 15, paddingBottom: 40 }}
                refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} colors={['#059669']} />}
            >
                {error ? (
                    <View style={styles.errorBox}>
                        <Icon name="alert-circle" size={24} color="#ef4444" />
                        <Text style={styles.errorText}>{error}</Text>
                        <TouchableOpacity onPress={onRefresh}>
                            <Text style={styles.retryText}>Retry</Text>
                        </TouchableOpacity>
                    </View>
                ) : (
                    <>
                        {activeTab === 'host' && (
                            <View style={styles.sectionCard}>
                                <Text style={styles.sectionCardTitle}>👤 Visit Summary by Host</Text>
                                {renderPivotTable(data?.pivot_host, 'Host', null)}
                            </View>
                        )}

                        {activeTab === 'month' && (
                            <View style={styles.sectionCard}>
                                <Text style={styles.sectionCardTitle}>📅 Visit Summary by Month</Text>
                                {renderPivotTable(data?.pivot_month, 'Month', fmtMonth)}
                            </View>
                        )}

                        {activeTab === 'day' && (
                            <View style={styles.sectionCard}>
                                <Text style={styles.sectionCardTitle}>📆 Visit Summary by Date</Text>
                                {renderPivotTable(data?.pivot_day, 'Date', fmtDay)}
                            </View>
                        )}

                        {activeTab === 'perf' && (
                            <>
                                <Text style={styles.sectionTitle}>⚡ Host Performance</Text>
                                {renderPerformance()}
                            </>
                        )}

                        {activeTab === 'analytics' && (
                            <>
                                {renderTrendChart()}
                                {renderDeptChart()}
                                {renderHourlyChart()}
                                {renderPurposeChart()}
                                {renderTopVisitors()}
                            </>
                        )}
                    </>
                )}
            </ScrollView>
        </SafeAreaView>
    );
}

const styles = StyleSheet.create({
    container: { flex: 1, backgroundColor: '#f8fafc' },
    loadingContainer: { flex: 1, justifyContent: 'center', alignItems: 'center' },
    loadingText: { marginTop: 12, color: '#64748b', fontWeight: '600' },

    // Header
    header: { flexDirection: 'row', alignItems: 'center', backgroundColor: '#059669', paddingHorizontal: 16, paddingVertical: 16, paddingTop: 10 },
    backBtn: { marginRight: 12, padding: 4 },
    headerTitle: { fontSize: 20, fontWeight: '900', color: '#fff' },
    headerSubtitle: { fontSize: 12, color: 'rgba(255,255,255,0.8)', marginTop: 2, fontWeight: '600' },

    // Presets
    presetBar: { backgroundColor: '#fff', borderBottomWidth: 1, borderBottomColor: '#f1f5f9', maxHeight: 50, paddingVertical: 8 },
    presetChip: { paddingHorizontal: 14, paddingVertical: 6, borderRadius: 20, backgroundColor: '#f1f5f9', marginRight: 8 },
    presetChipActive: { backgroundColor: '#059669' },
    presetChipText: { fontSize: 12, fontWeight: '700', color: '#64748b' },
    presetChipTextActive: { color: '#fff' },

    // Date Picker Row
    datePickerRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', paddingHorizontal: 15, paddingVertical: 10, backgroundColor: '#fff', borderBottomWidth: 1, borderBottomColor: '#f1f5f9' },
    datePickerBtn: { flexDirection: 'row', alignItems: 'center', backgroundColor: '#f0fdf4', borderWidth: 1, borderColor: '#059669', borderRadius: 10, paddingHorizontal: 12, paddingVertical: 8, gap: 6 },
    datePickerText: { fontSize: 13, fontWeight: '700', color: '#059669' },
    datePickerSep: { fontSize: 13, color: '#94a3b8', marginHorizontal: 10, fontWeight: '600' },

    // Search
    searchBar: { flexDirection: 'row', alignItems: 'center', backgroundColor: '#fff', marginHorizontal: 15, marginTop: 10, borderRadius: 12, paddingHorizontal: 12, paddingVertical: 8, borderWidth: 1, borderColor: '#e2e8f0' },
    searchInput: { flex: 1, marginLeft: 8, fontSize: 14, color: '#1e293b' },

    // Tabs
    tabBar: { maxHeight: 48, marginTop: 10, flexGrow: 0 },
    tab: { paddingHorizontal: 16, paddingVertical: 10, marginRight: 4, borderRadius: 20, backgroundColor: '#f1f5f9' },
    tabActive: { backgroundColor: '#1e293b' },
    tabText: { fontSize: 13, fontWeight: '700', color: '#64748b' },
    tabTextActive: { color: '#fff' },

    // Section
    sectionCard: { backgroundColor: '#fff', borderRadius: 16, padding: 16, marginBottom: 16, elevation: 2, shadowColor: '#000', shadowOffset: { width: 0, height: 2 }, shadowOpacity: 0.06, shadowRadius: 4 },
    sectionCardTitle: { fontSize: 15, fontWeight: '900', color: '#1e293b', marginBottom: 14 },
    sectionTitle: { fontSize: 18, fontWeight: '900', color: '#1e293b', marginBottom: 12 },

    // Table
    tableRow: { flexDirection: 'row', borderBottomWidth: 1, borderBottomColor: '#f1f5f9' },
    tableRowEven: { backgroundColor: '#f8fafc' },
    tableHeader: { backgroundColor: '#1e293b', borderTopLeftRadius: 8, borderTopRightRadius: 8 },
    tableCell: { width: 68, paddingVertical: 10, paddingHorizontal: 4, textAlign: 'center', fontSize: 11, fontWeight: '600', color: '#1e293b' },
    tableCellName: { width: 120, textAlign: 'left', paddingLeft: 10 },
    tableHeaderText: { color: '#fff', fontWeight: '800', fontSize: 11 },

    // Empty
    emptyBox: { alignItems: 'center', paddingVertical: 30 },
    emptyText: { fontSize: 13, color: '#94a3b8', marginTop: 10, fontWeight: '600', textAlign: 'center' },

    // Error
    errorBox: { backgroundColor: '#fef2f2', borderRadius: 12, padding: 16, flexDirection: 'row', alignItems: 'center', gap: 10 },
    errorText: { flex: 1, color: '#ef4444', fontWeight: '600' },
    retryText: { color: '#3b82f6', fontWeight: '800', textDecorationLine: 'underline' },

    // Performance
    perfCard: { backgroundColor: '#fff', borderRadius: 16, padding: 16, marginBottom: 14, elevation: 2, shadowColor: '#000', shadowOffset: { width: 0, height: 2 }, shadowOpacity: 0.06, shadowRadius: 4, borderLeftWidth: 4, borderLeftColor: '#3b82f6' },
    perfHeader: { flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 14 },
    perfName: { flex: 1, fontSize: 15, fontWeight: '800', color: '#1e293b' },
    perfBadge: { backgroundColor: '#eef2ff', paddingHorizontal: 10, paddingVertical: 4, borderRadius: 12 },
    perfBadgeText: { fontSize: 12, fontWeight: '800', color: '#4f46e5' },
    perfMetrics: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
    perfMetricItem: { width: '47%' },
    perfMetricValue: { fontSize: 20, fontWeight: '900', color: '#1e293b' },
    perfMetricLabel: { fontSize: 11, color: '#64748b', fontWeight: '600', marginTop: 2 },
    rateBar: { height: 8, backgroundColor: '#f1f5f9', borderRadius: 4, overflow: 'hidden', marginTop: 4 },
    rateBarFill: { height: '100%', borderRadius: 4 },

    // Charts
    chartCard: { backgroundColor: '#fff', borderRadius: 16, padding: 16, marginBottom: 16, elevation: 2, shadowColor: '#000', shadowOffset: { width: 0, height: 2 }, shadowOpacity: 0.06, shadowRadius: 4 },
    chartTitle: { fontSize: 15, fontWeight: '900', color: '#1e293b', marginBottom: 12 },
    chart: { borderRadius: 12, marginLeft: -10 },

    // Top Visitors
    topVisitorRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: '#f8fafc', gap: 10 },
    topVisitorRank: { width: 30, height: 30, borderRadius: 15, backgroundColor: '#eef2ff', justifyContent: 'center', alignItems: 'center' },
    topVisitorRankText: { fontSize: 12, fontWeight: '900', color: '#4f46e5' },
    topVisitorName: { fontSize: 14, fontWeight: '800', color: '#1e293b' },
    topVisitorHosts: { fontSize: 11, color: '#64748b', marginTop: 2 },
    topVisitorCountBadge: { backgroundColor: '#3b82f6', paddingHorizontal: 10, paddingVertical: 4, borderRadius: 12 },
    topVisitorCountText: { fontSize: 12, fontWeight: '900', color: '#fff' },

    // Calendar Picker
    calOverlay: { flex: 1, backgroundColor: 'rgba(0,0,0,0.5)', justifyContent: 'center', alignItems: 'center' },
    calContainer: { backgroundColor: '#fff', borderRadius: 20, padding: 20, width: screenWidth - 50, elevation: 8, shadowColor: '#000', shadowOffset: { width: 0, height: 4 }, shadowOpacity: 0.15, shadowRadius: 10 },
    calTitle: { fontSize: 16, fontWeight: '900', color: '#1e293b', textAlign: 'center', marginBottom: 16 },
    calNav: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 },
    calNavBtn: { padding: 6 },
    calNavText: { fontSize: 16, fontWeight: '800', color: '#1e293b' },
    calWeekRow: { flexDirection: 'row', marginBottom: 6 },
    calWeekDay: { flex: 1, textAlign: 'center', fontSize: 12, fontWeight: '800', color: '#94a3b8' },
    calGrid: { flexDirection: 'row', flexWrap: 'wrap' },
    calDay: { width: `${100 / 7}%`, aspectRatio: 1, justifyContent: 'center', alignItems: 'center', borderRadius: 20 },
    calDayText: { fontSize: 14, fontWeight: '600', color: '#1e293b' },
    calDaySelected: { backgroundColor: '#059669' },
    calDayTextSelected: { color: '#fff', fontWeight: '900' },
    calDayToday: { backgroundColor: '#f0fdf4', borderWidth: 1, borderColor: '#059669' },
    calDayTextToday: { color: '#059669', fontWeight: '800' },
    calCloseBtn: { marginTop: 14, alignItems: 'center', paddingVertical: 10 },
    calCloseBtnText: { fontSize: 14, fontWeight: '700', color: '#94a3b8' },
});
