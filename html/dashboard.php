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

// Текущий месяц
$current_month = date('Y-m');
$days_in_month = date('t');
$current_day = date('j');
$days_passed = max(1, $current_day);
$days_remaining = max(1, $days_in_month - $current_day + 1);

// Получаем планы
$stmt = $pdo->prepare("SELECT * FROM plans WHERE tabel_number = ?");
$stmt->execute([$user['tabel_number']]);
$plans = $stmt->fetch();

if (!$plans) {
    $plans = [
        'calls_plan' => 350, 'calls_answered_plan' => 245, 'meetings_plan' => 35,
        'contracts_plan' => 21, 'registrations_plan' => 15, 'smart_cash_plan' => 10,
        'pos_systems_plan' => 5, 'inn_leads_plan' => 5, 'teams_plan' => 3, 'turnover_plan' => 1500000
    ];
}

// Получаем ВСЕ отчёты за месяц (для суммирования)
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
$month_total = $stmt->fetch();
foreach ($month_total as $key => $value) { if ($value === null) $month_total[$key] = 0; }

// Получаем статистику по дням для истории
$stmt = $pdo->prepare("
    SELECT 
        report_date,
        SUM(calls) as calls,
        SUM(calls_answered) as calls_answered,
        SUM(meetings) as meetings,
        SUM(contracts) as contracts,
        SUM(registrations) as registrations,
        SUM(smart_cash) as smart_cash,
        SUM(pos_systems) as pos_systems,
        SUM(inn_leads) as inn_leads,
        SUM(teams) as teams,
        SUM(turnover) as turnover
    FROM daily_reports 
    WHERE user_id = ? AND strftime('%Y-%m', report_date) = ?
    GROUP BY report_date
    ORDER BY report_date DESC
");
$stmt->execute([$user_id, $current_month]);
$history = $stmt->fetchAll();

// Функция расчёта прогноза и дневной цели
function calculateMetrics($plan, $fact, $days_passed, $days_remaining) {
    if ($days_passed > 1) {
        $daily_avg = $fact / ($days_passed - 1);
        $forecast = round($fact + ($daily_avg * $days_remaining));
    } else {
        $forecast = $plan;
    }
    
    $ideal_daily = ceil($plan / 30);
    $should_be = $ideal_daily * ($days_passed - 1);
    $deviation = $should_be - $fact;
    
    if ($deviation > 0) {
        $extra = ceil($deviation / $days_remaining);
        $daily_goal = $ideal_daily + $extra;
        $behind = true;
    } else {
        $daily_goal = $ideal_daily;
        $behind = false;
    }
    
    if ($forecast >= $plan) {
        $status = '🚀 Будет выполнено';
        $color = '#00a36c';
    } elseif ($forecast >= $plan * 0.8) {
        $status = '⚠️ Под угрозой';
        $color = '#f59e0b';
    } else {
        $status = '🔴 Провал';
        $color = '#ef4444';
    }
    
    $progress = $plan > 0 ? min(100, round(($fact / $plan) * 100)) : 0;
    
    return [
        'forecast' => $forecast,
        'daily_goal' => $daily_goal,
        'ideal_daily' => $ideal_daily,
        'status' => $status,
        'color' => $color,
        'progress' => $progress,
        'deviation' => $deviation,
        'behind' => $behind
    ];
}

// ВСЕ ПОКАЗАТЕЛИ с названиями
$metrics_data = [
    'calls' => ['name' => '📞 Звонки', 'plan' => $plans['calls_plan'], 'fact' => $month_total['calls_fact']],
    'calls_answered' => ['name' => '✅ Дозвоны', 'plan' => $plans['calls_answered_plan'], 'fact' => $month_total['calls_answered_fact']],
    'meetings' => ['name' => '🤝 Встречи', 'plan' => $plans['meetings_plan'], 'fact' => $month_total['meetings_fact']],
    'contracts' => ['name' => '📄 Договоры', 'plan' => $plans['contracts_plan'], 'fact' => $month_total['contracts_fact']],
    'registrations' => ['name' => '📝 Регистрации ТЭ', 'plan' => $plans['registrations_plan'], 'fact' => $month_total['registrations_fact']],
    'smart_cash' => ['name' => '💳 smart-кассы', 'plan' => $plans['smart_cash_plan'], 'fact' => $month_total['smart_cash_fact']],
    'pos_systems' => ['name' => '🖥️ POS-системы', 'plan' => $plans['pos_systems_plan'], 'fact' => $month_total['pos_systems_fact']],
    'inn_leads' => ['name' => '📊 инн по чаевым', 'plan' => $plans['inn_leads_plan'], 'fact' => $month_total['inn_leads_fact']],
    'teams' => ['name' => '👥 команды', 'plan' => $plans['teams_plan'], 'fact' => $month_total['teams_fact']],
    'turnover' => ['name' => '💰 оборот по чаевым', 'plan' => $plans['turnover_plan'], 'fact' => $month_total['turnover_fact'], 'is_money' => true]
];

// Расчёт метрик
$metrics = [];
foreach ($metrics_data as $key => $m) {
    $calc = calculateMetrics($m['plan'], $m['fact'], $days_passed, $days_remaining);
    $metrics[$key] = array_merge($m, $calc);
}

// Обработка сохранения отчёта (ДОБАВЛЯЕМ, а не заменяем)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_report'])) {
    $report_date = $_POST['report_date'];
    
    // Получаем существующий отчёт за эту дату
    $stmt = $pdo->prepare("SELECT * FROM daily_reports WHERE user_id = ? AND report_date = ?");
    $stmt->execute([$user_id, $report_date]);
    $existing = $stmt->fetch();
    
    // Новые значения (если поле пустое - 0)
    $new_calls = (int)$_POST['calls'];
    $new_calls_answered = (int)$_POST['calls_answered'];
    $new_meetings = (int)$_POST['meetings'];
    $new_contracts = (int)$_POST['contracts'];
    $new_registrations = (int)$_POST['registrations'];
    $new_smart_cash = (int)$_POST['smart_cash'];
    $new_pos_systems = (int)$_POST['pos_systems'];
    $new_inn_leads = (int)$_POST['inn_leads'];
    $new_teams = (int)$_POST['teams'];
    $new_turnover = (int)$_POST['turnover'];
    
    if ($existing) {
        // Обновляем - СУММИРУЕМ с существующими значениями
        $stmt = $pdo->prepare("
            UPDATE daily_reports SET 
                calls = calls + ?,
                calls_answered = calls_answered + ?,
                meetings = meetings + ?,
                contracts = contracts + ?,
                registrations = registrations + ?,
                smart_cash = smart_cash + ?,
                pos_systems = pos_systems + ?,
                inn_leads = inn_leads + ?,
                teams = teams + ?,
                turnover = turnover + ?
            WHERE user_id = ? AND report_date = ?
        ");
        $stmt->execute([
            $new_calls, $new_calls_answered, $new_meetings, $new_contracts, $new_registrations,
            $new_smart_cash, $new_pos_systems, $new_inn_leads, $new_teams, $new_turnover,
            $user_id, $report_date
        ]);
    } else {
        // Вставляем новый отчёт
        $stmt = $pdo->prepare("
            INSERT INTO daily_reports 
            (user_id, report_date, calls, calls_answered, meetings, contracts, registrations, smart_cash, pos_systems, inn_leads, teams, turnover)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_id, $report_date,
            $new_calls, $new_calls_answered, $new_meetings, $new_contracts, $new_registrations,
            $new_smart_cash, $new_pos_systems, $new_inn_leads, $new_teams, $new_turnover
        ]);
    }
    
    header('Location: dashboard.php?saved=1');
    exit;
}

