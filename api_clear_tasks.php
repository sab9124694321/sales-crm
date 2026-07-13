<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Не авторизован']);
    exit;
}

require_once 'db.php';

// Получаем список task_ids из POST-запроса
$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['task_ids']) || !is_array($input['task_ids'])) {
    echo json_encode(['success' => false, 'error' => 'Нет задач для удаления']);
    exit;
}

$taskIds = array_filter($input['task_ids'], function($id) { return !empty(trim($id)); });
if (empty($taskIds)) {
    echo json_encode(['success' => false, 'error' => 'Пустой список задач']);
    exit;
}

// Дополнительная проверка: удаляем только задачи, принадлежащие текущему пользователю
$placeholders = implode(',', array_fill(0, count($taskIds), '?'));
$stmt = $pdo->prepare("DELETE FROM epk_tasks WHERE task_id IN ($placeholders) AND user_tabel = ?");
$params = array_merge($taskIds, [$_SESSION['tabel']]);
$stmt->execute($params);

$deletedCount = $stmt->rowCount();

// Логируем действие (для аудита)
error_log("Удалены задачи (user_tabel={$_SESSION['tabel']}, user_id={$_SESSION['user_id']}): " . implode(', ', $taskIds));

echo json_encode([
    'success' => true,
    'deleted' => $deletedCount,
    'requested' => count($taskIds)
]);