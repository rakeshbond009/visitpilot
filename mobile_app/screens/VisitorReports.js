import React, { useState, useEffect } from 'react';
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
    FlatList,
    Platform,
    TextInput,
} from 'react-native';
import { MaterialCommunityIcons as Icon } from '@expo/vector-icons';
import { Picker } from '@react-native-picker/picker';
import apiClient from '../utils/apiClient';

const { width } = Dimensions.get('window');

const RANGE_OPTIONS = [
    { label: 'Today', value: 'today' },
    { label: 'Yesterday', value: 'yesterday' },
    { label: 'Last 7 Days', value: '7days' },
    { label: 'Last 30 Days', value: '30days' },
    { label: 'This Month', value: 'month' },
];

export default function VisitorReports({ navigation }) {
    const [loading, setLoading] = useState(true);
    const [data, setData] = useState(null);
    const [filters, setFilters] = useState({
        range: 'today',
        startDate: new Date().toISOString().split('T')[0],
        endDate: new Date().toISOString().split('T')[0],
        department: '',
        employee_id: '',
    });
    const [showFilters, setShowFilters] = useState(false);

    const getDatesFromRange = (range) => {
        const today = new Date();
        let start = new Date();
        let end = new Date();

        switch (range) {
            case 'today':
                start = today;
                end = today;
                break;
            case 'yesterday':
                start.setDate(today.getDate() - 1);
                end.setDate(today.getDate() - 1);
                break;
            case '7days':
                start.setDate(today.getDate() - 7);
                break;
            case '30days':
                start.setDate(today.getDate() - 30);
                break;
            case 'month':
                start = new Date(today.getFullYear(), today.getMonth(), 1);
                break;
        }
        return {
            start: start.toISOString().split('T')[0],
            end: end.toISOString().split('T')[0],
        };
    };

    const fetchReports = async () => {
        setLoading(true);
        try {
            const response = await apiClient.get('api/admin/get_reports.php', {
                params: {
                    start_date: filters.startDate,
                    end_date: filters.endDate,
                    department: filters.department,
                    employee_id: filters.employee_id,
                }
            });

            if (response.data.status === 'success') {
                setData(response.data.data);
            } else {
                Alert.alert('Error', response.data.message || 'Failed to fetch reports');
            }
        } catch (error) {
            console.error('Reports Fetch Error:', error);
            Alert.alert('Error', 'Network error occurred. Please check your connection.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchReports();
    }, [filters.startDate, filters.endDate, filters.department, filters.employee_id]);

    const handleRangeChange = (range) => {
        const dates = getDatesFromRange(range);
        setFilters(prev => ({
            ...prev,
            range,
            startDate: dates.start,
            endDate: dates.end,
        }));
    };

    const renderStatCard = (title, value, subText = '', color) => (
        <View style={styles.statCard}>
            <Text style={styles.statLabel}>{title}</Text>
            <Text style={[styles.statValue, { color }]}>{value}</Text>
            {subText ? <Text style={styles.statSubText}>{subText}</Text> : null}
        </View>
    );

    const renderAIInsights = () => (
        <View style={styles.aiCard}>
            <View style={styles.aiHeader}>
                <Icon name="robot" size={20} color="#fff" />
                <Text style={styles.aiTitle}>AI Insights</Text>
            </View>
            <View style={styles.aiBody}>
                <View style={styles.insightItem}>
                    <Icon name="history" size={16} color="#f59e0b" style={styles.insightIcon} />
                    <View style={styles.insightTextCont}>
                        <Text style={styles.insightBold}>Traffic Pattern:</Text>
                        <Text style={styles.insightDesc}>Most visitors arrive between {data?.summary?.peak_hour}. Suggest increasing staff during this window.</Text>
                    </View>
                </View>

                <View style={styles.insightItem}>
                    <Icon name="office-building" size={16} color="#3b82f6" style={styles.insightIcon} />
                    <View style={styles.insightTextCont}>
                        <Text style={styles.insightBold}>Department Load:</Text>
                        <Text style={styles.insightDesc}>The {data?.summary?.top_department || 'General'} department is receiving the highest footfall ({data?.summary?.top_dept_count || 0} visits).</Text>
                    </View>
                </View>

                <View style={styles.insightItem}>
                    <Icon name="account-check" size={16} color="#10b981" style={styles.insightIcon} />
                    <View style={styles.insightTextCont}>
                        <Text style={styles.insightBold}>Top Host:</Text>
                        <Text style={styles.insightDesc}>{data?.summary?.top_host || 'N/A'} has the most appointments.</Text>
                    </View>
                </View>

                <View style={styles.predictionBox}>
                    <Icon name="lightbulb-on" size={16} color="#f59e0b" />
                    <Text style={styles.predictionText}>
                        Prediction: expect a <Text style={{ color: data?.summary?.trend_color || '#10b981', fontWeight: 'bold' }}>{data?.summary?.trend_text || 'Stable'}</Text> flow for the next 48 hours.
                    </Text>
                </View>
            </View>
        </View>
    );

    const renderDailyTrend = () => {
        const hasData = data?.trends && data.trends.length > 0;
        const maxVal = hasData ? Math.max(...data.trends.map(t => t.count), 1) : 1;
        const chartHeight = 100;

        return (
            <View style={styles.sectionCard}>
                <View style={styles.sectionHeader}>
                    <Icon name="chart-line" size={18} color="#1e293b" />
                    <Text style={styles.sectionTitle}>Visitor Traffic Trends</Text>
                </View>
                {hasData ? (
                    <View style={styles.chartRow}>
                        {data.trends.map((t, idx) => (
                            <View key={idx} style={styles.chartBarCol}>
                                <View style={[styles.chartBar, { height: (t.count / maxVal) * chartHeight, backgroundColor: '#3b82f6' }]} />
                                <Text style={styles.chartLabel}>{new Date(t.date).getDate()}/{new Date(t.date).getMonth() + 1}</Text>
                            </View>
                        ))}
                    </View>
                ) : (
                    <View style={styles.emptyChart}>
                        <Text style={styles.emptyChartText}>No trend data for this period</Text>
                    </View>
                )}
            </View>
        );
    };

    const renderHourlyTrend = () => {
        const hasData = data?.hourly && data.hourly.length > 0;
        const hours = Array.from({ length: 13 }, (_, i) => i + 8); // 8 AM to 8 PM
        const maxVal = hasData ? Math.max(...data.hourly.map(h => h.count), 1) : 1;
        const chartHeight = 100;

        return (
            <View style={styles.sectionCard}>
                <View style={styles.sectionHeader}>
                    <Icon name="clock-outline" size={18} color="#1e293b" />
                    <Text style={styles.sectionTitle}>Visits by Time of Day</Text>
                </View>
                {hasData ? (
                    <View style={styles.chartRow}>
                        {hours.map((h, idx) => {
                            const match = data.hourly.find(item => parseInt(item.hour) === h);
                            const count = match ? match.count : 0;
                            const label = h > 12 ? `${h - 12}P` : (h === 12 ? '12P' : `${h}A`);
                            return (
                                <View key={idx} style={styles.chartBarCol}>
                                    <View style={[styles.chartBar, { height: (count / maxVal) * chartHeight, backgroundColor: '#06b6d4' }]} />
                                    <Text style={styles.chartLabel}>{label}</Text>
                                </View>
                            );
                        })}
                    </View>
                ) : (
                    <View style={styles.emptyChart}>
                        <Text style={styles.emptyChartText}>No hourly data available</Text>
                    </View>
                )}
            </View>
        );
    };

    const renderDeptDistribution = () => {
        const hasData = data?.departments && data.departments.length > 0;
        const maxVal = hasData ? Math.max(...data.departments.map(d => d.count), 1) : 1;

        return (
            <View style={styles.sectionCard}>
                <View style={styles.sectionHeader}>
                    <Icon name="office-building" size={18} color="#1e293b" />
                    <Text style={styles.sectionTitle}>Department Distribution</Text>
                </View>
                {hasData ? (
                    <View style={{ marginTop: 10 }}>
                        {data.departments.map((d, idx) => (
                            <View key={idx} style={styles.distRow}>
                                <View style={styles.distInfo}>
                                    <Text style={styles.distName}>{d.department || 'General'}</Text>
                                    <Text style={styles.distCount}>{d.count} visits</Text>
                                </View>
                                <View style={styles.progressBg}>
                                    <View style={[styles.progressBar, { width: `${(d.count / maxVal) * 100}%`, backgroundColor: '#10b981' }]} />
                                </View>
                            </View>
                        ))}
                    </View>
                ) : (
                    <View style={styles.emptyChart}>
                        <Text style={styles.emptyChartText}>No department data available</Text>
                    </View>
                )}
            </View>
        );
    }

    const renderLogItem = ({ item }) => (
        <View style={styles.logRow}>
            <View style={styles.logMain}>
                <View style={styles.visitorBlock}>
                    <Text style={styles.logVisitorName}>{item.visitor_name}</Text>
                    <Text style={styles.logVisitorSub}>{item.mobile}</Text>
                </View>
                <View style={styles.logTimeBlock}>
                    <Text style={styles.logDate}>{new Date(item.created_at).toLocaleDateString()}</Text>
                    <Text style={styles.logTime}>{new Date(item.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</Text>
                </View>
            </View>
            <View style={styles.logSubRow}>
                <Text style={styles.logDetail}><Icon name="account" size={12} /> {item.host_name}</Text>
                <Text style={styles.logDetail}><Icon name="office-building" size={12} /> {item.department}</Text>
            </View>
            <View style={styles.logStatusRow}>
                <View style={styles.checkTimes}>
                    <Text style={styles.checkText}>In: {item.check_in_time ? new Date(item.check_in_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '--:--'}</Text>
                    <Text style={styles.checkText}>Out: {item.check_out_time ? new Date(item.check_out_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '--:--'}</Text>
                </View>
                <View style={[styles.statusBadge, { backgroundColor: item.status === 'checked_in' ? '#dcfce7' : (item.status === 'pending' ? '#fef3c7' : '#f1f5f9') }]}>
                    <Text style={[styles.statusText, { color: item.status === 'checked_in' ? '#166534' : (item.status === 'pending' ? '#92400e' : '#475569') }]}>
                        {item.status.toUpperCase()}
                    </Text>
                </View>
            </View>
        </View>
    );

    return (
        <SafeAreaView style={styles.container}>
            <View style={styles.header}>
                <TouchableOpacity onPress={() => navigation.goBack()} style={styles.backBtn}>
                    <Icon name="chevron-left" size={28} color="#1e293b" />
                </TouchableOpacity>
                <Text style={styles.headerTitle}>Reports & Analytics</Text>
                <TouchableOpacity onPress={() => setShowFilters(!showFilters)} style={styles.filterBtn}>
                    <Icon name="filter-variant" size={24} color="#3b82f6" />
                </TouchableOpacity>
            </View>

            {showFilters && (
                <View style={styles.filterCard}>
                    <Text style={styles.filterTitle}>Filters</Text>
                    <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.rangeOptions}>
                        {RANGE_OPTIONS.map(opt => (
                            <TouchableOpacity
                                key={opt.value}
                                style={[styles.rangeTab, filters.range === opt.value && styles.rangeTabActive]}
                                onPress={() => handleRangeChange(opt.value)}
                            >
                                <Text style={[styles.rangeTabText, filters.range === opt.value && styles.rangeTabTextActive]}>{opt.label}</Text>
                            </TouchableOpacity>
                        ))}
                    </ScrollView>

                    <View style={styles.filterPickers}>
                        <View style={styles.datePickerRow}>
                            <View style={styles.pickerBox}>
                                <Text style={styles.label}>Start Date (YYYY-MM-DD)</Text>
                                <View style={styles.pickerWrapper}>
                                    <TextInput
                                        style={styles.dateInput}
                                        value={filters.startDate}
                                        onChangeText={(val) => setFilters(prev => ({ ...prev, startDate: val, range: 'custom' }))}
                                        placeholder="2024-01-01"
                                    />
                                </View>
                            </View>
                            <View style={styles.pickerBox}>
                                <Text style={styles.label}>End Date (YYYY-MM-DD)</Text>
                                <View style={styles.pickerWrapper}>
                                    <TextInput
                                        style={styles.dateInput}
                                        value={filters.endDate}
                                        onChangeText={(val) => setFilters(prev => ({ ...prev, endDate: val, range: 'custom' }))}
                                        placeholder="2024-01-01"
                                    />
                                </View>
                            </View>
                        </View>

                        <View style={styles.pickerBox}>
                            <Text style={styles.label}>Department</Text>
                            <View style={styles.pickerWrapper}>
                                <Picker
                                    selectedValue={filters.department}
                                    onValueChange={(val) => setFilters(prev => ({ ...prev, department: val }))}
                                    style={styles.picker}
                                >
                                    <Picker.Item label="All Departments" value="" />
                                    {data?.meta?.departments?.map(dept => (
                                        <Picker.Item key={dept} label={dept} value={dept} />
                                    ))}
                                </Picker>
                            </View>
                        </View>

                        <View style={styles.pickerBox}>
                            <Text style={styles.label}>Host / Employee</Text>
                            <View style={styles.pickerWrapper}>
                                <Picker
                                    selectedValue={filters.employee_id}
                                    onValueChange={(val) => setFilters(prev => ({ ...prev, employee_id: val }))}
                                    style={styles.picker}
                                >
                                    <Picker.Item label="All Hosts" value="" />
                                    {data?.meta?.employees?.map(emp => (
                                        <Picker.Item key={emp.id} label={emp.name} value={emp.id} />
                                    ))}
                                </Picker>
                            </View>
                        </View>
                    </View>

                    <TouchableOpacity style={styles.applyBtn} onPress={() => setShowFilters(false)}>
                        <Text style={styles.applyBtnText}>Apply</Text>
                    </TouchableOpacity>
                </View>
            )}

            {loading ? (
                <View style={styles.loadingBox}>
                    <ActivityIndicator size="large" color="#3b82f6" />
                    <Text style={styles.loadingText}>Loading Report...</Text>
                </View>
            ) : (
                <FlatList
                    data={data?.logs || []}
                    renderItem={renderLogItem}
                    keyExtractor={item => item.id.toString()}
                    ListHeaderComponent={
                        <View style={styles.topSection}>
                            <View style={styles.statsGrid}>
                                <View style={styles.statsRow}>
                                    {renderStatCard('Total Visits', data?.summary?.total_visits, '', '#2563eb')}
                                    {renderStatCard('Peak Hour', data?.summary?.peak_hour, '', '#dc2626')}
                                </View>
                                <View style={styles.statsRow}>
                                    {renderStatCard('Top Dept', data?.summary?.top_department || '-', '', '#166534')}
                                    <View style={[styles.statCard, { backgroundColor: '#f8fafc' }]}>
                                        <Text style={styles.statLabel}>AI Forecast</Text>
                                        <Text style={[styles.statValue, { color: '#0891b2' }]}>~{data?.summary?.predicted}</Text>
                                        <Text style={styles.statSubText}>Tomorrow</Text>
                                    </View>
                                </View>
                            </View>

                            {renderDailyTrend()}
                            {renderAIInsights()}
                            {renderHourlyTrend()}
                            {renderDeptDistribution()}

                            <Text style={styles.sectionTitleLog}>Detailed Visitor Log</Text>
                        </View>
                    }
                    ListEmptyComponent={
                        <View style={styles.emptyBox}>
                            <Text style={styles.emptyMsg}>No records found</Text>
                        </View>
                    }
                    contentContainerStyle={styles.scrollArea}
                    showsVerticalScrollIndicator={false}
                />
            )}
        </SafeAreaView>
    );
}

const styles = StyleSheet.create({
    container: {
        flex: 1,
        backgroundColor: '#f1f5f9',
    },
    header: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        paddingHorizontal: 16,
        paddingVertical: 15,
        backgroundColor: '#fff',
        borderBottomWidth: 1,
        borderBottomColor: '#e2e8f0',
    },
    headerTitle: {
        fontSize: 18,
        fontWeight: 'bold',
        color: '#1e293b',
    },
    backBtn: {
        padding: 5,
    },
    filterBtn: {
        padding: 5,
    },
    topSection: {
        padding: 16,
    },
    statsGrid: {
        marginBottom: 16,
    },
    statsRow: {
        flexDirection: 'row',
        gap: 12,
        marginBottom: 12,
    },
    statCard: {
        flex: 1,
        backgroundColor: '#fff',
        padding: 15,
        borderRadius: 12,
        alignItems: 'center',
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 1 },
        shadowOpacity: 0.1,
        shadowRadius: 2,
        elevation: 1,
    },
    statLabel: {
        fontSize: 10,
        fontWeight: 'bold',
        color: '#64748b',
        textTransform: 'uppercase',
        marginBottom: 5,
    },
    statValue: {
        fontSize: 20,
        fontWeight: 'bold',
    },
    statSubText: {
        fontSize: 10,
        color: '#94a3b8',
        marginTop: 2,
    },
    sectionCard: {
        backgroundColor: '#fff',
        borderRadius: 12,
        padding: 16,
        marginBottom: 16,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 1 },
        shadowOpacity: 0.1,
        shadowRadius: 2,
        elevation: 1,
    },
    sectionHeader: {
        flexDirection: 'row',
        alignItems: 'center',
        gap: 8,
        marginBottom: 15,
    },
    sectionTitle: {
        fontSize: 14,
        fontWeight: 'bold',
        color: '#1e293b',
    },
    chartRow: {
        flexDirection: 'row',
        alignItems: 'flex-end',
        justifyContent: 'space-between',
        height: 120,
    },
    chartBarCol: {
        alignItems: 'center',
        flex: 1,
    },
    chartBar: {
        width: 10,
        borderRadius: 5,
        marginBottom: 8,
    },
    chartLabel: {
        fontSize: 8,
        color: '#94a3b8',
        fontWeight: 'bold',
    },
    aiCard: {
        backgroundColor: '#fff',
        borderRadius: 12,
        marginBottom: 16,
        borderWidth: 2,
        borderColor: '#3b82f6',
        overflow: 'hidden',
    },
    aiHeader: {
        backgroundColor: '#3b82f6',
        flexDirection: 'row',
        alignItems: 'center',
        padding: 12,
        gap: 8,
    },
    aiTitle: {
        color: '#fff',
        fontWeight: 'bold',
        fontSize: 14,
    },
    aiBody: {
        padding: 16,
    },
    insightItem: {
        flexDirection: 'row',
        marginBottom: 12,
        gap: 10,
    },
    insightIcon: {
        marginTop: 2,
    },
    insightTextCont: {
        flex: 1,
    },
    insightBold: {
        fontSize: 13,
        fontWeight: 'bold',
        color: '#1e293b',
    },
    insightDesc: {
        fontSize: 12,
        color: '#64748b',
        lineHeight: 18,
    },
    predictionBox: {
        backgroundColor: '#f8fafc',
        padding: 10,
        borderRadius: 8,
        flexDirection: 'row',
        alignItems: 'center',
        gap: 8,
        borderWidth: 1,
        borderColor: '#e2e8f0',
    },
    predictionText: {
        fontSize: 12,
        color: '#475569',
        flex: 1,
    },
    distRow: {
        marginBottom: 12,
    },
    distInfo: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        marginBottom: 4,
    },
    distName: {
        fontSize: 13,
        fontWeight: 'bold',
        color: '#334155',
    },
    distCount: {
        fontSize: 12,
        color: '#64748b',
    },
    progressBg: {
        height: 6,
        backgroundColor: '#f1f5f9',
        borderRadius: 3,
    },
    progressBar: {
        height: '100%',
        borderRadius: 3,
    },
    sectionTitleLog: {
        fontSize: 16,
        fontWeight: 'bold',
        color: '#1e293b',
        marginVertical: 15,
    },
    logRow: {
        backgroundColor: '#fff',
        marginHorizontal: 16,
        marginBottom: 12,
        borderRadius: 12,
        padding: 15,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 1 },
        shadowOpacity: 0.1,
        shadowRadius: 2,
        elevation: 1,
    },
    logMain: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        marginBottom: 10,
    },
    visitorBlock: {
        flex: 1,
    },
    logVisitorName: {
        fontSize: 15,
        fontWeight: 'bold',
        color: '#1e293b',
    },
    logVisitorSub: {
        fontSize: 12,
        color: '#64748b',
    },
    logTimeBlock: {
        alignItems: 'flex-end',
    },
    logDate: {
        fontSize: 12,
        fontWeight: 'bold',
        color: '#1e293b',
    },
    logTime: {
        fontSize: 11,
        color: '#64748b',
    },
    logSubRow: {
        flexDirection: 'row',
        gap: 15,
        marginBottom: 10,
    },
    logDetail: {
        fontSize: 13,
        color: '#475569',
    },
    logStatusRow: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        paddingTop: 10,
        borderTopWidth: 1,
        borderTopColor: '#f1f5f9',
    },
    checkTimes: {
        flexDirection: 'row',
        gap: 15,
    },
    checkText: {
        fontSize: 11,
        color: '#64748b',
    },
    statusBadge: {
        paddingHorizontal: 8,
        paddingVertical: 3,
        borderRadius: 20,
    },
    statusText: {
        fontSize: 10,
        fontWeight: 'bold',
    },
    filterCard: {
        backgroundColor: '#fff',
        padding: 16,
        borderBottomWidth: 1,
        borderBottomColor: '#e2e8f0',
    },
    filterTitle: {
        fontSize: 14,
        fontWeight: 'bold',
        marginBottom: 12,
    },
    rangeOptions: {
        marginBottom: 15,
    },
    rangeTab: {
        paddingHorizontal: 15,
        paddingVertical: 8,
        borderRadius: 20,
        backgroundColor: '#f1f5f9',
        marginRight: 8,
    },
    rangeTabActive: {
        backgroundColor: '#3b82f6',
    },
    rangeTabText: {
        fontSize: 12,
        color: '#64748b',
    },
    rangeTabTextActive: {
        color: '#fff',
        fontWeight: 'bold',
    },
    filterPickers: {
        marginBottom: 15,
    },
    pickerBox: {
        flex: 1,
    },
    label: {
        fontSize: 12,
        color: '#64748b',
        marginBottom: 5,
    },
    pickerWrapper: {
        backgroundColor: '#f8fafc',
        borderRadius: 8,
        borderWidth: 1,
        borderColor: '#e2e8f0',
    },
    picker: {
        height: 45,
    },
    applyBtn: {
        backgroundColor: '#3b82f6',
        padding: 12,
        borderRadius: 8,
        alignItems: 'center',
    },
    applyBtnText: {
        color: '#fff',
        fontWeight: 'bold',
    },
    loadingBox: {
        flex: 1,
        justifyContent: 'center',
        alignItems: 'center',
    },
    loadingText: {
        marginTop: 10,
        color: '#64748b',
    },
    scrollArea: {
        paddingBottom: 20,
    },
    emptyBox: {
        padding: 40,
        alignItems: 'center',
    },
    emptyMsg: {
        color: '#94a3b8',
    },
    datePickerRow: {
        flexDirection: 'row',
        gap: 12,
        marginBottom: 15,
    },
    dateInput: {
        height: 40,
        paddingHorizontal: 10,
        fontSize: 14,
        color: '#1e293b',
    },
    emptyChart: {
        height: 100,
        justifyContent: 'center',
        alignItems: 'center',
        backgroundColor: '#f8fafc',
        borderRadius: 8,
        borderWidth: 1,
        borderColor: '#e2e8f0',
        borderStyle: 'dashed',
    },
    emptyChartText: {
        fontSize: 12,
        color: '#94a3b8',
    },
});
