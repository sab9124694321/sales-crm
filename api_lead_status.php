<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещён']);
    exit;
}

require_once 'db.php';

$input = json_decode(file_get_contents('php://input'), true);
$lead_id = (int)($input['lead_id'] ?? 0);
$status = $input['status'] ?? '';
$comment = $input['comment'] ?? '';

if (!$lead_id || !in_array($status, ['converted', 'rejected'])) {
    echo json_encode(['success' => false, 'error' => 'Некорректные данные']);
    exit;
}

$manager_id = $_SESSION['user_id'];

try {
    // Проверяем, что лид принадлежит этому менеджеру и имеет статус assigned
    $stmt = $pdo->prepare("SELECT hunter_id, status FROM hunter_leads WHERE id = ? AND manager_id = ?");
    $stmt->execute([$lead_id, $manager_id]);
    $lead = $stmt->fetch();
    if (!$lead || $lead['status'] !== 'assigned') {
        throw new Exception('Лид не найден или не в работе');
    }
    $hunter_id = $lead['hunter_id'];

    if ($status === 'converted') {
        // Успех: начисляем бонус
        $stmt = $pdo->prepare("UPDATE hunter_leads SET status = 'converted', converted_bonus = 50 WHERE id = ?");
        $stmt->execute([$lead_id]);
        $stmt = $pdo->prepare("UPDATE hunters SET points = points + 50 WHERE id = ?");
        $stmt->execute([$hunter_id]);
    } else {
        // Неудача: сохраняем комментарий
        $stmt = $pdo->prepare("UPDATE hunter_leads SET status = 'rejected', comment = ? WHERE id = ?");
        $stmt->execute([$comment, $lead_id]);
    }
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
