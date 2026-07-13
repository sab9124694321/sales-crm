<?php
// Включаем вывод ошибок для отладки (на проде можно выключить)
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
require_once 'config.php';

if (!defined('GIGACHAT_AUTH')) {
    error_log('GIGACHAT_AUTH не определён в config.php');
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Не авторизован']);
    exit;
}

$userId = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['error' => 'Нет данных']);
    exit;
}

$taskId     = $input['task_id'] ?? '';
$callResult = $input['call_result'] ?? '';
$painPoint  = $input['pain_point'] ?? '';
$objection  = $input['objection'] ?? '';
$objectionText = $input['objection_text'] ?? '';
$nextStep   = $input['next_step'] ?? '';
$decisionMaker = $input['decision_maker'] ?? '';
$nextCallDate  = $input['next_call_date'] ?? '';
$nextCallTime  = $input['next_call_time'] ?? '';
$freeComment   = $input['free_comment'] ?? '';

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

// === ФУНКЦИИ ДЛЯ GIGACHAT ===
function getAccessToken(): ?string {
    if (!defined('GIGACHAT_AUTH')) {
        error_log('ERROR: GIGACHAT_AUTH not defined');
        return null;
    }
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    error_log('Token HTTP code: ' . $httpCode);
    if ($curlError) error_log('CURL error on token: ' . $curlError);
    if ($httpCode === 200) {
        $json = json_decode($response, true);
        $token = $json['access_token'] ?? null;
        if ($token) {
            error_log('Token obtained successfully');
            return $token;
        }
        error_log('Response did not contain access_token: ' . substr($response, 0, 200));
    } else {
        error_log('Token request failed. Response: ' . substr($response, 0, 200));
    }
    return null;
}

function callGigaChatForScore(string $commentText, string $token): ?int {
    $prompt = <<<EOT
Ты — эксперт по оценке качества комментариев менеджеров по продажам эквайринга. Оценивай комментарий по шкале от 0 до 100:

- **0–39** – комментарий явно абсурдный, не связан с деловым разговором, содержит бессмыслицу, стихи, нецензурные выражения или не относится к эквайрингу.
- **40–79** – средний комментарий, есть деловые элементы, но мало конкретики или есть небольшие отклонения от темы.
- **80–100** – отличный деловой комментарий: есть конкретные цифры, названия банков, условия, решение, следующий шаг.

Вот примеры:
- **Плохой (фрод)**: "Проблема: бессонница. Возражение: два банана один огурец за 100. Договорились: я не торгую семечкам." → Оценка: 5
- **Хороший**: "Проблема: комиссия выше на 4 пункта чем у Альфы. Возражение: комиссия конкурента 2%, у нас 2,4%. Договорились: компенсировать за счет бесплатного РКО и более дешевых комиссий по АПМ." → Оценка: 95

Теперь оцени следующий комментарий, верни только число от 0 до 100 без пояснений.

Комментарий: $commentText
EOT;

    $data = [
        'model' => 'GigaChat',
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.7,   // повышена для более чёткого разделения
        'max_tokens' => 10,
    ];
    $ch = curl_init('https://gigachat.devices.sberbank.ru/api/v1/chat/completions');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    error_log('GigaChat HTTP code: ' . $httpCode);
    if ($curlError) error_log('CURL error on score: ' . $curlError);
    if ($httpCode === 200) {
        $json = json_decode($response, true);
        $content = $json['choices'][0]['message']['content'] ?? '';
        error_log('GigaChat raw response: ' . $content);
        $score = (int) filter_var($content, FILTER_SANITIZE_NUMBER_INT);
        if ($score >= 0 && $score <= 100) {
            error_log('GigaChat score: ' . $score);
            return $score;
        }
        error_log('Invalid score format: ' . $content);
    } else {
        error_log('GigaChat request failed. Response: ' . substr($response, 0, 300));
    }
    return null;
}

