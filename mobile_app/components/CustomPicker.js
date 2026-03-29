import React, { useState } from 'react';
import {
    StyleSheet,
    View,
    Text,
    TouchableOpacity,
    Modal,
    FlatList,
    SafeAreaView,
} from 'react-native';
import { MaterialCommunityIcons as Icon } from '@expo/vector-icons';

const CustomPicker = ({ label, selectedValue, onValueChange, options, placeholder = "Select an option" }) => {
    const [modalVisible, setModalVisible] = useState(false);

    const selectedOption = options.find(opt => opt.value === selectedValue);

    const handleSelect = (value) => {
        onValueChange(value);
        setModalVisible(false);
    };

    return (
        <View style={styles.container}>
            <TouchableOpacity
                style={styles.pickerTrigger}
                onPress={() => setModalVisible(true)}
            >
                <Text style={[styles.pickerText, !selectedValue && styles.placeholder]}>
                    {selectedOption ? selectedOption.label : placeholder}
                </Text>
                <Icon name="chevron-down" size={20} color="#64748b" />
            </TouchableOpacity>

            <Modal
                visible={modalVisible}
                transparent={true}
                animationType="slide"
                onRequestClose={() => setModalVisible(false)}
            >
                <TouchableOpacity
                    style={styles.modalOverlay}
                    activeOpacity={1}
                    onPress={() => setModalVisible(false)}
                >
                    <SafeAreaView style={styles.modalContent}>
                        <View style={styles.modalHeader}>
                            <Text style={styles.modalTitle}>{label || "Select Option"}</Text>
                            <TouchableOpacity onPress={() => setModalVisible(false)}>
                                <Icon name="close" size={24} color="#1e293b" />
                            </TouchableOpacity>
                        </View>

                        <FlatList
                            data={options}
                            keyExtractor={(item) => item.value.toString()}
                            renderItem={({ item }) => (
                                <TouchableOpacity
                                    style={[
                                        styles.optionItem,
                                        selectedValue === item.value && styles.selectedOption
                                    ]}
                                    onPress={() => handleSelect(item.value)}
                                >
                                    <Text style={[
                                        styles.optionText,
                                        selectedValue === item.value && styles.selectedOptionText
                                    ]}>
                                        {item.label}
                                    </Text>
                                    {selectedValue === item.value && (
                                        <Icon name="check" size={20} color="#6366f1" />
                                    )}
                                </TouchableOpacity>
                            )}
                        />
                    </SafeAreaView>
                </TouchableOpacity>
            </Modal>
        </View>
    );
};

const styles = StyleSheet.create({
    container: {
        flex: 1,
    },
    pickerTrigger: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        height: 50,
        paddingHorizontal: 4,
    },
    pickerText: {
        fontSize: 16,
        color: '#1e293b',
    },
    placeholder: {
        color: '#94a3b8',
    },
    modalOverlay: {
        flex: 1,
        backgroundColor: 'rgba(0,0,0,0.5)',
        justifyContent: 'flex-end',
    },
    modalContent: {
        backgroundColor: '#fff',
        borderTopLeftRadius: 24,
        borderTopRightRadius: 24,
        maxHeight: '70%',
    },
    modalHeader: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        padding: 20,
        borderBottomWidth: 1,
        borderBottomColor: '#f1f5f9',
    },
    modalTitle: {
        fontSize: 18,
        fontWeight: 'bold',
        color: '#1e293b',
    },
    optionItem: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        padding: 16,
        borderBottomWidth: 1,
        borderBottomColor: '#f8fafc',
    },
    selectedOption: {
        backgroundColor: '#f5f3ff',
    },
    optionText: {
        fontSize: 16,
        color: '#475569',
    },
    selectedOptionText: {
        color: '#6366f1',
        fontWeight: '600',
    },
});

export default CustomPicker;
