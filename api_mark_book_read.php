<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Auth']);
    exit;
}

require_once 'db.php';
require_once 'ai_classify.php';
require_once 'config.php';

$tabel = $_SESSION['tabel'];
$cat = classifyEmployee($pdo, $tabel, 10);

// Получаем текущую книгу (из ai_recommendations)
$cur = $pdo->prepare("SELECT advice_book FROM ai_recommendations WHERE employee_tabel = ?");
$cur->execute([$tabel]);
$current_book = $cur->fetchColumn();
if ($current_book) {
    // Сохраняем прочитанную книгу в историю
    $stmt = $pdo->prepare("INSERT INTO employee_book_reads (employee_tabel, book_title, source) VALUES (?, ?, 'ai_recommendation')");
    $stmt->execute([$tabel, $current_book]);
}

// Генерируем новую книгу (как было)
$stmt = $pdo->prepare("SELECT book_title, book_author FROM ai_book_cache WHERE category = ? ORDER BY RANDOM() LIMIT 1");
$stmt->execute([$cat]);
$book = $stmt->fetch();
if ($book) {
    $bookText = $book['book_title'] . ($book['book_author'] ? ' | ' . $book['book_author'] : '');
} else {
    // Кэш пуст – генерируем через GigaChat одну книгу
    $accessToken = $_SESSION['gigachat_token'] ?? null;
    if (!$accessToken) {
        $accessToken = getAccessToken();
        if ($accessToken) $_SESSION['gigachat_token'] = $accessToken;
    }
    if ($accessToken) {
        $catNames = [1 => 'Охотник', 2 => 'Настойчивый', 3 => 'Коммуникатор', 4 => 'Кросс-продавец'];
        $prompt = "Порекомендуй одну книгу (или курс) для менеджера по продажам категории «{$catNames[$cat]}». Ответь строго: Название | Автор";
        $response = callGigaChat($prompt, $accessToken);
        if ($response) {
            $parts = explode('|', $response);
            $title = trim($parts[0] ?? '');
            $author = trim($parts[1] ?? '');
            if ($title) {
                $pdo->prepare("INSERT INTO ai_book_cache (category, book_title, book_author) VALUES (?, ?, ?)")
                   ->execute([$cat, $title, $author]);
                $bookText = $title . ($author ? ' | ' . $author : '');
            } else {
                $bookText = 'Рекомендация не получена';
            }
        } else {
            $bookText = 'Рекомендация не получена';
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Нет токена GigaChat']);
        exit;
    }
}

// Сохраняем новую книгу в ai_recommendations
$exists = $pdo->prepare("SELECT COUNT(*) FROM ai_recommendations WHERE employee_tabel = ?");
$exists->execute([$tabel]);
if ($exists->fetchColumn() > 0) {
    $upd = $pdo->prepare("UPDATE ai_recommendations SET recommendation = ?, advice_book = ?, state_number = ?, updated_at = datetime('now') WHERE employee_tabel = ?");
    $upd->execute([$advice ?? '', $bookText, $cat, $tabel]);
} else {
    $ins = $pdo->prepare("INSERT INTO ai_recommendations (employee_tabel, state_number, recommendation, advice_book, updated_at) VALUES (?, ?, ?, ?, datetime('now'))");
    $ins->execute([$tabel, $cat, $advice ?? 'Продолжайте в том же духе!', $bookText]);
}

echo json_encode(['success' => true, 'book' => $bookText]);

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

function callGigaChat(string $prompt, string $accessToken): ?string {
    $data = [
        'model' => 'GigaChat',
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.7,
        'max_tokens' => 100,
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
        return $json['choices'][0]['message']['content'] ?? null;
    }
    return null;
}
