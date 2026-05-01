<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once 'db.php';

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$name = $_SESSION['name'];

// Получаем план пользователя
$stmt = $pdo->prepare("SELECT plan_contracts FROM monthly_plans WHERE user_id = ? AND year = ? AND month = ?");
$stmt->execute([$user_id, date('Y'), date('m')]);
$plan = $stmt->fetch();
$plan_contracts = $plan ? $plan['plan_contracts'] : 30;

// Получаем отчёты пользователя
$stmt = $pdo->prepare("SELECT * FROM daily_reports WHERE user_id = ? ORDER BY report_date DESC LIMIT 30");
$stmt->execute([$user_id]);
$reports = $stmt->fetchAll();

// Расчёт прогресса за сегодня
$today = date('Y-m-d');
$todayCalls = 0;
$todayContracts = 0;
foreach ($reports as $r) {
    if ($r['report_date'] === $today) {
        $todayCalls += $r['calls'];
        $todayContracts += $r['contracts'];
    }
}
$progress = $plan_contracts > 0 ? min(100, round(($todayContracts / $plan_contracts) * 100)) : 0;
$deviation = $plan_contracts > 0 ? round((1 - $todayContracts / $plan_contracts) * 100) : 0;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Sales CRM</title>
    <meta charset="utf-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; }
        .header { background: #1a2c3e; color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .header h1 { color: #00a36c; }
        .nav { display: flex; gap: 20px; }
        .nav a, .logout { color: white; text-decoration: none; padding: 8px 16px; border-radius: 8px; background: #00a36c; }
        .container { max-width: 1200px; margin: 0 auto; padding: 30px 20px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .card h3 { color: #1a2c3e; margin-bottom: 15px; }
        .card .value { font-size: 32px; font-weight: bold; color: #00a36c; }
        .form-card { background: white; border-radius: 12px; padding: 25px; margin-bottom: 30px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; color: #555; }
        input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; }
        button { background: #00a36c; color: white; border: none; padding: 12px; border-radius: 8px; cursor: pointer; width: 100%; }
        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 8px; margin-bottom: 20px; }
        .warning { background: #fff3cd; color: #856404; padding: 10px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #856404; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; }
        .badge { padding: 4px 8px; border-radius: 20px; font-size: 12px; display: inline-block; }
        .badge.success { background: #d4edda; color: #155724; }
        .badge.warning { background: #fff3cd; color: #856404; }
        .badge.danger { background: #f8d7da; color: #721c24; }
        @media (max-width: 768px) { .stats { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="header">
        <h1>Sales CRM</h1>
        <div style="display: flex; gap: 20px; align-items: center;">
            <span>👋 <?= htmlspecialchars($name) ?> (<?= htmlspecialchars($role) ?>)</span>
            <div class="nav">
                <a href="dashboard.php">📊 Дашборд</a>
                <?php if ($role !== 'employee'): ?>
                <a href="team.php">👥 Команда</a>
                <?php endif; ?>
                <?php if ($role === 'admin'): ?>
                <a href="admin.php">⚙️ Админ</a>
                <?php endif; ?>
            </div>
            <a href="logout.php" class="logout">Выйти</a>
        </div>
    </div>
    <div class="container">
        <div class="stats">
            <div class="card"><h3>📞 Звонков сегодня</h3><div class="value"><?= $todayCalls ?></div></div>
            <div class="card"><h3>📄 Договоров сегодня</h3><div class="value"><?= $todayContracts ?></div></div>
            <div class="card"><h3>📈 Выполнение плана</h3><div class="value"><?= $progress ?>%</div></div>
        </div>
        
        <?php if ($deviation > 20): ?>
        <div class="warning">
            <h3>🔴 Критическое отставание!</h3>
            <p>Отставание от плана составляет <?= $deviation ?>%. Срочно наверстайте! Цель на завтра: минимум 5 договоров.</p>
        </div>
        <?php elseif ($deviation > 10): ?>
        <div class="warning">
            <h3>⚠️ Отставание от плана</h3>
            <p>Отставание <?= $deviation ?>%. Увеличьте активность. План на завтра: 3 договора.</p>
        </div>
        <?php elseif ($progress < 80): ?>
        <div class="card" style="border-left: 4px solid #00a36c;">
            <h3>📋 Вы в графике</h3>
            <p>Продолжайте в том же духе!</p>
        </div>
        <?php else: ?>
        <div class="card" style="border-left: 4px solid #00a36c; background: #e8f5e9;">
            <h3>🎉 Отличный результат!</h3>
            <p>Перевыполнение плана! Так держать!</p>
        </div>
        <?php endif; ?>
        
        <div class="form-card">
            <h3>📝 Ежедневный отчёт</h3>
            <form method="post" action="save_report.php">
                <div class="form-group"><label>📞 Звонки</label><input type="number" name="calls" required></div>
                <div class="form-group"><label>✅ Дозвоны</label><input type="number" name="calls_answered" required></div>
                <div class="form-group"><label>📅 Встречи</label><input type="number" name="meetings" required></div>
                <div class="form-group"><label>📄 Договоры</label><input type="number" name="contracts" required></div>
                <div class="form-group"><label>📝 Регистрации</label><input type="number" name="registrations" required></div>
                <div class="form-group"><label>💳 Смарт-кассы</label><input type="number" name="smart_cash" required></div>
                <div class="form-group"><label>🖥️ ПОС-системы</label><input type="number" name="pos_systems" required></div>
                <div class="form-group"><label>🔗 ИНН для чаевых</label><input type="number" name="inn_leads" required></div>
                <div class="form-group"><label>👥 Команды на чаевые</label><input type="number" name="teams" required></div>
                <div class="form-group"><label>💰 Оборот чаевых (руб)</label><input type="number" name="turnover" required></div>
                <button type="submit">Сохранить отчёт</button>
            </form>
        </div>
        
        <div class="card">
            <h3>📋 История отчётов</h3>
            <table>
                <thead><tr><th>Дата</th><th>Звонки</th><th>Дозвоны</th><th>Встречи</th><th>Договоры</th><th>Статус</th></tr></thead>
                <tbody>
                <?php foreach ($reports as $r): 
                    $percent = $plan_contracts > 0 ? min(100, round(($r['contracts'] / $plan_contracts) * 100)) : 0;
                    $statusClass = $percent >= 70 ? 'success' : ($percent >= 50 ? 'warning' : 'danger');
                    $statusText = $percent >= 100 ? 'Перевыполнение' : ($percent >= 70 ? 'Выполняется' : ($percent >= 50 ? 'Отставание' : 'Критично'));
                ?>
                    <tr>
                        <td><?= $r['report_date'] ?></td>
                        <td><?= $r['calls'] ?></td>
                        <td><?= $r['calls_answered'] ?></td>
                        <td><?= $r['meetings'] ?></td>
                        <td><?= $r['contracts'] ?></td>
                        <td><span class="badge <?= $statusClass ?>"><?= $statusText ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($reports)): ?>
                    <tr><td colspan="6" style="text-align:center">Нет отчётов</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
