<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}
require_once '../db.php';
require_once '../gamification.php';
$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';
if ($action == 'mark_read') {
    markNotificationsAsRead($pdo, $user_id);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['error' => 'Invalid action']);
}
?>
