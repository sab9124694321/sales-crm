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
$selectedTerritoryId = $_GET['territory_id'] ?? null;
$selectedManagerId = $_GET['manager_id'] ?? null;

// Территории и руководители
$territories = [
    1 => ['name' => 'Северная', 'code' => 'north', 'manager_tabel' => '1001'],
    2 => ['name' => 'Южная', 'code' => 'south', 'manager_tabel' => '2001'],
    3 => ['name' => 'Центральная', 'code' => 'center', 'manager_tabel' => '3001'],
    4 => ['name' => 'Западная', 'code' => 'west', 'manager_tabel' => '4001'],
    5 => ['name' => 'Восточная', 'code' => 'east', 'manager_tabel' => '5001']
];

// Получаем всех руководителей
$managers = [];
$stmt = $pdo->query("SELECT id, tabel_number, full_name FROM users WHERE role = 'manager' ORDER BY tabel_number");
while ($mgr = $stmt->fetch()) {
    foreach ($territories as $tid => $t) {
        if ($mgr['tabel_number'] == $t['manager_tabel']) {
            $managers[$tid] = [
                'id' => $mgr['id'],
                'tabel' => $mgr['tabel_number'],
                'name' => $mgr['full_name']
            ];
            break;
        }
    }
}

$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$daysPassed = (int)date('j');
$daysLeft = (int)date('t') - $daysPassed;

// Показатели для KPI
$kpiMetrics = [
    'calls' => ['name' => 'Звонки', 'icon' => '📞', 'color' => '#00a36c', 'daily_plan' => 30],
    'contracts' => ['name' => 'Договоры', 'icon' => '📄', 'color' => '#dc3545', 'daily_plan' => 2],
    'meetings' => ['name' => 'Встречи', 'icon' => '📅', 'color' => '#ffc107', 'daily_plan' => 3],
    'turnover' => ['name' => 'Оборот чаевых', 'icon' => '💰', 'color' => '#17a2b8', 'daily_plan' => 7333]
];

// Если выбран конкретный руководитель - показываем детализацию
$showManagerDetail = false;
$selectedManager = null;
if ($selectedManagerId && isset($managers[$selectedTerritoryId]) && $managers[$selectedTerritoryId]['id'] == $selectedManagerId) {
    $selectedManager = $managers[$selectedTerritoryId];
    $showManagerDetail = true;
}

