<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Не авторизован']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$lead_id = $data['lead_id'] ?? null;
$status = $data['status'] ?? null;
$comment = $data['comment'] ?? null;

if (!$lead_id || !$status) {
    echo json_encode(['success' => false, 'error' => 'Недостаточно данных']);
    exit;
}

// Получаем данные лида
$stmt = $pdo->prepare("SELECT * FROM hunter_leads WHERE id = ?");
$stmt->execute([$lead_id]);
$lead = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lead) {
    echo json_encode(['success' => false, 'error' => 'Лид не найден']);
    exit;
}

$user_id = $_SESSION['user_id'];

if ($status === 'converted') {
    // По контракту: +200 XP за одобрение
    $bonus = 200;
    $converted_total = 50 + $bonus; // 50 за отправку + 200 за одобрение

    $stmt = $pdo->prepare("UPDATE hunter_leads SET status = 'converted', manager_id = ?, bonus_points = ?, converted_bonus = ?, updated_at = datetime('now') WHERE id = ?");
    $stmt->execute([$user_id, $bonus, $converted_total, $lead_id]);

    // Начисляем 200 XP охотнику
    $stmt = $pdo->prepare("UPDATE hunters SET points = COALESCE(points, 0) + ? WHERE id = ?");
    $stmt->execute([$bonus, $lead['hunter_id']]);

    // Уведомление охотнику
    $message = 'Ваш лид "' . $lead['client_name'] . '" подтвержден! +' . $bonus . ' XP начислено!';
    $stmt = $pdo->prepare("INSERT INTO hunter_notifications (hunter_id, message, type, is_read, created_at) VALUES (?, ?, 'success', 0, datetime('now'))");
    $stmt->execute([$lead['hunter_id'], $message]);

    echo json_encode(['success' => true]);

} elseif ($status === 'rejected') {
    $stmt = $pdo->prepare("UPDATE hunter_leads SET status = 'rejected', manager_id = ?, comment = ?, updated_at = datetime('now') WHERE id = ?");
    $stmt->execute([$user_id, $comment, $lead_id]);

    // Уведомление об отказе
    $message = 'Ваш лид "' . $lead['client_name'] . '" не подтвержден. Причина: ' . ($comment ?: 'не указана');
    $stmt = $pdo->prepare("INSERT INTO hunter_notifications (hunter_id, message, type, is_read, created_at) VALUES (?, ?, 'error', 0, datetime('now'))");
    $stmt->execute([$lead['hunter_id'], $message]);

    echo json_encode(['success' => true]);

} elseif ($status === 'assigned') {
    $stmt = $pdo->prepare("UPDATE hunter_leads SET status = 'assigned', manager_id = ?, updated_at = datetime('now') WHERE id = ?");
    $stmt->execute([$user_id, $lead_id]);

    echo json_encode(['success' => true]);

} else {
    echo json_encode(['success' => false, 'error' => 'Неизвестный статус']);
}
