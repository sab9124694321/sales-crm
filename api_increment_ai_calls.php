<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Auth']);
    exit;
}

require_once 'db.php';

$user_id = $_SESSION['user_id'];
$today = date('Y-m-d');

// Увеличиваем ai_calls (если записи нет, создаём через INSERT OR IGNORE, затем UPDATE)
$pdo->prepare("INSERT OR IGNORE INTO daily_reports (user_id, tabel_number, report_date, ai_calls) VALUES (?, ?, ?, 0)")
   ->execute([$user_id, $_SESSION['tabel'], $today]);

$pdo->prepare("UPDATE daily_reports SET ai_calls = ai_calls + 1 WHERE user_id = ? AND report_date = ?")
   ->execute([$user_id, $today]);

echo json_encode(['success' => true]);
