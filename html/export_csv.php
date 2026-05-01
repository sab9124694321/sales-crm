<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'employee') {
    header('Location: login.php');
    exit;
}
require_once 'db.php';

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Получаем сотрудников
if ($role == 'admin') {
    $employees = $pdo->query("SELECT id, full_name, tabel_number FROM users WHERE role != 'admin'")->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT id, full_name, tabel_number FROM users WHERE manager_id = ? OR id = ?");
    $stmt->execute([$user_id, $user_id]);
    $employees = $stmt->fetchAll();
}

// Фильтры
$employee_id = $_GET['employee_id'] ?? ($employees[0]['id'] ?? 0);
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Получаем отчёты
$stmt = $pdo->prepare("
    SELECT * FROM daily_reports 
    WHERE user_id = ? AND report_date BETWEEN ? AND ? 
    ORDER BY report_date DESC
");
$stmt->execute([$employee_id, $start_date, $end_date]);
$reports = $stmt->fetchAll();

// Экспорт в CSV
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Дата', 'Звонки', 'Дозвоны', 'Встречи', 'Договоры', 'Регистрации', 'Смарт-кассы', 'ПОС-системы', 'ИНН чаевые', 'Команды чаевые', 'Оборот чаевых']);
    
    foreach ($reports as $r) {
        fputcsv($output, [
            $r['report_date'],
            $r['calls'],
            $r['calls_answered'],
            $r['meetings'],
            $r['contracts'],
            $r['registrations'],
            $r['smart_cash'],
            $r['pos_systems'],
            $r['inn_leads'],
            $r['teams'],
            $r['turnover']
        ]);
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Экспорт отчётов</title>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; background: #f0f2f5; margin: 0; }
        .header { background: #1a2c3e; color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .header h1 { color: #00a36c; }
        .container { max-width: 1200px; margin: 0 auto; padding: 30px 20px; }
        .card { background: white; border-radius: 12px; padding: 25px; margin-bottom: 20px; }
        .filters { display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 20px; }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-size: 12px; color: #666; margin-bottom: 5px; }
        select, input { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; }
        button { background: #00a36c; color: white; padding: 8px 20px; border: none; border-radius: 6px; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; }
        .nav a { color: #00a36c; text-decoration: none; margin-right: 15px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Sales CRM</h1>
        <div class="nav">
            <a href="dashboard.php">Дашборд</a>
            <a href="team.php">Команда</a>
            <a href="admin.php">Админ</a>
            <a href="export_csv.php">Экспорт</a>
            <a href="logout.php">Выйти</a>
        </div>
    </div>
    <div class="container">
        <div class="card">
            <h2>📊 Экспорт отчётов в CSV</h2>
            <form method="get" class="filters">
                <div class="filter-group">
                    <label>Сотрудник</label>
                    <select name="employee_id">
                        <?php foreach ($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>" <?= $employee_id == $emp['id'] ? 'selected' : '' ?>><?= htmlspecialchars($emp['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Дата от</label>
                    <input type="date" name="start_date" value="<?= $start_date ?>">
                </div>
                <div class="filter-group">
                    <label>Дата до</label>
                    <input type="date" name="end_date" value="<?= $end_date ?>">
                </div>
                <button type="submit">Применить</button>
                <button type="submit" name="export" value="1">📥 Скачать CSV</button>
            </form>
            
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr><th>Дата</th><th>Звонки</th><th>Дозвоны</th><th>Встречи</th><th>Договоры</th><th>Оборот</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $r): ?>
                        <tr>
                            <td><?= $r['report_date'] ?></td>
                            <td><?= $r['calls'] ?></td>
                            <td><?= $r['calls_answered'] ?></td>
                            <td><?= $r['meetings'] ?></td>
                            <td><?= $r['contracts'] ?></td>
                            <td><?= number_format($r['turnover'], 0, ',', ' ') ?> ₽</td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($reports)): ?>
                        <tr><td colspan="6" style="text-align:center">Нет данных</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
