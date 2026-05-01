<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once 'db.php';

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    INSERT INTO daily_reports (user_id, report_date, calls, calls_answered, meetings, contracts, registrations, smart_cash, pos_systems, inn_leads, teams, turnover)
    VALUES (?, CURRENT_DATE, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    $user_id,
    intval($_POST['calls'] ?? 0),
    intval($_POST['calls_answered'] ?? 0),
    intval($_POST['meetings'] ?? 0),
    intval($_POST['contracts'] ?? 0),
    intval($_POST['registrations'] ?? 0),
    intval($_POST['smart_cash'] ?? 0),
    intval($_POST['pos_systems'] ?? 0),
    intval($_POST['inn_leads'] ?? 0),
    intval($_POST['teams'] ?? 0),
    floatval($_POST['turnover'] ?? 0)
]);

header('Location: dashboard.php?success=1');
?>
