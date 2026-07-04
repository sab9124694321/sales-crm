<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Auth']);
    exit;
}

require_once 'db.php';

$input = json_decode(file_get_contents('php://input'), true);
$task_ids = $input['task_ids'] ?? [];

if (empty($task_ids)) {
    echo json_encode(['success' => false, 'error' => 'Empty task list']);
    exit;
}

$tabel = $_SESSION['tabel'];
$added = 0;
$skipped = 0;

$stmt = $pdo->prepare("
    INSERT OR IGNORE INTO epk_tasks (task_id, user_tabel, status, product, imported_at)
    VALUES (?, ?, 'Назначена', 'Торговый эквайринг', datetime('now'))
");

foreach ($task_ids as $task_id) {
    $task_id = strtolower(trim($task_id));
    if (empty($task_id)) continue;

    $stmt->execute([$task_id, $tabel]);
    if ($stmt->rowCount() > 0) {
        $added++;
    } else {
        $skipped++;
    }
}

echo json_encode(['success' => true, 'added' => $added, 'skipped' => $skipped]);
