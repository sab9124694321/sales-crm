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

if (!$control_id || !in_array($action, ['confirm', 'reject', 'recall'])) {
    echo json_encode(['error' => 'Неверные данные']);
    exit;
}

if (($action === 'reject' || $action === 'recall') && !$comment) {
    echo json_encode(['error' => 'Комментарий обязателен']);
    exit;
}

$status_map = [
    'confirm' => 'Подтверждено',
    'reject' => 'Отклонено',
    'recall' => 'Перепрозвон'
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

    // Получаем task_id для обновления статуса задачи
    $stmt = $pdo->prepare("SELECT task_id FROM rop_control_queue WHERE id = ?");
    $stmt->execute([$control_id]);
    $task_id = $stmt->fetchColumn();

    if ($task_id) {
        // Обновляем статус задачи
        if ($action === 'confirm') {
            $new_task_status = 'Подтверждена';
        } elseif ($action === 'reject') {
            $new_task_status = 'Назначена'; // Возвращаем в пул
        } else { // recall
            $new_task_status = 'На контроле РОП';
        }

        $stmt = $pdo->prepare("
            UPDATE epk_tasks 
            SET status = ?, updated_at = datetime('now')
            WHERE task_id = ?
        ");
        $stmt->execute([$new_task_status, $task_id]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'status' => $status]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['error' => 'Ошибка: ' . $e->getMessage()]);
}
