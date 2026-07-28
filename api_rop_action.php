<?php
session_start();
header('Content-Type: application/json');
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Не авторизован']);
    exit;
}

$role = $_SESSION['role'];
$allowed_roles = ['head', 'territory_head', 'admin', 'ubr_middle'];
if (!in_array($role, $allowed_roles)) {
    echo json_encode(['error' => 'Нет прав']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$control_id = (int)($input['control_id'] ?? 0);
$action = trim($input['action'] ?? '');
$comment = trim($input['comment'] ?? '');

$valid_actions = ['confirm', 'reject', 'recall', 'confirm_reject'];
if (!$control_id || !in_array($action, $valid_actions)) {
    echo json_encode(['error' => 'Неверные данные']);
    exit;
}

if (($action === 'reject' || $action === 'recall' || $action === 'confirm_reject') && !$comment) {
    echo json_encode(['error' => 'Комментарий обязателен']);
    exit;
}

$status_map = [
    'confirm' => 'Подтверждено',
    'reject' => 'Отклонено',
    'recall' => 'Перепрозвон',
    'confirm_reject' => 'Отказ подтверждён'
];
$status = $status_map[$action];

try {
    $pdo->beginTransaction();

    // Обновляем запись в очереди контроля
    $stmt = $pdo->prepare("
        UPDATE rop_control_queue 
        SET status = ?, rop_comment = ?, rop_action = ?, checked_at = datetime('now')
        WHERE id = ?
    ");
    $stmt->execute([$status, $comment, $action, $control_id]);

    // Получаем task_id, top_status и статус задачи из epk_tasks
    $stmt = $pdo->prepare("
        SELECT rcq.task_id, rcq.top_status, et.status as current_task_status
        FROM rop_control_queue rcq
        LEFT JOIN epk_tasks et ON rcq.task_id = et.task_id
        WHERE rcq.id = ?
    ");
    $stmt->execute([$control_id]);
    $queue_item = $stmt->fetch(PDO::FETCH_ASSOC);
    $task_id = $queue_item['task_id'] ?? null;
    $current_top = $queue_item['top_status'] ?? 'active';
    $current_status = $queue_item['current_task_status'] ?? '';

    if ($task_id) {
        $new_task_status = '';
        $new_top_status = '';

        if ($action === 'confirm') {
            // Если это задача "Нет контакта" – финализируем её как успешную
            if ($current_top === 'nocontact') {
                $new_task_status = 'Согласен';
                $new_top_status = 'closed';
            } else {
                // Для остальных – оставляем как было
                $new_task_status = 'Подтверждена';
                $new_top_status = $current_top; // сохраняем
            }
        } elseif ($action === 'reject') {
            $new_task_status = 'Назначена';
            $new_top_status = 'active';
        } elseif ($action === 'recall') {
            $new_task_status = 'На контроле РОП';
            $new_top_status = 'active';
        } elseif ($action === 'confirm_reject') {
            $new_task_status = 'Отказ подтверждён';
            $new_top_status = 'rejected_confirmed';
        }

        $stmt = $pdo->prepare("
            UPDATE epk_tasks 
            SET status = ?, top_status = ?, updated_at = datetime('now')
            WHERE task_id = ?
        ");
        $stmt->execute([$new_task_status, $new_top_status, $task_id]);
    }

    $pdo->commit();
    echo json_encode([
        'success' => true, 
        'status' => $status,
        'task_status' => $new_task_status ?? null,
        'top_status' => $new_top_status ?? null
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['error' => 'Ошибка: ' . $e->getMessage()]);
}