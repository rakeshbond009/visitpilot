import React, { useState, useEffect } from 'react';
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
    Image,
    Modal,
    Switch,
    Dimensions,
    Linking,
    Keyboard
} from 'react-native';
import { MaterialCommunityIcons as Icon } from '@expo/vector-icons';
import { CameraView, useCameraPermissions } from 'expo-camera';
import CustomPicker from '../components/CustomPicker';
import AsyncStorage from '@react-native-async-storage/async-storage';
import apiClient from '../utils/apiClient';
import { CONFIG } from '../utils/config';

const BASE_URL = CONFIG.API_BASE_URL;
const { width, height } = Dimensions.get('window');

export default function RegisterVisitor({ navigation, route }) {
    const [name, setName] = useState('');
    const [mobile, setMobile] = useState('');
    const [email, setEmail] = useState('');
    const [purpose, setPurpose] = useState('');
    const [hostId, setHostId] = useState('');
    const [idType, setIdType] = useState('Aadhar');
    const [idNumber, setIdNumber] = useState('');
    const [assets, setAssets] = useState('');
    const [accessArea, setAccessArea] = useState('Reception');
    const [members, setMembers] = useState(['']);

    // New states for alignment with web
    const [lookupValue, setLookupValue] = useState('');
    const [idProofEnabled, setIdProofEnabled] = useState(false);
    const [otpEnabled, setOtpEnabled] = useState(false);
    const [showOtpModal, setShowOtpModal] = useState(false);
    const [otpValue, setOtpValue] = useState('');
    const [currentOtp, setCurrentOtp] = useState(null);
    const [isOtpVerified, setIsOtpVerified] = useState(false);
    const [sendingOtp, setSendingOtp] = useState(false);
    const [photo, setPhoto] = useState(null);
    const [isPreApproved, setIsPreApproved] = useState(false);
    const [address, setAddress] = useState('');
    const [invitationId, setInvitationId] = useState(null);

    const [hosts, setHosts] = useState([]);
    const [purposes, setPurposes] = useState([]);
    const [areas, setAreas] = useState([]);
    const [loading, setLoading] = useState(false);
    const [searching, setSearching] = useState(false);
    const [user, setUser] = useState(null);
    const [mandatoryFields, setMandatoryFields] = useState(["visitor_name", "mobile_number", "id_proof", "purpose", "meeting_host", "otp_check"]);
    const [showSuccess, setShowSuccess] = useState(false);
    const [registerResult, setRegisterResult] = useState(null);
    const [scanned, setScanned] = useState(false);
    const [scanModalVisible, setScanModalVisible] = useState(false);

    // Custom Alert State
    const [alertVisible, setAlertVisible] = useState(false);
    const [alertConfig, setAlertConfig] = useState({ title: '', message: '', type: 'success' });

    // Camera states
    const [permission, requestPermission] = useCameraPermissions();
    const [showCamera, setShowCamera] = useState(false);
    const [cameraRef, setCameraRef] = useState(null);
    const [cameraFacing, setCameraFacing] = useState('front');

    useEffect(() => {
        fetchMetadata();
    }, []);

    useEffect(() => {
        if (otpValue.length === 6 && showOtpModal) {
            verifyOTP();
        }
    }, [otpValue]);

    useEffect(() => {
        if (route.params?.code) {
            setLookupValue(route.params.code);
            // We pass true to show the confirmation alert even when redirected from scan
            // as requested by the user.
            setTimeout(() => {
                lookupInvitation(route.params.code, true);
            }, 500);
        }
    }, [route.params?.code]);

    const handleCapturePress = async () => {
        if (!permission) {
            // Camera permissions are still loading.
            return;
        }

        if (!permission.granted) {
            const result = await requestPermission();
            if (!result.granted) {
                showAlert('Permission Required', 'Camera access is needed to capture visitor photo', 'error');
                return;
            }
        }

        setShowCamera(true);
    };

    const takePicture = async () => {
        if (cameraRef) {
            try {
                const photoData = await cameraRef.takePictureAsync({
                    quality: 0.5,
                    base64: true,
                    skipProcessing: true
                });
                setPhoto(`data:image/jpeg;base64,${photoData.base64}`);
                setShowCamera(false);
            } catch (error) {
                console.error('Take Picture Error:', error);
                showAlert('Error', 'Failed to capture photo', 'error');
            }
        }
    };

    const validateForm = () => {
        const cleanMobile = mobile ? mobile.trim() : '';
        let errors = [];
        if (isMandatory('visitor_name') && !name) errors.push('Visitor Name');
        if (isMandatory('mobile_number') && (!cleanMobile || cleanMobile.length < 10)) errors.push('Mobile Number');
        if (isMandatory('email') && !email) errors.push('Email Address');
        if (isMandatory('company_address') && !address) errors.push('Company / Address');
        if (isMandatory('id_proof') && (!idProofEnabled || !idNumber)) errors.push('ID Proof Type & Number');
        if (isMandatory('purpose') && !purpose) errors.push('Purpose of Visit');
        if (isMandatory('meeting_host') && !hostId) errors.push('Host (Who to Meet)');
        if (isMandatory('access_area') && !accessArea) errors.push('Access Area');
        if (isMandatory('assets_carried') && !assets) errors.push('Assets Carried');
        if (isMandatory('photo') && !photo) errors.push('Visitor Photo');

        if (isMandatory('members')) {
            const hasMember = members.some(m => m.trim() !== '');
            if (!hasMember) errors.push('Accompanying Visitor List');
        }
        return errors;
    };

    const sendOTP = async () => {
        // Validation FIRST
        const formErrors = validateForm();
        if (formErrors.length > 0) {
            showAlert('Complete Form First', `Please fill these mandatory fields before sending OTP:\n\n• ${formErrors.join('\n• ')}`, 'warning');
            return;
        }

        const cleanMobile = mobile.trim();
        setSendingOtp(true);
        try {
            const response = await apiClient.get('api/visit/send_otp.php', {
                params: { mobile: cleanMobile }
            });
            if (response.data.status === 'success') {
                setShowOtpModal(true);
                if (response.data.data?.debug_otp) {
                    console.log("DEBUG OTP:", response.data.data.debug_otp);
                    const otpStr = response.data.data.debug_otp.toString();
                    setCurrentOtp(otpStr);

                }
            } else {
                showAlert('Error', response.data.message || 'Failed to send OTP', 'error');
            }
        } catch (error) {
            console.error('Send OTP Error:', error);
            showAlert('Error', 'Failed to connect to server', 'error');
        } finally {
            setSendingOtp(false);
        }
    };

    const showAlert = (title, message, type = 'success', options = {}) => {
        setAlertConfig({ title, message, type, ...options });
        setAlertVisible(true);
    };

    const verifyOTP = async () => {
        const cleanMobile = mobile.trim();
        if (otpValue.length < 6) {
            showAlert('Error', 'Please enter 6-digit OTP', 'error');
            return;
        }

        Keyboard.dismiss();
        setLoading(true);
        try {
            const response = await apiClient.get('api/visit/verify_otp.php', {
                params: {
                    mobile: cleanMobile,
                    otp: otpValue
                }
            });
            if (response.data.status === 'success') {
                setIsOtpVerified(true);
                setShowOtpModal(false);
                showAlert('Success', 'Mobile number verified successfully!');
                // Wait briefly for states to potentially settle then proceed
                setTimeout(() => {
                    handleRegister(true);
                }, 500);
            } else {
                showAlert('Error', response.data.message || 'Invalid OTP', 'error');
            }
        } catch (error) {
            console.error('Verify OTP Error:', error);
            showAlert('Error', 'Failed to verify OTP', 'error');
        } finally {
            setLoading(false);
        }
    };

    const lookupInvitation = async (passedCode = null, showSuccessAlert = true) => {
        const queryValue = passedCode || lookupValue;
        if (!queryValue) return;

        setSearching(true);
        try {
            const response = await apiClient.get('api/visit/check_invitation.php', {
                params: { query: queryValue.trim() }
            });
            if (response.data.status === 'success' && response.data.data) {
                const inv = response.data.data;
                setName(inv.visitor_name || '');
                setMobile(inv.mobile || '');
                setEmail(inv.email || '');
                setAddress(inv.address || '');
                setHostId(inv.host_id?.toString() || '');
                setPurpose(inv.purpose || '');
                setInvitationId(inv.id || null);
                setAssets(inv.assets_carried || ''); // Restore the assets pre-fill fix
                setIsPreApproved(true);
                setPhoto(null); // Force new photo for every visit

                if (showSuccessAlert) {
                    const details = `Visitor: ${inv.visitor_name}\nHost: ${inv.host_name || 'N/A'}\nPurpose: ${inv.purpose || 'N/A'}\nAssets: ${inv.assets_carried || 'None'}`;
                    showAlert('Success', `Invitation found and details pre-filled!\n\n${details}`, 'success');
                }
            } else {
                showAlert('Not Found', response.data.message || 'No active invitation found.', 'error');
            }
        } catch (error) {
            console.error('Lookup Error:', error);
            showAlert('Error', 'Failed to search invitation', 'error');
        } finally {
            setSearching(false);
        }
    };

    const fetchMetadata = async () => {
        setLoading(true);
        try {
            // Get user data
            const userData = await AsyncStorage.getItem('userData');
            if (userData) {
                const parsedUser = JSON.parse(userData);
                setUser(parsedUser);
                setMandatoryFields(parsedUser.mandatory_fields || ["visitor_name", "mobile_number", "id_proof", "purpose", "meeting_host"]);

                if (parsedUser.mandatory_fields?.includes('id_proof')) {
                    setIdProofEnabled(true);
                }

                if (parsedUser.mandatory_fields?.includes('otp_check')) {
                    setOtpEnabled(true);
                }

                if (parsedUser.employee_id) {
                    setHostId(parsedUser.employee_id?.toString() || '');
                }
            }

            // Fetch metadata from API
            const metaResponse = await apiClient.get('api/metadata/list.php');
            if (metaResponse.data.status === 'success' && metaResponse.data.data) {
                const meta = metaResponse.data.data;
                setPurposes(meta.purposes || []);
                setAreas(meta.areas || []);

                if (meta.mandatory_fields) {
                    setMandatoryFields(meta.mandatory_fields);
                    if (meta.mandatory_fields.includes('id_proof')) setIdProofEnabled(true);
                    if (meta.mandatory_fields.includes('otp_check')) setOtpEnabled(true);
                }

                if (meta.purposes?.length > 0) setPurpose(meta.purposes[0].purpose_name);
                if (meta.areas?.length > 0) setAccessArea(meta.areas[0].area_name);
            }

            // Fetch employees for host selection
            const response = await apiClient.get('api/employee/list.php');
            if (response.data.status === 'success' && response.data.data) {
                const empList = response.data.data.employees || [];
                setHosts(empList);
                if (empList.length > 0 && !hostId) {
                    setHostId(empList[0].id?.toString() || '');
                }
            }
        } catch (error) {
            console.error('Fetch Metadata Error:', error);
        } finally {
            setLoading(false);
        }
    };

    const addMember = () => {
        setMembers([...members, '']);
    };

    const removeMember = (index) => {
        const newMembers = [...members];
        newMembers.splice(index, 1);
        setMembers(newMembers);
    };

    const updateMember = (text, index) => {
        const newMembers = [...members];
        newMembers[index] = text;
        setMembers(newMembers);
    };

    const searchVisitor = async () => {
        const cleanMobile = mobile.trim();
        if (cleanMobile.length < 10) return;

        setSearching(true);
        try {
            const response = await apiClient.get('api/visitor/search.php', {
                params: { mobile: cleanMobile }
            });
            if (response.data.status === 'success' && response.data.data) {
                const v = response.data.data;
                setName(v.name || '');
                setEmail(v.email || '');
                setAddress(v.address || '');
                setIdType(v.id_proof_type || 'Aadhar');
                setIdNumber(v.id_proof_number || '');
                setPhoto(null); // Force new photo for every visit

                // Auto-fill last host and purpose if available
                if (v.last_visit) {
                    if (v.last_visit.employee_id) setHostId(v.last_visit.employee_id.toString());
                    if (v.last_visit.purpose) setPurpose(v.last_visit.purpose);
                }
            }
        } catch (error) {
            console.log('Visitor not found or error');
        } finally {
            setSearching(false);
        }
    };

    const handleBarCodeScanned = async ({ type, data }) => {
        if (scanned) return;
        setScanned(true);
        setScanModalVisible(false);
        setLookupValue(data);
        lookupInvitation(data);
    };




    const onOtpToggleChange = (value) => {
        setOtpEnabled(value);
        if (value) {
            if (!mobile || mobile.length < 10) {
                showAlert('Caution', 'Please enter a valid 10-digit mobile number first.', 'warning');
                setOtpEnabled(false);
                return;
            }

            showAlert(
                'Ready for Verification?',
                'Have you filled all details and clicked the photo?',
                'warning',
                {
                    showCancel: true,
                    confirmText: 'Yes, All filled!',
                    onConfirm: () => {
                        sendOTP();
                    }
                }
            );
        } else {
            setIsOtpVerified(false);
        }
    };

    const isMandatory = (key) => mandatoryFields.includes(key);

    const handleRegister = async (otpForcePassed = false) => {
        const cleanMobile = mobile.trim();

        const errors = validateForm();

        if (errors.length > 0) {
            showAlert('Missing Information', `The following fields are mandatory:\n\n• ${errors.join('\n• ')}`, 'error');
            return;
        }

        if (otpEnabled && !isOtpVerified && !otpForcePassed) {
            sendOTP();
            return;
        }

        if (!hostId) {
            showAlert('Error', 'Host employee identification missing. Please contact administrator.', 'error');
            return;
        }

        setLoading(true);
        try {
            const response = await apiClient.post('api/visitor/register.php', {
                name,
                mobile: cleanMobile,
                email,
                address,
                purpose,
                employee_id: hostId,
                id_proof_type: idProofEnabled ? idType : '',
                id_proof_number: idProofEnabled ? idNumber : '',
                assets_carried: assets,
                access_area: accessArea,
                members: members.filter(m => m.trim() !== ''),
                require_otp: otpEnabled,
                invitation_id: invitationId,
                photo_data: photo, // Send the base64 photo
                user_id: user?.id
            });

            const result = response.data;
            if (result.status === 'success') {
                const newVisitCode = result.data.visit_code;
                setRegisterResult({
                    visitor_name: name,
                    visit_code: newVisitCode,
                    qr_code_url: result.data.qr_code_url,
                    status: result.data.status || 'pending',
                    approval_status: result.data.approval_status || 'pending',
                    visit_date: new Date().toISOString()
                });
                setShowSuccess(true);

                /* 
                // Automatically send WhatsApp to Host - DISABLED per requirement
                const selectedHost = (hosts || []).find(h => h?.id?.toString() === hostId);
                console.log('Selected Host for WhatsApp:', selectedHost);

                if (selectedHost && selectedHost.mobile) {
                    // Send message immediately
                    setTimeout(() => {
                        sendWhatsAppMessage(selectedHost.mobile, selectedHost.name, name, newVisitCode);
                    }, 500); // Slight delay to ensure UI settles
                } else {
                    console.log('Host mobile not found for ID:', hostId);
                }
                */
            } else {
                showAlert('Error', result.message || 'Registration failed', 'error');
            }
        } catch (error) {
            console.error('Registration Error:', error);
            showAlert('Error', 'Failed to connect to server', 'error');
        } finally {
            setLoading(false);
        }
    };

    const renderSweetAlert = () => (
        <Modal
            animationType="fade"
            transparent={true}
            visible={alertVisible}
            onRequestClose={() => setAlertVisible(false)}
        >
            <View style={alertStyles.alertOverlay}>
                <View style={alertStyles.alertContent}>
                    <View style={[alertStyles.alertHeader, { backgroundColor: alertConfig.type === 'success' ? '#15803d' : (alertConfig.type === 'warning' ? '#f59e0b' : '#ef4444') }]}>
                        <Icon
                            name={alertConfig.type === 'success' ? 'check-circle' : (alertConfig.type === 'warning' ? 'help-circle' : 'alert-circle')}
                            size={32}
                            color="#fff"
                            style={{ marginRight: 10 }}
                        />
                        <Text style={alertStyles.alertHeaderTitle}>{alertConfig.title || (alertConfig.type === 'success' ? 'Success!' : (alertConfig.type === 'warning' ? 'Confirm' : 'Error'))}</Text>
                    </View>
                    <View style={alertStyles.alertBody}>
                        <Text style={alertStyles.alertMessage}>{alertConfig.message}</Text>

                        {alertConfig.showCancel ? (
                            <View style={alertStyles.alertActionRow}>
                                <TouchableOpacity
                                    style={[alertStyles.alertButton, alertStyles.alertCancelButton]}
                                    onPress={() => setAlertVisible(false)}
                                >
                                    <Text style={alertStyles.alertCancelButtonText}>Cancel</Text>
                                </TouchableOpacity>
                                <TouchableOpacity
                                    style={[alertStyles.alertButton, alertStyles.alertConfirmButton, { backgroundColor: alertConfig.type === 'warning' ? '#f59e0b' : '#15803d' }]}
                                    onPress={() => {
                                        setAlertVisible(false);
                                        if (alertConfig.onConfirm) alertConfig.onConfirm();
                                    }}
                                >
                                    <Text style={alertStyles.alertConfirmButtonText}>{alertConfig.confirmText || 'Yes'}</Text>
                                </TouchableOpacity>
                            </View>
                        ) : (
                            <TouchableOpacity
                                style={[alertStyles.alertButton, { borderColor: alertConfig.type === 'success' ? '#15803d' : '#ef4444', width: '100%', alignItems: 'center' }]}
                                onPress={() => setAlertVisible(false)}
                            >
                                <Text style={[alertStyles.alertButtonText, { color: alertConfig.type === 'success' ? '#15803d' : '#ef4444' }]}>OK</Text>
                            </TouchableOpacity>
                        )}
                    </View>
                </View>
            </View>
        </Modal>
    );

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
                    <Text style={styles.headerTitle}>Visitor Registration</Text>
                    <View style={{ width: 40 }} />
                </View>

                <ScrollView
                    contentContainerStyle={styles.scrollContent}
                    showsVerticalScrollIndicator={false}
                >
                    {/* Pre-approved lookup section */}
                    <View style={styles.lookupCard}>
                        <View style={styles.lookupHeader}>
                            <Icon name="qrcode-scan" size={20} color="#fff" />
                            <Text style={styles.lookupTitle}>PRE-APPROVED? LOOK UP INVITATION</Text>
                        </View>
                        <View style={styles.lookupBody}>
                            <View style={styles.lookupInputWrapper}>
                                <TextInput
                                    style={styles.lookupInput}
                                    placeholder="Enter Mobile # or Visit Code"
                                    placeholderTextColor="rgba(255,255,255,0.7)"
                                    value={lookupValue}
                                    onChangeText={setLookupValue}
                                />
                                <TouchableOpacity
                                    style={{ padding: 10 }}
                                    onPress={() => {
                                        setScanned(false);
                                        setScanModalVisible(true);
                                    }}
                                >
                                    <Icon name="qrcode-scan" size={24} color="#fff" />
                                </TouchableOpacity>
                                <TouchableOpacity
                                    style={styles.lookupBtn}
                                    onPress={() => lookupInvitation()}
                                    disabled={searching}
                                >
                                    {searching ? (
                                        <ActivityIndicator size="small" color="#fff" />
                                    ) : (
                                        <>
                                            <Icon name="magnify" size={20} color="#fff" />
                                            <Text style={styles.lookupBtnText}>FIND INVITE</Text>
                                        </>
                                    )}
                                </TouchableOpacity>
                            </View>
                        </View>
                    </View>

                    <View style={styles.formRow}>
                        {/* Left Column: Form Details */}
                        <View style={styles.formColumnLeft}>
                            {/* Visitor Information Section */}
                            <View style={styles.sectionCard}>
                                <View style={styles.sectionHeader}>
                                    <Icon name="badge-account-horizontal" size={20} color="#3b82f6" />
                                    <Text style={styles.sectionHeaderText}>VISITOR INFORMATION</Text>
                                </View>

                                <View style={styles.inputGroup}>
                                    <TextInput
                                        style={styles.modernInput}
                                        placeholder={`FULL NAME${isMandatory('visitor_name') ? ' *' : ''}`}
                                        placeholderTextColor="#94a3b8"
                                        value={name}
                                        onChangeText={setName}
                                    />
                                </View>

                                <View style={styles.row}>
                                    <View style={[styles.inputGroup, { flex: 1, marginRight: 8 }]}>
                                        <TextInput
                                            style={styles.modernInput}
                                            placeholder={`MOBILE NUMBER${isMandatory('mobile_number') ? ' *' : ''}`}
                                            placeholderTextColor="#94a3b8"
                                            value={mobile}
                                            onChangeText={setMobile}
                                            keyboardType="phone-pad"
                                            maxLength={10}
                                            onBlur={searchVisitor}
                                        />
                                    </View>
                                    <View style={[styles.inputGroup, { flex: 1 }]}>
                                        <TextInput
                                            style={styles.modernInput}
                                            placeholder={`EMAIL ADDRESS${isMandatory('email') ? ' *' : ' (OPTIONAL)'}`}
                                            placeholderTextColor="#94a3b8"
                                            value={email}
                                            onChangeText={setEmail}
                                            keyboardType="email-address"
                                        />
                                    </View>
                                </View>

                                <View style={styles.inputGroup}>
                                    <TextInput
                                        style={styles.modernInput}
                                        placeholder={`COMPANY / ADDRESS${isMandatory('company_address') ? ' *' : ''}`}
                                        placeholderTextColor="#94a3b8"
                                        value={address}
                                        onChangeText={setAddress}
                                    />
                                </View>

                                <View style={styles.toggleRow}>
                                    <Switch
                                        value={idProofEnabled}
                                        onValueChange={setIdProofEnabled}
                                        trackColor={{ false: '#e2e8f0', true: '#93c5fd' }}
                                        thumbColor={idProofEnabled ? '#3b82f6' : '#f4f3f4'}
                                    />
                                    <Text style={styles.toggleLabel}>Capture ID Proof {isMandatory('id_proof') ? ' *' : '(Optional)'}</Text>
                                </View>

                                {idProofEnabled && (
                                    <View style={styles.row}>
                                        <View style={[styles.inputGroup, { flex: 1, marginRight: 8 }]}>
                                            <CustomPicker
                                                label="ID TYPE"
                                                selectedValue={idType}
                                                onValueChange={setIdType}
                                                options={[
                                                    { label: 'Aadhaar', value: 'Aadhar' },
                                                    { label: 'PAN Card', value: 'PAN' },
                                                    { label: 'Driving License', value: 'DL' },
                                                    { label: 'Voter ID', value: 'Voter' },
                                                    { label: 'Other', value: 'Other' }
                                                ]}
                                            />
                                        </View>
                                        <View style={[styles.inputGroup, { flex: 1 }]}>
                                            <TextInput
                                                style={styles.modernInput}
                                                placeholder="ID NUMBER"
                                                placeholderTextColor="#94a3b8"
                                                value={idNumber}
                                                onChangeText={setIdNumber}
                                            />
                                        </View>
                                    </View>
                                )}
                            </View>

                            {/* Accompanying Visitors Section */}
                            <View style={styles.sectionCard}>
                                <View style={styles.sectionHeader}>
                                    <Icon name="account-group" size={20} color="#3b82f6" />
                                    <Text style={styles.sectionHeaderText}>ACCOMPANYING VISITORS {isMandatory('members') ? '*' : '(OPTIONAL)'}</Text>
                                </View>

                                {members.map((member, index) => (
                                    <View key={index} style={styles.memberInputRow}>
                                        <TextInput
                                            style={[styles.modernInput, { flex: 1 }]}
                                            placeholder={`Member ${index + 1} Name`}
                                            placeholderTextColor="#94a3b8"
                                            value={member}
                                            onChangeText={(text) => updateMember(text, index)}
                                        />
                                        {members.length > 1 && (
                                            <TouchableOpacity onPress={() => removeMember(index)} style={styles.removeMemberBtn}>
                                                <Icon name="close-circle" size={24} color="#ef4444" />
                                            </TouchableOpacity>
                                        )}
                                    </View>
                                ))}

                                <TouchableOpacity style={styles.addMemberBtnModern} onPress={addMember}>
                                    <Icon name="account-plus" size={18} color="#3b82f6" />
                                    <Text style={styles.addMemberTextModern}>Add Additional Member</Text>
                                </TouchableOpacity>
                            </View>

                            {/* Visit Details Section */}
                            <View style={styles.sectionCard}>
                                <View style={styles.sectionHeader}>
                                    <Icon name="office-building" size={20} color="#3b82f6" />
                                    <Text style={styles.sectionHeaderText}>VISIT DETAILS</Text>
                                </View>

                                <View style={styles.row}>
                                    <View style={[styles.inputGroup, { flex: 1, marginRight: 8 }]}>
                                        <Text style={styles.inputLabel}>WHO TO MEET?{isMandatory('meeting_host') ? ' *' : ''}</Text>
                                        <CustomPicker
                                            selectedValue={hostId}
                                            onValueChange={setHostId}
                                            options={(hosts || []).map((h, idx) => ({
                                                label: `${h?.name || 'Unknown'} (${h?.department || 'N/A'})`,
                                                value: h?.id?.toString() || `unknown-${idx}`
                                            }))}
                                        />
                                    </View>
                                    <View style={[styles.inputGroup, { flex: 1 }]}>
                                        <Text style={styles.inputLabel}>PURPOSE{isMandatory('purpose') ? ' *' : ''}</Text>
                                        <CustomPicker
                                            selectedValue={purpose}
                                            onValueChange={setPurpose}
                                            options={(purposes || []).map((p, idx) => ({
                                                label: p?.purpose_name || 'General',
                                                value: p?.purpose_name || `purpose-${idx}`
                                            }))}
                                        />
                                    </View>
                                </View>

                                <View style={styles.inputGroup}>
                                    <Text style={styles.inputLabel}>DESIGNATED ACCESS AREA {isMandatory('access_area') ? '*' : '(OPTIONAL)'}</Text>
                                    <CustomPicker
                                        selectedValue={accessArea}
                                        onValueChange={setAccessArea}
                                        options={(areas || []).map((a, idx) => ({
                                            label: a?.area_name || 'Reception',
                                            value: a?.area_name || `area-${idx}`
                                        }))}
                                    />
                                </View>

                                <View style={styles.inputGroup}>
                                    <TextInput
                                        style={[styles.modernInput, { height: 80, textAlignVertical: 'top', paddingTop: 12 }]}
                                        placeholder={`ASSETS CARRIED${isMandatory('assets_carried') ? ' *' : ' (OPTIONAL)'}`}
                                        placeholderTextColor="#94a3b8"
                                        value={assets}
                                        onChangeText={setAssets}
                                        multiline={true}
                                    />
                                </View>
                            </View>
                        </View>

                        {/* Right Column: Live Photo & OTP */}
                        <View style={styles.formColumnRight}>
                            <View style={styles.sectionCard}>
                                <View style={styles.sectionHeader}>
                                    <Icon name="video" size={20} color="#3b82f6" />
                                    <Text style={styles.sectionHeaderText}>LIVE PHOTO{isMandatory('photo') ? ' *' : ''}</Text>
                                </View>

                                <TouchableOpacity style={styles.cameraBox} activeOpacity={0.7}>
                                    {photo ? (
                                        <Image source={{ uri: photo }} style={styles.capturedImage} />
                                    ) : (
                                        <Icon name="account-circle" size={100} color="#e2e8f0" />
                                    )}
                                </TouchableOpacity>

                                <TouchableOpacity
                                    style={styles.captureBtn}
                                    onPress={handleCapturePress}
                                >
                                    <Icon name="camera" size={20} color="#fff" />
                                    <Text style={styles.captureBtnText}>CAPTURE PHOTO</Text>
                                </TouchableOpacity>
                            </View>

                            <View style={styles.otpCard}>
                                <View style={styles.toggleRow}>
                                    <Switch
                                        value={otpEnabled}
                                        onValueChange={(val) => {
                                            onOtpToggleChange(val);
                                            if (val && mobile.length === 10) sendOTP();
                                        }}
                                        disabled={isMandatory('otp_check')}
                                        trackColor={{ false: '#e2e8f0', true: '#93c5fd' }}
                                        thumbColor={otpEnabled ? '#3b82f6' : '#f4f3f4'}
                                    />
                                    <Text style={[styles.toggleLabel, { fontWeight: '800', fontSize: 12 }]}>ENABLE OTP CHECK{isMandatory('otp_check') ? ' *' : ''}</Text>
                                </View>

                                {otpEnabled && !isOtpVerified && mobile.length === 10 && (
                                    <TouchableOpacity
                                        style={[styles.sendOtpBtnInline, { marginTop: 12 }]}
                                        onPress={sendOTP}
                                    >
                                        <Text style={styles.sendOtpBtnTextInline}>SEND OTP FOR VERIFICATION</Text>
                                    </TouchableOpacity>
                                )}

                                {isOtpVerified && (
                                    <View style={[styles.verifiedBadgeInline, { marginTop: 12 }]}>
                                        <Icon name="check-decagram" size={16} color="#059669" />
                                        <Text style={styles.verifiedTextInline}>MOBILE VERIFIED</Text>
                                    </View>
                                )}
                            </View>
                        </View>
                    </View>

                    <TouchableOpacity
                        style={[styles.mainSubmitBtn, loading && styles.disabledBtn]}
                        onPress={() => handleRegister()}
                        disabled={loading}
                    >
                        {loading ? (
                            <ActivityIndicator color="#fff" />
                        ) : (
                            <>
                                <Text style={styles.mainSubmitBtnText}>COMPLETE REGISTRATION & CHECK-IN</Text>
                                <Icon name="arrow-right-circle" size={24} color="#fff" style={{ marginLeft: 12 }} />
                            </>
                        )}
                    </TouchableOpacity>

                    <Modal
                        animationType="slide"
                        transparent={true}
                        visible={scanModalVisible}
                        onRequestClose={() => setScanModalVisible(false)}
                    >
                        <View style={{ flex: 1, backgroundColor: 'rgba(0,0,0,0.5)', justifyContent: 'flex-end' }}>
                            <View style={{ backgroundColor: '#fff', borderTopLeftRadius: 24, borderTopRightRadius: 24, padding: 24, height: '80%' }}>
                                <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 }}>
                                    <Text style={{ fontSize: 20, fontWeight: 'bold' }}>Scan Invitation</Text>
                                    <TouchableOpacity onPress={() => setScanModalVisible(false)}>
                                        <Icon name="close" size={24} color="#000" />
                                    </TouchableOpacity>
                                </View>
                                <View style={{ flex: 1, backgroundColor: '#000', borderRadius: 12, overflow: 'hidden' }}>
                                    <CameraView
                                        style={StyleSheet.absoluteFill}
                                        onBarcodeScanned={scanned ? undefined : handleBarCodeScanned}
                                        barcodeScannerSettings={{ barcodeTypes: ["qr"] }}
                                    >
                                        <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center' }}>
                                            <View style={{ width: 250, height: 250, borderWidth: 2, borderColor: '#00ff00', backgroundColor: 'transparent' }} />
                                        </View>
                                    </CameraView>
                                </View>
                            </View>
                        </View>
                    </Modal>

                    {renderSweetAlert()}
                    <View style={{ height: 40 }} />
                </ScrollView>
            </KeyboardAvoidingView>

            {renderSweetAlert()}
            {/* Camera Modal */}
            <Modal
                visible={showCamera}
                transparent={false}
                animationType="slide"
            >
                <View style={styles.cameraContainer}>
                    <CameraView
                        style={styles.camera}
                        ref={(ref) => setCameraRef(ref)}
                        facing={cameraFacing}
                    >
                        <View style={styles.cameraOverlay}>
                            <View style={styles.cameraTopControls}>
                                <TouchableOpacity
                                    style={styles.closeCameraBtn}
                                    onPress={() => setShowCamera(false)}
                                >
                                    <Icon name="close" size={30} color="#fff" />
                                </TouchableOpacity>

                                <TouchableOpacity
                                    style={styles.flipCameraBtn}
                                    onPress={() => setCameraFacing(prev => prev === 'front' ? 'back' : 'front')}
                                >
                                    <Icon name="camera-flip" size={30} color="#fff" />
                                </TouchableOpacity>
                            </View>

                            <View style={styles.cameraControls}>
                                <TouchableOpacity
                                    style={styles.takePictureBtn}
                                    onPress={takePicture}
                                >
                                    <View style={styles.takePictureInner} />
                                </TouchableOpacity>
                            </View>
                        </View>
                    </CameraView>
                </View>
            </Modal>

            {/* OTP Verification Modal */}
            <Modal
                visible={showOtpModal}
                transparent={true}
                animationType="slide"
            >
                <View style={styles.modalOverlay}>
                    <View style={styles.otpModalContent}>
                        <View style={styles.otpModalHeader}>
                            <Icon name="shield-check" size={40} color="#3b82f6" />
                            <Text style={styles.otpModalTitle}>Mobile Verification</Text>
                            <Text style={styles.otpModalSubtitle}>Enter the 6-digit OTP sent to {mobile}</Text>
                        </View>

                        <View style={styles.otpInputContainer}>
                            <TextInput
                                style={styles.otpInput}
                                value={otpValue}
                                onChangeText={setOtpValue}
                                keyboardType="number-pad"
                                maxLength={6}
                                placeholder="000000"
                                placeholderTextColor="#94a3b8"
                                autoFocus={true}
                            />
                        </View>

                        <View style={styles.otpModalActions}>
                            <TouchableOpacity
                                style={[styles.verifyBtn, loading && styles.disabledBtn]}
                                onPress={verifyOTP}
                                disabled={loading}
                            >
                                {loading ? (
                                    <ActivityIndicator color="#fff" />
                                ) : (
                                    <Text style={styles.verifyBtnText}>VERIFY & CONTINUE</Text>
                                )}
                            </TouchableOpacity>

                            <TouchableOpacity
                                style={styles.resendBtn}
                                onPress={sendOTP}
                                disabled={sendingOtp}
                            >
                                <Text style={styles.resendBtnText}>
                                    {sendingOtp ? 'Sending...' : 'Resend OTP'}
                                </Text>
                            </TouchableOpacity>


                            <TouchableOpacity
                                style={styles.cancelBtn}
                                onPress={() => setShowOtpModal(false)}
                            >
                                <Text style={styles.cancelBtnText}>Cancel</Text>
                            </TouchableOpacity>
                        </View>
                    </View>
                </View>
            </Modal>

            {/* Success Modal */}
            <Modal
                visible={showSuccess}
                transparent={true}
                animationType="fade"
            >
                <View style={styles.modalOverlay}>
                    <View style={styles.modalContent}>
                        <View style={styles.modalHeaderSuccess}>
                            <View style={styles.successIconWrapper}>
                                <Icon name="check" size={40} color="#15803d" />
                            </View>
                            <Text style={styles.successTitle}>Registration Success!</Text>
                        </View>

                        <View style={styles.modalBody}>
                            <Text style={styles.successSubtitle}>
                                Visit request has been submitted for {registerResult?.visitor_name}.
                            </Text>

                            <View style={styles.qrCard}>
                                {registerResult?.status === 'approved' ? (
                                    <>
                                        {registerResult?.qr_code_url ? (
                                            <Image
                                                source={{ uri: `${BASE_URL}${registerResult.qr_code_url}` }}
                                                style={styles.qrImage}
                                                resizeMode="contain"
                                            />
                                        ) : (
                                            <Icon name="qrcode" size={100} color="#2563eb" style={{ marginBottom: 16 }} />
                                        )}
                                        <Text style={styles.visitCode}>{registerResult?.visit_code}</Text>
                                        <Text style={styles.visitCodeLabel}>VISITOR PASS CODE</Text>
                                        <View style={[styles.scheduledTag, { backgroundColor: registerResult?.approval_status === 'pending' ? '#fff7ed' : '#dcfce7' }]}>
                                            <Text style={[styles.scheduledText, { color: registerResult?.approval_status === 'pending' ? '#c2410c' : '#166534' }]}>
                                                Status: {registerResult?.approval_status === 'pending' ? 'Awaiting Host Acknowledgment' : 'Checked-in / Approved'}
                                            </Text>
                                        </View>
                                    </>
                                ) : (
                                    <>
                                        <View style={styles.pendingIconWrapper}>
                                            <Icon name="timer-sand" size={80} color="#f59e0b" style={{ marginBottom: 16 }} />
                                        </View>
                                        <Text style={styles.visitCode}>{registerResult?.visit_code}</Text>
                                        <Text style={styles.visitCodeLabel}>APPLICATION REFERENCE</Text>
                                        <View style={styles.scheduledTag}>
                                            <Text style={styles.scheduledText}>
                                                Status: Pending Host Approval
                                            </Text>
                                        </View>
                                        <View style={styles.pendingNoticeAlert}>
                                            <Icon name="information" size={16} color="#0369a1" />
                                            <Text style={styles.pendingNoticeText}>
                                                The visitor pass will be issued once the host approves this visit request.
                                            </Text>
                                        </View>
                                    </>
                                )}
                            </View>

                            <View style={styles.modalActions}>


                                <TouchableOpacity
                                    style={styles.anotherBtn}
                                    onPress={() => {
                                        setShowSuccess(false);
                                        setName('');
                                        setMobile('');
                                        setEmail('');
                                        setAddress('');
                                        setAssets('');
                                        setIdNumber('');
                                        setMembers(['']);
                                        setPhoto(null);
                                        setIsPreApproved(false);
                                        setInvitationId(null);
                                        setIsOtpVerified(false);
                                    }}
                                >
                                    <Icon name="plus" size={20} color="#475569" style={{ marginRight: 8 }} />
                                    <Text style={styles.anotherBtnText}>Register Another</Text>
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
        backgroundColor: '#f8fafc',
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
        padding: 16,
        marginTop: -30,
    },
    lookupCard: {
        backgroundColor: '#fff',
        borderRadius: 16,
        borderWidth: 2,
        borderColor: '#3b82f6',
        overflow: 'hidden',
        marginBottom: 24,
        elevation: 4,
        shadowColor: '#3b82f6',
        shadowOffset: { width: 0, height: 4 },
        shadowOpacity: 0.1,
        shadowRadius: 10,
    },
    lookupHeader: {
        backgroundColor: '#3b82f6',
        flexDirection: 'row',
        alignItems: 'center',
        paddingVertical: 12,
        paddingHorizontal: 16,
    },
    lookupTitle: {
        fontSize: 13,
        fontWeight: '800',
        color: '#fff',
        marginLeft: 8,
        letterSpacing: 0.5,
    },
    lookupBody: {
        padding: 16,
    },
    lookupInputWrapper: {
        flexDirection: 'row',
        borderWidth: 1.5,
        borderColor: '#3b82f6',
        borderRadius: 30,
        overflow: 'hidden',
        height: 50,
    },
    lookupInput: {
        flex: 1,
        paddingHorizontal: 20,
        fontSize: 15,
        color: '#1e293b',
    },
    lookupBtn: {
        backgroundColor: '#3b82f6',
        flexDirection: 'row',
        alignItems: 'center',
        paddingHorizontal: 16,
    },
    lookupBtnText: {
        color: '#fff',
        fontWeight: '700',
        fontSize: 13,
        marginLeft: 4,
    },
    formRow: {
        flexDirection: 'column', // In mobile we keep it vertical or handle side-by-side if screen is wide
    },
    sectionCard: {
        backgroundColor: '#fff',
        borderRadius: 16,
        padding: 16,
        marginBottom: 20,
        borderWidth: 1,
        borderColor: '#e2e8f0',
    },
    sectionHeader: {
        flexDirection: 'row',
        alignItems: 'center',
        marginBottom: 20,
        borderLeftWidth: 4,
        borderLeftColor: '#3b82f6',
        paddingLeft: 12,
        marginLeft: -16,
    },
    sectionHeaderText: {
        fontSize: 13,
        fontWeight: '800',
        color: '#3b82f6',
        letterSpacing: 0.5,
    },
    inputGroup: {
        marginBottom: 16,
    },
    modernInput: {
        backgroundColor: '#f8fafc',
        borderWidth: 1,
        borderColor: '#e2e8f0',
        borderRadius: 8,
        paddingHorizontal: 16,
        height: 50,
        fontSize: 14,
        color: '#1e293b',
        fontWeight: '500',
    },
    inputLabel: {
        fontSize: 11,
        fontWeight: '700',
        color: '#64748b',
        marginBottom: 6,
        marginLeft: 4,
    },
    row: {
        flexDirection: 'row',
    },
    toggleRow: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: '#f8fafc',
        padding: 12,
        borderRadius: 8,
        borderWidth: 1,
        borderColor: '#e2e8f0',
        marginBottom: 16,
    },
    toggleLabel: {
        fontSize: 14,
        fontWeight: '600',
        color: '#334155',
        marginLeft: 12,
    },
    memberInputRow: {
        flexDirection: 'row',
        alignItems: 'center',
        marginBottom: 12,
    },
    removeMemberBtn: {
        marginLeft: 8,
    },
    addMemberBtnModern: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'center',
        paddingVertical: 12,
        borderRadius: 8,
        borderWidth: 1.5,
        borderColor: '#3b82f6',
        borderStyle: 'dashed',
        marginTop: 8,
    },
    addMemberTextModern: {
        fontSize: 13,
        fontWeight: '700',
        color: '#3b82f6',
        marginLeft: 8,
    },
    cameraBox: {
        width: '100%',
        aspectRatio: 1,
        backgroundColor: '#f8fafc',
        borderRadius: 12,
        borderWidth: 1,
        borderColor: '#e2e8f0',
        justifyContent: 'center',
        alignItems: 'center',
        overflow: 'hidden',
        marginBottom: 16,
    },
    capturedImage: {
        width: '100%',
        height: '100%',
    },
    captureBtn: {
        backgroundColor: '#4f46e5',
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'center',
        paddingVertical: 14,
        borderRadius: 10,
    },
    captureBtnText: {
        color: '#fff',
        fontWeight: '800',
        fontSize: 14,
        marginLeft: 8,
    },
    otpCard: {
        backgroundColor: '#fff',
        borderRadius: 16,
        padding: 16,
        marginBottom: 24,
        borderWidth: 2,
        borderColor: '#3b82f6',
    },
    mainSubmitBtn: {
        backgroundColor: '#059669',
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'center',
        paddingVertical: 18,
        borderRadius: 40,
        elevation: 8,
        shadowColor: '#059669',
        shadowOffset: { width: 0, height: 10 },
        shadowOpacity: 0.3,
        shadowRadius: 15,
    },
    mainSubmitBtnText: {
        color: '#fff',
        fontSize: 16,
        fontWeight: '900',
        letterSpacing: 0.5,
    },
    disabledBtn: {
        backgroundColor: '#94a3b8',
    },
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
    },
    modalHeaderSuccess: {
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
    },
    qrImage: {
        width: 180,
        height: 180,
        marginBottom: 16,
    },
    visitCode: {
        fontSize: 28,
        fontWeight: '900',
        color: '#2563eb',
    },
    visitCodeLabel: {
        fontSize: 11,
        fontWeight: '800',
        color: '#64748b',
        marginTop: 4,
    },
    scheduledTag: {
        backgroundColor: '#f1f5f9',
        paddingHorizontal: 12,
        paddingVertical: 6,
        borderRadius: 20,
        marginTop: 16,
    },
    scheduledText: {
        fontSize: 12,
        fontWeight: '700',
        color: '#475569',
    },
    modalActions: {
        width: '100%',
    },
    whatsappBtn: {
        backgroundColor: '#25d366',
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'center',
        paddingVertical: 14,
        borderRadius: 12,
        marginBottom: 12,
    },
    whatsappBtnText: {
        color: '#fff',
        fontWeight: '700',
    },
    anotherBtn: {
        backgroundColor: '#f1f5f9',
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'center',
        paddingVertical: 14,
        borderRadius: 12,
        marginBottom: 12,
    },
    anotherBtnText: {
        color: '#475569',
        fontWeight: '700',
    },
    dashboardLink: {
        alignItems: 'center',
        paddingVertical: 12,
    },
    dashboardLinkText: {
        color: '#64748b',
        fontWeight: '600',
        textDecorationLine: 'underline',
    },
    // OTP Modal Styles
    otpModalContent: {
        backgroundColor: '#fff',
        borderRadius: 24,
        width: '100%',
        padding: 24,
        alignItems: 'center',
    },
    otpModalHeader: {
        alignItems: 'center',
        marginBottom: 24,
    },
    otpModalTitle: {
        fontSize: 20,
        fontWeight: '800',
        color: '#1e293b',
        marginTop: 12,
    },
    otpModalSubtitle: {
        fontSize: 14,
        color: '#64748b',
        textAlign: 'center',
        marginTop: 8,
    },
    otpInputContainer: {
        width: '100%',
        marginBottom: 24,
    },
    otpInput: {
        backgroundColor: '#f1f5f9',
        borderRadius: 12,
        height: 60,
        fontSize: 32,
        fontWeight: '700',
        textAlign: 'center',
        letterSpacing: 10,
        color: '#3b82f6',
        borderWidth: 2,
        borderColor: '#e2e8f0',
    },
    otpModalActions: {
        width: '100%',
    },
    verifyBtn: {
        backgroundColor: '#3b82f6',
        paddingVertical: 16,
        borderRadius: 12,
        alignItems: 'center',
        marginBottom: 12,
    },
    verifyBtnText: {
        color: '#fff',
        fontWeight: '800',
        fontSize: 14,
    },
    resendBtn: {
        paddingVertical: 12,
        alignItems: 'center',
    },
    resendBtnText: {
        color: '#3b82f6',
        fontWeight: '700',
        fontSize: 14,
    },
    cancelBtn: {
        paddingVertical: 12,
        alignItems: 'center',
        marginTop: 8,
    },
    cancelBtnText: {
        color: '#94a3b8',
        fontWeight: '600',
        fontSize: 14,
    },
    cameraContainer: {
        flex: 1,
        backgroundColor: '#000',
    },
    camera: {
        flex: 1,
    },
    cameraOverlay: {
        flex: 1,
        backgroundColor: 'transparent',
        justifyContent: 'space-between',
        padding: 20,
    },
    closeCameraBtn: {
        alignSelf: 'flex-end',
        marginTop: Platform.OS === 'ios' ? 40 : 20,
        backgroundColor: 'rgba(0,0,0,0.5)',
        borderRadius: 20,
        padding: 5,
    },
    cameraControls: {
        alignItems: 'center',
        marginBottom: 40,
    },
    takePictureBtn: {
        width: 80,
        height: 80,
        borderRadius: 40,
        borderWidth: 4,
        borderColor: '#fff',
        justifyContent: 'center',
        alignItems: 'center',
    },
    takePictureInner: {
        width: 66,
        height: 66,
        borderRadius: 33,
        backgroundColor: '#fff',
    },
    cameraTopControls: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        padding: 20,
        width: '100%',
    },
    flipCameraBtn: {
        width: 50,
        height: 50,
        borderRadius: 25,
        backgroundColor: 'rgba(0,0,0,0.5)',
        justifyContent: 'center',
        alignItems: 'center',
    },
    pendingIconWrapper: {
        width: 120,
        height: 120,
        borderRadius: 60,
        backgroundColor: '#fffbeb',
        justifyContent: 'center',
        alignItems: 'center',
        marginBottom: 16,
        borderWidth: 1,
        borderColor: '#fde68a',
    },
    pendingNoticeAlert: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: '#f0f9ff',
        padding: 12,
        borderRadius: 10,
        marginTop: 20,
        borderWidth: 1,
        borderColor: '#bae6fd',
    },
    pendingNoticeText: {
        fontSize: 12,
        color: '#0369a1',
        marginLeft: 8,
        flex: 1,
        fontWeight: '500',
    },
    sendOtpBtnInline: {
        backgroundColor: '#f59e0b',
        paddingVertical: 12,
        paddingHorizontal: 16,
        borderRadius: 10,
        alignItems: 'center',
        justifyContent: 'center',
    },
    sendOtpBtnTextInline: {
        color: '#fff',
        fontSize: 12,
        fontWeight: '900',
        letterSpacing: 0.5,
    },
    verifiedBadgeInline: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: '#ecfdf5',
        paddingVertical: 10,
        borderRadius: 10,
        borderWidth: 1,
        borderColor: '#10b981',
    },
    verifiedTextInline: {
        color: '#065f46',
        fontSize: 13,
        fontWeight: '800',
        marginLeft: 6,
    },
});

