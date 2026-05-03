<?php
session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
require_once '../db.php';
$stmt = $pdo->prepare("INSERT INTO inn_records (inn_value, product_type, user_id, user_name, report_date) VALUES (?, ?, ?, ?, date('now'))");
$stmt->execute([$_POST['inn'], $_POST['product_type'], $_SESSION['user_id'], $_SESSION['name']]);
echo json_encode(['success' => true]);
?>
