<?php
session_start();
if (!isset($_SESSION['user_id'])) { 
    http_response_code(401);
    die(json_encode(['error' => 'Не авторизован']));
}
require_once 'db.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    http_response_code(400);
    die(json_encode(['error' => 'ID не указан']));
}

$stmt = $pdo->prepare("SELECT * FROM tips_rollout WHERE id = ?");
$stmt->execute([$id]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if ($record) {
    header('Content-Type: application/json');
    echo json_encode($record);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Запись не найдена']);
}