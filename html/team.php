<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] == 'employee') {
    header('Location: dashboard.php');
    exit;
}
require_once 'db.php';

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$name = $_SESSION['name'];
$showDays = $_GET['days'] ?? 90;

// Получаем сотрудников
if ($role == 'admin') {
    $employees = $pdo->query("SELECT id, tabel_number, full_name FROM users WHERE role != 'admin'")->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT id, tabel_number, full_name FROM users WHERE manager_id = ? OR id = ?");
    $stmt->execute([$user_id, $user_id]);
    $employees = $stmt->fetchAll();
}

// Период
$endDate = date('Y-m-d');
$startDate = date('Y-m-d', strtotime("-$showDays days"));

// Получаем агрегированные данные
$aggregated = [];
if (!empty($employees)) {
    $ids = array_column($employees, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT user_id, 
               SUM(calls) as calls,
               SUM(calls_answered) as calls_answered,
               SUM(meetings) as meetings,
               SUM(contracts) as contracts,
               SUM(registrations) as registrations,
               SUM(smart_cash) as smart_cash,
               SUM(pos_systems) as pos_systems,
               SUM(inn_leads) as inn_leads,
               SUM(teams) as teams,
               SUM(turnover) as turnover,
               COUNT(DISTINCT report_date) as days_worked
        FROM daily_reports 
        WHERE user_id IN ($placeholders) AND report_date BETWEEN ? AND ?
        GROUP BY user_id
    ");
    $params = array_merge($ids, [$startDate, $endDate]);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $aggregated[$row['user_id']] = $row;
    }
}

// Планы
$plans = [];
$stmt = $pdo->prepare("SELECT user_id, plan_calls, plan_answered, plan_meetings, plan_contracts, plan_registrations, plan_smart_cash, plan_pos_systems, plan_inn_leads, plan_teams, plan_turnover FROM monthly_plans WHERE year = ? AND month = ?");
$stmt->execute([date('Y'), date('m')]);
foreach ($stmt->fetchAll() as $p) {
    $plans[$p['user_id']] = $p;
}