const alertStyles = StyleSheet.create({
    alertOverlay: {
        flex: 1,
        backgroundColor: 'rgba(0,0,0,0.5)',
        justifyContent: 'center',
        alignItems: 'center',
    },
    alertContent: {
        width: '85%',
        backgroundColor: '#fff',
        borderRadius: 20,
        overflow: 'hidden',
        elevation: 10,
    },
    alertHeader: {
        flexDirection: 'row',
        padding: 15,
        alignItems: 'center',
    },
    alertHeaderTitle: {
        color: '#fff',
        fontSize: 18,
        fontWeight: 'bold',
    },
    alertBody: {
        padding: 20,
    },
    alertMessage: {
        fontSize: 15,
        color: '#334155',
        lineHeight: 22,
        marginBottom: 20,
    },
    alertActionRow: {
        flexDirection: 'row',
        justifyContent: 'flex-end',
        gap: 10,
    },
    alertButton: {
        paddingVertical: 10,
        paddingHorizontal: 20,
        borderRadius: 10,
        borderWidth: 1,
        borderColor: '#0d6efd',
    },
    alertCancelButton: {
        borderColor: '#cbd5e1',
        backgroundColor: '#f8fafc',
    },
    alertCancelButtonText: {
        color: '#64748b',
        fontWeight: '600',
    },
    alertConfirmButton: {
        borderColor: 'transparent',
    },
    alertConfirmButtonText: {
        color: '#fff',
        fontWeight: 'bold',
    },
    alertButtonText: {
        fontWeight: 'bold',
        color: '#0d6efd',
    },
});
