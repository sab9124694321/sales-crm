<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['image'])) {
    echo json_encode(['error' => 'Некорректный запрос']);
    exit;
}

$tmpPath = $_FILES['image']['tmp_name'];

if (!file_exists($tmpPath)) {
    echo json_encode(['error' => 'Файл не загружен']);
    exit;
}
if ($_FILES['image']['size'] > 10 * 1024 * 1024) {
    echo json_encode(['error' => 'Файл слишком большой (макс 10MB)']);
    exit;
}

// ========== TESSERACT ==========
function ocrTesseract($tmpPath) {
    $pythonBin = '/usr/bin/python3';
    $scriptPath = __DIR__ . '/ocr_parser.py';
    
    if (!file_exists($pythonBin) || !file_exists($scriptPath)) {
        return null;
    }
    
    $cmd = escapeshellarg($pythonBin) . " " . escapeshellarg($scriptPath) . " " . escapeshellarg($tmpPath) . " 2>&1";
    $output = shell_exec($cmd);
    
    $logFile = __DIR__ . '/ocr_debug.log';
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [TESSERACT] OUTPUT: " . ($output ?? 'NULL') . "\n", FILE_APPEND);
    
    $result = json_decode($output, true);
    return is_array($result) ? $result : null;
}

function isTesseractGood($result) {
    if (!is_array($result) || isset($result['error'])) return false;
    $inn = $result['inn'] ?? null;
    return $inn && preg_match('/^\d{10}$|^\d{12}$/', $inn);
}

// ========== GIGACHAT ==========
function getGigaChatToken() {
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

function ocrGigaChat($tmpPath) {
    $token = $_SESSION['gigachat_token'] ?? null;
    if (!$token) {
        $token = getGigaChatToken();
        if ($token) {
            $_SESSION['gigachat_token'] = $token;
        } else {
            return ['error' => 'Не удалось получить токен GigaChat'];
        }
    }
    
    $imageData = base64_encode(file_get_contents($tmpPath));
    $base64Image = 'data:image/jpeg;base64,' . $imageData;
    
    $prompt = "Ты видишь фотографию кассового чека. Извлеки из него:\n"
        . "1. ИНН — это ровно 10 или 12 цифр, обычно рядом с надписью \"ИНН\" или \"ИНН:\"\n"
        . "2. Телефон — начинается с +7 или 8, затем 10 цифр\n"
        . "3. Адрес — город, улица, дом (может быть на нескольких строках)\n\n"
        . "ВАЖНО:\n"
        . "- Если ИНН не виден четко — верни null, НЕ придумывай цифры\n"
        . "- ИНН должен быть точно 10 или 12 цифр, не больше и не меньше\n"
        . "- Не добавляй пояснений, только JSON\n\n"
        . "Ответ строго в формате JSON:\n"
        . '{\"inn\": \"...\", \"phone\": \"...\", \"address\": \"...\"}';
    
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
        return ['error' => "GigaChat ошибка $httpCode", 'raw' => $response];
    }
    
    $data = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    preg_match('/\{.*\}/s', $content, $matches);
    $jsonStr = $matches[0] ?? '{}';
    $result = json_decode($jsonStr, true);
    
    $logFile = __DIR__ . '/ocr_debug.log';
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [GIGACHAT] RESULT: " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    
    return is_array($result) ? $result : ['error' => 'Не удалось извлечь JSON', 'raw' => $content];
}

// ========== ЛОГИКА ==========
$tesseractResult = ocrTesseract($tmpPath);

if (isTesseractGood($tesseractResult)) {
    echo json_encode([
        'inn' => $tesseractResult['inn'],
        'phone' => $tesseractResult['phone'],
        'address' => $tesseractResult['address'],
        'source' => 'tesseract'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$gigaResult = ocrGigaChat($tmpPath);

if (isset($gigaResult['error'])) {
    if ($tesseractResult) {
        echo json_encode([
            'inn' => $tesseractResult['inn'] ?? null,
            'phone' => $tesseractResult['phone'] ?? null,
            'address' => $tesseractResult['address'] ?? null,
            'source' => 'tesseract_fallback',
            'warning' => 'GigaChat недоступен, результат может быть неточным',
            'gigachat_error' => $gigaResult['error']
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['error' => 'OCR не удался', 'details' => $gigaResult['error']]);
    }
    exit;
}

echo json_encode([
    'inn' => $gigaResult['inn'] ?? null,
    'phone' => $gigaResult['phone'] ?? null,
    'address' => $gigaResult['address'] ?? null,
    'source' => 'gigachat'
], JSON_UNESCAPED_UNICODE);