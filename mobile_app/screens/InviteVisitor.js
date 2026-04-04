import React, { useState, useEffect, useRef } from 'react';
import {
    StyleSheet,
    View,
    Text,
    TouchableOpacity,
    TextInput,
    ScrollView,
    Alert,
    SafeAreaView,
    KeyboardAvoidingView,
    Platform,
    ActivityIndicator,
    Modal,
    Image,
    Linking,
} from 'react-native';
import DateTimePicker from '@react-native-community/datetimepicker';
import { MaterialCommunityIcons as Icon } from '@expo/vector-icons';
import CustomPicker from '../components/CustomPicker';
import AsyncStorage from '@react-native-async-storage/async-storage';
import apiClient from '../utils/apiClient';
import { CONFIG } from '../utils/config';

const BASE_URL = CONFIG.API_BASE_URL;

export default function InviteVisitor({ navigation }) {
    const nameRef = useRef(null);
    const mobileRef = useRef(null);
    const emailRef = useRef(null);
    const [name, setName] = useState('');
    const [mobile, setMobile] = useState('');
    const [email, setEmail] = useState('');
    const [purpose, setPurpose] = useState('');
    const [hostId, setHostId] = useState('');
    const [date, setDate] = useState(new Date().toISOString().split('T')[0]);
    const [showDatePicker, setShowDatePicker] = useState(false);
    const [loading, setLoading] = useState(false);
    const [purposes, setPurposes] = useState([]);
    const [user, setUser] = useState(null);

    // Success Modal State
    const [showSuccess, setShowSuccess] = useState(false);
    const [inviteResult, setInviteResult] = useState(null);

    useEffect(() => {
        fetchInitialData();
    }, []);

    const onDateChange = (event, selectedDate) => {
        setShowDatePicker(Platform.OS === 'ios');
        if (selectedDate) {
            setDate(selectedDate.toISOString().split('T')[0]);
        }
    };

    // Auto-fill visitor details on 10 digits
    useEffect(() => {
        if (mobile.length === 10) {
            lookupVisitor(mobile);
        }
    }, [mobile]);

    const lookupVisitor = async (phone) => {
        try {
            const response = await apiClient.get(`api/visitor/search.php?mobile=${phone}`);
            if (response.data.status === 'success') {
                const visitor = response.data.data;
                setName(visitor.name || '');
                setEmail(visitor.email || '');
            }
        } catch (err) {
            console.log('Lookup error:', err.message);
        }
    };


    const fetchInitialData = async () => {
        setLoading(true);
        try {
            // Get user data
            const userData = await AsyncStorage.getItem('userData');
            if (userData) {
                const parsedUser = JSON.parse(userData);
                setUser(parsedUser);

                // Auto-set hostId from logged in user's employee_id
                if (parsedUser.employee_id) {
                    setHostId(parsedUser.employee_id.toString());
                }
            }

            // Fetch metadata (purposes)
            const metaResponse = await apiClient.get('api/metadata/list.php');
            if (metaResponse.data.status === 'success') {
                setPurposes(metaResponse.data.data.purposes || []);
                if (metaResponse.data.data.purposes?.length > 0) {
                    setPurpose(metaResponse.data.data.purposes[0].purpose_name);
                }
            }
        } catch (error) {
            console.error('Fetch Initial Data Error:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleInvite = async () => {
        if (!name || !mobile || !purpose) {
            Alert.alert('Error', 'Please fill in all required fields (Name, Mobile, Purpose)');
            return;
        }

        // hostId is required from session
        if (!hostId) {
            Alert.alert('Error', 'Host employee identification missing. Please contact administrator.');
            return;
        }

        setLoading(true);
        try {
            const response = await apiClient.post('api/host/invite.php', {
                name,
                mobile,
                email,
                purpose,
                employee_id: hostId || undefined,
                visit_date: date
            });

            const result = response.data;
            if (result.status === 'success') {
                setInviteResult(result.data);
                setShowSuccess(true);

            } else {
                Alert.alert('Error', result.message || 'Failed to create invitation');
            }
        } catch (error) {
            console.error('Invite Error:', error);
            Alert.alert('Error', 'Failed to connect to server');
        } finally {
            setLoading(false);
        }
    };


    return (
        <SafeAreaView style={styles.container}>
            <KeyboardAvoidingView
                behavior={Platform.OS === 'ios' ? 'padding' : undefined}
                style={{ flex: 1 }}
            >
                <View style={styles.header}>
                    <TouchableOpacity onPress={() => navigation.goBack()} style={styles.backBtn}>
                        <Icon name="arrow-left" size={24} color="#fff" />
                    </TouchableOpacity>
                    <Text style={styles.headerTitle}>Invite Visitor</Text>
                    <View style={{ width: 40 }} />
                </View>

                <ScrollView
                    contentContainerStyle={styles.scrollContent}
                    showsVerticalScrollIndicator={false}
                    keyboardShouldPersistTaps="handled"
                >
                    <View style={styles.formCard}>
                        <Text style={styles.sectionTitle}>VISITOR DETAILS</Text>

                        <View style={styles.inputGroup}>
                            <Text style={styles.label}>Visitor Name *</Text>
                            <TouchableOpacity
                                activeOpacity={1}
                                onPress={() => nameRef.current?.focus()}
                                style={styles.inputWrapper}
                            >
                                <Icon name="account-outline" size={22} color="#6366f1" style={styles.inputIcon} />
                                <TextInput
                                    ref={nameRef}
                                    style={styles.input}
                                    placeholder="Enter full name"
                                    placeholderTextColor="#94a3b8"
                                    value={name}
                                    onChangeText={setName}
                                />
                            </TouchableOpacity>
                        </View>

                        <View style={styles.inputGroup}>
                            <Text style={styles.label}>Mobile Number *</Text>
                            <TouchableOpacity
                                activeOpacity={1}
                                onPress={() => mobileRef.current?.focus()}
                                style={styles.inputWrapper}
                            >
                                <Icon name="phone-outline" size={22} color="#6366f1" style={styles.inputIcon} />
                                <TextInput
                                    ref={mobileRef}
                                    style={styles.input}
                                    placeholder="10-digit mobile"
                                    placeholderTextColor="#94a3b8"
                                    value={mobile}
                                    onChangeText={setMobile}
                                    keyboardType="phone-pad"
                                    maxLength={10}
                                />
                            </TouchableOpacity>
                        </View>

                        <View style={styles.inputGroup}>
                            <Text style={styles.label}>Email Address (Optional)</Text>
                            <TouchableOpacity
                                activeOpacity={1}
                                onPress={() => emailRef.current?.focus()}
                                style={styles.inputWrapper}
                            >
                                <Icon name="email-outline" size={22} color="#6366f1" style={styles.inputIcon} />
                                <TextInput
                                    ref={emailRef}
                                    style={styles.input}
                                    placeholder="email@example.com"
                                    placeholderTextColor="#94a3b8"
                                    value={email}
                                    onChangeText={setEmail}
                                    keyboardType="email-address"
                                    autoCapitalize="none"
                                />
                            </TouchableOpacity>
                        </View>

                        <Text style={styles.sectionTitle}>VISIT DETAILS</Text>

                        <View style={styles.inputGroup}>
                            <Text style={styles.label}>Purpose of Visit *</Text>
                            <View style={styles.inputWrapper}>
                                <Icon name="clipboard-text-outline" size={22} color="#6366f1" style={styles.inputIcon} />
                                <CustomPicker
                                    label="Purpose of Visit"
                                    selectedValue={purpose}
                                    onValueChange={(itemValue) => setPurpose(itemValue)}
                                    options={purposes.map(p => ({
                                        label: p.purpose_name,
                                        value: p.purpose_name
                                    }))}
                                    placeholder="Select Purpose"
                                />
                            </View>
                        </View>

                        <View style={styles.inputGroup}>
                            <Text style={styles.label}>Expected Date</Text>
                            <TouchableOpacity
                                style={styles.inputWrapper}
                                onPress={() => setShowDatePicker(true)}
                                activeOpacity={0.7}
                            >
                                <Icon name="calendar-month-outline" size={22} color="#6366f1" style={styles.inputIcon} />
                                <TextInput
                                    style={styles.input}
                                    value={date.split('-').reverse().join('-')}
                                    editable={false}
                                    pointerEvents="none"
                                />
                            </TouchableOpacity>
                            {showDatePicker && (
                                <DateTimePicker
                                    value={new Date(date)}
                                    mode="date"
                                    display="default"
                                    onChange={onDateChange}
                                />
                            )}
                        </View>

                        <TouchableOpacity
                            style={[styles.submitBtn, loading && styles.disabledBtn]}
                            onPress={handleInvite}
                            disabled={loading}
                            activeOpacity={0.8}
                        >
                            {loading ? (
                                <ActivityIndicator color="#fff" />
                            ) : (
                                <>
                                    <Text style={styles.submitBtnText}>Generate Invitation</Text>
                                    <Icon name="arrow-right" size={20} color="#fff" style={{ marginLeft: 8 }} />
                                </>
                            )}
                        </TouchableOpacity>
                    </View>
                    <View style={{ height: 40 }} />
                </ScrollView>
            </KeyboardAvoidingView>

            {/* Success Modal */}
            <Modal
                visible={showSuccess}
                transparent={true}
                animationType="fade"
            >
                <View style={styles.modalOverlay}>
                    <View style={styles.modalContent}>
                        <View style={styles.modalHeader}>
                            <View style={styles.successIconWrapper}>
                                <Icon name="check" size={40} color="#15803d" />
                            </View>
                            <Text style={styles.successTitle}>Invitation Created!</Text>
                        </View>

                        <View style={styles.modalBody}>
                            <Text style={styles.successSubtitle}>
                                A pre-approved pass has been generated for {inviteResult?.visitor_name}.
                            </Text>

                            <View style={styles.qrCard}>
                                {inviteResult?.qr_code && (
                                    <Image
                                        source={{ uri: `${BASE_URL}${inviteResult.qr_code}` }}
                                        style={styles.qrImage}
                                        resizeMode="contain"
                                    />
                                )}
                                <Text style={styles.visitCode}>{inviteResult?.visit_code}</Text>
                                <Text style={styles.visitCodeLabel}>VISITOR PASS CODE</Text>
                                <View style={styles.scheduledTag}>
                                    <Text style={styles.scheduledText}>
                                        Scheduled: {inviteResult?.visit_date ? (() => {
                                            const d = new Date(inviteResult.visit_date);
                                            const day = d.getDate().toString().padStart(2, '0');
                                            const month = d.toLocaleString('en-GB', { month: 'short' });
                                            const year = d.getFullYear();
                                            return `${day}-${month}-${year}`;
                                        })() : ''}
                                    </Text>
                                </View>
                            </View>

                            <View style={styles.modalActions}>

                                <TouchableOpacity
                                    style={styles.printBtn}
                                    onPress={() => {/* Add print logic if needed */ }}
                                >
                                    <Icon name="printer" size={20} color="#3b82f6" style={{ marginRight: 8 }} />
                                    <Text style={styles.printBtnText}>Print Invitation</Text>
                                </TouchableOpacity>

                                <TouchableOpacity
                                    style={styles.anotherBtn}
                                    onPress={() => {
                                        setShowSuccess(false);
                                        setName('');
                                        setMobile('');
                                        setEmail('');
                                        setDate(new Date().toISOString().split('T')[0]);
                                    }}
                                >
                                    <Icon name="plus" size={20} color="#475569" style={{ marginRight: 8 }} />
                                    <Text style={styles.anotherBtnText}>Create Another</Text>
                                </TouchableOpacity>

                                <TouchableOpacity
                                    style={styles.dashboardLink}
                                    onPress={() => {
                                        setShowSuccess(false);
                                        navigation.goBack();
                                    }}
                                >
                                    <Text style={styles.dashboardLinkText}>Back to Dashboard</Text>
                                </TouchableOpacity>
                            </View>
                        </View>
                    </View>
                </View>
            </Modal>
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
        paddingTop: Platform.OS === 'ios' ? 0 : 20,
        paddingBottom: 20,
        backgroundColor: '#6366f1',
        borderBottomLeftRadius: 30,
        borderBottomRightRadius: 30,
        elevation: 10,
        shadowColor: '#6366f1',
        shadowOffset: { width: 0, height: 10 },
        shadowOpacity: 0.3,
        shadowRadius: 15,
    },
    backBtn: {
        padding: 8,
        backgroundColor: 'rgba(255,255,255,0.2)',
        borderRadius: 12,
    },
    headerTitle: {
        fontSize: 20,
        fontWeight: '800',
        color: '#fff',
        letterSpacing: 0.5,
    },
    scrollContent: {
        padding: 20,
        paddingTop: 30,
    },
    formCard: {
        backgroundColor: '#fff',
        borderRadius: 24,
        padding: 24,
        shadowColor: '#64748b',
        shadowOffset: { width: 0, height: 20 },
        shadowOpacity: 0.1,
        shadowRadius: 30,
        elevation: 8,
    },
    sectionTitle: {
        fontSize: 12,
        fontWeight: '800',
        color: '#6366f1',
        letterSpacing: 1.5,
        marginBottom: 20,
        marginTop: 10,
        textTransform: 'uppercase',
        opacity: 0.8,
    },
    inputGroup: {
        marginBottom: 24,
    },
    label: {
        fontSize: 13,
        fontWeight: '700',
        color: '#334155',
        marginBottom: 8,
        marginLeft: 4,
    },
    inputWrapper: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: '#f8fafc',
        borderWidth: 1.5,
        borderColor: '#e2e8f0',
        borderRadius: 16,
        paddingHorizontal: 16,
        height: 56,
    },
    inputIcon: {
        marginRight: 12,
    },
    input: {
        flex: 1,
        height: 56,
        fontSize: 16,
        color: '#1e293b',
        fontWeight: '500',
    },
    submitBtn: {
        backgroundColor: '#6366f1',
        flexDirection: 'row',
        justifyContent: 'center',
        alignItems: 'center',
        paddingVertical: 18,
        borderRadius: 18,
        marginTop: 12,
        shadowColor: '#6366f1',
        shadowOffset: { width: 0, height: 10 },
        shadowOpacity: 0.3,
        shadowRadius: 15,
        elevation: 8,
    },
    disabledBtn: {
        backgroundColor: '#cbd5e1',
        shadowOpacity: 0,
        elevation: 0,
    },
    submitBtnText: {
        color: '#fff',
        fontSize: 17,
        fontWeight: '800',
        letterSpacing: 0.5,
    },
    // Modal Styles
    modalOverlay: {
        flex: 1,
        backgroundColor: 'rgba(0,0,0,0.7)',
        justifyContent: 'center',
        alignItems: 'center',
        padding: 24,
    },
    modalContent: {
        backgroundColor: '#fff',
        borderRadius: 20,
        width: '100%',
        overflow: 'hidden',
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 20 },
        shadowOpacity: 0.4,
        shadowRadius: 40,
        elevation: 20,
    },
    modalHeader: {
        backgroundColor: '#15803d',
        paddingVertical: 32,
        alignItems: 'center',
    },
    successIconWrapper: {
        width: 60,
        height: 60,
        borderRadius: 30,
        backgroundColor: '#fff',
        justifyContent: 'center',
        alignItems: 'center',
        marginBottom: 16,
    },
    successTitle: {
        fontSize: 24,
        fontWeight: '800',
        color: '#fff',
        textAlign: 'center',
    },
    modalBody: {
        padding: 24,
        alignItems: 'center',
    },
    successSubtitle: {
        fontSize: 15,
        color: '#64748b',
        textAlign: 'center',
        marginBottom: 24,
        lineHeight: 22,
        fontWeight: '500',
        paddingHorizontal: 20,
    },
    qrCard: {
        backgroundColor: '#fff',
        padding: 20,
        borderRadius: 16,
        borderWidth: 1,
        borderColor: '#e2e8f0',
        alignItems: 'center',
        width: '80%',
        marginBottom: 32,
        elevation: 2,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 4 },
        shadowOpacity: 0.1,
        shadowRadius: 8,
    },
    qrImage: {
        width: 150,
        height: 150,
        marginBottom: 16,
    },
    visitCode: {
        fontSize: 28,
        fontWeight: '900',
        color: '#2563eb',
        letterSpacing: 1,
    },
    visitCodeLabel: {
        fontSize: 11,
        fontWeight: '800',
        color: '#64748b',
        marginTop: 4,
        textTransform: 'uppercase',
        letterSpacing: 1,
    },
    scheduledTag: {
        backgroundColor: '#dbeafe',
        paddingHorizontal: 12,
        paddingVertical: 6,
        borderRadius: 8,
        marginTop: 12,
        borderWidth: 1,
        borderColor: '#bfdbfe',
    },
    scheduledText: {
        color: '#2563eb',
        fontSize: 12,
        fontWeight: '700',
    },
    modalActions: {
        width: '100%',
        gap: 12,
    },
    whatsappBtn: {
        backgroundColor: '#15803d',
        flexDirection: 'row',
        justifyContent: 'center',
        alignItems: 'center',
        paddingVertical: 14,
        borderRadius: 12,
    },
    whatsappBtnText: {
        color: '#fff',
        fontSize: 15,
        fontWeight: '700',
    },
    printBtn: {
        backgroundColor: '#fff',
        flexDirection: 'row',
        justifyContent: 'center',
        alignItems: 'center',
        paddingVertical: 14,
        borderRadius: 12,
        borderWidth: 1,
        borderColor: '#3b82f6',
    },
    printBtnText: {
        color: '#3b82f6',
        fontSize: 15,
        fontWeight: '700',
    },
    anotherBtn: {
        backgroundColor: '#f1f5f9',
        flexDirection: 'row',
        justifyContent: 'center',
        alignItems: 'center',
        paddingVertical: 14,
        borderRadius: 12,
    },
    anotherBtnText: {
        color: '#475569',
        fontSize: 15,
        fontWeight: '700',
    },
    dashboardLink: {
        paddingVertical: 8,
        alignItems: 'center',
        marginTop: 8,
    },
    dashboardLinkText: {
        color: '#64748b',
        fontSize: 14,
        fontWeight: '600',
        textDecorationLine: 'underline',
    },
});
