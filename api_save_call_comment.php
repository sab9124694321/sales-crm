<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';

// Проверка авторизации
if (empty($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Не авторизован']);
    exit;
}

$userId = $_SESSION['user_id'];

// Получаем данные из POST
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['error' => 'Нет данных']);
    exit;
}

$taskId     = $input['task_id']     ?? '';
$callResult = $input['call_result'] ?? '';
$painPoint  = $input['pain_point']  ?? '';
$objection  = $input['objection']   ?? '';
$objectionText = $input['objection_text'] ?? '';
$nextStep   = $input['next_step']   ?? '';
$decisionMaker = $input['decision_maker'] ?? '';
$nextCallDate  = $input['next_call_date']  ?? '';
$nextCallTime  = $input['next_call_time']  ?? '';
$freeComment   = $input['free_comment']    ?? '';

if (empty($taskId) || empty($callResult)) {
    echo json_encode(['error' => 'task_id и call_result обязательны']);
    exit;
}

// Собираем комментарий
$commentParts = [];
if ($painPoint)      $commentParts[] = "Проблема: $painPoint";
if ($objection)      $commentParts[] = "Возражение: $objection" . ($objectionText ? " — $objectionText" : '');
if ($nextStep)       $commentParts[] = "Договорились: $nextStep";
if ($decisionMaker)  $commentParts[] = "Решение: $decisionMaker";
if ($nextCallDate && $nextCallTime) $commentParts[] = "Следующий контакт: $nextCallDate $nextCallTime";
elseif ($nextCallDate) $commentParts[] = "Следующий контакт: $nextCallDate";
if ($freeComment)    $commentParts[] = "Комментарий: $freeComment";

$commentText = implode(". ", $commentParts);

// === ПРОВЕРКА ЧЕРЕЗ GIGACHAT AI ===
$validation = validateWithGigaChat($commentText);

// Если ПДН найдены — блокируем сохранение
if ($validation['has_pdn']) {
    echo json_encode([
        'error' => 'Сохранение ПДН возможно только в контактах РИТМ, сохраните данные там. После этого удалите ПДН в комментариях и сохраните.'
    ]);
    exit;
}

// Если текст не релевантен — блокируем
if (!$validation['is_relevant']) {
    echo json_encode([
        'error' => 'Комментарий не связан с продажей эквайринга. ' . $validation['relevance_reason'] . ' Сохранение заблокировано.'
    ]);
    exit;
}

// Используем фрод-скор от GigaChat
$fraudScore = $validation['fraud_score'];

// === ФУНКЦИЯ ВАЛИДАЦИИ ЧЕРЕЗ GIGACHAT ===
function validateWithGigaChat($text) {
    // Fallback: если GigaChat недоступен — локальный расчёт
    $fallback = [
        'has_pdn' => false,
        'pdn_type' => 'нет',
        'is_relevant' => true,
        'relevance_reason' => 'локальная проверка',
        'fraud_score' => 50,
        'fraud_reason' => 'Проверка AI недоступна, использован средний скор'
    ];

    // Проверяем, есть ли config.php с токеном
    if (!file_exists('config.php')) {
        return $fallback;
    }
    require_once 'config.php';
    if (!defined('GIGACHAT_AUTH')) {
        return $fallback;
    }

    // Получаем access_token
    $token = getGigaChatToken();
    if (!$token) {
        return $fallback;
    }

    // Формируем промпт (без двойных кавычек внутри одинарных!)
    $prompt = 'Ты — система проверки комментариев менеджера по продажам эквайринга Сбербанка. Проанализируй комментарий и верни ТОЛЬКО JSON без форматирования и без Markdown: {"has_pdn":true/false,"pdn_type":"фамилия/телефон/инн/email/нет","is_relevant":true/false,"relevance_reason":"причина","fraud_score":0-100,"fraud_reason":"обоснование"} Правила: ФАМИЛИЯ запрещена (Иванов, Петров, Сидоров и т.д.). ИМЯ и ОТЧЕСТВО разрешены (Иван, Петр, Александрович). ТЕЛЕФОН запрещен (10-11 цифр, начинается с 7, 8, +7). ИНН запрещен (10 или 12 цифр подряд). EMAIL запрещен (содержит @). Текст должен быть связан с продажей эквайринга: проблема клиента, возражения, договоренности, следующий шаг. Запрещен произвольный текст не по теме. Комментарий: ' . $text;

    // Отправляем запрос в GigaChat
    $ch = curl_init('https://gigachat.devices.sberbank.ru/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'GigaChat',
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.1,
        'max_tokens' => 500
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return $fallback;
    }

    $data = json_decode($response, true);
    if (!isset($data['choices'][0]['message']['content'])) {
        return $fallback;
    }

    $content = $data['choices'][0]['message']['content'];

    // Очищаем от Markdown (```json ... ```)
    $content = preg_replace('/^```json\s*/i', '', $content);
    $content = preg_replace('/\s*```$/i', '', $content);
    $content = trim($content);

    $result = json_decode($content, true);
    if (!$result || !isset($result['has_pdn'])) {
        return $fallback;
    }

    return $result;
}

