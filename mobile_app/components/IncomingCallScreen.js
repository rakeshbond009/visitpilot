import React, { useEffect, useState, useRef } from 'react';
import { View, Text, StyleSheet, TouchableOpacity, Image, Modal, Animated, Dimensions, ActivityIndicator } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { BlurView } from 'expo-blur';

const { width, height } = Dimensions.get('window');

const IncomingCallScreen = ({ visible, visitorData, onAccept, onReject }) => {
    const [actionLoading, setActionLoading] = useState(false);
    const pulseAnim = useRef(new Animated.Value(1)).current;
    const slideAnim = useRef(new Animated.Value(height)).current;
    const opacityAnim = useRef(new Animated.Value(0)).current;

    useEffect(() => {
        if (visible) {
            // Entry animations
            Animated.parallel([
                Animated.timing(slideAnim, {
                    toValue: 0,
                    duration: 800,
                    useNativeDriver: true,
                }),
                Animated.timing(opacityAnim, {
                    toValue: 1,
                    duration: 500,
                    useNativeDriver: true,
                })
            ]).start();

            // Pulse animation for avatar
            Animated.loop(
                Animated.sequence([
                    Animated.timing(pulseAnim, {
                        toValue: 1.15,
                        duration: 1200,
                        useNativeDriver: true,
                    }),
                    Animated.timing(pulseAnim, {
                        toValue: 1,
                        duration: 1200,
                        useNativeDriver: true,
                    }),
                ])
            ).start();
        } else {
            // Reset animations and loading state
            slideAnim.setValue(height);
            opacityAnim.setValue(0);
            setActionLoading(false);
        }
    }, [visible]);

    if (!visible) return null;

    return (
        <Modal visible={visible} animationType="none" transparent={true}>
            <View style={styles.outerContainer}>
                {/* Background Image / Blur */}
                {visitorData?.photo ? (
                    <Image
                        source={{ uri: visitorData.photo }}
                        style={StyleSheet.absoluteFill}
                        blurRadius={40}
                    />
                ) : (
                    <View style={[StyleSheet.absoluteFill, { backgroundColor: '#0f172a' }]} />
                )}

                <BlurView intensity={80} style={StyleSheet.absoluteFill} tint="dark" />

                <Animated.View style={[
                    styles.contentContainer,
                    {
                        transform: [{ translateY: slideAnim }],
                        opacity: opacityAnim
                    }
                ]}>
                    <View style={styles.header}>
                        <View style={styles.liveBadge}>
                            <View style={styles.liveDot} />
                            <Text style={styles.incomingText}>Incoming Arrival</Text>
                        </View>
                        <Text style={styles.timeText}>Visitor at Gate</Text>
                    </View>

                    <View style={styles.visitorProfile}>
                        <Animated.View style={[styles.avatarWrapper, { transform: [{ scale: pulseAnim }] }]}>
                            <View style={styles.avatarBorder}>
                                {visitorData?.photo ? (
                                    <Image source={{ uri: visitorData.photo }} style={styles.avatar} />
                                ) : (
                                    <View style={styles.placeholderAvatar}>
                                        <Ionicons name="person" size={100} color="#94a3b8" />
                                    </View>
                                )}
                            </View>
                        </Animated.View>

                        <View style={styles.nameContainer}>
                            <Text style={styles.visitorName}>{visitorData?.visitor_name || visitorData?.name || visitorData?.username || 'Unknown Visitor'}</Text>
                            <Text style={styles.visitorInfo}>{visitorData?.company || 'Organization Not Disclosed'}</Text>
                        </View>

                        <View style={[styles.detailsGlass, { marginTop: 10 }]}>
                            <View style={styles.detailItem}>
                                <Ionicons name="enter-outline" size={18} color="#60a5fa" />
                                <Text style={styles.detailText}>{visitorData?.purpose || 'General Visit'}</Text>
                            </View>
                            <View style={[styles.detailItem, { marginTop: 8, borderTopWidth: 1, borderTopColor: 'rgba(255,255,255,0.05)', paddingTop: 8 }]}>
                                <Ionicons name="briefcase-outline" size={18} color="#fbbf24" />
                                <Text style={styles.detailText}>{visitorData?.assets_carried || visitorData?.assets || 'No items carried'}</Text>
                            </View>
                        </View>
                    </View>

                    <View style={styles.actionSection}>
                        <View style={styles.actionRow}>
                            <TouchableOpacity 
                                activeOpacity={0.7} 
                                style={[styles.actionButton, actionLoading && { opacity: 0.5 }]} 
                                onPress={() => {
                                    if (actionLoading) return;
                                    setActionLoading(true);
                                    onReject();
                                }}
                                disabled={actionLoading}
                            >
                                <View style={[styles.iconCircle, styles.rejectBtnColor]}>
                                    {actionLoading ? (
                                        <ActivityIndicator color="#fff" size="large" />
                                    ) : (
                                        <Ionicons name="close" size={36} color="#fff" />
                                    )}
                                </View>
                                <Text style={styles.btnText}>Decline</Text>
                            </TouchableOpacity>

                            <TouchableOpacity 
                                activeOpacity={0.7} 
                                style={[styles.actionButton, actionLoading && { opacity: 0.5 }]} 
                                onPress={() => {
                                    if (actionLoading) return;
                                    setActionLoading(true);
                                    onAccept();
                                }}
                                disabled={actionLoading}
                            >
                                <View style={[styles.iconCircle, styles.acceptBtnColor]}>
                                    {actionLoading ? (
                                        <ActivityIndicator color="#fff" size="large" />
                                    ) : (
                                        <Ionicons name="checkmark-sharp" size={38} color="#fff" />
                                    )}
                                </View>
                                <Text style={styles.btnText}>Authorize</Text>
                            </TouchableOpacity>
                        </View>
                    </View>
                </Animated.View>
            </View>
        </Modal>
    );
};