// === ЛОКАЛЬНАЯ ПРОВЕРКА (только ПДН + короткие) ===
function validateWithGigaChat($text, $callResult) {
    // 1. Проверка ПДН (блокирующая)
    $commonSurnames = 'Иванов|Петров|Сидоров|Смирнов|Кузнецов|Попов|Васильев|Соколов|Михайлов|Новиков|Федоров|Морозов|Волков|Алексеев|Лебедев|Семенов|Егоров|Павлов|Козлов|Степанов|Николаев|Орлов|Андреев|Макаров|Захаров|Зайцев|Соловьев|Борисов|Яковлев|Григорьев|Романов|Воробьев|Антонов|Фролов|Беляев|Гусев|Кузьмин|Медведев|Тихонов|Исаев|Карпов|Афанасьев|Максимов|Мельников|Давыдов|Калинин|Богданов|Осипов|Фомин|Комаров|Поляков|Марков|Шестаков|Нестеров|Кудрявцев|Баранов|Куликов|Коновалов';
    if (preg_match('/\b(' . $commonSurnames . ')\b/u', $text)) {
        return ['has_pdn' => true, 'pdn_type' => 'фамилия', 'fraud_score' => 90, 'fraud_reason' => 'Обнаружена фамилия клиента'];
    }
    if (preg_match('/(\+?7|8|9)\d{9,10}/', $text)) {
        return ['has_pdn' => true, 'pdn_type' => 'телефон', 'fraud_score' => 95, 'fraud_reason' => 'Обнаружен номер телефона'];
    }
    if (preg_match('/\b\d{10}\b|\b\d{12}\b/', $text)) {
        return ['has_pdn' => true, 'pdn_type' => 'инн', 'fraud_score' => 95, 'fraud_reason' => 'Обнаружен ИНН'];
    }
    if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text)) {
        return ['has_pdn' => true, 'pdn_type' => 'email', 'fraud_score' => 90, 'fraud_reason' => 'Обнаружен email'];
    }

    // 2. Для финальных статусов (signed, contract, reject) – высокий скор, пропускаем GigaChat
    if (in_array($callResult, ['signed', 'contract', 'reject'])) {
        return ['has_pdn' => false, 'fraud_score' => 80, 'fraud_reason' => 'Финальный статус, комментарий не проверяется'];
    }

    // 3. Короткие комментарии (<10 символов) – сразу в РОП без GigaChat
    $length = mb_strlen($text);
    if ($length < 10) {
        return ['has_pdn' => false, 'fraud_score' => 30, 'fraud_reason' => 'Слишком короткий комментарий (<10 символов)'];
    }

    // 4. Вызов GigaChat с новым промптом
    error_log('Attempting to call GigaChat for comment length ' . $length);
    $token = getAccessToken();
    if ($token) {
        $score = callGigaChatForScore($text, $token);
        if ($score !== null) {
            return ['has_pdn' => false, 'fraud_score' => $score, 'fraud_reason' => 'Оценка GigaChat'];
        }
    } else {
        error_log('No token, using fallback');
    }

    // 5. Fallback (если GigaChat недоступен)
    $relevantKeywords = ['цена', 'конкурент', 'терминал', 'эквайринг', 'процент', 'ставка', 'комиссия', 'договор', 'встреча', 'звонок', 'перезвон', 'отказ', 'нужен', 'не нужен', 'дорого', 'дешевле', 'сбер', 'банк', 'карта', 'оплата', 'касса', 'оборот', 'оборудование', 'подключение', 'установка', 'обслуживание', 'обучение', 'менеджер', 'лид', 'клиент', 'торговля', 'магазин', 'точка'];
    $hasRelevant = false;
    foreach ($relevantKeywords as $kw) {
        if (mb_stripos($text, $kw) !== false) {
            $hasRelevant = true;
            break;
        }
    }
    if (!$hasRelevant) {
        if ($length > 30) {
            return ['has_pdn' => false, 'fraud_score' => 30, 'fraud_reason' => 'Длинный комментарий не по теме (нет ключевых слов)'];
        } else {
            return ['has_pdn' => false, 'fraud_score' => 40, 'fraud_reason' => 'Комментарий не по теме, средняя длина'];
        }
    }

    if ($length < 30) {
        return ['has_pdn' => false, 'fraud_score' => 50, 'fraud_reason' => 'Средний комментарий (10-30 символов)'];
    } else {
        return ['has_pdn' => false, 'fraud_score' => 65, 'fraud_reason' => 'Хороший комментарий (релевантный, длинный)'];
    }
}

