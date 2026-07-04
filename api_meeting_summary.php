<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['head', 'admin', 'territory_head', 'terman'])) {
    echo json_encode(['error' => 'Access denied']);
    exit;
}
require_once 'db.php';
require_once 'config.php';

$tabel = $_POST['tabel'] ?? '';
$manual_text = trim($_POST['manual_text'] ?? '');
if (!$tabel) exit(json_encode(['error' => 'No tabel']));

$stmt = $pdo->prepare("SELECT full_name FROM users WHERE tabel_number = ?");
$stmt->execute([$tabel]);
$user = $stmt->fetch();
if (!$user) exit(json_encode(['error' => 'User not found']));

if (empty($manual_text)) {
    echo json_encode(['summary' => 'Добавьте характеристику сотрудника в поле выше, чтобы получить AI-анализ.']);
    exit;
}

$prompt = "Ты — мотивирующий коуч и психолог. Руководитель предоставил характеристику сотрудника {$user['full_name']}. 
Твоя задача: дать руководителю 3-4 абзаца поддержки и рекомендаций для разговора. 
- В первом абзаце подчеркни сильные стороны сотрудника, даже если они не очевидны (найди позитив).
- Во втором – мягко обозначь зоны роста и предложи 1-2 конкретных действия.
- В третьем – дай готовую, тёплую фразу поддержки, которую руководитель может сказать сотруднику.

Кроме того, добавь отдельный абзац под названием \"Обратная связь по бизнес-результату\", где кратко оцени текущие показатели сотрудника (договоры, оборот) и дай чёткую рекомендацию, на чём сосредоточиться в ближайшее время.

Пиши живым, вдохновляющим языком. Не используй маркдаун (звёздочки, решётки, дефисы для списков). Не используй символы * # - _ >. Используй только обычный текст с абзацами.

Вот характеристика сотрудника:\n" . mb_substr($manual_text, 0, 4000);

function getAccessToken(): ?string {
    $authBase64 = GIGACHAT_AUTH;
    $rquid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
    $ch = curl_init('https://ngw.devices.sberbank.ru:9443/api/v2/oauth');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['scope'=>'GIGACHAT_API_PERS']));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . $authBase64,
        'Content-Type: application/x-www-form-urlencoded',
        'RqUID: ' . $rquid,
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    if(curl_getinfo($ch, CURLINFO_HTTP_CODE)===200){
        $json = json_decode($response, true);
        return $json['access_token'] ?? null;
    }
    return null;
}

function callGigaChat(string $prompt, string $token): ?string {
    $data = [
        'model' => 'GigaChat',
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.95,
        'max_tokens' => 1200,
    ];
    $ch = curl_init('https://gigachat.devices.sberbank.ru/api/v1/chat/completions');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer '.$token, 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    if(curl_getinfo($ch, CURLINFO_HTTP_CODE)===200){
        $json = json_decode($response, true);
        $text = $json['choices'][0]['message']['content'] ?? null;
        if ($text) {
            // Жёсткая очистка от маркдауна
            $text = preg_replace('/[*_`~#\-]+/', '', $text);
            $text = preg_replace('/^\s*[-*+]\s+/m', '', $text);
            $text = preg_replace('/\s*\n\s*\n\s*/', "\n\n", $text);
            $text = trim($text);
        }
        return $text;
    }
    return null;
}

$token = getAccessToken();
if (!$token) {
    echo json_encode(['error' => 'Ошибка авторизации GigaChat']);
    exit;
}
$result = callGigaChat($prompt, $token);
if ($result) {
    echo json_encode(['summary' => nl2br($result)]);
} else {
    echo json_encode(['error' => 'GigaChat не ответил']);
}