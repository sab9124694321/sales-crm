<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once 'db.php';

$role = $_SESSION['role'];
if (!in_array($role, ['admin', 'head', 'territory_head'])) { die('Нет доступа'); }

$meeting_date = $_GET['date'] ?? date('Y-m-d');
$month = date('Y-m', strtotime($meeting_date));
$work_days_total = 22;
$current_day = date('j', strtotime($meeting_date));
$days_passed = min($work_days_total, max(1, $current_day));
$days_left = max(1, $work_days_total - $days_passed + 1);

// ----- подчинённые (как в team.php) -----
$subordinates = [];
if ($role === 'admin') {
    $subordinates = $pdo->query("SELECT u.*, h.full_name as head_name, t.name as territory_name FROM users u LEFT JOIN users h ON u.head_tabel = h.tabel_number LEFT JOIN territories t ON u.territory_id = t.id WHERE u.role = 'manager' AND u.is_active = 1 ORDER BY u.full_name")->fetchAll();
} elseif ($role === 'terman') {
    $stmt = $pdo->prepare("SELECT u.*, h.full_name as head_name, t.name as territory_name FROM users u JOIN territory_managers tm ON u.territory_id = tm.territory_id LEFT JOIN users h ON u.head_tabel = h.tabel_number LEFT JOIN territories t ON u.territory_id = t.id WHERE tm.manager_id = ? AND u.role = 'manager' AND u.is_active=1 ORDER BY u.full_name");
    $stmt->execute([$_SESSION['user_id']]);
    $subordinates = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT u.*, h.full_name as head_name, t.name as territory_name FROM users u LEFT JOIN users h ON u.head_tabel = h.tabel_number LEFT JOIN territories t ON u.territory_id = t.id WHERE u.manager_id = ? AND u.role = 'manager' AND u.is_active=1 ORDER BY u.full_name");
    $stmt->execute([$_SESSION['user_id']]);
    $subordinates = $stmt->fetchAll();
}

// ---------- Сбор показателей ----------
$team_today = ['calls'=>0,'calls_answered'=>0,'meetings'=>0,'contracts'=>0,'registrations'=>0,'smart_cash'=>0,'pos_systems'=>0,'inn_leads'=>0,'teams'=>0,'turnover'=>0];
$team_month = $team_today;
$team_plan  = $team_today;
$employees = [];
$all_quests = [];

