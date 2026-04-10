<?php
require 'includes/db.php';

// Data from user's portal debug screenshot
$appId = '2042539358257250304';
$token = 'ea69ab86-41fa-4e4a-a527-74a4e82465e3-02';
$time = '1775862983115';
$nonce = 'web-bf3f4415fecd10afbd4fe617f0188b7f-1775862983115';
$path = '/open-api/api-iot/device/accessControl/getCardNoPage';
$target = 'CA75914C4811A7F7171A62CE25404D27CE560B4472D8E366C69462091D0C60B7D6846456696E8447C72FB0539A699305AE78DB2BEE14244BCC0DA2E90142D03E';

// Get secret
$stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'dahua_app_secret'");
$secret = $stmt->fetchColumn();

echo "Testing combinations for Secret starting with: " . substr($secret, 0, 4) . "...\n";

$methods = ["POST"];
$bodies = ["", "{}", "73b18d96b080ce4d5653b64bc756c9a9", hash('sha512', "{}")]; // MD5 of {} and SHA512 of {}

$variants = [];
// Standard HMAC-SHA512
$variants[] = $appId . $token . $time . $nonce . "POST";
$variants[] = $appId . $token . $time . $nonce . "POST" . hash('sha512', "{}");

// Simple SHA512 (Concatenation style)
$variants_sha = [
    $appId . $token . $time . $nonce . "POST" . $secret,
    $appId . $time . $nonce . "POST" . $secret,
    $appId . $productId . $time . $nonce . "v1" . $secret, // User's MD5 model from Request #2
];

foreach ($variants as $v) {
    if (strtoupper(hash_hmac('sha512', $v, $secret)) == $target) {
        die("!!! MATCH FOUND (HMAC) !!!\nString: " . str_replace("\n", "\\n", $v) . "\n");
    }
}

foreach ($variants_sha as $v) {
    if (strtoupper(hash('sha512', $v)) == $target) {
        die("!!! MATCH FOUND (SHA512) !!!\nString: " . str_replace("\n", "\\n", $v) . "\n");
    }
}

// Brute Permutation
$parts = [$appId, $token, $time, $nonce, "POST"];
// Try all permutations of the 4 parts + method
// (Simple recursive permutation would be too long for this script, let's try some specific common sets)

echo "No basic match. Checking if Method comes before or after Time...\n";
$variants2 = [
    $appId . $token . $time . $nonce . "POST",
    $appId . $token . "POST" . $time . $nonce,
    $appId . "POST" . $token . $time . $nonce,
    $appId . $token . $time . "POST" . $nonce,
];

foreach ($variants2 as $v) {
    if (strtoupper(hash_hmac('sha512', $v, $secret)) == $target) {
        die("!!! MATCH FOUND !!!\nString: " . $v . "\n");
    }
}

echo "No match yet. Are we using the right secret?\n";
echo "Secret: " . $secret . "\n";
