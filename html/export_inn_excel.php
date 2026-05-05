<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    die('Доступ запрещён');
}

require_once 'db.php';

$role = $_SESSION['role'] ?? 'employee';
$user_name = $_SESSION['name'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Получаем записи
if ($role == 'admin') {
    $stmt = $pdo->prepare("SELECT * FROM inn_records WHERE report_date BETWEEN :from AND :to ORDER BY report_date DESC");
    $stmt->execute(['from' => $date_from, 'to' => $date_to]);
} elseif ($role == 'manager') {
    $stmt = $pdo->prepare("SELECT * FROM inn_records WHERE manager_name = :name AND report_date BETWEEN :from AND :to ORDER BY report_date DESC");
    $stmt->execute(['name' => $user_name, 'from' => $date_from, 'to' => $date_to]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM inn_records WHERE employee_name = :name AND report_date BETWEEN :from AND :to ORDER BY report_date DESC");
    $stmt->execute(['name' => $user_name, 'from' => $date_from, 'to' => $date_to]);
}

$records = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="inn_export_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Дата', 'ИНН', 'Продукт', 'Сотрудник', 'Руководитель']);

foreach ($records as $record) {
    fputcsv($output, [
        $record['report_date'],
        $record['inn'],
        $record['product'],
        $record['employee_name'],
        $record['manager_name'] ?: '-'
    ]);
}

fclose($output);
