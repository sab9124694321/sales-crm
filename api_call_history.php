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

$stmt = $pdo->prepare("SELECT * FROM call_comments WHERE task_id = ? ORDER BY created_at DESC");
$stmt->execute([$task_id]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['history' => $history]);
