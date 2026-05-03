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

if (!$user['tabel_number']) {
    die("Ошибка: у сотрудника не указан табельный номер");
}

// Текущий месяц
$current_month = date('Y-m');
$days_in_month = date('t');
$current_day = date('j');
$days_passed = $days_in_month - $days_in_month + $current_day - 1; // дни прошло
$days_remaining = $days_in_month - $current_day + 1;

// Получаем МЕСЯЧНЫЕ планы из таблицы plans
$stmt = $pdo->prepare("SELECT * FROM plans WHERE tabel_number = ?");
$stmt->execute([$user['tabel_number']]);
$plans = $stmt->fetch();

if (!$plans) {
    die("Ошибка: не найдены планы для сотрудника. Обратитесь к администратору.");
}

// Получаем факт за текущий месяц
$stmt = $pdo->prepare("
    SELECT 
        SUM(calls) as calls_fact,
        SUM(calls_answered) as calls_answered_fact,
        SUM(meetings) as meetings_fact,
        SUM(contracts) as contracts_fact,
        SUM(registrations) as registrations_fact,
        SUM(smart_cash) as smart_cash_fact,
        SUM(pos_systems) as pos_systems_fact,
        SUM(inn_leads) as inn_leads_fact,
        SUM(teams) as teams_fact,
        SUM(turnover) as turnover_fact
    FROM daily_reports 
    WHERE user_id = ? AND strftime('%Y-%m', report_date) = ?
");
$stmt->execute([$user_id, $current_month]);
$fact = $stmt->fetch();

foreach ($fact as $key => $value) {
    if ($value === null) $fact[$key] = 0;
}

$metrics = [
    'calls' => ['name' => '📞 Звонки', 'plan_month' => $plans['calls_plan'], 'fact' => $fact['calls_fact']],
    'calls_answered' => ['name' => '✅ Дозвоны', 'plan_month' => $plans['calls_answered_plan'], 'fact' => $fact['calls_answered_fact']],
    'meetings' => ['name' => '🤝 Встречи', 'plan_month' => $plans['meetings_plan'], 'fact' => $fact['meetings_fact']],
    'contracts' => ['name' => '📄 Договоры', 'plan_month' => $plans['contracts_plan'], 'fact' => $fact['contracts_fact']],
    'registrations' => ['name' => '📝 Регистрации', 'plan_month' => $plans['registrations_plan'], 'fact' => $fact['registrations_fact']],
    'smart_cash' => ['name' => '💳 smart-кассы', 'plan_month' => $plans['smart_cash_plan'], 'fact' => $fact['smart_cash_fact']],
    'pos_systems' => ['name' => '🖥️ POS-системы', 'plan_month' => $plans['pos_systems_plan'], 'fact' => $fact['pos_systems_fact']],
    'inn_leads' => ['name' => '📊 инн по чаевым', 'plan_month' => $plans['inn_leads_plan'], 'fact' => $fact['inn_leads_fact']],
    'teams' => ['name' => '👥 новые команды по чаевым', 'plan_month' => $plans['teams_plan'], 'fact' => $fact['teams_fact']],
    'turnover' => ['name' => '💰 новый оборот по чаевым', 'plan_month' => $plans['turnover_plan'], 'fact' => $fact['turnover_fact'], 'is_money' => true]
];

$forecast = [];
$daily_goal = [];
$status = [];
$progress = [];
$color = [];
$behind = [];

foreach ($metrics as $key => &$m) {
    $plan_month = (int)$m['plan_month'];
    $factVal = (int)$m['fact'];
    
    // Прогресс %
    $progress[$key] = $plan_month > 0 ? min(100, round(($factVal / $plan_month) * 100)) : 0;
    
    // Дневная норма для выполнения плана
    $daily_required = ceil($plan_month / $days_in_month);
    
    // Сколько должно быть по графику на сегодня
    $should_be = $daily_required * $days_passed;
    
    // Отставание
    $deviation = $should_be - $factVal;
    $behind[$key] = $deviation > 0;
    
    if ($deviation > 0 && $days_remaining > 0) {
        $extra = ceil($deviation / $days_remaining);
        $daily_goal[$key] = $daily_required + $extra;
    } else {
        $daily_goal[$key] = $daily_required;
    }
    
    // Прогноз
    if ($days_passed > 0) {
        $daily_avg = $factVal / $days_passed;
        $forecast_val = $factVal + ($daily_avg * $days_remaining);
    } else {
        $forecast_val = $plan_month;
    }
    $forecast[$key] = round($forecast_val);
    
    // Статус
    if ($forecast_val >= $plan_month) {
        $status[$key] = '🚀 Будет выполнено';
        $color[$key] = '#00a36c';
    } elseif ($forecast_val >= $plan_month * 0.8) {
        $status[$key] = '⚠️ Под угрозой';
        $color[$key] = '#f59e0b';
    } else {
        $status[$key] = '🔴 Провал';
        $color[$key] = '#ef4444';
    }
    
    $m['daily_required'] = $daily_required;
    $m['daily_goal'] = $daily_goal[$key];
    $m['behind'] = $behind[$key];
    $m['deviation'] = $deviation;
    $m['forecast'] = $forecast[$key];
    $m['progress'] = $progress[$key];
    $m['status'] = $status[$key];
    $m['color'] = $color[$key];
}

// Обработка сохранения отчёта (без ON CONFLICT)
$editReport = null;
$editDate = null;

if (isset($_GET['edit'])) {
    $editDate = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM daily_reports WHERE user_id = ? AND report_date = ?");
    $stmt->execute([$user_id, $editDate]);
    $editReport = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_report'])) {
    $report_date = $_POST['report_date'];
    $calls = (int)$_POST['calls'];
    $calls_answered = (int)$_POST['calls_answered'];
    $meetings = (int)$_POST['meetings'];
    $contracts = (int)$_POST['contracts'];
    $registrations = (int)$_POST['registrations'];
    $smart_cash = (int)$_POST['smart_cash'];
    $pos_systems = (int)$_POST['pos_systems'];
    $inn_leads = (int)$_POST['inn_leads'];
    $teams = (int)$_POST['teams'];
    $turnover = (int)$_POST['turnover'];
    
    // Проверяем, есть ли уже отчёт за эту дату
    $check = $pdo->prepare("SELECT id FROM daily_reports WHERE user_id = ? AND report_date = ?");
    $check->execute([$user_id, $report_date]);
    
    if ($check->fetch()) {
        // Обновляем
        $stmt = $pdo->prepare("
            UPDATE daily_reports SET 
                calls = ?, calls_answered = ?, meetings = ?, contracts = ?, registrations = ?,
                smart_cash = ?, pos_systems = ?, inn_leads = ?, teams = ?, turnover = ?
            WHERE user_id = ? AND report_date = ?
        ");
        $stmt->execute([$calls, $calls_answered, $meetings, $contracts, $registrations,
                        $smart_cash, $pos_systems, $inn_leads, $teams, $turnover,
                        $user_id, $report_date]);
    } else {
        // Вставляем
        $stmt = $pdo->prepare("
            INSERT INTO daily_reports 
            (user_id, report_date, calls, calls_answered, meetings, contracts, registrations,
             smart_cash, pos_systems, inn_leads, teams, turnover)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $report_date, $calls, $calls_answered, $meetings, $contracts, 
                        $registrations, $smart_cash, $pos_systems, $inn_leads, $teams, $turnover]);
    }
    
    header('Location: dashboard.php?saved=1');
    exit;
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM daily_reports WHERE user_id = ? AND report_date = ?");
    $stmt->execute([$user_id, $_GET['delete']]);
    header('Location: dashboard.php?deleted=1');
    exit;
}

// История отчётов
$stmt = $pdo->prepare("
    SELECT * FROM daily_reports 
    WHERE user_id = ? AND strftime('%Y-%m', report_date) = ?
    ORDER BY report_date DESC
");
$stmt->execute([$user_id, $current_month]);
$history = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Дашборд сотрудника - Sales CRM</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; background: #f5f7fb; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { color: #00a36c; margin-bottom: 10px; }
        .subtitle { color: #666; margin-bottom: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 16px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-left: 4px solid; }
        .stat-card h3 { font-size: 14px; color: #666; margin-bottom: 10px; }
        .stat-card .fact { font-size: 28px; font-weight: bold; margin-bottom: 5px; }
        .stat-card .plan-month { font-size: 12px; color: #888; margin-bottom: 10px; }
        .progress-bar { background: #e5e7eb; border-radius: 10px; height: 8px; margin: 10px 0; overflow: hidden; }
        .progress-fill { background: #00a36c; height: 100%; border-radius: 10px; transition: width 0.3s; }
        .forecast { font-size: 12px; margin-top: 8px; padding-top: 8px; border-top: 1px solid #eee; }
        .daily-goal { background: #fef3c7; padding: 8px; border-radius: 8px; margin-top: 10px; font-size: 13px; }
        .daily-goal-warning { background: #fee2e2; color: #dc2626; }
        .status-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; margin-left: 8px; }
        .month-info { font-size: 10px; color: #aaa; margin-top: 5px; text-align: right; }
        .report-form { background: white; border-radius: 16px; padding: 20px; margin-bottom: 30px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .form-group label { display: block; font-size: 12px; color: #666; margin-bottom: 5px; }
        .form-group input { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 8px; }
        button { background: #00a36c; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-size: 14px; }
        .history-table { width: 100%; border-collapse: collapse; }
        .history-table th, .history-table td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; font-size: 13px; }
        .history-table th { background: #f8f9fa; }
        .edit-btn { background: #f59e0b; padding: 4px 8px; border-radius: 6px; text-decoration: none; color: white; font-size: 11px; }
        .delete-btn { background: #ef4444; padding: 4px 8px; border-radius: 6px; text-decoration: none; color: white; font-size: 11px; }
        .success-message { background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .note { font-size: 11px; color: #888; margin-top: 10px; text-align: center; }
    </style>
</head>
<body>
<div class="container">
    <?php require_once 'navbar.php'; ?>
    <?php require_once 'gamification_widget.php'; ?>
    <?php require_once 'ai_widget.php'; ?>
    
    <h1>📊 Дашборд сотрудника</h1>
    <div class="subtitle"><?= htmlspecialchars($name) ?> | План на <?= date('F Y') ?></div>
    
    <?php if (isset($_GET['saved'])): ?>
        <div class="success-message">✅ Отчёт сохранён!</div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
        <div class="success-message">🗑️ Отчёт удалён!</div>
    <?php endif; ?>
    
    <div class="stats-grid">
        <?php foreach ($metrics as $key => $m): ?>
        <div class="stat-card" style="border-left-color: <?= $m['color'] ?>">
            <h3><?= $m['name'] ?></h3>
            <div class="fact">
                <?= isset($m['is_money']) ? number_format($m['fact'], 0, ',', ' ') . ' ₽' : $m['fact'] ?>
            </div>
            <div class="plan-month">
                📋 План на месяц: <?= isset($m['is_money']) ? number_format($m['plan_month'], 0, ',', ' ') . ' ₽' : $m['plan_month'] ?>
                <span class="status-badge" style="background: <?= $m['color'] ?>20; color: <?= $m['color'] ?>"><?= $m['status'] ?></span>
            </div>
            <div class="progress-bar"><div class="progress-fill" style="width: <?= $m['progress'] ?>%"></div></div>
            <div class="forecast">
                📈 Прогноз на месяц: <?= isset($m['is_money']) ? number_format($m['forecast'], 0, ',', ' ') . ' ₽' : $m['forecast'] ?>
            </div>
            <div class="daily-goal <?= $m['behind'] ? 'daily-goal-warning' : '' ?>">
                🎯 Норма на сегодня: <strong><?= isset($m['is_money']) ? number_format($m['daily_goal'], 0, ',', ' ') . ' ₽' : $m['daily_goal'] ?></strong>
                <span style="font-size: 10px;">(идеально: <?= $m['daily_required'] ?> в день, осталось <?= $days_remaining ?> дней)</span>
                <?php if ($m['behind']): ?>
                    <br><span style="font-size: 10px;">⚠️ Вы отстаёте на <?= abs($m['deviation']) ?> ед.</span>
                <?php endif; ?>
            </div>
            <div class="month-info">📅 <?= $current_day ?>/<?= $days_in_month ?> дней прошло</div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <div class="report-form">
        <h2>📝 Ежедневный отчёт</h2>
        <form method="post">
            <div class="form-group" style="margin-bottom: 15px;">
                <label>📅 Дата</label>
                <input type="date" name="report_date" value="<?= $editDate ?? date('Y-m-d') ?>" required style="width: auto;">
            </div>
            <div class="form-grid">
                <div class="form-group"><label>📞 Звонки</label><input type="number" name="calls" value="<?= $editReport['calls'] ?? '' ?>"></div>
                <div class="form-group"><label>✅ Дозвоны</label><input type="number" name="calls_answered" value="<?= $editReport['calls_answered'] ?? '' ?>"></div>
                <div class="form-group"><label>🤝 Встречи</label><input type="number" name="meetings" value="<?= $editReport['meetings'] ?? '' ?>"></div>
                <div class="form-group"><label>📄 Договоры</label><input type="number" name="contracts" value="<?= $editReport['contracts'] ?? '' ?>"></div>
                <div class="form-group"><label>📝 Регистрации</label><input type="number" name="registrations" value="<?= $editReport['registrations'] ?? '' ?>"></div>
                <div class="form-group"><label>💳 smart-кассы</label><input type="number" name="smart_cash" value="<?= $editReport['smart_cash'] ?? '' ?>"></div>
                <div class="form-group"><label>🖥️ POS-системы</label><input type="number" name="pos_systems" value="<?= $editReport['pos_systems'] ?? '' ?>"></div>
                <div class="form-group"><label>📊 инн по чаевым</label><input type="number" name="inn_leads" value="<?= $editReport['inn_leads'] ?? '' ?>"></div>
                <div class="form-group"><label>👥 новые команды по чаевым</label><input type="number" name="teams" value="<?= $editReport['teams'] ?? '' ?>"></div>
                <div class="form-group"><label>💰 новый оборот по чаевым (руб)</label><input type="number" name="turnover" value="<?= $editReport['turnover'] ?? '' ?>"></div>
            </div>
            <button type="submit" name="save_report">💾 Сохранить отчёт</button>
            <?php if ($editReport): ?>
                <a href="dashboard.php" style="background: #6c757d; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; margin-left: 10px;">❌ Отмена</a>
            <?php endif; ?>
        </form>
    </div>
    
    <div class="report-form">
        <h2>📜 История отчётов за месяц</h2>
        <?php if (empty($history)): ?>
            <p style="color: #666;">Нет отчётов за текущий месяц</p>
        <?php else: ?>
            <table class="history-table">
                <thead><tr><th>Дата</th><th>Звонки</th><th>Дозвоны</th><th>Встречи</th><th>Договоры</th><th>Регистрации</th><th>smart-кассы</th><th>инн по чаевым</th><th>команды</th><th>оборот</th><th>Действия</th></tr></thead>
                <tbody>
                    <?php foreach ($history as $row): ?>
                    <tr>
                        <td><?= $row['report_date'] ?></td>
                        <td><?= $row['calls'] ?></td>
                        <td><?= $row['calls_answered'] ?></td>
                        <td><?= $row['meetings'] ?></td>
                        <td><?= $row['contracts'] ?></td>
                        <td><?= $row['registrations'] ?></td>
                        <td><?= $row['smart_cash'] ?></td>
                        <td><?= $row['inn_leads'] ?></td>
                        <td><?= $row['teams'] ?></td>
                        <td><?= number_format($row['turnover'], 0, ',', ' ') ?> ₽</td>
                        <td><a href="?edit=<?= $row['report_date'] ?>" class="edit-btn">✏️</a> <a href="?delete=<?= $row['report_date'] ?>" class="delete-btn" onclick="return confirm('Удалить?')">🗑️</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <div class="note">💡 План на месяц — это сумма за весь месяц. Норма на сегодня рассчитана с учётом графика и отставания.</div>
</div>
</body>
</html>
