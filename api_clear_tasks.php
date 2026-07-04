<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Auth']);
    exit;
}

require_once 'db.php';

$tabel = $_SESSION['tabel'];
$stmt = $pdo->prepare("DELETE FROM epk_tasks WHERE user_tabel = ?");
$stmt->execute([$tabel]);

echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
