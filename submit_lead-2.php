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

$hunter_id = $_SESSION['hunter_id'];

$client_name = trim($_POST['client_name'] ?? '');
$inn = trim($_POST['inn'] ?? '');
$contact_name = trim($_POST['contact_name'] ?? '');
$contact_phone = trim($_POST['contact_phone'] ?? '');
$client_email = trim($_POST['client_email'] ?? '');
$address = trim($_POST['address'] ?? '');
$consent = isset($_POST['consent']);

// Валидация
if (empty($client_name) || empty($inn) || empty($contact_name) || empty($contact_phone)) {
    $_SESSION['lead_error'] = 'Заполните все обязательные поля';
    header('Location: hunter_dashboard.php');
    exit;
}

if (!$consent) {
    $_SESSION['lead_error'] = 'Необходимо подтвердить получение согласия на передачу данных';
    header('Location: hunter_dashboard.php');
    exit;
}

if (!preg_match('/^\d{10}$|^\d{12}$/', $inn)) {
    $_SESSION['lead_error'] = 'ИНН должен содержать ровно 10 или 12 цифр';
    header('Location: hunter_dashboard.php');
    exit;
}

// Проверяем, не существует ли уже такой ИНН
$stmt = $pdo->prepare("SELECT id FROM hunter_leads WHERE inn = ? LIMIT 1");
$stmt->execute([$inn]);
if ($stmt->fetch()) {
    $_SESSION['lead_error'] = 'Лид с таким ИНН уже существует в системе';
    header('Location: hunter_dashboard.php');
    exit;
}

// Сохраняем лид
$stmt = $pdo->prepare("INSERT INTO hunter_leads (hunter_id, client_name, client_phone, client_email, inn, address, contact_name, contact_phone, status, bonus_points, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 50, datetime('now'))");
$stmt->execute([$hunter_id, $client_name, $contact_phone, $client_email, $inn, $address, $contact_name, $contact_phone]);

// Начисляем баллы охотнику
$pdo->prepare("UPDATE hunters SET points = points + 50 WHERE id = ?")->execute([$hunter_id]);

// Уведомление охотнику
$pdo->prepare("INSERT INTO hunter_notifications (hunter_id, message, type) VALUES (?, ?, 'success')")
    ->execute([$hunter_id, 'Лид ' . $client_name . ' добавлен! +50 XP. Ждем проверки менеджером.']);

$_SESSION['lead_success'] = 'Лид успешно добавлен! +50 XP начислено';
header('Location: hunter_dashboard.php');
exit;