// Данные по территориям
$territoryStats = [];
foreach ($territories as $tid => $terr) {
    if (!isset($managers[$tid])) {
        $territoryStats[$tid] = [
            'name' => $terr['name'],
            'calls' => 0, 'contracts' => 0, 'meetings' => 0, 'turnover' => 0,
            'plan_calls' => 0, 'plan_contracts' => 0, 'plan_meetings' => 0, 'plan_turnover' => 0,
            'forecast_calls' => 0, 'forecast_contracts' => 0, 'forecast_meetings' => 0, 'forecast_turnover' => 0,
            'needed_per_day' => 0,
            'manager' => null
        ];
        continue;
    }
    
    $manager = $managers[$tid];
    
    // Получаем сотрудников территории
    $stmt = $pdo->prepare("SELECT id FROM users WHERE manager_id = ?");
    $stmt->execute([$manager['id']]);
    $members = $stmt->fetchAll();
    $memberIds = array_column($members, 'id');
    
    if (empty($memberIds)) {
        $territoryStats[$tid] = [
            'name' => $terr['name'],
            'calls' => 0, 'contracts' => 0, 'meetings' => 0, 'turnover' => 0,
            'plan_calls' => 0, 'plan_contracts' => 0, 'plan_meetings' => 0, 'plan_turnover' => 0,
            'forecast_calls' => 0, 'forecast_contracts' => 0, 'forecast_meetings' => 0, 'forecast_turnover' => 0,
            'needed_per_day' => 0,
            'manager' => $manager
        ];
        continue;
    }
    
    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
    
    // Факт
    $stmt = $pdo->prepare("
        SELECT SUM(calls) as calls, SUM(contracts) as contracts,
               SUM(meetings) as meetings, SUM(turnover) as turnover
        FROM daily_reports 
        WHERE user_id IN ($placeholders) AND report_date BETWEEN ? AND ?
    ");
    $stmt->execute(array_merge($memberIds, [$monthStart, $monthEnd]));
    $fact = $stmt->fetch();
    
    // Планы
    $stmt = $pdo->prepare("
        SELECT SUM(plan_calls) as plan_calls, SUM(plan_contracts) as plan_contracts,
               SUM(plan_meetings) as plan_meetings, SUM(plan_turnover) as plan_turnover
        FROM monthly_plans WHERE user_id IN ($placeholders) AND year = ? AND month = ?
    ");
    $stmt->execute(array_merge($memberIds, [date('Y'), date('m')]));
    $plans = $stmt->fetch();
    
    $stats = [
        'name' => $terr['name'],
        'calls' => $fact['calls'] ?? 0,
        'contracts' => $fact['contracts'] ?? 0,
        'meetings' => $fact['meetings'] ?? 0,
        'turnover' => $fact['turnover'] ?? 0,
        'plan_calls' => $plans['plan_calls'] ?? (30 * 30),
        'plan_contracts' => $plans['plan_contracts'] ?? (2 * 30),
        'plan_meetings' => $plans['plan_meetings'] ?? (3 * 30),
        'plan_turnover' => $plans['plan_turnover'] ?? (7333 * 30),
        'manager' => $manager
    ];
    
    // Прогнозы
    $stats['forecast_calls'] = $daysPassed > 0 ? round($stats['calls'] + ($stats['calls'] / $daysPassed) * $daysLeft) : $stats['calls'];
    $stats['forecast_contracts'] = $daysPassed > 0 ? round($stats['contracts'] + ($stats['contracts'] / $daysPassed) * $daysLeft) : $stats['contracts'];
    $stats['forecast_meetings'] = $daysPassed > 0 ? round($stats['meetings'] + ($stats['meetings'] / $daysPassed) * $daysLeft) : $stats['meetings'];
    $stats['forecast_turnover'] = $daysPassed > 0 ? round($stats['turnover'] + ($stats['turnover'] / $daysPassed) * $daysLeft) : $stats['turnover'];
    
    $stats['needed_per_day'] = $daysLeft > 0 ? max(0, round(($stats['plan_contracts'] - $stats['contracts']) / $daysLeft)) : 0;
    $stats['progress'] = $stats['plan_contracts'] > 0 ? min(100, round(($stats['contracts'] / $stats['plan_contracts']) * 100)) : 0;
    
    $territoryStats[$tid] = $stats;
}

// Если выбран руководитель - получаем его команду
$teamEmployees = [];
$managerStats = null;
if ($showManagerDetail && $selectedManager) {
    $stmt = $pdo->prepare("SELECT id, full_name, tabel_number FROM users WHERE manager_id = ?");
    $stmt->execute([$selectedManager['id']]);
    $teamEmployees = $stmt->fetchAll();
    
    $memberIds = array_column($teamEmployees, 'id');
    if (!empty($memberIds)) {
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        
        // Суммарные данные команды
        $stmt = $pdo->prepare("
            SELECT SUM(calls) as calls, SUM(contracts) as contracts,
                   SUM(meetings) as meetings, SUM(turnover) as turnover
            FROM daily_reports WHERE user_id IN ($placeholders) AND report_date BETWEEN ? AND ?
        ");
        $stmt->execute(array_merge($memberIds, [$monthStart, $monthEnd]));
        $teamFact = $stmt->fetch();
        
        // Планы команды
        $stmt = $pdo->prepare("
            SELECT SUM(plan_calls) as plan_calls, SUM(plan_contracts) as plan_contracts,
                   SUM(plan_meetings) as plan_meetings, SUM(plan_turnover) as plan_turnover
            FROM monthly_plans WHERE user_id IN ($placeholders) AND year = ? AND month = ?
        ");
        $stmt->execute(array_merge($memberIds, [date('Y'), date('m')]));
        $teamPlans = $stmt->fetch();
        
        $managerStats = [
            'calls' => $teamFact['calls'] ?? 0,
            'contracts' => $teamFact['contracts'] ?? 0,
            'meetings' => $teamFact['meetings'] ?? 0,
            'turnover' => $teamFact['turnover'] ?? 0,
            'plan_calls' => $teamPlans['plan_calls'] ?? (30 * 30),
            'plan_contracts' => $teamPlans['plan_contracts'] ?? (2 * 30),
            'plan_meetings' => $teamPlans['plan_meetings'] ?? (3 * 30),
            'plan_turnover' => $teamPlans['plan_turnover'] ?? (7333 * 30),
            'employees_count' => count($teamEmployees)
        ];
        
        $managerStats['forecast_contracts'] = $daysPassed > 0 ? round($managerStats['contracts'] + ($managerStats['contracts'] / $daysPassed) * $daysLeft) : $managerStats['contracts'];
        $managerStats['needed_per_day'] = $daysLeft > 0 ? max(0, round(($managerStats['plan_contracts'] - $managerStats['contracts']) / $daysLeft)) : 0;
        
        // Данные по каждому сотруднику
        foreach ($teamEmployees as &$emp) {
            $stmt = $pdo->prepare("
                SELECT SUM(contracts) as contracts FROM daily_reports 
                WHERE user_id = ? AND report_date BETWEEN ? AND ?
            ");
            $stmt->execute([$emp['id'], $monthStart, $monthEnd]);
            $empContracts = $stmt->fetch()['contracts'] ?? 0;
            
            $stmt = $pdo->prepare("SELECT plan_contracts FROM monthly_plans WHERE user_id = ? AND year = ? AND month = ?");
            $stmt->execute([$emp['id'], date('Y'), date('m')]);
            $empPlan = $stmt->fetch()['plan_contracts'] ?? 30;
            
            $emp['contracts'] = $empContracts;
            $emp['plan'] = $empPlan;
            $emp['percent'] = $empPlan > 0 ? min(100, round(($empContracts / $empPlan) * 100)) : 0;
        }
    }
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
        .container { max-width: 1400px; margin: 0 auto; padding: 30px 20px; }
        .card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 30px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .territory-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .territory-card { background: white; border-radius: 12px; padding: 20px; cursor: pointer; transition: all 0.3s; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-decoration: none; display: block; }
        .territory-card:hover { transform: translateY(-3px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .territory-card h3 { color: #1a2c3e; margin-bottom: 10px; }
        .territory-card .value { font-size: 28px; font-weight: bold; color: #00a36c; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stat-card h3 { font-size: 14px; color: #666; margin-bottom: 10px; border-left: 3px solid; padding-left: 10px; }
        .stat-card .value { font-size: 28px; font-weight: bold; }
        .progress-bar { width: 100%; background: #eee; border-radius: 10px; overflow: hidden; height: 8px; margin-top: 10px; }
        .progress-fill { height: 8px; }
        .forecast-warning { color: #dc3545; font-weight: bold; font-size: 12px; margin-top: 10px; }
        .back-link { display: inline-block; margin-bottom: 20px; color: #00a36c; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; }
        .badge { padding: 4px 8px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .badge.success { background: #d4edda; color: #155724; }
        .badge.warning { background: #fff3cd; color: #856404; }
        .badge.danger { background: #f8d7da; color: #721c24; }
        .progress-bar-table { width: 80px; background: #eee; border-radius: 10px; overflow: hidden; display: inline-block; height: 6px; margin-left: 5px; }
        .progress-fill-table { background: #00a36c; height: 6px; }
        @media (max-width: 768px) { .territory-grid { grid-template-columns: 1fr; } }
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
        <?php if ($showManagerDetail && $selectedManager): ?>
            <!-- Детализация по руководителю -->
            <a href="region_manager.php" class="back-link">← Назад к территориям</a>
            
            <div class="card">
                <h2>🗺️ Территория: <?= $territories[$selectedTerritoryId]['name'] ?></h2>
                <p>Руководитель: <?= htmlspecialchars($selectedManager['name']) ?> (таб. <?= $selectedManager['tabel'] ?>)</p>
                <p>Сотрудников: <?= $managerStats['employees_count'] ?? 0 ?></p>
            </div>
            
            <!-- KPI руководителя -->
            <div class="stats-grid">
                <div class="stat-card"><h3 style="border-left-color: #00a36c">📞 Звонки</h3><div class="value"><?= number_format($managerStats['calls'] ?? 0) ?></div><div class="plan">План: <?= number_format($managerStats['plan_calls'] ?? 0) ?></div></div>
                <div class="stat-card"><h3 style="border-left-color: #ffc107">📅 Встречи</h3><div class="value"><?= number_format($managerStats['meetings'] ?? 0) ?></div><div class="plan">План: <?= number_format($managerStats['plan_meetings'] ?? 0) ?></div></div>
                <div class="stat-card"><h3 style="border-left-color: #dc3545">📄 Договоры</h3><div class="value"><?= number_format($managerStats['contracts'] ?? 0) ?></div><div class="plan">План: <?= number_format($managerStats['plan_contracts'] ?? 0) ?></div>
                    <div class="progress-bar"><div class="progress-fill" style="width: <?= $managerStats['plan_contracts'] > 0 ? min(100, round(($managerStats['contracts'] / $managerStats['plan_contracts']) * 100)) : 0 ?>%; background: #00a36c"></div></div>
                    <div class="forecast-warning">📈 Прогноз: <?= number_format($managerStats['forecast_contracts'] ?? 0) ?><br>
                    <?php if (($managerStats['needed_per_day'] ?? 0) > 0): ?>
                    ⚠️ Нужно в день: <strong><?= $managerStats['needed_per_day'] ?></strong>
                    <?php endif; ?>
                    </div>
                </div>
                <div class="stat-card"><h3 style="border-left-color: #17a2b8">💰 Оборот</h3><div class="value"><?= number_format($managerStats['turnover'] ?? 0, 0, ',', ' ') ?> ₽</div><div class="plan">План: <?= number_format($managerStats['plan_turnover'] ?? 0, 0, ',', ' ') ?> ₽</div></div>
            </div>
            
            <!-- Таблица сотрудников -->
            <div class="card">
                <h3>👥 Сотрудники территории</h3>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr><th>Сотрудник</th><th>Табельный</th><th>📄 Договоры</th><th>План</th><th>Выполнение</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teamEmployees as $emp): ?>
                            <tr>
                                <td><?= htmlspecialchars($emp['full_name']) ?></td>
                                <td><?= $emp['tabel_number'] ?></td>
                                <td><strong><?= number_format($emp['contracts']) ?></strong></td>
                                <td><?= $emp['plan'] ?></td>
                                <td>
                                    <span class="badge <?= $emp['percent'] >= 80 ? 'success' : ($emp['percent'] >= 60 ? 'warning' : 'danger') ?>"><?= $emp['percent'] ?>%</span>
                                    <div class="progress-bar-table"><div class="progress-fill-table" style="width: <?= $emp['percent'] ?>%"></div></div>
                                 </div>
                              </div>
                            </div>
                          <tr>
                            <?php endforeach; ?>
                        </tbody>
                     ?>
                </div>
            </div>
            
        <?php else: ?>
            <!-- Карточки территорий -->
            <div class="territory-grid">
                <?php foreach ($territories as $tid => $terr): 
                    $stats = $territoryStats[$tid] ?? null;
                    if (!$stats) continue;
                    $progress = $stats['progress'];
                    $statusColor = $progress >= 80 ? '#00a36c' : ($progress >= 60 ? '#ffc107' : '#dc3545');
                ?>
                <a href="?territory_id=<?= $tid ?>&manager_id=<?= $stats['manager']['id'] ?>" class="territory-card">
                    <h3>🗺️ <?= htmlspecialchars($terr['name']) ?></h3>
                    <div class="value" style="color: <?= $statusColor ?>"><?= number_format($stats['contracts']) ?> / <?= number_format($stats['plan_contracts']) ?></div>
                    <div>Договоров / План</div>
                    <div class="progress-bar"><div class="progress-fill" style="width: <?= $progress ?>%; background: <?= $statusColor ?>"></div></div>
                    <div style="font-size:12px; margin-top:10px;">📈 Прогноз: <?= number_format($stats['forecast_contracts']) ?></div>
                    <div style="font-size:12px;">👤 <?= htmlspecialchars($stats['manager']['name']) ?></div>
                </a>
                <?php endforeach; ?>
            </div>
            
            <!-- Сводная таблица по территориям -->
            <div class="card">
                <h3>📊 Сводка по территориям</h3>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr><th>Территория</th><th>Руководитель</th><th>📞 Звонки</th><th>📅 Встречи</th><th>📄 Договоры</th><th>💰 Оборот</th><th>Прогноз</th><th>Выполнение</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($territories as $tid => $terr): 
                                $stats = $territoryStats[$tid] ?? null;
                                if (!$stats) continue;
                                $progress = $stats['progress'];
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($terr['name']) ?></strong></td>
                                <td><?= htmlspecialchars($stats['manager']['name']) ?> (<?= $stats['manager']['tabel'] ?>)</td>
                                <td><?= number_format($stats['calls']) ?></td>
                                <td><?= number_format($stats['meetings']) ?></td>
                                <td><strong><?= number_format($stats['contracts']) ?></strong> / <?= number_format($stats['plan_contracts']) ?></td>
                                <td><?= number_format($stats['turnover'], 0, ',', ' ') ?> ₽</td>
                                <td><?= number_format($stats['forecast_contracts']) ?> <?= $stats['forecast_contracts'] < $stats['plan_contracts'] ? '⚠️' : '✅' ?></td>
                                <td>
                                    <span class="badge <?= $progress >= 80 ? 'success' : ($progress >= 60 ? 'warning' : 'danger') ?>"><?= $progress ?>%</span>
                                    <div class="progress-bar-table"><div class="progress-fill-table" style="width: <?= $progress ?>%"></div></div>
                                 </div>
                              </div>
                            </div>
                          </tr>
                            <?php endforeach; ?>
                        </tbody>
                     ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
