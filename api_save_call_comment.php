<?php
session_start();
header('Content-Type: application/json');
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Не авторизован']);
    exit;
}

$user_id = $_SESSION['user_id'];
$tabel = $_SESSION['tabel'] ?? null;
$input = json_decode(file_get_contents('php://input'), true);

$task_id = trim($input['task_id'] ?? '');
$comment_text = trim($input['comment_text'] ?? '');
$product = trim($input['product'] ?? '');
$status = trim($input['status'] ?? 'think');
$next_call_date = trim($input['next_call_date'] ?? '');
$pain_point = trim($input['pain_point'] ?? '');
$objection = trim($input['objection'] ?? '');
$objection_text = trim($input['objection_text'] ?? '');
$next_step = trim($input['next_step'] ?? '');
$decision_maker = trim($input['decision_maker'] ?? '');
$free_comment = trim($input['free_comment'] ?? '');

if (!$task_id || !$comment_text) {
    echo json_encode(['error' => 'Нет данных']);
    exit;
}

// ========== ФРОД-СКОР (0-100) ==========
$fraud_score = 0;

// 1. Длина комментария (0-20 баллов)
$len = mb_strlen($comment_text);
if ($len > 300) $fraud_score += 20;
elseif ($len > 150) $fraud_score += 15;
elseif ($len > 80) $fraud_score += 10;
else $fraud_score += 5;

// 2. Заполнены все обязательные поля (0-20 баллов)
$filled = 0;
if ($pain_point) $filled++;
if ($objection) $filled++;
if ($next_step) $filled++;
if ($decision_maker) $filled++;
$fraud_score += ($filled / 4) * 20;

// 3. Детализация возражения (0-15 баллов)
if ($objection_text && mb_strlen($objection_text) > 30) $fraud_score += 15;
elseif ($objection_text) $fraud_score += 8;

// 4. Конкретность договорённостей (0-15 баллов)
if (mb_strlen($next_step) > 40) $fraud_score += 15;
elseif (mb_strlen($next_step) > 20) $fraud_score += 10;
else $fraud_score += 5;

// 5. Наличие даты следующего контакта (0-10 баллов)
if ($next_call_date) $fraud_score += 10;

// 6. Свободный комментарий (0-10 баллов)
if ($free_comment && mb_strlen($free_comment) > 20) $fraud_score += 10;
elseif ($free_comment) $fraud_score += 5;

// 7. Проверка на паттерны фрода (штраф)
$lower = mb_strtolower($comment_text);

// Односложные комментарии
if (preg_match('/^(да|нет|ок|понятно|ясно|хорошо|спасибо|в работе|дозвон|не ответил|недоступен)[\.!]*$/iu', $comment_text)) {
    $fraud_score -= 30;
}

// Копипаст — проверяем последние 3 комментария этого менеджера
$stmt = $pdo->prepare("SELECT comment_text FROM call_comments WHERE user_id = ? ORDER BY created_at DESC LIMIT 3");
$stmt->execute([$user_id]);
$recent = $stmt->fetchAll(PDO::FETCH_COLUMN);
foreach ($recent as $r) {
    similar_text($comment_text, $r, $sim);
    if ($sim > 80) {
        $fraud_score -= 25;
        break;
    }
}

// Подозрительные фразы
$suspicious = ['не помню', 'звонил', 'не дозвонился', 'не отвечает', 'занят', 'перезвоню', 'позже'];
$susp_count = 0;
foreach ($suspicious as $s) {
    if (mb_stripos($lower, $s) !== false) $susp_count++;
}
if ($susp_count >= 3) $fraud_score -= 15;

// Ограничиваем 0-100
$fraud_score = max(0, min(100, round($fraud_score)));

// ========== РЕШЕНИЕ О РОП-КОНТРОЛЕ ==========
$rop_control = ($fraud_score < 60);
$new_status = $rop_control ? 'На контроле РОП' : 'Подтверждена';

// ========== РАСЧЁТ ГОТОВНОСТИ СДЕЛКИ ==========
$readiness = 0;
if ($status === 'signed') $readiness = 100;
elseif ($status === 'reject') $readiness = 0;
else {
    $keywords = [
        'инн' => 10, 'телефон' => 10, 'email' => 10,
        'договор' => 25, 'подписать' => 25, 'согласен' => 25,
        'сбербизнес' => 10, '1с' => 10, 'кп' => 15,
        'встреча' => 15, 'презентация' => 15, 'демо' => 15
    ];
    foreach ($keywords as $word => $points) {
        if (mb_stripos($lower, $word) !== false) $readiness += $points;
    }
    $readiness = min(100, $readiness);
}

// ========== СОХРАНЕНИЕ ==========
try {
    $pdo->beginTransaction();

    // Сохраняем комментарий
    $stmt = $pdo->prepare("
        INSERT INTO call_comments 
        (task_id, user_id, comment_text, call_result, next_call_date, deal_readiness, created_at)
        VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
    ");
    $stmt->execute([$task_id, $user_id, $comment_text, $status, $next_call_date, $readiness]);

    // Обновляем статус задачи
    $stmt = $pdo->prepare("
        UPDATE epk_tasks 
        SET status = ?, next_call_date = ?, updated_at = datetime('now')
        WHERE task_id = ?
    ");
    $stmt->execute([$new_status, $next_call_date, $task_id]);

    // Если РОП-контроль — добавляем в таблицу контроля
    if ($rop_control) {
        $stmt = $pdo->prepare("
            INSERT INTO rop_control_queue 
            (task_id, user_id, tabel, fraud_score, comment_text, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'На проверке', datetime('now'))
        ");
        $stmt->execute([$task_id, $user_id, $tabel, $fraud_score, $comment_text]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'fraud_score' => $fraud_score,
        'rop_control' => $rop_control,
        'readiness' => $readiness,
        'next_call_date' => $next_call_date
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['error' => 'Ошибка сохранения: ' . $e->getMessage()]);
}
