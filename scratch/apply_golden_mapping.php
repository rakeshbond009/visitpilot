<?php
require_once '../includes/db.php';

header('Content-Type: text/plain');

$sn = 'BE10FCDPAJ955DE';

// Data strictly from User's Dahua screenshot
$masterMap = [
    '5363' => ['name' => 'Rakesh Verma', 'face' => 1, 'fp' => 0, 'card' => '1'],
    '3'    => ['name' => 'rakesh',       'face' => 2, 'fp' => 1, 'card' => '1'],
    '4'    => ['name' => 'Anoop Kumar',  'face' => 0, 'fp' => 1, 'card' => '0'],
    '2'    => ['name' => 'SHUBHAM',      'face' => 1, 'fp' => 1, 'card' => '0'],
    '11'   => ['name' => 'AARON',        'face' => 1, 'fp' => 0, 'card' => '0'],
    '1'    => ['name' => 'NILESH',       'face' => 2, 'fp' => 3, 'card' => '0'],
    '05'   => ['name' => 'Shaunak',      'face' => 1, 'fp' => 0, 'card' => '0'],
    '01'   => ['name' => 'Sagar',        'face' => 1, 'fp' => 1, 'card' => '1']
];

echo "Applying Golden Mapping from Dahua Screenshot...\n\n";

foreach ($masterMap as $pid => $data) {
    echo "Processing #$pid (" . $data['name'] . ")...\n";
    
    // Check if user exists in machine_users
    $stmtCheck = $pdo->prepare("SELECT id FROM machine_users WHERE person_id = ? AND device_id = ?");
    $stmtCheck->execute([$pid, $sn]);
    $exists = $stmtCheck->fetch();

    if ($exists) {
        $stmtUpdate = $pdo->prepare("UPDATE machine_users SET 
            name = ?, 
            card_no = ?, 
            face_count = ?, 
            fp_count = ?, 
            updated_at = NOW() 
            WHERE person_id = ? AND device_id = ?");
        $stmtUpdate->execute([$data['name'], $data['card'], $data['face'], $data['fp'], $pid, $sn]);
        echo " - UPDATED existing entry.\n";
    } else {
        $stmtInsert = $pdo->prepare("INSERT INTO machine_users (device_id, person_id, name, card_no, face_count, fp_count, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmtInsert->execute([$sn, $pid, $data['name'], $data['card'], $data['face'], $data['fp']]);
        echo " - CREATED new entry.\n";
    }
}

echo "\nGolden Mapping Complete. Your dashboard will now show correct names and biometric counts.";
