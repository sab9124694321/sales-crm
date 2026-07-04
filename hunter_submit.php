<?php
session_start();
if (!isset($_SESSION['hunter_id'])) {
    header('Location: hunter_login.php');
    exit;
}
require_once 'db.php';

$hunter_id = $_SESSION['hunter_id'];
$inn = trim($_POST['inn'] ?? '');
$address = trim($_POST['address'] ?? '');
$shop_phone = trim($_POST['shop_phone'] ?? '');
$contact_name = trim($_POST['contact_name'] ?? '');

if (!$inn || !$address || !$contact_name) {
    $_SESSION['hunter_error'] = 'Заполните ИНН, адрес и контактное лицо';
    header('Location: hunter_dashboard.php');
    exit;
}

$stmt = $pdo->prepare("INSERT INTO hunter_leads (hunter_id, inn, address, shop_phone, contact_name, status, bonus_points) VALUES (?, ?, ?, ?, ?, 'new', 10)");
$stmt->execute([$hunter_id, $inn, $address, $shop_phone, $contact_name]);

$pdo->prepare("UPDATE hunters SET points = points + 10 WHERE id = ?")->execute([$hunter_id]);

$_SESSION['hunter_success'] = '✅ Лид отправлен! +10 баллов.';
header('Location: hunter_dashboard.php');
exit;