foreach ($subordinates as $sub) {
    $tab = $sub['tabel_number'];
    // план
    $pStmt = $pdo->prepare("SELECT * FROM plans WHERE tabel_number=? AND period=?");
    $pStmt->execute([$tab, $month]);
    $plan = $pStmt->fetch();
    if (!$plan) $plan = ['calls_plan'=>350,'calls_answered_plan'=>245,'meetings_plan'=>35,'contracts_plan'=>21,'registrations_plan'=>15,'smart_cash_plan'=>10,'pos_systems_plan'=>5,'inn_leads_plan'=>5,'teams_plan'=>3,'turnover_plan'=>1500000];

    // факт за день
    $dStmt = $pdo->prepare("SELECT calls,calls_answered,meetings,contracts,registrations,smart_cash,pos_systems,inn_leads,teams,turnover FROM daily_reports WHERE user_id=? AND report_date=?");
    $dStmt->execute([$sub['id'], $meeting_date]);
    $day = $dStmt->fetch();
    if (!$day) $day = array_fill_keys(['calls','calls_answered','meetings','contracts','registrations','smart_cash','pos_systems','inn_leads','teams','turnover'], 0);

    // месяц
    $mStmt = $pdo->prepare("SELECT COALESCE(SUM(calls),0) as calls, COALESCE(SUM(calls_answered),0) as calls_answered, COALESCE(SUM(meetings),0) as meetings, COALESCE(SUM(contracts),0) as contracts, COALESCE(SUM(registrations),0) as registrations, COALESCE(SUM(smart_cash),0) as smart_cash, COALESCE(SUM(pos_systems),0) as pos_systems, COALESCE(SUM(inn_leads),0) as inn_leads, COALESCE(SUM(teams),0) as teams, COALESCE(SUM(turnover),0) as turnover FROM daily_reports WHERE user_id=? AND strftime('%Y-%m',report_date)=?");
    $mStmt->execute([$sub['id'], $month]);
    $mon = $mStmt->fetch();

    // квесты
    $qStmt = $pdo->prepare("SELECT q.title, q.description, q.type, q.points, q.ends_at, qt.status, qt.taken_at FROM quest_takers qt JOIN quests q ON qt.quest_id=q.id WHERE qt.employee_tabel=? AND qt.status NOT IN ('closed','rewarded','failed') AND (qt.taken_at<=? OR q.ends_at>=?) ORDER BY q.ends_at");
    $qStmt->execute([$tab, $meeting_date, $meeting_date]);
    $quests = $qStmt->fetchAll();

    // накопление по команде
    foreach (['calls','calls_answered','meetings','contracts','registrations','smart_cash','pos_systems','inn_leads','teams','turnover'] as $f) {
        $team_today[$f] += $day[$f];
        $team_month[$f] += $mon[$f];
        $team_plan[$f] += ($plan[$f.'_plan'] ?? 0);
    }

    // расчёт целей на день с учётом гэпа для каждого показателя
    $daily_targets = [];
    foreach (['calls','calls_answered','meetings','contracts','registrations','smart_cash','pos_systems','inn_leads','teams','turnover'] as $f) {
        $p = $plan[$f.'_plan'] ?? 0;
        $ideal = ceil($p / $work_days_total);
        $should_be = $ideal * ($days_passed - 1);
        $dev = $should_be - ($mon[$f] ?? 0);
        $daily_targets[$f] = ($dev > 0) ? ceil(($p - ($mon[$f] ?? 0)) / $days_left) : $ideal;
    }

    $employees[] = [
        'name' => $sub['full_name'],
        'head' => $sub['head_name'] ?? '—',
        'territory' => $sub['territory_name'] ?? '—',
        'plan' => $plan,
        'day' => $day,
        'month' => $mon,
        'daily_targets' => $daily_targets,
        'quests' => $quests
    ];

    foreach ($quests as $q) {
        $all_quests[] = $q + ['employee' => $sub['full_name']];
    }
}