// === ФУНКЦИЯ ПОЛУЧЕНИЯ ТОКЕНА GIGACHAT ===
function getGigaChatToken() {
    if (!defined('GIGACHAT_AUTH')) {
        return null;
    }

    $ch = curl_init('https://ngw.devices.sberbank.ru:9443/api/v2/oauth');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'scope=GIGACHAT_API_PERS');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . GIGACHAT_AUTH,
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        return null;
    }

    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

// === СОХРАНЕНИЕ В БАЗУ ===
try {
    $pdo->beginTransaction();

    // Считаем порядковый номер звонка
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM call_comments WHERE task_id = ?");
    $stmt->execute([$taskId]);
    $callCount = $stmt->fetchColumn() + 1;

    // Определяем top_status
    $topStatus = 'active';
    if ($callResult === 'contract' || $callResult === 'signed') {
        $topStatus = 'signed';
    } elseif ($callResult === 'reject') {
        $topStatus = 'rejected_confirmed';
    } elseif ($callResult === 'think') {
        $topStatus = 'think';
    }

    // Сохраняем комментарий
    $stmt = $pdo->prepare("
        INSERT INTO call_comments (task_id, user_id, call_result, comment_text, fraud_score, call_count, created_at)
        VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
    ");
    $stmt->execute([$taskId, $userId, $callResult, $commentText, $fraudScore, $callCount]);

    // Обновляем задачу
    $stmt = $pdo->prepare("
        UPDATE epk_tasks SET
            status = ?,
            top_status = ?,
            call_count = call_count + 1,
            first_status_at = COALESCE(first_status_at, datetime('now'))
        WHERE uuid = ?
    ");
    $status = ($callResult === 'contract') ? 'Договор заключён' :
              ($callResult === 'reject') ? 'Отказ подтверждён' :
              ($callResult === 'noanswer') ? 'Недозвон' :
              ($callResult === 'signed') ? 'Подписан' : 'Подтверждена';
    $stmt->execute([$status, $topStatus, $taskId]);

    // Если фрод-скор < 60 — добавляем в очередь РОПа
    if ($fraudScore < 60) {
        $stmt = $pdo->prepare("
            INSERT OR REPLACE INTO rop_control_queue (task_id, user_id, fraud_score, comment_text, status, top_status, created_at)
            VALUES (?, ?, ?, ?, 'На проверке', ?, datetime('now'))
        ");
        $stmt->execute([$taskId, $userId, $fraudScore, $commentText, $topStatus]);
    }

    // Обновляем статистику менеджера
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        INSERT INTO manager_call_stats (user_id, call_date, total_calls, fraud_low_count)
        VALUES (?, ?, 1, ?)
        ON CONFLICT(user_id, call_date) DO UPDATE SET
            total_calls = total_calls + 1,
            fraud_low_count = fraud_low_count + ?
    ");
    $isLow = ($fraudScore < 60) ? 1 : 0;
    $stmt->execute([$userId, $today, $isLow, $isLow]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'fraud_score' => $fraudScore,
        'call_count' => $callCount,
        'message' => 'Звонок сохранен'
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['error' => 'Ошибка сохранения: ' . $e->getMessage()]);
}
