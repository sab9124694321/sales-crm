<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Не авторизован']);
    exit;
}

require_once '../db.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['name'];

$stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = (SELECT manager_id FROM users WHERE id = ?)");
$stmt->execute([$user_id]);
$manager_name = $stmt->fetchColumn();

$data = json_decode(file_get_contents('php://input'), true);
$inn = trim($data['inn'] ?? '');
$product = $data['product'] ?? '';
$field_type = $data['field_type'] ?? 'reg';
$report_date = date('Y-m-d');

if (empty($inn) || empty($product)) {
    echo json_encode(['success' => false, 'error' => 'ИНН и продукт обязательны']);
    exit;
}

try {
    $stmt = $pdo->prepare("INSERT INTO inn_records (inn, product, employee_name, manager_name, report_date) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$inn, $product, $user_name, $manager_name, $report_date]);

    $stmt = $pdo->prepare("SELECT id FROM daily_reports WHERE user_id = ? AND report_date = ?");
    $stmt->execute([$user_id, $report_date]);

    if ($stmt->fetch()) {
        switch ($field_type) {
            case 'reg': $pdo->exec("UPDATE daily_reports SET registrations = registrations + 1 WHERE user_id = $user_id AND report_date = '$report_date'"); break;
            case 'smart': $pdo->exec("UPDATE daily_reports SET smart_cash = smart_cash + 1 WHERE user_id = $user_id AND report_date = '$report_date'"); break;
            case 'pos': $pdo->exec("UPDATE daily_reports SET pos_systems = pos_systems + 1 WHERE user_id = $user_id AND report_date = '$report_date'"); break;
            case 'tea': $pdo->exec("UPDATE daily_reports SET inn_leads = inn_leads + 1 WHERE user_id = $user_id AND report_date = '$report_date'"); break;
        }
    } else {
        $r = ($field_type == 'reg') ? 1 : 0;
        $s = ($field_type == 'smart') ? 1 : 0;
        $p = ($field_type == 'pos') ? 1 : 0;
        $t = ($field_type == 'tea') ? 1 : 0;
        $stmt = $pdo->prepare("INSERT INTO daily_reports (user_id, report_date, registrations, smart_cash, pos_systems, inn_leads) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $report_date, $r, $s, $p, $t]);
    }

    echo json_encode(['success' => true, 'message' => 'ИНН добавлен']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
