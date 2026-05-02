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

// Получаем данные сотрудника
$stmt = $pdo->prepare("SELECT tabel_number, full_name, manager_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Обработка редактирования отчёта
$editReport = null;
$editDate = null;
if (isset($_GET['edit'])) {
    $editDate = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM daily_reports WHERE user_id = ? AND report_date = ?");
    $stmt->execute([$user_id, $editDate]);
    $editReport = $stmt->fetch();
}

// Обработка сохранения (INSERT или UPDATE)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportDate = $_POST['report_date'] ?? date('Y-m-d');
    
    $calls = !empty($_POST['calls']) ? intval($_POST['calls']) : 0;
    $calls_answered = !empty($_POST['calls_answered']) ? intval($_POST['calls_answered']) : 0;
    $meetings = !empty($_POST['meetings']) ? intval($_POST['meetings']) : 0;
    $contracts = !empty($_POST['contracts']) ? intval($_POST['contracts']) : 0;
    $registrations = !empty($_POST['registrations']) ? intval($_POST['registrations']) : 0;
    $smart_cash = !empty($_POST['smart_cash']) ? intval($_POST['smart_cash']) : 0;
    $pos_systems = !empty($_POST['pos_systems']) ? intval($_POST['pos_systems']) : 0;
    $inn_leads = !empty($_POST['inn_leads']) ? intval($_POST['inn_leads']) : 0;
    $teams = !empty($_POST['teams']) ? intval($_POST['teams']) : 0;
    $turnover = !empty($_POST['turnover']) ? floatval(str_replace(',', '.', $_POST['turnover'])) : 0;
    
    $stmt = $pdo->prepare("SELECT id FROM daily_reports WHERE user_id = ? AND report_date = ?");
    $stmt->execute([$user_id, $reportDate]);
    $exists = $stmt->fetch();
    
    if ($exists) {
        $stmt = $pdo->prepare("
            UPDATE daily_reports SET 
                calls = ?, calls_answered = ?, meetings = ?, contracts = ?, registrations = ?,
                smart_cash = ?, pos_systems = ?, inn_leads = ?, teams = ?, turnover = ?
            WHERE user_id = ? AND report_date = ?
        ");
        $stmt->execute([
            $calls, $calls_answered, $meetings, $contracts, $registrations,
            $smart_cash, $pos_systems, $inn_leads, $teams, $turnover,
            $user_id, $reportDate
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO daily_reports 
            (user_id, report_date, calls, calls_answered, meetings, contracts, registrations, 
             smart_cash, pos_systems, inn_leads, teams, turnover)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_id, $reportDate,
            $calls, $calls_answered, $meetings, $contracts, $registrations,
            $smart_cash, $pos_systems, $inn_leads, $teams, $turnover
        ]);
    }
    header('Location: dashboard.php?saved=1');
    exit;
}

// Удаление отчёта
if (isset($_GET['delete'])) {
    $deleteDate = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM daily_reports WHERE user_id = ? AND report_date = ?");
    $stmt->execute([$user_id, $deleteDate]);
    header('Location: dashboard.php?deleted=1');
    exit;
}

// Показатели
$metrics = [
    'calls' => ['name' => 'Звонки', 'icon' => '📞', 'daily_plan' => 30, 'unit' => 'шт'],
    'calls_answered' => ['name' => 'Дозвоны', 'icon' => '✅', 'daily_plan' => 15, 'unit' => 'шт'],
    'meetings' => ['name' => 'Встречи', 'icon' => '📅', 'daily_plan' => 3, 'unit' => 'шт'],
    'contracts' => ['name' => 'Договоры', 'icon' => '📄', 'daily_plan' => 2, 'unit' => 'шт'],
    'registrations' => ['name' => 'Регистрации', 'icon' => '📝', 'daily_plan' => 3, 'unit' => 'шт'],
    'smart_cash' => ['name' => 'Смарт-кассы', 'icon' => '💳', 'daily_plan' => 0.33, 'unit' => 'шт'],
    'pos_systems' => ['name' => 'ПОС-системы', 'icon' => '🖥️', 'daily_plan' => 0.033, 'unit' => 'шт'],
    'inn_leads' => ['name' => 'ИНН чаевые', 'icon' => '🔗', 'daily_plan' => 2, 'unit' => 'шт'],
    'teams' => ['name' => 'Команды чаевые', 'icon' => '👥', 'daily_plan' => 0.16, 'unit' => 'шт'],
    'turnover' => ['name' => 'Оборот чаевых', 'icon' => '💰', 'daily_plan' => 7333, 'unit' => '₽']
];

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$daysPassed = (int)date('j');
$daysLeft = (int)date('t') - $daysPassed;

// Получаем отчёт за сегодня
$stmt = $pdo->prepare("SELECT * FROM daily_reports WHERE user_id = ? AND report_date = ?");
$stmt->execute([$user_id, $today]);
$todayReport = $stmt->fetch();
if (!$todayReport) {
    $todayReport = [];
    foreach ($metrics as $key => $m) {
        $todayReport[$key] = 0;
    }
}

// Получаем все отчёты за месяц для истории
$stmt = $pdo->prepare("
    SELECT * FROM daily_reports 
    WHERE user_id = ? AND report_date BETWEEN ? AND ?
    ORDER BY report_date DESC
");
$stmt->execute([$user_id, $monthStart, $monthEnd]);
$historyReports = $stmt->fetchAll();

// Получаем данные за месяц для KPI
$stmt = $pdo->prepare("
    SELECT SUM(calls) as calls, SUM(calls_answered) as calls_answered,
           SUM(meetings) as meetings, SUM(contracts) as contracts,
           SUM(registrations) as registrations, SUM(smart_cash) as smart_cash,
           SUM(pos_systems) as pos_systems, SUM(inn_leads) as inn_leads,
           SUM(teams) as teams, SUM(turnover) as turnover
    FROM daily_reports WHERE user_id = ? AND report_date BETWEEN ? AND ?
");
$stmt->execute([$user_id, $monthStart, $monthEnd]);
$monthTotal = $stmt->fetch();

// Получаем план на месяц
$stmt = $pdo->prepare("SELECT * FROM monthly_plans WHERE user_id = ? AND year = ? AND month = ?");
$stmt->execute([$user_id, date('Y'), date('m')]);
$userPlan = $stmt->fetch();

// Формируем данные по каждому показателю
$reportData = [];
$hasCritical = false;
$hasWarning = false;
$criticalMetrics = [];
$warningMetrics = [];

$summaryData = [];
foreach ($metrics as $key => $m) {
    $planMonth = $userPlan ? ($userPlan["plan_$key"] ?? ($m['daily_plan'] * 30)) : ($m['daily_plan'] * 30);
    $factMonth = $monthTotal[$key] ?? 0;
    $factToday = $todayReport[$key] ?? 0;
    
    $dailyNorm = $m['daily_plan'];
    $todayProgress = $dailyNorm > 0 ? min(100, round(($factToday / $dailyNorm) * 100)) : 0;
    
    $forecast = $daysPassed > 0 ? round($factMonth + ($factMonth / $daysPassed) * $daysLeft) : $factMonth;
    $deviation = $planMonth - $forecast;
    $neededPerDay = $daysLeft > 0 ? max(0, round(($planMonth - $factMonth) / $daysLeft)) : 0;
    $progressMonth = $planMonth > 0 ? min(100, round(($factMonth / $planMonth) * 100)) : 0;
    
    $status = 'success';
    if ($deviation > 20) {
        $status = 'critical';
        $hasCritical = true;
        $criticalMetrics[] = $m['name'];
    } elseif ($deviation > 10) {
        $status = 'warning';
        $hasWarning = true;
        $warningMetrics[] = $m['name'];
    }
    
    $reportData[$key] = [
        'name' => $m['name'],
        'icon' => $m['icon'],
        'daily_norm' => $dailyNorm,
        'today_fact' => $factToday,
        'today_progress' => $todayProgress,
        'month_fact' => $factMonth,
        'month_plan' => $planMonth,
        'month_progress' => $progressMonth,
        'forecast' => $forecast,
        'deviation' => $deviation,
        'needed_per_day' => $neededPerDay,
        'status' => $status,
        'unit' => $m['unit']
    ];
    
    $summaryData[$key] = [
        'fact' => $factMonth,
        'plan' => $planMonth,
        'unit' => $m['unit'],
        'name' => $m['name'],
        'icon' => $m['icon']
    ];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Sales CRM - Мой дашборд</title>
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
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stat-card h3 { font-size: 14px; color: #666; margin-bottom: 15px; border-left: 3px solid; padding-left: 10px; }
        .stat-card .value { font-size: 28px; font-weight: bold; }
        .stat-card .daily-target { font-size: 12px; color: #999; margin-top: 5px; }
        .progress-bar { width: 100%; background: #eee; border-radius: 10px; overflow: hidden; height: 8px; margin-top: 10px; }
        .progress-fill { height: 8px; }
        .status-message { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: bold; }
        .status-critical { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .status-warning { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; }
        .status-success { background: #d4edda; color: #155724; border-left: 4px solid #00a36c; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-size: 12px; color: #555; }
        input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; }
        button { background: #00a36c; color: white; border: none; padding: 12px 20px; border-radius: 8px; cursor: pointer; font-size: 16px; width: 100%; }
        button:hover { background: #008a5a; }
        .success-message { background: #d4edda; color: #155724; padding: 10px; border-radius: 8px; margin-bottom: 20px; text-align: center; }
        .forecast { font-size: 12px; margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; }
        .forecast-warning { color: #dc3545; }
        .forecast-success { color: #00a36c; }
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .summary-item { background: #f8f9fa; border-radius: 10px; padding: 12px; text-align: center; }
        .summary-item .summary-value { font-size: 20px; font-weight: bold; }
        .summary-item .summary-label { font-size: 12px; color: #666; margin-top: 5px; }
        .history-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .history-table th, .history-table td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; font-size: 12px; }
        .history-table th { background: #f8f9fa; }
        .edit-btn, .delete-btn { padding: 4px 8px; border-radius: 6px; text-decoration: none; font-size: 11px; margin: 0 2px; display: inline-block; }
        .edit-btn { background: #00a36c; color: white; }
        .delete-btn { background: #dc3545; color: white; }
        .edit-form-container { background: #f8f9fa; padding: 20px; border-radius: 12px; margin-bottom: 20px; }
        .edit-form-container h3 { margin-bottom: 15px; color: #1a2c3e; }
        .scroll-table { overflow-x: auto; max-height: 400px; overflow-y: auto; }
        @media (max-width: 768px) { .stats-grid { grid-template-columns: 1fr; } .summary-grid { grid-template-columns: repeat(2, 1fr); } }
    </style>
</head>
<body>
    <div class="header">
        <h1>Sales CRM</h1>
        <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
            <span>👋 <?= htmlspecialchars($name) ?> (<?= htmlspecialchars($role) ?>)</span>
            <div class="nav">
                <a href="dashboard.php">📊 Дашборд</a>
                <?php if ($role !== 'employee'): ?>
                <a href="team.php">👥 Команда</a>
                <?php endif; ?>
                <?php if ($role === 'admin'): ?>
                <a href="admin.php">⚙️ Админ</a>
                <?php endif; ?>
                <?php if ($role !== 'employee'): ?>
                <a href="region_manager.php">🗺️ Тер. менеджер</a>
                <?php endif; ?>
            </div>
            <a href="logout.php" class="logout">Выйти</a>
        </div>
    </div>
    <div class="container">
        <?php if (isset($_GET['saved'])): ?>
        <div class="success-message">✅ Отчёт сохранён!</div>
        <?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?>
        <div class="success-message">✅ Отчёт удалён!</div>
        <?php endif; ?>
        
        <!-- Форма редактирования -->
        <?php if ($editReport): ?>
        <div class="edit-form-container card">
            <h3>✏️ Редактирование отчёта за <?= date('d.m.Y', strtotime($editDate)) ?></h3>
            <form method="post">
                <input type="hidden" name="report_date" value="<?= $editDate ?>">
                <div class="form-grid">
                    <div class="form-group"><label>📞 Звонки</label><input type="number" name="calls" value="<?= $editReport['calls'] ?? 0 ?>"></div>
                    <div class="form-group"><label>✅ Дозвоны</label><input type="number" name="calls_answered" value="<?= $editReport['calls_answered'] ?? 0 ?>"></div>
                    <div class="form-group"><label>📅 Встречи</label><input type="number" name="meetings" value="<?= $editReport['meetings'] ?? 0 ?>"></div>
                    <div class="form-group"><label>📄 Договоры</label><input type="number" name="contracts" value="<?= $editReport['contracts'] ?? 0 ?>"></div>
                    <div class="form-group"><label>📝 Регистрации</label><input type="number" name="registrations" value="<?= $editReport['registrations'] ?? 0 ?>"></div>
                    <div class="form-group"><label>💳 Смарт-кассы</label><input type="number" name="smart_cash" value="<?= $editReport['smart_cash'] ?? 0 ?>"></div>
                    <div class="form-group"><label>🖥️ ПОС-системы</label><input type="number" name="pos_systems" value="<?= $editReport['pos_systems'] ?? 0 ?>"></div>
                    <div class="form-group"><label>🔗 ИНН для чаевых</label><input type="number" name="inn_leads" value="<?= $editReport['inn_leads'] ?? 0 ?>"></div>
                    <div class="form-group"><label>👥 Команды на чаевые</label><input type="number" name="teams" value="<?= $editReport['teams'] ?? 0 ?>"></div>
                    <div class="form-group"><label>💰 Оборот чаевых (руб)</label><input type="number" name="turnover" value="<?= $editReport['turnover'] ?? 0 ?>" step="1"></div>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 15px;">
                    <button type="submit">💾 Сохранить изменения</button>
                    <a href="dashboard.php" style="background: #6c757d; color: white; padding: 12px 20px; border-radius: 8px; text-decoration: none;">❌ Отмена</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- Статусная панель -->
        <?php if ($hasCritical): ?>
        <div class="status-message status-critical">
            🔴 КРИТИЧЕСКАЯ ЗОНА! Отставание по показателям: <?= implode(', ', $criticalMetrics) ?>.
            Немедленно свяжитесь с руководителем!
        </div>
        <?php elseif ($hasWarning): ?>
        <div class="status-message status-warning">
            ⚠️ ВНИМАНИЕ! Отставание по показателям: <?= implode(', ', $warningMetrics) ?>.
            Рекомендуем ускориться!
        </div>
        <?php else: ?>
        <div class="status-message status-success">
            🎉 Отличная работа! Так держать!
        </div>
        <?php endif; ?>
        
        <!-- Суммарная строка -->
        <div class="card">
            <h3>📊 ИТОГО: ПЛАН / ФАКТ за месяц</h3>
            <div class="summary-grid">
                <?php foreach ($summaryData as $key => $s): ?>
                <div class="summary-item">
                    <div class="summary-value"><?= number_format($s['fact']) ?> / <?= number_format($s['plan']) ?> <?= $s['unit'] ?></div>
                    <div class="summary-label"><?= $s['icon'] ?> <?= $s['name'] ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- KPI карточки -->
        <div class="stats-grid">
            <?php foreach ($reportData as $key => $d): 
                $statusColor = $d['status'] == 'critical' ? '#dc3545' : ($d['status'] == 'warning' ? '#ffc107' : '#00a36c');
            ?>
            <div class="stat-card">
                <h3 style="border-left-color: <?= $statusColor ?>"><?= $d['icon'] ?> <?= $d['name'] ?></h3>
                <div class="value" style="color: <?= $statusColor ?>">
                    <?= number_format($d['today_fact']) ?> / <?= number_format($d['daily_norm']) ?> <?= $d['unit'] ?>
                </div>
                <div class="daily-target">Цель на сегодня: <?= number_format($d['daily_norm']) ?> <?= $d['unit'] ?></div>
                <div class="progress-bar"><div class="progress-fill" style="width: <?= $d['today_progress'] ?>%; background: <?= $statusColor ?>"></div></div>
                <div class="forecast">
                    📈 Месяц: <?= number_format($d['month_fact']) ?> / <?= number_format($d['month_plan']) ?> <?= $d['unit'] ?>
                    (<?= $d['month_progress'] ?>%)<br>
                    📊 Прогноз: <?= number_format($d['forecast']) ?> <?= $d['unit'] ?>
                    <?php if ($d['deviation'] > 0): ?>
                    <span class="forecast-warning"><br>⚠️ Отставание: <?= number_format($d['deviation']) ?> <?= $d['unit'] ?>
                    <br>🎯 Нужно в день: <strong><?= number_format($d['needed_per_day']) ?></strong> <?= $d['unit'] ?></span>
                    <?php elseif ($d['deviation'] < 0): ?>
                    <span class="forecast-success"><br>✅ Перевыполнение: <?= number_format(abs($d['deviation'])) ?> <?= $d['unit'] ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Форма ежедневного отчёта -->
        <div class="card">
            <h3>📝 Ежедневный отчёт за <?= date('d.m.Y') ?></h3>
            <form method="post">
                <input type="hidden" name="report_date" value="<?= $today ?>">
                <div class="form-grid">
                    <div class="form-group"><label>📞 Звонки</label><input type="number" name="calls" value="<?= $todayReport['calls'] ?? 0 ?>"></div>
                    <div class="form-group"><label>✅ Дозвоны</label><input type="number" name="calls_answered" value="<?= $todayReport['calls_answered'] ?? 0 ?>"></div>
                    <div class="form-group"><label>📅 Встречи</label><input type="number" name="meetings" value="<?= $todayReport['meetings'] ?? 0 ?>"></div>
                    <div class="form-group"><label>📄 Договоры</label><input type="number" name="contracts" value="<?= $todayReport['contracts'] ?? 0 ?>"></div>
                    <div class="form-group"><label>📝 Регистрации</label><input type="number" name="registrations" value="<?= $todayReport['registrations'] ?? 0 ?>"></div>
                    <div class="form-group"><label>💳 Смарт-кассы</label><input type="number" name="smart_cash" value="<?= $todayReport['smart_cash'] ?? 0 ?>"></div>
                    <div class="form-group"><label>🖥️ ПОС-системы</label><input type="number" name="pos_systems" value="<?= $todayReport['pos_systems'] ?? 0 ?>"></div>
                    <div class="form-group"><label>🔗 ИНН для чаевых</label><input type="number" name="inn_leads" value="<?= $todayReport['inn_leads'] ?? 0 ?>"></div>
                    <div class="form-group"><label>👥 Команды на чаевые</label><input type="number" name="teams" value="<?= $todayReport['teams'] ?? 0 ?>"></div>
                    <div class="form-group"><label>💰 Оборот чаевых (руб)</label><input type="number" name="turnover" value="<?= $todayReport['turnover'] ?? 0 ?>" step="1"></div>
                </div>
                <button type="submit">Сохранить отчёт</button>
            </form>
        </div>
        
        <!-- История отчётов -->
        <div class="card">
            <h3>📋 История отчётов</h3>
            <div class="scroll-table">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Дата</th>
                            <th>📞</th>
                            <th>✅</th>
                            <th>📅</th>
                            <th>📄</th>
                            <th>📝</th>
                            <th>💳</th>
                            <th>🖥️</th>
                            <th>🔗</th>
                            <th>👥</th>
                            <th>💰</th>
                            <th></th>
                         </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historyReports as $report): ?>
                        <tr>
                            <td><?= date('d.m.Y', strtotime($report['report_date'])) ?></td>
                            <td><?= number_format($report['calls']) ?></td>
                            <td><?= number_format($report['calls_answered']) ?></td>
                            <td><?= number_format($report['meetings']) ?></td>
                            <td><?= number_format($report['contracts']) ?></td>
                            <td><?= number_format($report['registrations']) ?></td>
                            <td><?= number_format($report['smart_cash']) ?></td>
                            <td><?= number_format($report['pos_systems']) ?></td>
                            <td><?= number_format($report['inn_leads']) ?></td>
                            <td><?= number_format($report['teams']) ?></td>
                            <td><?= number_format($report['turnover'], 0, ',', ' ') ?> ₽</td>
                            <td>
                                <a href="?edit=<?= $report['report_date'] ?>" class="edit-btn">✏️</a>
                                <a href="?delete=<?= $report['report_date'] ?>" class="delete-btn" onclick="return confirm('Удалить?')">🗑️</a>
                             </div>
                          </div>
                        </div>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($historyReports)): ?>
                        <tr><td colspan="12" style="text-align:center">Нет отчётов</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
