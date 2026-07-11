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

// === ЛОКАЛЬНАЯ ПРОВЕРКА (основная) + GIGACHAT (fallback) ===
function validateWithGigaChat($text) {
    // === ШАГ 1: Локальная проверка через регулярки ===
    $result = [
        'has_pdn' => false,
        'pdn_type' => 'нет',
        'is_relevant' => true,
        'relevance_reason' => 'локальная проверка пройдена',
        'fraud_score' => 0,
        'fraud_reason' => 'Нет нарушений'
    ];

    if (empty($text)) {
        $result['fraud_score'] = 0;
        $result['fraud_reason'] = 'Пустой комментарий';
        return $result;
    }

    // Проверка на ПДН
    // 1. Фамилия (типичные русские фамилии — заглавная + строчные, 3+ букв)
    $commonSurnames = 'Иванов|Петров|Сидоров|Смирнов|Кузнецов|Попов|Васильев|Соколов|Михайлов|Новиков|Федоров|Морозов|Волков|Алексеев|Лебедев|Семенов|Егоров|Павлов|Козлов|Степанов|Николаев|Орлов|Андреев|Макаров|Захаров|Зайцев|Соловьев|Борисов|Яковлев|Григорьев|Романов|Воробьев|Антонов|Фролов|Беляев|Гусев|Кузьмин|Медведев|Тихонов|Исаев|Карпов|Афанасьев|Максимов|Мельников|Давыдов|Калинин|Богданов|Осипов|Фомин|Комаров|Поляков|Марков|Шестаков|Нестеров|Кудрявцев|Баранов|Куликов|Коновалов';
    if (preg_match('/\b(' . $commonSurnames . ')\b/u', $text)) {
        $result['has_pdn'] = true;
        $result['pdn_type'] = 'фамилия';
        $result['fraud_score'] = 90;
        $result['fraud_reason'] = 'Обнаружена фамилия клиента';
        return $result;
    }

    // 2. Телефон (10-11 цифр, начинается с 7, 8, +7, 9)
    if (preg_match('/(\+?7|8|9)\d{9,10}/', $text)) {
        $result['has_pdn'] = true;
        $result['pdn_type'] = 'телефон';
        $result['fraud_score'] = 95;
        $result['fraud_reason'] = 'Обнаружен номер телефона';
        return $result;
    }

    // 3. ИНН (10 или 12 цифр подряд)
    if (preg_match('/\b\d{10}\b|\b\d{12}\b/', $text)) {
        $result['has_pdn'] = true;
        $result['pdn_type'] = 'инн';
        $result['fraud_score'] = 95;
        $result['fraud_reason'] = 'Обнаружен ИНН';
        return $result;
    }

    // 4. Email (содержит @)
    if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text)) {
        $result['has_pdn'] = true;
        $result['pdn_type'] = 'email';
        $result['fraud_score'] = 90;
        $result['fraud_reason'] = 'Обнаружен email';
        return $result;
    }

    // Проверка на релевантность (простая)
    $relevantKeywords = ['цена', 'конкурент', 'терминал', 'эквайринг', 'процент', 'ставка', 'комиссия', 'договор', 'встреча', 'звонок', 'перезвон', 'отказ', 'нужен', 'не нужен', 'дорого', 'дешевле', 'сбер', 'банк', 'карта', 'оплата', 'касса', 'оборот', 'оборудование', 'подключение', 'установка', 'обслуживание', 'обучение', 'менеджер', 'лид', 'клиент', 'торговля', 'магазин', 'точка'];
    $hasRelevant = false;
    foreach ($relevantKeywords as $kw) {
        if (mb_stripos($text, $kw) !== false) {
            $hasRelevant = true;
            break;
        }
    }
    if (!$hasRelevant) {
        // Проверим — может это просто короткий комментарий?
        if (mb_strlen($text) < 10) {
            $result['fraud_score'] = 30;
            $result['fraud_reason'] = 'Слишком короткий комментарий';
        } else {
            $result['is_relevant'] = false;
            $result['relevance_reason'] = 'Текст не связан с продажей эквайринга';
            $result['fraud_score'] = 70;
            $result['fraud_reason'] = 'Комментарий не по теме';
        }
        return $result;
    }

    // Оценка качества комментария
    $length = mb_strlen($text);
    if ($length < 15) {
        $result['fraud_score'] = 40;
        $result['fraud_reason'] = 'Комментарий слишком короткий (менее 15 символов)';
    } elseif ($length < 30) {
        $result['fraud_score'] = 25;
        $result['fraud_reason'] = 'Комментарий короткий (менее 30 символов)';
    } else {
        $result['fraud_score'] = 10;
        $result['fraud_reason'] = 'Комментарий соответствует требованиям';
    }

    return $result;
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
    if ($callResult === 'signed' || $callResult === 'contract') {
        $topStatus = 'closed';  // Согласен — финальный статус, задача закрыта
    } elseif ($callResult === 'reject') {
        $topStatus = 'rejected_confirmed';  // Отказ подтверждён — тоже финальный
    } elseif ($callResult === 'think') {
        $topStatus = 'think';
    } elseif ($callResult === 'recall') {
        $topStatus = 'recall';
    } elseif ($callResult === 'nocontact') {
        $topStatus = 'nocontact';
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
        WHERE task_id = ?
    ");
    // Статусы: signed=Согласен (финальный), reject=Отказ подтверждён (финальный)
    // contract объединён с signed → "Согласен"
    $status = ($callResult === 'signed' || $callResult === 'contract') ? 'Согласен' :
              (($callResult === 'reject') ? 'Отказ подтверждён' :
              (($callResult === 'noanswer') ? 'Недозвон' :
              (($callResult === 'recall') ? 'Перезвон' :
              (($callResult === 'nocontact') ? 'Нет контакта' : 'Подтверждена'))));
    $stmt->execute([$status, $topStatus, $taskId]);

    // Если фрод-скор < 60 — добавляем в очередь РОПа
    if ($fraudScore < 40) {
        // Получаем табельный номер из epk_tasks
        $tabelStmt = $pdo->prepare("SELECT user_tabel FROM epk_tasks WHERE task_id = ?");
        $tabelStmt->execute([$taskId]);
        $tabel = $tabelStmt->fetchColumn() ?: 'unknown';

        $stmt = $pdo->prepare("
            INSERT OR REPLACE INTO rop_control_queue (task_id, user_id, tabel, fraud_score, comment_text, status, top_status, created_at)
            VALUES (?, ?, ?, ?, ?, 'На проверке', ?, datetime('now'))
        ");
        $stmt->execute([$taskId, $userId, $tabel, $fraudScore, $commentText, $topStatus]);
    }

    // Обновляем статистику менеджера
    // Получаем табельный номер для manager_call_stats
    $tabelStmt = $pdo->prepare("SELECT user_tabel FROM epk_tasks WHERE task_id = ?");
    $tabelStmt->execute([$taskId]);
    $tabel = $tabelStmt->fetchColumn() ?: 'unknown';

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
    $isLow = ($fraudScore < 60) ? 1 : 0;
    $stmt->execute([$userId, $tabel, $isLow, $isLow]);

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
