<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once 'db.php';
require_once 'gamification.php';

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action == 'add_registration') {
    // Добавляем очки за новую регистрацию
    $points = 2; // 2 очка за регистрацию
    addRankPoints($pdo, $user_id, $points, 'Новая регистрация');
    
    echo json_encode(['success' => true, 'message' => 'Получено +2 очка!']);
    exit;
}

if ($action == 'check_notifications') {
    $notifications = getUnreadNotifications($pdo, $user_id);
    echo json_encode(['notifications' => $notifications]);
    exit;
}

if ($action == 'mark_read') {
    markNotificationsAsRead($pdo, $user_id);
    echo json_encode(['success' => true]);
    exit;
}
?>
