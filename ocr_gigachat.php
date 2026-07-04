<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';

function getAccessToken(): ?string {
    $authBase64 = GIGACHAT_AUTH;
    $rquid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    $ch = curl_init('https://ngw.devices.sberbank.ru:9443/api/v2/oauth');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['scope' => 'GIGACHAT_API_PERS']));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . $authBase64,
        'Content-Type: application/x-www-form-urlencoded',
        'RqUID: ' . $rquid,
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpCode === 200) {
        $json = json_decode($response, true);
        return $json['access_token'] ?? null;
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['image'])) {
    echo json_encode(['error' => 'Некорректный запрос']);
    exit;
}

$tmpPath = $_FILES['image']['tmp_name'];
$imageData = base64_encode(file_get_contents($tmpPath));
$base64Image = 'data:image/jpeg;base64,' . $imageData;

$token = $_SESSION['gigachat_token'] ?? null;
if (!$token) {
    $token = getAccessToken();
    if ($token) {
        $_SESSION['gigachat_token'] = $token;
    } else {
        echo json_encode(['error' => 'Не удалось получить токен GigaChat']);
        exit;
    }
}

$prompt = "Ты видишь фотографию кассового чека. Извлеки из него:
1. ИНН — это ровно 10 или 12 цифр, обычно рядом с надписью \"ИНН\" или \"ИНН:\"
2. Телефон — начинается с +7 или 8, затем 10 цифр
3. Адрес — город, улица, дом (может быть на нескольких строках)

ВАЖНО:
- Если ИНН не виден четко — верни null, НЕ придумывай цифры
- ИНН должен быть точно 10 или 12 цифр, не больше и не меньше
- Не добавляй пояснений, только JSON

Ответ строго в формате JSON:
{\"inn\": \"...\", \"phone\": \"...\", \"address\": \"...\"}";

$url = 'https://gigachat.devices.sberbank.ru/api/v1/chat/completions';
$payload = [
    'model' => 'GigaChat-Vision',
    'messages' => [
        [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => $base64Image]]
            ]
        ]
    ],
    'temperature' => 0.1,
    'max_tokens' => 300
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo json_encode(['error' => "GigaChat ошибка $httpCode", 'raw' => $response]);
    exit;
}

$data = json_decode($response, true);
$content = $data['choices'][0]['message']['content'] ?? '';
preg_match('/\{.*\}/s', $content, $matches);
$jsonStr = $matches[0] ?? '{}';
$result = json_decode($jsonStr, true);
if (!is_array($result)) {
    echo json_encode(['error' => 'Не удалось извлечь JSON из ответа', 'raw' => $content]);
} else {
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}
