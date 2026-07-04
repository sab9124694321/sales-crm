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
if (!$tabel) exit(json_encode(['error' => 'No tabel']));

$uploadDir = __DIR__ . '/uploads/employee_files/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

$uploaded = [];
$allText = '';
foreach ($_FILES['files']['tmp_name'] as $i => $tmp) {
    $name = $_FILES['files']['name'][$i];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $dest = $uploadDir . $tabel . '_' . time() . '_' . $i . '.' . $ext;
    if (move_uploaded_file($tmp, $dest)) {
        $uploaded[] = ['name' => $name, 'path' => $dest];
        if ($ext === 'txt') {
            $allText .= file_get_contents($dest) . "\n";
        } elseif ($ext === 'html' || $ext === 'htm') {
            $html = file_get_contents($dest);
            $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
            $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
            $text = strip_tags($html);
            $text = preg_replace('/\s+/', ' ', $text);
            $allText .= trim($text) . "\n";
        } else {
            $allText .= "[Файл $name: неподдерживаемый формат. Используйте TXT или HTML]\n";
        }
    }
}

$analysis = '';
if (!empty(trim($allText))) {
    $prompt = "Ты — опытный бизнес-коуч и психолог по мотивации персонала. Руководитель предоставил характеристику сотрудника (360). Твоя задача: выделить 2-3 сильные стороны и 2-3 зоны роста, а также дать практические рекомендации руководителю, как стимулировать сотрудника к достижениям, используя его сильные стороны. Ответ должен быть конкретным, без общих фраз, вдохновляющим. Не используй markdown. Пиши на русском.\n\nТекст характеристики:\n" . mb_substr($allText, 0, 3000);
    $token = getAccessToken();
    if ($token) {
        $analysis = callGigaChat($prompt, $token);
        if (!$analysis) $analysis = "GigaChat не вернул ответ. Попробуйте позже.";
    } else {
        $analysis = "Ошибка авторизации GigaChat. Проверьте config.php.";
    }
} else {
    $analysis = "Не удалось извлечь текст из файлов. Загрузите TXT/HTML или введите текст вручную в поле ниже.";
}

echo json_encode(['files' => $uploaded, 'analysis' => $analysis]);

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
        'temperature' => 0.9,      // повысили для креативности
        'max_tokens' => 800,
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
        return $json['choices'][0]['message']['content'] ?? null;
    }
    return null;
}