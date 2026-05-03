<?php
class GigaChat {
    private $authKey;
    private $accessToken;
    private $tokenExpires = 0;

    public function __construct($authKey) {
        $this->authKey = $authKey;
    }

    private function getAccessToken() {
        if ($this->accessToken && $this->tokenExpires > (time() + 300)) {
            return $this->accessToken;
        }

        $ch = curl_init('https://ngw.devices.sberbank.ru:9443/api/v2/oauth');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'Authorization: Basic ' . $this->authKey,
            'RqUID: ' . sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', 
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff))
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'scope=GIGACHAT_API_PERS');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log("GigaChat token error: $httpCode - $response");
            return false;
        }
        
        $data = json_decode($response, true);
        if (isset($data['access_token'])) {
            $this->accessToken = $data['access_token'];
            $this->tokenExpires = time() + ($data['expires_at'] ?? 1200);
            return $this->accessToken;
        }
        return false;
    }

    public function generateAdvice($prompt) {
        $token = $this->getAccessToken();
        if (!$token) return false;
        
        $ch = curl_init('https://gigachat.devices.sberbank.ru/api/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'model' => 'GigaChat',
            'messages' => [
                ['role' => 'system', 'content' => 'Ты — ИИ-наставник для менеджеров по продажам. Отвечай коротко (2-3 предложения), мотивирующе, используй эмодзи.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.7,
            'max_tokens' => 300
        ], JSON_UNESCAPED_UNICODE));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log("GigaChat API error: $httpCode - $response");
            return false;
        }
        
        $data = json_decode($response, true);
        return $data['choices'][0]['message']['content'] ?? false;
    }
}
?>