// Данные для графика
if (!empty($employees)) {
    $ids = array_column($employees, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT report_date, SUM(contracts) as total_contracts
        FROM daily_reports 
        WHERE user_id IN ($placeholders) AND report_date BETWEEN ? AND ?
        GROUP BY report_date
        ORDER BY report_date
    ");
    $stmt->execute(array_merge($ids, [$startDate, $endDate]));
    $dailyContracts = $stmt->fetchAll();
} else {
    $dailyContracts = [];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Sales CRM - Команда</title>
    <meta charset="utf-8">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; }
        .header { background: #1a2c3e; color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .header h1 { color: #00a36c; }
        .nav { display: flex; gap: 15px; flex-wrap: wrap; }
        .nav a, .logout { color: white; text-decoration: none; padding: 8px 16px; border-radius: 8px; background: #00a36c; }
        .container { max-width: 1400px; margin: 0 auto; padding: 30px 20px; }
        .card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 30px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stat-card h3 { font-size: 14px; color: #666; margin-bottom: 10px; }
        .stat-card .value { font-size: 28px; font-weight: bold; color: #00a36c; }
        select { padding: 8px; border-radius: 8px; border: 1px solid #ddd; margin-bottom: 15px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; position: sticky; top: 0; }
        .badge { padding: 4px 8px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .badge.success { background: #d4edda; color: #155724; }
        .badge.warning { background: #fff3cd; color: #856404; }
        .badge.danger { background: #f8d7da; color: #721c24; }
        .progress-bar { width: 100%; background: #eee; border-radius: 10px; overflow: hidden; height: 8px; }
        .progress-fill { background: #00a36c; height: 8px; }
        .progress-fill.warning { background: #ffc107; }
        .progress-fill.danger { background: #dc3545; }
        .scroll-table { overflow-x: auto; max-height: 500px; overflow-y: auto; }
        .filters { display: flex; gap: 15px; align-items: center; margin-bottom: 20px; flex-wrap: wrap; }
        @media (max-width: 768px) { .stats-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="header">
        <h1>Sales CRM</h1>
        <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
            <span>👋 <?= htmlspecialchars($name) ?> (<?= htmlspecialchars($role) ?>)</span>
            <div class="nav">
                <a href="dashboard.php">📊 Дашборд</a>
                <a href="team.php">👥 Команда</a>
                <?php if ($role === 'admin'): ?>
                <a href="admin.php">⚙️ Админ</a>
                <?php endif; ?>
                <a href="region_manager.php">🗺️ Тер. менеджер</a>
            </div>
            <a href="logout.php" class="logout">Выйти</a>
        </div>
    </div>
    <div class="container">
        <div class="filters">
            <label>📅 Период:</label>
            <select onchange="location.href='?days='+this.value">
                <option value="30" <?= $showDays == 30 ? 'selected' : '' ?>>Последний месяц</option>
                <option value="60" <?= $showDays == 60 ? 'selected' : '' ?>>Последние 2 месяца</option>
                <option value="90" <?= $showDays == 90 ? 'selected' : '' ?>>Последние 3 месяца</option>
            </select>
        </div>
        
        <div class="stats-grid">
            <?php 
            $totals = ['calls'=>0, 'contracts'=>0, 'meetings'=>0, 'turnover'=>0, 'days_worked'=>0];
            foreach ($employees as $emp) {
                $agg = $aggregated[$emp['id']] ?? ['calls'=>0, 'contracts'=>0, 'meetings'=>0, 'turnover'=>0, 'days_worked'=>0];
                $totals['calls'] += $agg['calls'];
                $totals['contracts'] += $agg['contracts'];
                $totals['meetings'] += $agg['meetings'];
                $totals['turnover'] += $agg['turnover'];
                $totals['days_worked'] += $agg['days_worked'];
            }
            ?>
            <div class="stat-card"><h3>📞 Звонков (всего)</h3><div class="value"><?= $totals['calls'] ?></div></div>
            <div class="stat-card"><h3>📄 Договоров (всего)</h3><div class="value"><?= $totals['contracts'] ?></div></div>
            <div class="stat-card"><h3>📅 Встреч (всего)</h3><div class="value"><?= $totals['meetings'] ?></div></div>
            <div class="stat-card"><h3>💰 Оборот (всего)</h3><div class="value"><?= number_format($totals['turnover'], 0, ',', ' ') ?> ₽</div></div>
        </div>
        
        <div class="card">
            <h3>📈 Динамика договоров по дням</h3>
            <canvas id="contractsChart" style="max-height: 300px;"></canvas>
        </div>
        
        <div class="card">
            <h3>👥 Детальная статистика команды (все 10 показателей)</h3>
            <div class="scroll-table">
                <table>
                    <thead>
                        <tr>
                            <th>Сотрудник</th>
                            <th>📞 Звонки</th>
                            <th>✅ Дозвоны</th>
                            <th>📅 Встречи</th>
                            <th>📄 Договоры</th>
                            <th>📝 Регистрации</th>
                            <th>💳 Смарт-кассы</th>
                            <th>🖥️ ПОС</th>
                            <th>🔗 ИНН чаевые</th>
                            <th>👥 Команды чаевые</th>
                            <th>💰 Оборот</th>
                            <th>Дней</th>
                            <th>Выполнение</th>
                         </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $emp): 
                            $agg = $aggregated[$emp['id']] ?? [
                                'calls'=>0, 'calls_answered'=>0, 'meetings'=>0, 'contracts'=>0,
                                'registrations'=>0, 'smart_cash'=>0, 'pos_systems'=>0,
                                'inn_leads'=>0, 'teams'=>0, 'turnover'=>0, 'days_worked'=>0
                            ];
                            $plan = $plans[$emp['id']] ?? null;
                            if ($plan && $plan['plan_contracts'] > 0) {
                                $contractsProgress = min(100, round(($agg['contracts'] / $plan['plan_contracts']) * 100));
                            } else {
                                $contractsProgress = $agg['contracts'] > 0 ? 100 : 0;
                            }
                            $statusClass = $contractsProgress >= 80 ? 'success' : ($contractsProgress >= 60 ? 'warning' : 'danger');
                            $statusText = $contractsProgress >= 100 ? 'Перевыполнение' : ($contractsProgress >= 80 ? 'Хорошо' : ($contractsProgress >= 60 ? 'Средне' : 'Отставание'));
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($emp['full_name']) ?></strong><br><span style="font-size:10px;color:#999;">таб. <?= $emp['tabel_number'] ?></span></td>
                            <td><?= $agg['calls'] ?></td>
                            <td><?= $agg['calls_answered'] ?></td>
                            <td><?= $agg['meetings'] ?></td>
                            <td><strong><?= $agg['contracts'] ?></strong></td>
                            <td><?= $agg['registrations'] ?></td>
                            <td><?= $agg['smart_cash'] ?></td>
                            <td><?= $agg['pos_systems'] ?></td>
                            <td><?= $agg['inn_leads'] ?></td>
                            <td><?= $agg['teams'] ?></td>
                            <td><?= number_format($agg['turnover'], 0, ',', ' ') ?> ₽</td>
                            <td><?= $agg['days_worked'] ?></td>
                            <td>
                                <div class="progress-bar"><div class="progress-fill <?= $statusClass ?>" style="width: <?= $contractsProgress ?>%"></div></div>
                                <span class="badge <?= $statusClass ?>" style="margin-top:5px;"><?= $statusText ?> (<?= $contractsProgress ?>%)</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <script>
        const dates = <?= json_encode(array_column($dailyContracts, 'report_date')) ?>;
        const contracts = <?= json_encode(array_column($dailyContracts, 'total_contracts')) ?>;
        const ctx = document.getElementById('contractsChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: { labels: dates, datasets: [{ label: 'Договоры', data: contracts, borderColor: '#00a36c', backgroundColor: 'rgba(0, 163, 108, 0.1)', fill: true, tension: 0.4 }] },
            options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'top' } } }
        });
    </script>
</body>
</html>
