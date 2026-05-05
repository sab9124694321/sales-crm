<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Не авторизован']);
    exit;
}

require_once 'db.php';

// Получаем данные пользователя
$stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$employee_name = $stmt->fetchColumn();

// Получаем руководителя (если есть)
$manager_name = '';
if (isset($_SESSION['manager_id']) && $_SESSION['manager_id']) {
    $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['manager_id']]);
    $manager_name = $stmt->fetchColumn();
}

// Получаем данные из запроса
$data = json_decode(file_get_contents('php://input'), true);
$inn = $data['inn'] ?? '';
$product = $data['product'] ?? '';
$report_date = $data['report_date'] ?? date('Y-m-d');

if (empty($inn) || empty($product)) {
    http_response_code(400);
    echo json_encode(['error' => 'ИНН и продукт обязательны']);
    exit;
}

// Сохраняем запись
$stmt = $pdo->prepare("
    INSERT INTO inn_records (inn, product, employee_name, manager_name, report_date)
    VALUES (:inn, :product, :employee_name, :manager_name, :report_date)
");

$result = $stmt->execute([
    ':inn' => $inn,
    ':product' => $product,
    ':employee_name' => $employee_name,
    ':manager_name' => $manager_name,
    ':report_date' => $report_date
]);

if ($result) {
    echo json_encode(['success' => true, 'message' => 'ИНН сохранён']);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка сохранения']);
}
