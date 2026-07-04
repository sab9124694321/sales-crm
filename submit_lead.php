<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['hunter_id'])) {
    header('Location: hunter_login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: hunter_dashboard.php');
    exit;
}

$client_name = trim($_POST['client_name'] ?? '');
$inn = trim($_POST['inn'] ?? '');
$contact_name = trim($_POST['contact_name'] ?? '');
$contact_phone = trim($_POST['contact_phone'] ?? '');
$client_email = trim($_POST['client_email'] ?? '');
$address = trim($_POST['address'] ?? '');
$comment = trim($_POST['comment'] ?? '');
$shop_phone = trim($_POST['shop_phone'] ?? '');
$client_phone = trim($_POST['client_phone'] ?? $shop_phone ?? $contact_phone ?? '');

// Валидация обязательных полей
if (empty($client_name) || empty($inn) || empty($contact_name) || empty($contact_phone) || empty($client_phone)) {
    $_SESSION['lead_error'] = 'Заполните все обязательные поля';
    header('Location: hunter_dashboard.php');
    exit;
}

// Проверка формата ИНН
if (!preg_match('/^\d{10}$|^\d{12}$/', $inn)) {
    $_SESSION['lead_error'] = 'ИНН должен содержать ровно 10 или 12 цифр';
    header('Location: hunter_dashboard.php');
    exit;
}

// Проверка: ИНН уже есть в hunter_leads
$stmt = $pdo->prepare("SELECT id FROM hunter_leads WHERE inn = ?");
$stmt->execute([$inn]);
if ($stmt->fetch()) {
    $_SESSION['lead_error'] = 'Заведение с таким ИНН уже есть в базе охотников';
    header('Location: hunter_dashboard.php');
    exit;
}

// Проверка: ИНН уже есть в основной базе клиентов
$stmt = $pdo->prepare("SELECT id FROM clients WHERE inn = ? LIMIT 1");
$stmt->execute([$inn]);
if ($stmt->fetch()) {
    $_SESSION['lead_error'] = 'Заведение с таким ИНН уже работает с нами';
    header('Location: hunter_dashboard.php');
    exit;
}

// Проверка: ИНН уже есть в inn_records
$stmt = $pdo->prepare("SELECT id FROM inn_records WHERE inn = ? LIMIT 1");
$stmt->execute([$inn]);
if ($stmt->fetch()) {
    $_SESSION['lead_error'] = 'Заведение с таким ИНН уже есть в базе';
    header('Location: hunter_dashboard.php');
    exit;
}

$hunter_id = $_SESSION['hunter_id'];

// Добавляем лид
$stmt = $pdo->prepare("INSERT INTO hunter_leads (hunter_id, client_name, client_phone, inn, contact_name, contact_phone, client_email, address, comment, shop_phone, status, bonus_points, converted_bonus, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', 0, 50, datetime('now'))");
$stmt->execute([$hunter_id, $client_name, $client_phone, $inn, $contact_name, $contact_phone, $client_email, $address, $comment, $shop_phone]);

// Начисляем 50 XP за отправку
$stmt = $pdo->prepare("UPDATE hunters SET points = COALESCE(points, 0) + 50 WHERE id = ?");
$stmt->execute([$hunter_id]);

// Уведомление охотнику
$message = 'Лид "' . $client_name . '" отправлен! +50 XP. Ждем проверки менеджером.';
$stmt = $pdo->prepare("INSERT INTO hunter_notifications (hunter_id, message, type, is_read, created_at) VALUES (?, ?, 'info', 0, datetime('now'))");
$stmt->execute([$hunter_id, $message]);

$_SESSION['lead_success'] = 'Лид успешно добавлен! +50 XP начислено';
header('Location: hunter_dashboard.php');
exit;
