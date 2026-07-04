<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', 0);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Требуется авторизация']);
    exit;
}

require_once 'db.php';
require_once 'config.php';

$input = json_decode(file_get_contents('php://input'), true);
$text = trim($input['text'] ?? '');

if ($text === '') {
    echo json_encode(['error' => 'Пустой текст']);
    exit;
}

// Токен GigaChat
$accessToken = $_SESSION['gigachat_token'] ?? null;
if (!$accessToken) {
    $accessToken = getAccessToken();
    if ($accessToken) {
        $_SESSION['gigachat_token'] = $accessToken;
    } else {
        echo json_encode(['error' => 'Не удалось получить токен GigaChat. Проверьте config.php.']);
        exit;
    }
}

$prompt = "Ты — AI-помощник менеджера по продажам эквайринга Сбербанка. Проанализируй текущий телефонный разговор и дай краткую рекомендацию (1-2 предложения): как продолжить разговор, какие фразы-зацепки использовать, чтобы заинтересовать клиента. Обрати внимание на интонацию и скорость речи (если можешь оценить по тексту). Укажи, что менеджер забыл упомянуть из важного. Текст разговора:\n" . $text;

$result = callGigaChatWithLog($prompt, $accessToken);
echo json_encode($result);

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

function callGigaChatWithLog(string $prompt, string $accessToken): array {
    $data = [
        'model' => 'GigaChat',
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 1.2,
        'max_tokens' => 250,
    ];
    $ch = curl_init('https://gigachat.devices.sberbank.ru/api/v1/chat/completions');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpCode === 200) {
        $json = json_decode($response, true);
        $text = $json['choices'][0]['message']['content'] ?? null;
        if ($text) {
            return ['response' => $text];
        }
        return ['error' => 'GigaChat не вернул текст ответа', 'raw' => substr($response, 0, 200)];
    }
    return ['error' => "HTTP $httpCode", 'raw' => substr($response, 0, 200)];
}
