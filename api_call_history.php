<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['history' => []]);
    exit;
}

require_once 'db.php';

$task_id = $_GET['task_id'] ?? '';
if (empty($task_id)) {
    echo json_encode(['history' => []]);
    exit;
}

// История комментариев
$stmt = $pdo->prepare("
    SELECT cc.*, u.full_name as manager_name 
    FROM call_comments cc
    LEFT JOIN users u ON cc.user_id = u.id
    WHERE cc.task_id = ? 
    ORDER BY cc.created_at DESC
");
$stmt->execute([$task_id]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Общее количество звонков по задаче
$stmt = $pdo->prepare("SELECT call_count FROM epk_tasks WHERE task_id = ?");
$stmt->execute([$task_id]);
$task_info = $stmt->fetch(PDO::FETCH_ASSOC);

// Текущий top_status задачи
$stmt = $pdo->prepare("SELECT top_status, first_status_at, status FROM epk_tasks WHERE task_id = ?");
$stmt->execute([$task_id]);
$task_status = $stmt->fetch(PDO::FETCH_ASSOC);

// Расчёт времени в статусе "думает"
$think_time = null;
if ($task_status && $task_status['first_status_at'] && $task_status['status'] === 'Думает') {
    $first = new DateTime($task_status['first_status_at']);
    $now = new DateTime();
    $diff = $first->diff($now);
    $think_time = [
        'days' => $diff->d,
        'hours' => $diff->h,
        'minutes' => $diff->i,
        'formatted' => $diff->format('%d дн %h ч %i мин')
    ];
}

echo json_encode([
    'history' => $history,
    'total_calls' => (int)($task_info['call_count'] ?? 0),
    'top_status' => $task_status['top_status'] ?? 'active',
    'task_status' => $task_status['status'] ?? 'Назначена',
    'first_status_at' => $task_status['first_status_at'] ?? null,
    'think_time' => $think_time
]);
