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
if (!$lead_id) {
    echo json_encode(['success' => false, 'error' => 'Не указан лид']);
    exit;
}

$manager_id = $_SESSION['user_id'];

try {
    // Проверяем, что лид свободен
    $stmt = $pdo->prepare("SELECT status FROM hunter_leads WHERE id = ?");
    $stmt->execute([$lead_id]);
    $lead = $stmt->fetch();
    if (!$lead || $lead['status'] !== 'new') {
        throw new Exception('Лид уже занят или не существует');
    }
    // Обновляем, только если статус всё ещё 'new'
    $stmt = $pdo->prepare("UPDATE hunter_leads SET manager_id = ?, status = 'assigned' WHERE id = ? AND status = 'new'");
    $stmt->execute([$manager_id, $lead_id]);
    if ($stmt->rowCount() === 0) {
        throw new Exception('Не удалось занять лид (возможно, его уже кто-то взял)');
    }
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
