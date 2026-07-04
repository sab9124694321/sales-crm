<?php
require_once 'db.php';
require_once 'config.php';

$categories = [
    1 => 'Охотник (много звонков, низкая конверсия)',
    2 => 'Настойчивый (высокий дозвон, мало встреч)',
    3 => 'Коммуникатор (хорошая конверсия, мало кросс-продаж)',
    4 => 'Кросс-продавец (сильные доп. продажи)',
];

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
    echo "OAuth ошибка HTTP $httpCode: $response\n";
    return null;
}

function callGigaChat(string $prompt, string $accessToken): ?string {
    $data = [
        'model' => 'GigaChat',
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.9,
        'max_tokens' => 150,
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
    if ($httpCode !== 200) {
        echo "GigaChat ошибка HTTP $httpCode: $response\n";
        return null;
    }
    $json = json_decode($response, true);
    return $json['choices'][0]['message']['content'] ?? null;
}

$token = getAccessToken();
if (!$token) die("Не удалось получить токен\n");

$adviceInsert = $pdo->prepare("INSERT INTO ai_advice_cache (category, advice_text) VALUES (?, ?)");
$bookInsert = $pdo->prepare("INSERT INTO ai_book_cache (category, book_title, book_author) VALUES (?, ?, ?)");

foreach ($categories as $catId => $catName) {
    echo "Генерируем советы для категории $catId...\n";
    for ($i = 0; $i < 20; $i++) {
        $prompt = "Ты — AI-наставник для менеджеров по продажам. Дай короткий практический совет (1 предложение) для категории «{$catName}». Совет должен быть уникальным, мотивирующим и направленным на улучшение ключевых метрик.";
        $advice = callGigaChat($prompt, $token);
        if ($advice) {
            $adviceInsert->execute([$catId, trim($advice)]);
        }
        sleep(1);
    }
    echo "Генерируем книги для категории $catId...\n";
    for ($i = 0; $i < 20; $i++) {
        $prompt = "Ты — AI-наставник для менеджеров по продажам. Порекомендуй одну книгу (или курс) для категории «{$catName}». Ответь строго в формате: Название | Автор (или платформа). Пример: «СПИН-продажи | Нил Рекхэм». Не используй кавычки.";
        $book = callGigaChat($prompt, $token);
        if ($book) {
            $parts = explode('|', $book);
            $title = trim($parts[0] ?? '');
            $author = trim($parts[1] ?? '');
            if ($title) {
                $bookInsert->execute([$catId, $title, $author]);
            }
        }
        sleep(1);
    }
}

echo "Готово! Кэш заполнен советами и книгами.\n";
