<?php
/**
 * Класс для работы с YandexGPT API
 * Документация: https://yandex.cloud/ru/docs/yandexgpt/api-ref/
 */
class YandexGPT 
{
    private $apiKey;
    private $folderId;
    private $apiUrl = 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion';
    
    public function __construct($apiKey, $folderId) 
    {
        $this->apiKey = $apiKey;
        $this->folderId = $folderId;
    }
    
    public function generate($prompt) 
    {
        $data = [
            'modelUri' => "gpt://{$this->folderId}/yandexgpt/latest",
            'completionOptions' => [
                'stream' => false,
                'temperature' => 0.7,
                'maxTokens' => 500
            ],
            'messages' => [
                [
                    'role' => 'system',
                    'text' => 'Ты — ИИ-наставник для менеджеров по продажам. Твои советы короткие, полезные, мотивирующие. Отвечай на русском языке, 2-3 предложения. Используй эмодзи.'
                ],
                [
                    'role' => 'user',
                    'text' => $prompt
                ]
            ]
        ];
        
        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Api-Key ' . $this->apiKey,
            'x-folder-id: ' . $this->folderId
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log("YandexGPT HTTP error: $httpCode");
            return false;
        }
        
        $result = json_decode($response, true);
        if (isset($result['result']['alternatives'][0]['message']['text'])) {
            return trim($result['result']['alternatives'][0]['message']['text']);
        }
        
        return false;
    }
    
    public function getPersonalAdvice($stats) 
    {
        $avgCalls = round($stats['avg_calls'] ?? 0);
        $avgMeetings = round($stats['avg_meetings'] ?? 0);
        $avgContracts = round($stats['avg_contracts'] ?? 0);
        $conversion = round($stats['conversion_rate'] ?? 0);
        
        if ($conversion < 25 && $avgCalls > 0) {
            $prompt = "Менеджер делает $avgCalls звонков в неделю, но дозванивается только в $conversion% случаев. Как повысить качество разговоров и процент дозвонов?";
        } elseif ($avgMeetings < 2) {
            $prompt = "У менеджера мало встреч с клиентами: $avgMeetings в неделю. Как эффективнее назначать встречи?";
        } elseif ($avgContracts == 0 && $avgMeetings > 2) {
            $prompt = "Менеджер проводит $avgMeetings встреч в неделю, но не заключает договоры. Какие техники помогут закрывать больше сделок?";
        } elseif ($avgCalls < 30) {
            $prompt = "Менеджер делает всего $avgCalls звонков в неделю. Как мотивировать его увеличить активность?";
        } else {
            $prompt = "Менеджер стабильно работает. Дай мотивирующий совет, как выйти на новый уровень.";
        }
        
        $advice = $this->generate($prompt);
        if ($advice === false) {
            return "🌟 Продолжайте в том же духе! Маленькие победы ведут к большим результатам.";
        }
        return $advice;
    }
}
?>
