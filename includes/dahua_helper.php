<?php
/**
 * Dahua DoLynk Cloud Integration Helper
 * Handles Authentication and Visitor/Person Synchronization
 */

class DahuaHelper
{
    private $appId;
    private $appSecret;
    private $baseUrl = 'https://open.dolynkcloud.com/api/v1'; // Standard DoLynk API base URL
    private $accessToken;

    public function __construct($appId, $appSecret)
    {
        $this->appId = $appId;
        $this->appSecret = $appSecret;
    }

    /**
     * Authenticate and get Access Token
     */
    public function getAccessToken()
    {
        // In a real scenario, you should cache this token in a session or database
        $url = "{$this->baseUrl}/token/get";
        $data = [
            'appId' => $this->appId,
            'appSecret' => $this->appSecret
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);
        if (isset($result['data']['accessToken'])) {
            $this->accessToken = $result['data']['accessToken'];
            return $this->accessToken;
        }
        return false;
    }

    /**
     * Synchronize a visitor to the Dahua Cloud
     * 
     * @param array $visitorData [name, phone, face_path, qr_code, start_time, end_time, device_sns]
     */
    public function syncVisitor($visitorData)
    {
        if (!$this->accessToken) {
            $this->getAccessToken();
        }

        if (!$this->accessToken)
            return ['success' => false, 'error' => 'Authentication Failed'];

        $url = "{$this->baseUrl}/visitor/add"; // Endpoint might vary: person/add or visitor/add

        // Prepare Base64 Image
        $base64Image = '';
        if (!empty($visitorData['face_path']) && file_exists($visitorData['face_path'])) {
            $imageData = file_get_contents($visitorData['face_path']);
            $base64Image = base64_encode($imageData);
        }

        $postData = [
            'name' => $visitorData['name'],
            'faceImage' => $base64Image,
            'qrCode' => $visitorData['qr_code'],
            'startTime' => $visitorData['start_time'],
            'endTime' => $visitorData['end_time'],
            'deviceSns' => $visitorData['device_sns'],
            'externalId' => (string) ($visitorData['visitor_id'] ?? '')
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            "Authorization: Bearer {$this->accessToken}"
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }
}
