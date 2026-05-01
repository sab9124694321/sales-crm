<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'manager')) {
    header('Location: login.php');
    exit;
}
require_once 'db.php';

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$name = $_SESSION['name'];

// Получаем список всех команд
if ($user_role == 'admin') {
    $teams = $pdo->query("SELECT id, tabel_number, full_name FROM users WHERE role = 'manager'")->fetchAll();
} else {
    $teams = $pdo->query("SELECT id, tabel_number, full_name FROM users WHERE id = $user_id")->fetchAll();
}

// Статистика по каждой команде
$teamStats = [];
foreach ($teams as $team) {
    $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE manager_id = ?");
    $stmt->execute([$team['id']]);
    $members = $stmt->fetchAll();
    $memberIds = array_column($members, 'id');
    if (empty($memberIds)) continue;
    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
    $stmt = $pdo->prepare("SELECT SUM(contracts) as contracts FROM daily_reports WHERE user_id IN ($placeholders) AND report_date = CURRENT_DATE");
    $stmt->execute($memberIds);
    $todayContracts = $stmt->fetch()['contracts'] ?? 0;
    $stmt = $pdo->prepare("SELECT SUM(plan_contracts) as plan FROM monthly_plans WHERE user_id IN ($placeholders) AND year = ? AND month = ?");
    $stmt->execute(array_merge($memberIds, [date('Y'), date('m')]));
    $plan = $stmt->fetch()['plan'] ?? 1;
    $percent = $plan > 0 ? min(100, round(($todayContracts / $plan) * 100)) : 0;
    $teamStats[] = ['id' => $team['id'], 'name' => $team['full_name'], 'tabel' => $team['tabel_number'], 'members_count' => count($members), 'contracts' => $todayContracts, 'plan' => $plan, 'percent' => $percent];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Территориальный менеджер</title>
    <meta charset="utf-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; }
        .header { background: #1a2c3e; color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .header h1 { color: #00a36c; }
        .nav { display: flex; gap: 15px; flex-wrap: wrap; }
        .nav a, .logout { color: white; text-decoration: none; padding: 8px 16px; border-radius: 8px; background: #00a36c; }
        .container { max-width: 1200px; margin: 0 auto; padding: 30px 20px; }
        .card { background: white; border-radius: 12px; padding: 25px; margin-bottom: 30px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stat-card .value { font-size: 28px; font-weight: bold; color: #00a36c; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; }
        .progress-bar { width: 100%; background: #eee; border-radius: 10px; overflow: hidden; height: 10px; }
        .progress-fill { background: #00a36c; height: 10px; }
        .badge { padding: 4px 8px; border-radius: 20px; font-size: 12px; }
        .badge.success { background: #d4edda; color: #155724; }
        .badge.warning { background: #fff3cd; color: #856404; }
        .badge.danger { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Sales CRM</h1>
        <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
            <span>👋 <?= htmlspecialchars($name) ?> (<?= htmlspecialchars($user_role) ?>)</span>
            <div class="nav">
                <a href="dashboard.php">📊 Дашборд</a>
                <a href="team.php">👥 Команда</a>
                <?php if ($user_role === 'admin'): ?>
                <a href="admin.php">⚙️ Админ</a>
                <?php endif; ?>
                <a href="region_manager.php">🗺️ Тер. менеджер</a>
            </div>
            <a href="logout.php" class="logout">Выйти</a>
        </div>
    </div>
    <div class="container">
        <div class="stats-grid">
            <div class="stat-card"><h3>🏢 Всего команд</h3><div class="value"><?= count($teamStats) ?></div></div>
            <div class="stat-card"><h3>👥 Всего сотрудников</h3><div class="value"><?= array_sum(array_column($teamStats, 'members_count')) ?></div></div>
            <div class="stat-card"><h3>📊 Среднее выполнение</h3><div class="value"><?= count($teamStats) > 0 ? round(array_sum(array_column($teamStats, 'percent')) / count($teamStats)) : 0 ?>%</div></div>
        </div>
        <div class="card">
            <h3>🗺️ Команды</h3>
            <div style="overflow-x: auto;">
                </table>
                    <thead>
                        <tr><th>ID команды</th><th>Руководитель</th><th>Сотрудников</th><th>Договоров сегодня</th><th>План</th><th>Выполнение</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($teamStats as $ts): ?>
                        <tr>
                            <td><?= $ts['id'] ?></td>
                            <td><?= htmlspecialchars($ts['name']) ?> (<?= $ts['tabel'] ?>)</td>
                            <td><?= $ts['members_count'] ?></td>
                            <td><strong><?= $ts['contracts'] ?></strong></td>
                            <td><?= $ts['plan'] ?></td>
                            <td>
                                <div class="progress-bar"><div class="progress-fill" style="width: <?= $ts['percent'] ?>%"></div></div>
                                <?= $ts['percent'] ?>%<br>
                                <span class="badge <?= $ts['percent'] >= 80 ? 'success' : ($ts['percent'] >= 60 ? 'warning' : 'danger') ?>">
                                    <?= $ts['percent'] >= 80 ? '✅ Хорошо' : ($ts['percent'] >= 60 ? '⚠️ Средне' : '🔴 Отставание') ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
