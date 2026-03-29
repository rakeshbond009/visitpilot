import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity, SafeAreaView } from 'react-native';
import { MaterialCommunityIcons as Icon } from '@expo/vector-icons';

export default function ComingSoon({ navigation, route }) {
    const { screenName } = route.params || { screenName: 'Feature' };

    return (
        <SafeAreaView style={styles.container}>
            <View style={styles.header}>
                <TouchableOpacity onPress={() => navigation.goBack()}>
                    <Icon name="arrow-left" size={24} color="#1e293b" />
                </TouchableOpacity>
                <Text style={styles.headerTitle}>{screenName}</Text>
                <View style={{ width: 24 }} />
            </View>
            <View style={styles.content}>
                <View style={styles.iconContainer}>
                    <Icon name="hammer-wrench" size={80} color="#3b82f6" />
                </View>
                <Text style={styles.title}>Under Construction</Text>
                <Text style={styles.desc}>
                    The {screenName} feature is currently being developed and will be available in a future update.
                </Text>
                <TouchableOpacity
                    style={styles.btn}
                    onPress={() => navigation.goBack()}
                >
                    <Text style={styles.btnText}>Go Back</Text>
                </TouchableOpacity>
            </View>
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
        padding: 20,
        backgroundColor: '#fff',
        borderBottomWidth: 1,
        borderBottomColor: '#e2e8f0',
    },
    headerTitle: {
        fontSize: 18,
        fontWeight: 'bold',
        color: '#1e293b',
    },
    content: {
        flex: 1,
        justifyContent: 'center',
        alignItems: 'center',
        padding: 40,
    },
    iconContainer: {
        width: 150,
        height: 150,
        borderRadius: 75,
        backgroundColor: '#eff6ff',
        justifyContent: 'center',
        alignItems: 'center',
        marginBottom: 30,
    },
    title: {
        fontSize: 24,
        fontWeight: 'bold',
        color: '#1e293b',
        marginBottom: 16,
    },
    desc: {
        fontSize: 16,
        color: '#64748b',
        textAlign: 'center',
        lineHeight: 24,
        marginBottom: 40,
    },
    btn: {
        backgroundColor: '#3b82f6',
        paddingHorizontal: 32,
        paddingVertical: 14,
        borderRadius: 12,
        elevation: 2,
        shadowColor: '#3b82f6',
        shadowOffset: { width: 0, height: 4 },
        shadowOpacity: 0.3,
        shadowRadius: 8,
    },
    btnText: {
        color: '#fff',
        fontWeight: 'bold',
        fontSize: 16,
    },
});
