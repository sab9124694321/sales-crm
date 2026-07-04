<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Auth required']);
    exit;
}

require_once 'db.php';

$tabel = $_SESSION['tabel'];

if (isset($_GET['id'])) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_tabel = ?");
    $stmt->execute([$_GET['id'], $tabel]);
} else {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_tabel = ? AND is_read = 0");
    $stmt->execute([$tabel]);
}

echo json_encode(['success' => true]);
