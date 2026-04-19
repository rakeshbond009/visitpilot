<?php
require_once '../includes/dahua_helper.php';
require_once '../includes/db.php';

header('Content-Type: text/plain');

$sn = 'BE10FCDPAJ955DE';
$targetIds = ['5363', '3'];

echo "Starting Force Sync for Target IDs on Device $sn...\n\n";

foreach ($targetIds as $pid) {
    echo "Fetching Detail for ID: $pid...\n";
    $detail = DahuaHelper::getPersonDetail($sn, $pid);
    
    if ($detail) {
        echo "Found: " . $detail['name'] . "\n";
        $faceCount = isset($detail['faceList']) ? count($detail['faceList']) : 0;
        $fpCount = isset($detail['fingerprintList']) ? count($detail['fingerprintList']) : 0;
        $cardNo = $detail['cardList'][0]['cardNo'] ?? 'N/A';
        
        echo "Updating local DB: Name=" . $detail['name'] . ", Faces=$faceCount, FP=$fpCount, Card=$cardNo\n";
        
        $pdo->prepare("UPDATE machine_users SET 
            name = ?, 
            card_no = ?,
            face_count = ?,
            fp_count = ?,
            updated_at = NOW()
            WHERE person_id = ? AND device_id = ?")
        ->execute([$detail['name'], $cardNo, $faceCount, $fpCount, $pid, $sn]);
        
        echo "SUCCESS: Updated ID $pid\n\n";
    } else {
        echo "FAILED: Could not fetch detail for ID $pid from Dahua Cloud.\n\n";
    }
}

echo "Force Sync Complete.";
