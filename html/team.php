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

// Получаем сотрудников команды
if ($role == 'admin') {
    $employees = $pdo->query("SELECT id, tabel_number, full_name FROM users WHERE role != 'admin'")->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT id, tabel_number, full_name FROM users WHERE manager_id = ? OR id = ?");
    $stmt->execute([$user_id, $user_id]);
    $employees = $stmt->fetchAll();
}

$employeeIds = array_column($employees, 'id');
$placeholders = implode(',', array_fill(0, count($employeeIds), '?'));

$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$daysPassed = (int)date('j');
$daysLeft = (int)date('t') - $daysPassed;

// Показатели для KPI
$kpiMetrics = [
    'calls' => ['name' => 'Звонки', 'icon' => '📞', 'color' => '#00a36c', 'plan_field' => 'plan_calls', 'daily_plan' => 30],
    'calls_answered' => ['name' => 'Дозвоны', 'icon' => '✅', 'color' => '#1a2c3e', 'plan_field' => 'plan_answered', 'daily_plan' => 15],
    'meetings' => ['name' => 'Встречи', 'icon' => '📅', 'color' => '#ffc107', 'plan_field' => 'plan_meetings', 'daily_plan' => 3],
    'contracts' => ['name' => 'Договоры', 'icon' => '📄', 'color' => '#dc3545', 'plan_field' => 'plan_contracts', 'daily_plan' => 2],
    'registrations' => ['name' => 'Регистрации', 'icon' => '📝', 'color' => '#17a2b8', 'plan_field' => 'plan_registrations', 'daily_plan' => 3],
    'smart_cash' => ['name' => 'Смарт-кассы', 'icon' => '💳', 'color' => '#6f42c1', 'plan_field' => 'plan_smart_cash', 'daily_plan' => 0.33],
    'pos_systems' => ['name' => 'ПОС', 'icon' => '🖥️', 'color' => '#fd7e14', 'plan_field' => 'plan_pos_systems', 'daily_plan' => 0.033],
    'inn_leads' => ['name' => 'ИНН чаевые', 'icon' => '🔗', 'color' => '#20c997', 'plan_field' => 'plan_inn_leads', 'daily_plan' => 2],
    'teams' => ['name' => 'Команды чаевые', 'icon' => '👥', 'color' => '#e83e8c', 'plan_field' => 'plan_teams', 'daily_plan' => 0.16],
    'turnover' => ['name' => 'Оборот чаевых', 'icon' => '💰', 'color' => '#00a36c', 'plan_field' => 'plan_turnover', 'daily_plan' => 7333]
];

// Получаем суммарные данные по команде
$totalData = [];
foreach ($kpiMetrics as $key => $m) {
    $totalData[$key] = 0;
}