// Удаление отчёта за день
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM daily_reports WHERE user_id = ? AND report_date = ?");
    $stmt->execute([$user_id, $_GET['delete']]);
    header('Location: dashboard.php?deleted=1');
    exit;
}

// Редактирование - получаем отчёт для заполнения формы
$editReport = null;
$editDate = null;
if (isset($_GET['edit'])) {
    $editDate = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM daily_reports WHERE user_id = ? AND report_date = ?");
    $stmt->execute([$user_id, $editDate]);
    $editReport = $stmt->fetch();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Дашборд сотрудника</title>
    <meta charset="utf-8">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:system-ui;background:#f5f7fb;padding:20px}
        .container{max-width:1400px;margin:0 auto}
        h1{color:#00a36c;margin-bottom:10px}
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;margin-bottom:30px}
        .stat-card{background:white;border-radius:16px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-left:4px solid}
        .stat-card .name{font-size:14px;color:#666;margin-bottom:8px}
        .stat-card .fact{font-size:28px;font-weight:bold;margin-bottom:5px}
        .plan-month{font-size:12px;color:#888;margin-bottom:10px}
        .progress-bar{background:#e5e7eb;border-radius:10px;height:8px;margin:10px 0}
        .progress-fill{height:100%;border-radius:10px}
        .forecast{font-size:12px;margin-top:8px;padding-top:8px;border-top:1px solid #eee}
        .daily-goal{background:#fef3c7;padding:8px;border-radius:8px;margin-top:10px;font-size:13px}
        .daily-goal-warning{background:#fee2e2;color:#dc2626}
        .status-badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:bold;margin-left:8px}
        .month-info{font-size:10px;color:#aaa;margin-top:5px;text-align:right}
        .report-form{background:white;border-radius:16px;padding:20px;margin-bottom:30px}
        .form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:15px;margin-bottom:20px}
        .form-group label{display:block;font-size:12px;color:#666;margin-bottom:5px}
        .form-group input{width:100%;padding:8px;border:1px solid #ddd;border-radius:8px}
        button{background:#00a36c;color:white;border:none;padding:10px 20px;border-radius:8px;cursor:pointer}
        .inn-row{display:flex;gap:8px;margin-bottom:8px}
        .inn-row input{flex:1;padding:8px;border:1px solid #00a36c;border-radius:8px}
        .inn-row button{background:#f59e0b;padding:8px 12px}
        .section-title{font-size:14px;font-weight:bold;color:#00a36c;margin:15px 0 10px;padding-bottom:5px;border-bottom:1px solid #eee}
        table{width:100%;border-collapse:collapse}
        th,td{padding:10px;text-align:left;border-bottom:1px solid #eee}
        th{background:#f8f9fa}
        .edit-btn,.delete-btn{padding:4px 8px;border-radius:6px;text-decoration:none;color:white;font-size:11px}
        .edit-btn{background:#f59e0b}
        .delete-btn{background:#ef4444}
        .success-message{background:#d4edda;color:#155724;padding:12px;border-radius:8px;margin-bottom:20px}
        .info-note{font-size:11px;color:#888;margin-top:10px;text-align:center}
    </style>
</head>
<body>
<div class="container">
    <?php require_once 'navbar.php'; ?>
    <?php require_once 'gamification_widget.php'; ?>
    <?php require_once 'ai_widget.php'; ?>
    
    <h1>📊 Дашборд сотрудника: <?= htmlspecialchars($name) ?></h1>
    
    <div class="stats-grid">
        <?php foreach ($metrics as $m): ?>
        <div class="stat-card" style="border-left-color: <?= $m['color'] ?>">
            <div class="name"><?= $m['name'] ?></div>
            <div class="fact"><?= isset($m['is_money']) ? number_format($m['fact'], 0, ',', ' ') . ' ₽' : $m['fact'] ?></div>
            <div class="plan-month">📋 План на месяц: <?= isset($m['is_money']) ? number_format($m['plan'], 0, ',', ' ') . ' ₽' : $m['plan'] ?></div>
            <div class="progress-bar"><div class="progress-fill" style="width: <?= $m['progress'] ?>%; background: <?= $m['color'] ?>"></div></div>
            <div class="forecast">
                📈 Прогноз на месяц: <?= isset($m['is_money']) ? number_format($m['forecast'], 0, ',', ' ') . ' ₽' : $m['forecast'] ?>
                <span class="status-badge" style="background: <?= $m['color'] ?>20; color: <?= $m['color'] ?>"><?= $m['status'] ?></span>
            </div>
            <div class="daily-goal <?= $m['behind'] ? 'daily-goal-warning' : '' ?>">
                🎯 Норма на сегодня: <strong><?= isset($m['is_money']) ? number_format($m['daily_goal'], 0, ',', ' ') . ' ₽' : $m['daily_goal'] ?></strong>
                <span style="font-size: 10px;">(идеально: <?= $m['ideal_daily'] ?> в день, осталось <?= $days_remaining ?> дней)</span>
                <?php if ($m['behind']): ?>
                    <br><span style="font-size: 10px;">⚠️ Вы отстаёте на <?= abs($m['deviation']) ?> ед. Нужно делать больше!</span>
                <?php endif; ?>
            </div>
            <div class="month-info">📅 <?= $current_day ?>/<?= $days_in_month ?> дней прошло</div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <?php if (isset($_GET['saved'])): ?>
        <div class="success-message">✅ Отчёт сохранён! Данные добавлены к выбранной дате.</div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
        <div class="success-message">🗑️ Отчёт за день удалён!</div>
    <?php endif; ?>
    
    <div class="report-form">
        <h2>📝 Ежедневный отчёт</h2>
        <div class="info-note">💡 Введите показатели за день. При повторном сохранении за ту же дату значения СУММИРУЮТСЯ.</div>
        <form method="post">
            <div class="form-group"><label>📅 Дата</label><input type="date" name="report_date" value="<?= $editDate ?? date('Y-m-d') ?>" required></div>
            
            <!-- Основные показатели -->
            <div class="section-title">📞 Основные показатели</div>
            <div class="form-grid">
                <div class="form-group"><label>📞 Звонки</label><input type="number" name="calls" value="<?= $editReport['calls'] ?? 0 ?>"></div>
                <div class="form-group"><label>✅ Дозвоны</label><input type="number" name="calls_answered" value="<?= $editReport['calls_answered'] ?? 0 ?>"></div>
                <div class="form-group"><label>🤝 Встречи</label><input type="number" name="meetings" value="<?= $editReport['meetings'] ?? 0 ?>"></div>
                <div class="form-group"><label>📄 Договоры</label><input type="number" name="contracts" value="<?= $editReport['contracts'] ?? 0 ?>"></div>
                <div class="form-group"><label>👥 команды</label><input type="number" name="teams" value="<?= $editReport['teams'] ?? 0 ?>"></div>
                <div class="form-group"><label>💰 оборот по чаевым</label><input type="number" name="turnover" value="<?= $editReport['turnover'] ?? 0 ?>"></div>
            </div>
            
            <!-- Регистрации ТЭ с ИНН -->
            <div class="section-title">📝 Регистрации ТЭ</div>
            <div class="inn-row">
                <input type="text" id="inn_registrations" placeholder="Введите ИНН (10-12 цифр)">
                <button type="button" onclick="addInn('registrations', 'inn_registrations', 'registrations')">+1</button>
            </div>
            <div class="form-group">
                <input type="number" name="registrations" id="registrations" value="<?= $editReport['registrations'] ?? 0 ?>">
            </div>
            
            <!-- smart-кассы с ИНН -->
            <div class="section-title">💳 smart-кассы</div>
            <div class="inn-row">
                <input type="text" id="inn_smart_cash" placeholder="Введите ИНН (10-12 цифр)">
                <button type="button" onclick="addInn('smart_cash', 'inn_smart_cash', 'smart_cash')">+1</button>
            </div>
            <div class="form-group">
                <input type="number" name="smart_cash" id="smart_cash" value="<?= $editReport['smart_cash'] ?? 0 ?>">
            </div>
            
            <!-- POS-системы с ИНН -->
            <div class="section-title">🖥️ POS-системы</div>
            <div class="inn-row">
                <input type="text" id="inn_pos_systems" placeholder="Введите ИНН (10-12 цифр)">
                <button type="button" onclick="addInn('pos_systems', 'inn_pos_systems', 'pos_systems')">+1</button>
            </div>
            <div class="form-group">
                <input type="number" name="pos_systems" id="pos_systems" value="<?= $editReport['pos_systems'] ?? 0 ?>">
            </div>
            
            <!-- ИНН по чаевым -->
            <div class="section-title">📊 ИНН по чаевым</div>
            <div class="inn-row">
                <input type="text" id="inn_inn_leads" placeholder="Введите ИНН (10-12 цифр)">
                <button type="button" onclick="addInn('inn_leads', 'inn_inn_leads', 'inn_leads')">+1</button>
            </div>
            <div class="form-group">
                <input type="number" name="inn_leads" id="inn_leads" value="<?= $editReport['inn_leads'] ?? 0 ?>">
            </div>
            
            <button type="submit" name="save_report">💾 Сохранить отчёт</button>
            <?php if ($editReport): ?>
                <a href="dashboard.php" style="background:#6c757d;color:white;padding:10px 20px;border-radius:8px;text-decoration:none;margin-left:10px">❌ Отмена</a>
            <?php endif; ?>
        </form>
    </div>
    
    <div class="report-form">
        <h2>📜 История отчётов по дням</h2>
        <?php if (empty($history)): ?>
            <p>Нет отчётов</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Дата</th><th>Звонки</th><th>Дозвоны</th><th>Встречи</th><th>Договоры</th><th>Регистрации ТЭ</th><th>Оборот</th><th></th></tr></thead>
                <tbody>
                    <?php foreach($history as $row): ?>
                    <tr>
                        <td><?= $row['report_date'] ?></td>
                        <td><?= $row['calls'] ?></td>
                        <td><?= $row['calls_answered'] ?></td>
                        <td><?= $row['meetings'] ?></td>
                        <td><?= $row['contracts'] ?></td>
                        <td><?= $row['registrations'] ?></td>
                        <td><?= number_format($row['turnover'], 0, ',', ' ') ?> ₽</span></td>
                        <td><a href="?edit=<?= $row['report_date'] ?>" class="edit-btn">✏️</a> <a href="?delete=<?= $row['report_date'] ?>" class="delete-btn" onclick="return confirm('Удалить все данные за этот день?')">🗑️</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="info-note">📊 В таблице показаны СУММАРНЫЕ значения по дням. При повторном сохранении за ту же дату данные суммируются.</div>
        <?php endif; ?>
    </div>
</div>

<script>
function addInn(productType, inputId, fieldId) {
    let inn = document.getElementById(inputId).value.trim();
    if (!inn) { alert('Введите ИНН'); return; }
    if (inn.length < 10 || inn.length > 12) { alert('ИНН должен быть 10-12 цифр'); return; }
    fetch('/api/add_inn.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'inn=' + encodeURIComponent(inn) + '&product_type=' + encodeURIComponent(productType)
    }).then(r => r.json()).then(data => {
        if (data.success) {
            document.getElementById(inputId).value = '';
            let field = document.getElementById(fieldId);
            if (field) field.value = (parseInt(field.value) || 0) + 1;
            let btn = document.querySelector(`button[onclick*="${inputId}"]`);
            if (btn) { btn.textContent = '✅'; setTimeout(() => btn.textContent = '+1', 1000); }
        } else { alert('Ошибка'); }
    }).catch(() => alert('Ошибка'));
}
document.querySelectorAll('.inn-row input').forEach(inp => {
    inp.addEventListener('keypress', e => { if (e.key === 'Enter') e.target.nextElementSibling.click(); });
});
</script>
</body>
</html>
