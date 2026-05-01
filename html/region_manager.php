<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'manager')) {
    header('Location: login.php');
    exit;
}
require_once 'db.php';

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Получаем список всех команд (руководителей)
if ($user_role == 'admin') {
    $teams = $pdo->query("SELECT id, tabel_number, full_name FROM users WHERE role = 'manager'")->fetchAll();
} else {
    $teams = $pdo->query("SELECT id, tabel_number, full_name FROM users WHERE id = $user_id")->fetchAll();
}

// Данные по месяцам для графиков
$months = [];
for ($i = 11; $i >= 0; $i--) {
    $months[] = date('Y-m', strtotime("-$i months"));
}

// Статистика по каждой команде
$teamStats = [];
foreach ($teams as $team) {
    $teamId = $team['id'];
    $teamName = $team['full_name'];
    $teamTabel = $team['tabel_number'];
    
    // Получаем сотрудников команды
    $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE manager_id = ?");
    $stmt->execute([$teamId]);
    $members = $stmt->fetchAll();
    
    if (empty($members)) continue;
    
    $memberIds = array_column($members, 'id');
    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
    
    // Статистика за текущий месяц
    $currentMonth = date('Y-m');
    $stmt = $pdo->prepare("
        SELECT 
            SUM(calls) as calls,
            SUM(calls_answered) as answered,
            SUM(meetings) as meetings,
            SUM(contracts) as contracts,
            SUM(registrations) as registrations,
            SUM(smart_cash) as smart_cash,
            SUM(pos_systems) as pos_systems,
            SUM(inn_leads) as inn_leads,
            SUM(teams) as teams,
            SUM(turnover) as turnover
        FROM daily_reports 
        WHERE user_id IN ($placeholders) AND strftime('%Y-%m', report_date) = ?
    ");
    $params = array_merge($memberIds, [$currentMonth]);
    $stmt->execute($params);
    $monthStats = $stmt->fetch();
    
    // Планы команды
    $stmt = $pdo->prepare("
        SELECT 
            SUM(plan_calls) as plan_calls,
            SUM(plan_answered) as plan_answered,
            SUM(plan_meetings) as plan_meetings,
            SUM(plan_contracts) as plan_contracts,
            SUM(plan_registrations) as plan_registrations,
            SUM(plan_smart_cash) as plan_smart_cash,
            SUM(plan_pos_systems) as plan_pos_systems,
            SUM(plan_inn_leads) as plan_inn_leads,
            SUM(plan_teams) as plan_teams,
            SUM(plan_turnover) as plan_turnover
        FROM monthly_plans 
        WHERE user_id IN ($placeholders) AND year = ? AND month = ?
    ");
    $paramsPlan = array_merge($memberIds, [date('Y'), date('m')]);
    $stmt->execute($paramsPlan);
    $planStats = $stmt->fetch();
    
    $teamStats[] = [
        'id' => $teamId,
        'name' => $teamName,
        'tabel' => $teamTabel,
        'members_count' => count($members),
        'members' => $members,
        'stats' => $monthStats,
        'plans' => $planStats
    ];
}

// Общая статистика по всем командам
$totalStats = ['calls'=>0, 'answered'=>0, 'meetings'=>0, 'contracts'=>0, 'registrations'=>0, 'smart_cash'=>0, 'pos_systems'=>0, 'inn_leads'=>0, 'teams'=>0, 'turnover'=>0];
$totalPlans = ['calls'=>0, 'answered'=>0, 'meetings'=>0, 'contracts'=>0, 'registrations'=>0, 'smart_cash'=>0, 'pos_systems'=>0, 'inn_leads'=>0, 'teams'=>0, 'turnover'=>0];
foreach ($teamStats as $ts) {
    foreach ($totalStats as $key => $val) {
        $totalStats[$key] += $ts['stats'][$key] ?? 0;
        $totalPlans[$key] += $ts['plans'][$key] ?? 0;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Территориальный менеджер - Обзор команд</title>
    <meta charset="utf-8">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; }
        .header { background: #1a2c3e; color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .header h1 { color: #00a36c; }
        .nav a, .logout { color: white; text-decoration: none; padding: 8px 16px; border-radius: 8px; background: #00a36c; margin: 5px; display: inline-block; }
        .container { max-width: 1400px; margin: 0 auto; padding: 30px 20px; }
        .card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 30px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stat-card h3 { font-size: 14px; color: #666; margin-bottom: 10px; }
        .stat-card .value { font-size: 28px; font-weight: bold; color: #00a36c; }
        .stat-card .plan { font-size: 12px; color: #999; margin-top: 5px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; position: sticky; top: 0; }
        .badge { padding: 4px 8px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .badge.success { background: #d4edda; color: #155724; }
        .badge.warning { background: #fff3cd; color: #856404; }
        .badge.danger { background: #f8d7da; color: #721c24; }
        .progress-bar { width: 100%; background: #eee; border-radius: 10px; overflow: hidden; height: 8px; }
        .progress-fill { background: #00a36c; height: 8px; }
        .progress-fill.warning { background: #ffc107; }
        .progress-fill.danger { background: #dc3545; }
        .team-card { margin-bottom: 20px; border-left: 4px solid #00a36c; }
        .team-header { background: #f8f9fa; padding: 15px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; }
        .team-header:hover { background: #e9ecef; }
        .team-body { display: none; padding: 15px; }
        .team-body.show { display: block; }
        .scroll-table { overflow-x: auto; max-height: 400px; overflow-y: auto; }
        @media (max-width: 768px) { .stats-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="header">
        <h1>Sales CRM</h1>
        <div>
            <a href="dashboard.php" class="nav">📊 Дашборд</a>
            <a href="team.php" class="nav">👥 Команда</a>
            <a href="region_manager.php" class="nav">🗺️ Тер. менеджер</a>
            <?php if ($user_role === 'admin'): ?>
            <a href="admin.php" class="nav">⚙️ Админ</a>
            <?php endif; ?>
            <a href="logout.php" class="logout">Выйти</a>
        </div>
    </div>
    <div class="container">
        <!-- Общая статистика -->
        <div class="stats-grid">
            <div class="stat-card"><h3>🏢 Всего команд</h3><div class="value"><?= count($teamStats) ?></div></div>
            <div class="stat-card"><h3>👥 Всего сотрудников</h3><div class="value"><?= array_sum(array_column($teamStats, 'members_count')) ?></div></div>
            <div class="stat-card"><h3>📅 Выполнение плана (договоры)</h3>
                <div class="value"><?= $totalPlans['contracts'] > 0 ? round(($totalStats['contracts'] / $totalPlans['contracts']) * 100) : 0 ?>%</div>
                <div class="plan">Факт: <?= $totalStats['contracts'] ?> / План: <?= $totalPlans['contracts'] ?></div>
            </div>
            <div class="stat-card"><h3>💰 Оборот чаевых</h3>
                <div class="value"><?= number_format($totalStats['turnover'], 0, ',', ' ') ?> ₽</div>
                <div class="plan">План: <?= number_format($totalPlans['turnover'], 0, ',', ' ') ?> ₽</div>
            </div>
        </div>
        
        <!-- График сравнения команд -->
        <div class="card">
            <h3>📊 Сравнение команд по договорам</h3>
            <canvas id="teamsChart" style="max-height: 400px;"></canvas>
        </div>
        
        <!-- Список команд -->
        <div class="card">
            <h3>🗺️ Команды и руководители</h3>
            <?php foreach ($teamStats as $index => $team): 
                $contracts = $team['stats']['contracts'] ?? 0;
                $planContracts = $team['plans']['contracts'] ?? 1;
                $percent = $planContracts > 0 ? round(($contracts / $planContracts) * 100) : 0;
                $statusClass = $percent >= 80 ? 'success' : ($percent >= 60 ? 'warning' : 'danger');
            ?>
            <div class="team-card">
                <div class="team-header" onclick="toggleTeam(<?= $index ?>)">
                    <div>
                        <strong>👥 <?= htmlspecialchars($team['name']) ?></strong><br>
                        <small>таб. <?= $team['tabel'] ?> • Сотрудников: <?= $team['members_count'] ?></small>
                    </div>
                    <div style="text-align: right;">
                        <div>Договоров: <strong><?= $contracts ?></strong> / <?= $planContracts ?></div>
                        <div class="progress-bar" style="width: 150px; margin-top: 5px;"><div class="progress-fill <?= $statusClass ?>" style="width: <?= $percent ?>%"></div></div>
                        <span class="badge <?= $statusClass ?>"><?= $percent ?>%</span>
                    </div>
                </div>
                <div class="team-body" id="team-<?= $index ?>">
                    <div class="stats-grid" style="margin-bottom: 15px;">
                        <div class="stat-card"><h3>📞 Звонки</h3><div class="value"><?= $team['stats']['calls'] ?? 0 ?></div><div class="plan">План: <?= $team['plans']['calls'] ?? 0 ?></div></div>
                        <div class="stat-card"><h3>✅ Дозвоны</h3><div class="value"><?= $team['stats']['answered'] ?? 0 ?></div><div class="plan">План: <?= $team['plans']['answered'] ?? 0 ?></div></div>
                        <div class="stat-card"><h3>📅 Встречи</h3><div class="value"><?= $team['stats']['meetings'] ?? 0 ?></div><div class="plan">План: <?= $team['plans']['meetings'] ?? 0 ?></div></div>
                        <div class="stat-card"><h3>📄 Договоры</h3><div class="value"><?= $team['stats']['contracts'] ?? 0 ?></div><div class="plan">План: <?= $team['plans']['contracts'] ?? 0 ?></div></div>
                        <div class="stat-card"><h3>📝 Регистрации</h3><div class="value"><?= $team['stats']['registrations'] ?? 0 ?></div><div class="plan">План: <?= $team['plans']['registrations'] ?? 0 ?></div></div>
                        <div class="stat-card"><h3>💰 Оборот чаевых</h3><div class="value"><?= number_format($team['stats']['turnover'] ?? 0, 0, ',', ' ') ?> ₽</div><div class="plan">План: <?= number_format($team['plans']['turnover'] ?? 0, 0, ',', ' ') ?> ₽</div></div>
                    </div>
                    <div class="scroll-table">
                        <table>
                            <thead>
                                <tr><th>Сотрудник</th><th>📞</th><th>✅</th><th>📅</th><th>📄</th><th>📝</th><th>💳</th><th>🖥️</th><th>🔗</th><th>👥</th><th>💰</th><th>%</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($team['members'] as $member): 
                                $stmt = $pdo->prepare("SELECT calls, calls_answered, meetings, contracts, registrations, smart_cash, pos_systems, inn_leads, teams, turnover FROM daily_reports WHERE user_id = ? AND report_date = CURRENT_DATE");
                                $stmt->execute([$member['id']]);
                                $today = $stmt->fetch();
                                if (!$today) $today = ['calls'=>0, 'calls_answered'=>0, 'meetings'=>0, 'contracts'=>0, 'registrations'=>0, 'smart_cash'=>0, 'pos_systems'=>0, 'inn_leads'=>0, 'teams'=>0, 'turnover'=>0];
                                $memberPercent = $team['plans']['contracts'] > 0 ? round(($today['contracts'] / ($team['plans']['contracts'] / $team['members_count'])) * 100) : 0;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($member['full_name']) ?></td>
                                <td><?= $today['calls'] ?></td>
                                <td><?= $today['calls_answered'] ?></td>
                                <td><?= $today['meetings'] ?></td>
                                <td><strong><?= $today['contracts'] ?></strong></td>
                                <td><?= $today['registrations'] ?></td>
                                <td><?= $today['smart_cash'] ?></td>
                                <td><?= $today['pos_systems'] ?></td>
                                <td><?= $today['inn_leads'] ?></td>
                                <td><?= $today['teams'] ?></td>
                                <td><?= number_format($today['turnover'], 0, ',', ' ') ?> ₽</td>
                                <td><?= $memberPercent ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <script>
        function toggleTeam(index) {
            const el = document.getElementById('team-' + index);
            el.classList.toggle('show');
        }
        
        // График сравнения команд
        const ctx = document.getElementById('teamsChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($teamStats, 'name')) ?>,
                datasets: [
                    {
                        label: 'Договоры (факт)',
                        data: <?= json_encode(array_column(array_column($teamStats, 'stats'), 'contracts')) ?>,
                        backgroundColor: '#00a36c',
                        borderRadius: 8
                    },
                    {
                        label: 'План договоров',
                        data: <?= json_encode(array_column(array_column($teamStats, 'plans'), 'contracts')) ?>,
                        backgroundColor: '#1a2c3e',
                        borderRadius: 8
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { position: 'top' } }
            }
        });
    </script>
</body>
</html>