const styles = StyleSheet.create({
    outerContainer: {
        flex: 1,
        backgroundColor: '#000',
    },
    contentContainer: {
        flex: 1,
        paddingTop: 80,
        alignItems: 'center',
        justifyContent: 'space-between',
        paddingBottom: 60,
    },
    header: {
        alignItems: 'center',
    },
    liveBadge: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: 'rgba(239, 68, 68, 0.2)',
        paddingHorizontal: 12,
        paddingVertical: 6,
        borderRadius: 20,
        borderWidth: 1,
        borderColor: 'rgba(239, 68, 68, 0.3)',
    },
    liveDot: {
        width: 8,
        height: 8,
        borderRadius: 4,
        backgroundColor: '#ef4444',
        marginRight: 8,
    },
    incomingText: {
        color: '#ef4444',
        fontSize: 12,
        fontWeight: '700',
        letterSpacing: 1.5,
        textTransform: 'uppercase',
    },
    timeText: {
        color: '#fff',
        fontSize: 28,
        fontWeight: '800',
        marginTop: 12,
        textShadowColor: 'rgba(0, 0, 0, 0.5)',
        textShadowOffset: { width: 0, height: 2 },
        textShadowRadius: 4,
    },
    visitorProfile: {
        alignItems: 'center',
        width: '100%',
        flex: 1,
        justifyContent: 'flex-start',
        marginTop: 20,
    },
    avatarWrapper: {
        marginBottom: 15,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 20 },
        shadowOpacity: 0.5,
        shadowRadius: 30,
        elevation: 20,
    },
    avatarBorder: {
        width: 130,
        height: 130,
        borderRadius: 65,
        backgroundColor: 'rgba(255,255,255,0.05)',
        padding: 6,
        borderWidth: 1,
        borderColor: 'rgba(255,255,255,0.2)',
    },
    avatar: {
        width: '100%',
        height: '100%',
        borderRadius: 60,
    },
    placeholderAvatar: {
        width: '100%',
        height: '100%',
        borderRadius: 60,
        justifyContent: 'center',
        alignItems: 'center',
        backgroundColor: 'rgba(255,255,255,0.1)',
    },
    nameContainer: {
        alignItems: 'center',
        paddingHorizontal: 30,
    },
    visitorName: {
        color: '#fff',
        fontSize: 38,
        fontWeight: 'bold',
        textAlign: 'center',
        letterSpacing: -0.5,
    },
    visitorInfo: {
        color: '#94a3b8',
        fontSize: 20,
        marginTop: 6,
        fontWeight: '500',
    },
    detailsGlass: {
        marginTop: 30,
        backgroundColor: 'rgba(255, 255, 255, 0.08)',
        borderRadius: 24,
        paddingVertical: 12,
        paddingHorizontal: 24,
        borderWidth: 1,
        borderColor: 'rgba(255, 255, 255, 0.1)',
    },
    detailItem: {
        flexDirection: 'row',
        alignItems: 'center',
    },
    detailText: {
        color: '#fff',
        fontSize: 16,
        fontWeight: '600',
        marginLeft: 10,
    },
    actionSection: {
        width: '100%',
        paddingHorizontal: 20,
    },
    actionRow: {
        flexDirection: 'row',
        justifyContent: 'space-around',
        alignItems: 'flex-end',
        width: '100%',
    },
    actionButton: {
        alignItems: 'center',
        width: 100,
    },
    iconCircle: {
        width: 76,
        height: 76,
        borderRadius: 38,
        justifyContent: 'center',
        alignItems: 'center',
        marginBottom: 12,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 10 },
        shadowOpacity: 0.4,
        shadowRadius: 15,
        elevation: 12,
    },
    rejectBtnColor: {
        backgroundColor: '#ef4444',
    },
    waitBtnColor: {
        backgroundColor: '#f59e0b',
    },
    acceptBtnColor: {
        backgroundColor: '#22c55e',
    },
    btnText: {
        color: '#fff',
        fontSize: 14,
        fontWeight: '700',
        opacity: 0.9,
    },
});

export default IncomingCallScreen;