// === ОСНОВНАЯ ЛОГИКА ===
$validation = validateWithGigaChat($commentText, $callResult);

if ($validation['has_pdn']) {
    echo json_encode([
        'error' => 'Сохранение ПДН возможно только в контактах РИТМ, сохраните данные там. После этого удалите ПДН в комментариях и сохраните.'
    ]);
    exit;
}

$fraudScore = $validation['fraud_score'];

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM call_comments WHERE task_id = ?");
    $stmt->execute([$taskId]);
    $callCount = $stmt->fetchColumn() + 1;

    $topStatus = 'active';
    $taskStatus = 'Подтверждена';

    if ($callResult === 'signed' || $callResult === 'contract') {
        $taskStatus = 'Согласен';
        $topStatus = 'closed';
    } elseif ($callResult === 'reject') {
        $taskStatus = 'Отказ подтверждён';
        $topStatus = 'rejected_confirmed';
    } elseif ($callResult === 'noanswer') {
        $taskStatus = 'Недозвон';
        $topStatus = 'active';
    } elseif ($callResult === 'think') {
        $taskStatus = 'Думает';
        $topStatus = 'think';
    } elseif ($callResult === 'recall') {
        $taskStatus = 'Перезвон';
        $topStatus = 'recall';
    } elseif ($callResult === 'nocontact') {
        $taskStatus = 'Нет контакта';
        $topStatus = 'nocontact';
    } else {
        $taskStatus = 'Подтверждена';
        $topStatus = 'active';
    }

    $stmt = $pdo->prepare("
        INSERT INTO call_comments (task_id, user_id, call_result, comment_text, fraud_score, call_count, created_at)
        VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
    ");
    $stmt->execute([$taskId, $userId, $callResult, $commentText, $fraudScore, $callCount]);

    $stmt = $pdo->prepare("
        UPDATE epk_tasks SET
            status = ?,
            top_status = ?,
            call_count = call_count + 1,
            first_status_at = COALESCE(first_status_at, datetime('now')),
            next_call_date = ?,
            updated_at = datetime('now')
        WHERE task_id = ?
    ");
    $nextCallDateTime = ($nextCallDate && $nextCallTime) ? $nextCallDate . ' ' . $nextCallTime : ($nextCallDate ?: null);
    $stmt->execute([$taskStatus, $topStatus, $nextCallDateTime, $taskId]);

    if ($fraudScore < 40) {
        $tabelStmt = $pdo->prepare("SELECT user_tabel FROM epk_tasks WHERE task_id = ?");
        $tabelStmt->execute([$taskId]);
        $tabel = $tabelStmt->fetchColumn() ?: 'unknown';

        $stmt = $pdo->prepare("
            INSERT OR REPLACE INTO rop_control_queue 
                (task_id, user_id, tabel, fraud_score, comment_text, status, top_status, created_at)
            VALUES (?, ?, ?, ?, ?, 'На проверке', ?, datetime('now'))
        ");
        $stmt->execute([$taskId, $userId, $tabel, $fraudScore, $commentText, $topStatus]);
    }

    $tabelStmt = $pdo->prepare("SELECT user_tabel FROM epk_tasks WHERE task_id = ?");
    $tabelStmt->execute([$taskId]);
    $tabel = $tabelStmt->fetchColumn() ?: 'unknown';

    $isLow = ($fraudScore < 40) ? 1 : 0;
    $stmt = $pdo->prepare("
        INSERT INTO manager_call_stats (user_id, tabel, total_calls, fraud_flags, last_call_date, updated_at)
        VALUES (?, ?, 1, ?, date('now'), datetime('now'))
        ON CONFLICT(user_id) DO UPDATE SET
            tabel = excluded.tabel,
            total_calls = total_calls + 1,
            fraud_flags = fraud_flags + ?,
            last_call_date = date('now'),
            updated_at = datetime('now')
    ");
    $stmt->execute([$userId, $tabel, $isLow, $isLow]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'fraud_score' => $fraudScore,
        'call_count' => $callCount,
        'new_status' => $taskStatus,
        'top_status' => $topStatus,
        'message' => 'Звонок сохранен'
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['error' => 'Ошибка сохранения: ' . $e->getMessage()]);
}