$stmt = $pdo->prepare("
    SELECT SUM(calls) as calls, SUM(calls_answered) as calls_answered,
           SUM(meetings) as meetings, SUM(contracts) as contracts,
           SUM(registrations) as registrations, SUM(smart_cash) as smart_cash,
           SUM(pos_systems) as pos_systems, SUM(inn_leads) as inn_leads,
           SUM(teams) as teams, SUM(turnover) as turnover
    FROM daily_reports 
    WHERE user_id IN ($placeholders) AND report_date BETWEEN ? AND ?
");
$stmt->execute(array_merge($employeeIds, [$monthStart, $monthEnd]));
$row = $stmt->fetch();
foreach ($kpiMetrics as $key => $m) {
    $totalData[$key] = $row[$key] ?? 0;
}

// Получаем планы команды
$teamPlans = [];
$stmt = $pdo->prepare("
    SELECT SUM(plan_calls) as plan_calls, SUM(plan_answered) as plan_answered,
           SUM(plan_meetings) as plan_meetings, SUM(plan_contracts) as plan_contracts,
           SUM(plan_registrations) as plan_registrations, SUM(plan_smart_cash) as plan_smart_cash,
           SUM(plan_pos_systems) as plan_pos_systems, SUM(plan_inn_leads) as plan_inn_leads,
           SUM(plan_teams) as plan_teams, SUM(plan_turnover) as plan_turnover
    FROM monthly_plans WHERE user_id IN ($placeholders) AND year = ? AND month = ?
");
$stmt->execute(array_merge($employeeIds, [date('Y'), date('m')]));
$teamPlans = $stmt->fetch();

// Прогнозы
$forecasts = [];
foreach ($kpiMetrics as $key => $m) {
    $plan = $teamPlans["plan_$key"] ?? ($m['daily_plan'] * 30);
    $fact = $totalData[$key];
    $forecast = $daysPassed > 0 ? round($fact + ($fact / $daysPassed) * $daysLeft) : $fact;
    $deviation = $plan - $forecast;
    $neededPerDay = $daysLeft > 0 ? max(0, round(($plan - $fact) / $daysLeft)) : 0;
    $progress = $plan > 0 ? min(100, round(($fact / $plan) * 100)) : 0;
    $forecasts[$key] = [
        'plan' => $plan, 'fact' => $fact, 'forecast' => $forecast,
        'deviation' => $deviation, 'needed_per_day' => $neededPerDay,
        'progress' => $progress, 'unit' => $m['name'] == 'Оборот чаевых' ? '₽' : 'шт',
        'status' => $progress >= 100 ? 'success' : ($progress >= 70 ? 'warning' : 'danger')
    ];
}

// Данные сотрудников для таблицы
$employeeData = [];
foreach ($employees as $emp) {
    $stmt = $pdo->prepare("
        SELECT SUM(contracts) as contracts, SUM(calls) as calls,
               SUM(meetings) as meetings, SUM(turnover) as turnover
        FROM daily_reports 
        WHERE user_id = ? AND report_date BETWEEN ? AND ?
    ");
    $stmt->execute([$emp['id'], $monthStart, $monthEnd]);
    $empData = $stmt->fetch();
    
    $stmt = $pdo->prepare("SELECT plan_contracts FROM monthly_plans WHERE user_id = ? AND year = ? AND month = ?");
    $stmt->execute([$emp['id'], date('Y'), date('m')]);
    $empPlan = $stmt->fetch()['plan_contracts'] ?? 30;
    
    $employeeData[] = [
        'name' => $emp['full_name'],
        'tabel' => $emp['tabel_number'],
        'calls' => $empData['calls'] ?? 0,
        'meetings' => $empData['meetings'] ?? 0,
        'contracts' => $empData['contracts'] ?? 0,
        'turnover' => $empData['turnover'] ?? 0,
        'plan' => $empPlan,
        'percent' => $empPlan > 0 ? min(100, round((($empData['contracts'] ?? 0) / $empPlan) * 100)) : 0
    ];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Sales CRM - Команда</title>
    <meta charset="utf-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; }
        .nav { display: flex; gap: 15px; flex-wrap: wrap; }
        .nav a, .logout { color: white; text-decoration: none; padding: 8px 16px; border-radius: 8px; background: #00a36c; }
        .container { max-width: 1400px; margin: 0 auto; padding: 30px 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stat-card h3 { font-size: 14px; color: #666; margin-bottom: 10px; border-left: 3px solid; padding-left: 10px; }
        .stat-card .value { font-size: 28px; font-weight: bold; }
        .stat-card .plan { font-size: 12px; color: #999; margin-top: 5px; }
        .stat-card .progress-bar { width: 100%; background: #eee; border-radius: 10px; overflow: hidden; height: 8px; margin-top: 10px; }
        .stat-card .progress-fill { height: 8px; }
        .forecast-warning { color: #dc3545; font-weight: bold; }
        .forecast-success { color: #00a36c; }
        .card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 30px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; }
        .badge { padding: 4px 8px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .badge.success { background: #d4edda; color: #155724; }
        .badge.warning { background: #fff3cd; color: #856404; }
        .badge.danger { background: #f8d7da; color: #721c24; }
        .progress-bar-table { width: 80px; background: #eee; border-radius: 10px; overflow: hidden; display: inline-block; height: 6px; margin-left: 5px; }
        .progress-fill-table { background: #00a36c; height: 6px; }
        @media (max-width: 768px) { .stats-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<?php require_once "navbar.php"; ?>
        </div>
    </div>
    <div class="container">
        <!-- KPI карточки -->
        <div class="stats-grid">
            <?php foreach ($kpiMetrics as $key => $m): 
                $f = $forecasts[$key];
                $statusColor = $f['status'] == 'success' ? '#00a36c' : ($f['status'] == 'warning' ? '#ffc107' : '#dc3545');
                $unit = $f['unit'];
            ?>
            <div class="stat-card">
                <h3 style="border-left-color: <?= $m['color'] ?>"><?= $m['icon'] ?> <?= $m['name'] ?></h3>
                <div class="value" style="color: <?= $statusColor ?>"><?= number_format($f['fact']) ?> <?= $unit ?></div>
                <div class="plan">План: <?= number_format($f['plan']) ?> <?= $unit ?></div>
                <div class="progress-bar"><div class="progress-fill" style="width: <?= $f['progress'] ?>%; background: <?= $statusColor ?>"></div></div>
                <div class="forecast-<?= $f['deviation'] > 0 ? 'warning' : 'success' ?>" style="margin-top:10px; font-size:12px;">
                    📈 Прогноз: <?= number_format($f['forecast']) ?> <?= $unit ?>
                    <?php if ($f['deviation'] > 0): ?>
                    <br>⚠️ Нужно в день: <strong><?= $f['needed_per_day'] ?></strong> <?= $unit ?>
                    <?php elseif ($f['deviation'] < 0): ?>
                    <br>✅ Перевыполнение
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Таблица сотрудников -->
        <div class="card">
            <h3>👥 Детальная статистика сотрудников</h3>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Сотрудник</th>
                            <th>📞 Звонки</th>
                            <th>📅 Встречи</th>
                            <th>📄 Договоры</th>
                            <th>💰 Оборот</th>
                            <th>План</th>
                            <th>Выполнение</th>
                         </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employeeData as $emp): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($emp['name']) ?></strong><br><span style="font-size:10px;color:#999;">таб. <?= $emp['tabel'] ?></span></td>
                            <td><?= number_format($emp['calls']) ?></td>
                            <td><?= number_format($emp['meetings']) ?></td>
                            <td><strong><?= number_format($emp['contracts']) ?></strong></td>
                            <td><?= number_format($emp['turnover'], 0, ',', ' ') ?> ₽</td>
                            <td><?= $emp['plan'] ?></td>
                            <td>
                                <span class="badge <?= $emp['percent'] >= 80 ? 'success' : ($emp['percent'] >= 60 ? 'warning' : 'danger') ?>"><?= $emp['percent'] ?>%</span>
                                <div class="progress-bar-table"><div class="progress-fill-table" style="width: <?= $emp['percent'] ?>%"></div></div>
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