// ---------- Сводка команды ----------
function calcSummary($plan, $fact, $dp, $dl, $wd = 22) {
    $ideal = ceil($plan/$wd);
    $should_be = $ideal * ($dp - 1);
    $dev = $should_be - $fact;
    $daily = ($dev > 0) ? ceil(($plan - $fact) / $dl) : $ideal;
    $forecast = ($dp>1 && $fact>0) ? round($fact + ($fact/($dp-1))*$dl) : $plan;
    return ['forecast'=>$forecast, 'daily'=>$daily, 'percent'=>$plan>0?round($fact/$plan*100):0];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>📋 Протокол совещания</title>
    <link rel="stylesheet" href="style.css">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { font-size: 10px; }
        }
        body { font-family: system-ui; background:#fff; margin:0; padding:12px; }
        .container { max-width: 210mm; margin:0 auto; }
        /* стандартная навигация (как на других страницах) */
        .nav { display:flex; align-items:center; padding:12px 20px; background:linear-gradient(135deg,#1a1a2e,#16213e); color:#fff; border-radius:16px; margin-bottom:20px; gap:12px; flex-wrap:wrap; }
        .nav a { color:#ccc; text-decoration:none; padding:8px 14px; border-radius:8px; font-size:13px; font-weight:500; }
        .nav a:hover, .nav a.active { background:rgba(255,255,255,0.1); color:#fff; }
        .nav .logo { font-size:20px; font-weight:700; color:#fff; margin-right:auto; }
        .nav .user { margin-left:auto; color:#aaa; font-size:13px; }
        .nav a.logout { color:#e03131; }
        .header { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
        .header h2 { margin:0; font-size:1.2rem; }
        .block { margin-bottom:16px; }
        .block h3 { margin:0 0 6px; font-size:1rem; border-bottom:1px solid #aaa; padding-bottom:4px; }
        table { width:100%; border-collapse:collapse; font-size:11px; }
        th, td { padding:2px 3px; border:1px solid #ccc; text-align:center; vertical-align:top; }
        th { background:#f0f0f0; }
        .compact td, .compact th { padding:1px 2px; }
        .red { color:#c00; }
        .green { color:#070; }
        .orange { color:#e67300; }
        .badge { font-size:9px; padding:1px 5px; border-radius:8px; background:#eee; }
        .filters { display:flex; gap:12px; align-items:flex-end; margin-bottom:16px; }
        .filters input[type="date"] { padding:6px 10px; border-radius:8px; border:1px solid #ccc; }
        .legend { font-size:10px; color:#555; margin-bottom:12px; display:flex; gap:16px; flex-wrap:wrap; }
        .legend span { white-space:nowrap; }
        .daily-goal { font-size:9px; font-weight:bold; color:#1a73e8; }
        .approaching { background-color: #fff3cd; }
        .overdue { background-color: #ffe3e3; }
        .table-legend { font-size:9px; color:#555; margin-bottom:8px; }
        .btn { padding:6px 14px; border:none; border-radius:6px; cursor:pointer; background:#1a73e8; color:#fff; }
        .btn-sm { padding:4px 10px; font-size:12px; background:#6c757d; }
    </style>
</head>
<body>

<!-- Стандартная навигация (скрывается при печати) -->
<div class="nav no-print">
    <a href="dashboard.php" class="logo">🚀 SZB</a>
    <a href="dashboard.php">📊 Дашборд</a>
    <a href="team.php">👥 Команда</a>
    <a href="territories.php">🌍 Территории</a>
    <a href="export_inn.php">📋 ИНН</a>
    <a href="quests.php">🎯 Квесты</a>
    <a href="ai.php">🤖 AI</a>
    <?php if ($role === 'admin'): ?><a href="admin.php">⚙️ Админ</a><?php endif; ?>
    <span class="user">👤 <?= htmlspecialchars($_SESSION['name']) ?></span>
    <a href="logout.php" class="logout">🚪 Выйти</a>
</div>

<div class="container">
    <!-- Форма выбора даты (скрывается при печати) -->
    <div class="filters no-print">
        <form method="get" style="display:flex; gap:10px; align-items:center;">
            <label>Дата совещания:</label>
            <input type="date" name="date" value="<?= $meeting_date ?>">
            <button type="submit" class="btn btn-sm">📅 Смотреть</button>
            <button type="button" class="btn btn-sm" onclick="window.print()" style="background:#555;">🖨️ Печать</button>
        </form>
    </div>

    <!-- Легенда общая -->
    <div class="legend">
        <span>📊 <strong>План</strong> – цель на месяц</span>
        <span>✅ <strong>Факт</strong> – результат за день</span>
        <span>🎯 <strong>Цель на день (с учётом отставания)</strong> – сколько нужно сегодня, чтобы догнать план</span>
    </div>

    <!-- БЛОК 1: Результат команды -->
    <div class="block">
        <h3>📊 Сводка команды за <?= date('d.m.Y', strtotime($meeting_date)) ?></h3>
        <table>
            <tr><th>Показатель</th><th>План на месяц</th><th>Факт за день</th><th>Факт месяц</th><th>% выполнения</th><th>Прогноз</th><th>🎯 Цель на день</th></tr>
            <?php
            $metrics = [
                'calls' => 'Звонки', 'calls_answered' => 'Дозвоны', 'meetings' => 'Встречи',
                'contracts' => 'Договоры', 'registrations' => 'ТЭ', 'smart_cash' => 'Смарт',
                'pos_systems' => 'ПОС', 'inn_leads' => 'Чаевые', 'teams' => 'Команды', 'turnover' => 'Оборот чаевых'
            ];
            foreach ($metrics as $key => $label):
                $plan = $team_plan[$key];
                $day = $team_today[$key];
                $mon = $team_month[$key];
                $calc = calcSummary($plan, $mon, $days_passed, $days_left);
            ?>
            <tr>
                <td><?= $label ?></td>
                <td><?= number_format($plan,0,'.',' ') ?></td>
                <td><?= number_format($day,0,'.',' ') ?></td>
                <td><?= number_format($mon,0,'.',' ') ?></td>
                <td><?= $calc['percent'] ?>%</td>
                <td><?= number_format($calc['forecast'],0,'.',' ') ?></td>
                <td><?= number_format($calc['daily'],0,'.',' ') ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <!-- БЛОК 2: Сотрудники с целью на день -->
    <div class="block">
        <h3>👥 План/факт и цель на день (<?= count($employees) ?> чел.)</h3>
        <div class="table-legend">
            В ячейке: <strong>Верх</strong> – факт за день, <strong>Середина</strong> – план на месяц, <strong>Низ (🎯)</strong> – цель на день с учётом отставания
        </div>
        <table class="compact">
            <tr>
                <th>Сотрудник</th>
                <?php foreach ($metrics as $key => $label): ?>
                    <th><?= $label ?></th>
                <?php endforeach; ?>
            </tr>
            <?php foreach ($employees as $emp): ?>
            <tr>
                <td style="text-align:left;"><?= htmlspecialchars($emp['name']) ?></td>
                <?php foreach (array_keys($metrics) as $f): 
                    $fact = $emp['day'][$f];
                    $plan = $emp['plan'][$f.'_plan'] ?? 0;
                    $goal = $emp['daily_targets'][$f];
                    $pct = $plan>0 ? round($fact/$plan*100) : 0;
                    $color = $pct>=100 ? 'green' : ($pct>=80 ? '' : 'red');
                ?>
                <td class="<?= $color ?>">
                    <?= number_format($fact,0,'.',' ') ?><br>
                    <small><?= number_format($plan,0,'.',' ') ?></small><br>
                    <span class="daily-goal">🎯<?= number_format($goal,0,'.',' ') ?></span>
                </td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <!-- БЛОК 3: Задачи/квесты -->
    <div class="block">
        <h3>📋 Задачи и квесты (активные)</h3>
        <?php if (empty($all_quests)): ?>
            <p>Нет активных квестов.</p>
        <?php else: ?>
        <table>
            <tr>
                <th>Сотрудник</th>
                <th>Квест</th>
                <th>Описание</th>
                <th>Тип</th>
                <th>Баллы</th>
                <th>Назначен</th>
                <th>Срок</th>
                <th>Статус</th>
            </tr>
            <?php foreach ($all_quests as $q): 
                $ends_at = $q['ends_at'];
                $deadline = $ends_at ? strtotime($ends_at) : null;
                $today_ts = strtotime($meeting_date);
                $days_left_q = $deadline ? ceil(($deadline - $today_ts) / 86400) : null;
                $is_overdue = $deadline && $deadline < $today_ts;
                $approaching = !$is_overdue && $days_left_q !== null && $days_left_q <= 2;
                $row_class = $is_overdue ? 'overdue' : ($approaching ? 'approaching' : '');
                $taken_date = $q['taken_at'] ? date('d.m.Y', strtotime($q['taken_at'])) : '—';
            ?>
            <tr class="<?= $row_class ?>">
                <td><?= htmlspecialchars($q['employee']) ?></td>
                <td><?= htmlspecialchars($q['title']) ?></td>
                <td><?= htmlspecialchars($q['description'] ?? '') ?></td>
                <td><?= $q['type']=='group'?'Груп.':'Лич.' ?></td>
                <td><?= $q['points'] ?></td>
                <td><?= $taken_date ?></td>
                <td><?= $ends_at ? date('d.m.Y', $deadline) : '—' ?></td>
                <td><?= $q['status'] ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>