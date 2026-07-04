<?php
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
require_once 'db.php';

$tabel = $_POST['tabel'] ?? '';
$date = $_POST['date'] ?? '';
$calls = (int)($_POST['calls'] ?? 0);

if (!$tabel || !$date) {
    echo json_encode(['success' => false, 'error' => 'Missing params']);
    exit;
}

$stmt = $pdo->prepare("SELECT id FROM daily_forecasts WHERE tabel_number = ? AND forecast_date = ?");
$stmt->execute([$tabel, $date]);
$exists = $stmt->fetch();

if ($exists) {
    $stmt = $pdo->prepare("UPDATE daily_forecasts SET expected_calls = ? WHERE tabel_number = ? AND forecast_date = ?");
    $stmt->execute([$calls, $tabel, $date]);
} else {
    $stmt = $pdo->prepare("INSERT INTO daily_forecasts (tabel_number, forecast_date, expected_calls) VALUES (?, ?, ?)");
    $stmt->execute([$tabel, $date, $calls]);
}

echo json_encode(['success' => true]);
