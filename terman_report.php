<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$role = $_SESSION['role'] ?? '';
$user_name = $_SESSION['full_name'] ?? 'Пользователь';
$user_id = $_SESSION['user_id'];

$allowed = ['head', 'territory_head', 'admin', 'terman'];
if (!in_array($role, $allowed)) die('🚫 Доступ запрещён.');

// ── Функция получения показателей за день (логика: сумма ТЭ+Смарт+ПОС) ──
function getProductCounts($pdo, $tabel, $date_from, $date_to) {
    $sql = "
        SELECT
            SUM(CASE WHEN product IN ('ТЭ', 'Смарт', 'ПОС') THEN 1 ELSE 0 END) AS total,
            SUM(CASE WHEN is_key = 1 THEN 1 ELSE 0 END) AS keyv,
            SUM(CASE WHEN product IN ('ПОС', 'Смарт') THEN 1 ELSE 0 END) AS kas,
            SUM(CASE WHEN station_type = 'target' THEN 1 ELSE 0 END) AS target
        FROM inn_records
        WHERE employee_tabel = ?
          AND DATE(sale_date) BETWEEN ? AND ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tabel, $date_from, $date_to]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'total'  => (int) ($row['total'] ?? 0), // Теперь это сумма ТЭ+Смарт+ПОС
        'keyv'   => (int) ($row['keyv'] ?? 0),
        'kas'    => (int) ($row['kas'] ?? 0),
        'target' => (int) ($row['target'] ?? 0)
    ];
}

// ── Функция получения ВСЕХ комментариев (по роли) ──
function getAllComments($pdo, $user_tabel, $target_role) {
    $stmt = $pdo->prepare("SELECT comment, comment_date FROM head_comments WHERE head_tabel = ? AND target_role = ? ORDER BY comment_date DESC");
    $stmt->execute([$user_tabel, $target_role]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Функция подсчёта рабочих дней ──
function countWorkingDays($start, $end) {
    $start = new DateTime($start);
    $end = new DateTime($end);
    $end->modify('+1 day');
    $days = 0;
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end);
    foreach ($period as $dt) {
        $dayOfWeek = (int) $dt->format('N');
        if ($dayOfWeek < 6) $days++;
    }
    return $days;
}

// ── Определяем параметры из GET (дата) ──
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d', strtotime('-1 day'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
    $selected_date = date('Y-m-d', strtotime('-1 day'));
}
$today = date('Y-m-d');
if ($selected_date > $today) {
    $selected_date = $today;
}

$year = (int) date('Y', strtotime($selected_date));
$month = (int) date('m', strtotime($selected_date));
$max_day = (int) date('d', strtotime($selected_date));

$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
if ($max_day > $days_in_month) $max_day = $days_in_month;
$today_day = (int) date('d');
$today_month = (int) date('m');
$today_year = (int) date('Y');
if ($year == $today_year && $month == $today_month && $max_day > $today_day) {
    $max_day = $today_day;
}
if ($max_day < 1) $max_day = 1;

$display_days = range(1, $max_day);

$month_names = ['', 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
$month_name = $month_names[$month];
$period = sprintf('%04d-%02d', $year, $month);
$date_from = sprintf('%04d-%02d-01', $year, $month);
$date_to   = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);

// ── Фильтр по территории ──
$territory_filter = isset($_GET['territory']) ? (int) $_GET['territory'] : 0;
$territories = $pdo->query("SELECT id, name FROM territories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// ── Получение менеджеров ──
$sql = "
    SELECT u.tabel_number, u.full_name, u.territory_id, u.head_tabel,
           u.position_start_date, u.created_at,
           t.name AS territory_name, h.full_name AS head_name
    FROM users u
    LEFT JOIN territories t ON u.territory_id = t.id
    LEFT JOIN users h ON u.head_tabel = h.tabel_number
    WHERE u.role = 'manager' AND u.is_active = 1
";
if ($territory_filter > 0) {
    $sql .= " AND u.territory_id = " . (int) $territory_filter;
}
$sql .= " ORDER BY t.name, h.full_name, u.full_name";
$managers = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
if (empty($managers)) die('Нет активных менеджеров');

// ── Планы (contracts_plan) ──
$plans = [];
$stmt = $pdo->prepare("SELECT tabel_number, contracts_plan FROM plans WHERE period = ?");
$stmt->execute([$period]);
while ($r = $stmt->fetch()) {
    $t = (string)$r['tabel_number'];
    $plans[$t] = (int)$r['contracts_plan'];
}

// ── Сбор продаж, отсутствий и целевых ──
$sales = [];
$absences = [];
foreach ($managers as $m) {
    $t = trim((string)$m['tabel_number']);
    foreach ($display_days as $d) {
        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $sales[$t][$date_str] = getProductCounts($pdo, $t, $date_str, $date_str);
    }
    $stmt = $pdo->prepare("
        SELECT absence_date 
        FROM employee_absences 
        WHERE TRIM(employee_tabel) = ? 
          AND DATE(absence_date) BETWEEN ? AND ?
    ");
    $stmt->execute([$t, $date_from, $date_to]);
    $abs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $absences[$t] = array_fill_keys($abs, true);
}

// ── Факт за месяц и целевые за месяц ──
$fact_month = [];
$target_month = [];
foreach ($sales as $t => $days) {
    $total = 0;
    $target = 0;
    foreach ($days as $cnt) {
        $total += $cnt['total'];
        $target += $cnt['target'];
    }
    $fact_month[$t] = $total;
    $target_month[$t] = $target;
}

// ── Настройки цветов ──
$colors = $pdo->query("SELECT red_max, yellow_max FROM terman_color_settings WHERE territory_id IS NULL ORDER BY id DESC LIMIT 1")->fetch();
if (!$colors) $colors = ['red_max' => 1, 'yellow_max' => 2];

function calcStaz($date) {
    if (empty($date)) return '000/00';
    try {
        $d1 = new DateTime($date);
        $d2 = new DateTime();
        $diff = $d1->diff($d2);
        return sprintf('%03d/%02d', $diff->y, $diff->m);
    } catch (Exception $e) {
        return '000/00';
    }
}

function getDayColor($cnt, $colors) {
    $red = (int) ($colors['red_max'] ?? 1);
    $yellow = (int) ($colors['yellow_max'] ?? 2);
    if ($cnt <= $red) return ['bg' => '#ff6b6b', 'txt' => '#4a0000'];
    if ($cnt <= $yellow) return ['bg' => '#ffd93d', 'txt' => '#5a3e00'];
    return ['bg' => '#51cf66', 'txt' => '#003d00'];
}

// ── ГРУППИРОВКА ──
$structure = [];
foreach ($managers as $m) {
    $terr_id   = (int) ($m['territory_id'] ?? 0);
    $terr_name = $m['territory_name'] ?? 'Без территории';
    $head_name = $m['head_name'] ?? 'Без руководителя';
    $head_tabel = (string) ($m['head_tabel'] ?? '');
    $tabel_key = trim((string)$m['tabel_number']);

    if (!isset($structure[$terr_id])) {
        $structure[$terr_id] = [
            'name'  => $terr_name,
            'heads' => [],
            'daily_totals' => array_fill_keys($display_days, ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0]),
            'total_plan' => 0,
            'total_fact' => 0,
            'total_target'=> 0,
            'total_rr'   => 0,
            'total_vp'   => 0,
            'total_cs'   => 0,
        ];
    }
    if (!isset($structure[$terr_id]['heads'][$head_name])) {
        $structure[$terr_id]['heads'][$head_name] = [
            'head_tabel' => $head_tabel,
            'managers' => [],
            'total_plan' => 0,
            'total_fact' => 0,
            'total_target'=> 0,
            'total_rr'   => 0,
            'total_vp'   => 0,
            'total_cs'   => 0,
        ];
    }
    $structure[$terr_id]['heads'][$head_name]['managers'][] = array_merge($m, ['tabel_key' => $tabel_key]);
}

// ── ВЫЧИСЛЕНИЕ ИТОГОВ ──
$grand_plan = 0;
$grand_fact = 0;
$grand_target = 0;
$grand_daily = array_fill_keys($display_days, ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0]);

$month_start = sprintf('%04d-%02d-01', $year, $month);
$month_end = date('Y-m-t', strtotime($month_start));
$total_working_days = countWorkingDays($month_start, $month_end);
$today_date = date('Y-m-d');
$working_days_passed = countWorkingDays($month_start, $today_date);

foreach ($structure as $terr_id => &$terr) {
    $terr_plan = 0;
    $terr_fact = 0;
    $terr_target = 0;
    $terr_daily = array_fill_keys($display_days, ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0]);

    foreach ($terr['heads'] as $head_name => &$head_group) {
        $head_plan = 0;
        $head_fact = 0;
        $head_target = 0;

        foreach ($head_group['managers'] as &$m) {
            $t = $m['tabel_key'];
            if (empty($t)) continue;

            $plan = $plans[$t] ?? 0;
            $fact = $fact_month[$t] ?? 0;
            $target = $target_month[$t] ?? 0;

            $m['plan'] = $plan;
            $m['fact'] = $fact;
            $m['target'] = $target;
            $m['vp']   = $plan > 0 ? round(($fact / $plan) * 100) : 0;
            $m['rr']   = ($working_days_passed > 0) ? round(($fact / $working_days_passed) * $total_working_days) : 0;
            $start_date = (!empty($m['position_start_date'])) ? $m['position_start_date'] : ($m['created_at'] ?? '');
            $m['staz'] = calcStaz($start_date);

            $head_plan += $plan;
            $head_fact += $fact;
            $head_target += $target;

            foreach ($display_days as $d) {
                $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $cnt = isset($sales[$t][$date_str]) ? $sales[$t][$date_str] : ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0];
                $terr_daily[$d]['total'] += $cnt['total'];
                $terr_daily[$d]['keyv'] += $cnt['keyv'];
                $terr_daily[$d]['kas']  += $cnt['kas'];
                $terr_daily[$d]['target'] += $cnt['target'];
                $grand_daily[$d]['total'] += $cnt['total'];
                $grand_daily[$d]['keyv'] += $cnt['keyv'];
                $grand_daily[$d]['kas']  += $cnt['kas'];
                $grand_daily[$d]['target'] += $cnt['target'];
            }
        }

        $head_group['total_plan'] = $head_plan;
        $head_group['total_fact'] = $head_fact;
        $head_group['total_target'] = $head_target;
        $head_group['total_cs'] = $head_target;
        $head_group['total_rr'] = ($working_days_passed > 0 && $head_fact > 0) ? round(($head_fact / $working_days_passed) * $total_working_days) : 0;
        $head_group['total_vp'] = $head_plan > 0 ? round(($head_fact / $head_plan) * 100) : 0;

        $terr_plan += $head_plan;
        $terr_fact += $head_fact;
        $terr_target += $head_target;
    }

    $terr['total_plan'] = $terr_plan;
    $terr['total_fact'] = $terr_fact;
    $terr['total_target'] = $terr_target;
    $terr['total_cs'] = $terr_target;
    $terr['total_rr'] = ($working_days_passed > 0 && $terr_fact > 0) ? round(($terr_fact / $working_days_passed) * $total_working_days) : 0;
    $terr['total_vp'] = $terr_plan > 0 ? round(($terr_fact / $terr_plan) * 100) : 0;
    $terr['daily_totals'] = $terr_daily;

    $grand_plan += $terr_plan;
    $grand_fact += $terr_fact;
    $grand_target += $terr_target;
}
unset($terr, $head_group, $m);
$grand_rr = ($working_days_passed > 0 && $grand_fact > 0) ? round(($grand_fact / $working_days_passed) * $total_working_days) : 0;
$grand_vp = $grand_plan > 0 ? round(($grand_fact / $grand_plan) * 100) : 0;
$grand_cs = $grand_target;

$days_reverse = array_reverse($display_days);
$weekdays_ru = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

// ── Подготовка ВСЕХ комментариев (для менеджеров) ──
$manager_comments = [];
foreach ($managers as $m) {
    $t = trim((string)$m['tabel_number']);
    $manager_comments[$t] = getAllComments($pdo, $t, 'manager');
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>📋 Отчёт термена — <?= $selected_date ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        body{font-family:system-ui,sans-serif;background:#f5f5f5;padding:20px;margin:0;}
        .container{max-width:100%;margin:0 auto;background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1);}
        .nav{display:flex;align-items:center;padding:12px 20px;background:linear-gradient(135deg,#1a1a2e,#16213e);color:#fff;border-radius:16px;margin-bottom:20px;gap:12px;flex-wrap:wrap;}
        .nav a{color:#ccc;text-decoration:none;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:500;}
        .nav a:hover,.nav a.active{background:rgba(255,255,255,0.1);color:#fff;}
        .nav .logo{font-size:20px;font-weight:700;color:#fff;margin-right:auto;}
        .nav .user{margin-left:auto;color:#aaa;font-size:13px;}
        .nav a.logout{color:#e03131;}
        h1{margin:0 0 8px;font-size:22px;}
        .subtitle{color:#666;margin-bottom:18px;font-size:14px;}
        .top-bar{display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:18px;padding:14px;background:#fafafa;border-radius:6px;border:1px solid #e0e0e0;}
        .top-bar label{font-size:13px;color:#555;white-space:nowrap;}
        .top-bar input,.top-bar select{padding:6px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;}
        .top-bar button{padding:7px 16px;border:none;border-radius:4px;cursor:pointer;font-size:13px;transition:.2s;}
        .btn-primary{background:#1976d2;color:#fff;} .btn-primary:hover{background:#1565c0;}
        .btn-gray{background:#757575;color:#fff;} .btn-gray:hover{background:#616161;}
        .btn-excel{background:#1e7e34;color:#fff;} .btn-excel:hover{background:#166b2b;}
        .btn-pdf{background:#b22222;color:#fff;} .btn-pdf:hover{background:#9b1d1d;}
        .color-settings{display:flex;gap:18px;align-items:center;flex-wrap:wrap;}
        .table-wrap{overflow-x:auto;margin-top:12px;border:1px solid #ddd;border-radius:6px;margin-bottom:30px;}
        table{border-collapse:collapse;width:100%;font-size:9px;}
        th,td{border:1px solid #ddd;padding:2px 3px;text-align:center;white-space:nowrap;}
        th{background:#f0f0f0;font-weight:600;color:#333;position:sticky;top:0;z-index:2;}
        th:first-child,td:first-child{position:sticky;left:0;background:#fff;z-index:3;}
        th:first-child{z-index:4;}
        .cell-day{width:18px;height:18px;cursor:pointer;transition:.12s;font-weight:600;font-size:8px;}
        .cell-day:hover{transform:scale(1.1);box-shadow:0 0 4px rgba(0,0,0,.2);z-index:1;position:relative;}
        .name-col{text-align:left;min-width:120px;padding-left:6px !important;}
        .staz-col{min-width:35px;}
        .rr-col{min-width:32px;font-weight:700;}
        .weekend{background:#f5f5f5 !important;color:#999 !important;}
        .totals-row td{font-weight:700;background:#fff8e1 !important;}
        .grand-totals td{font-weight:800;background:#ffecb3 !important;font-size:10px;}
        .edit-icon{cursor:pointer;color:#1976d2;font-size:10px;margin-left:2px;opacity:.7;}
        .edit-icon:hover{opacity:1;}
        .comment-icon{cursor:pointer;color:#6c757d;font-size:10px;margin-right:3px;opacity:.7;}
        .comment-icon:hover{opacity:1;color:#0d6efd;}
        .absence-mark{background:#e0e0e0 !important;color:#555 !important;border:1px solid #ccc !important;}
        .no-data{padding:40px;text-align:center;color:#888;font-size:16px;background:#fafafa;border-radius:6px;border:1px dashed #ccc;}
        .modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100;justify-content:center;align-items:center;}
        .modal.active{display:flex;}
        .modal-box{background:#fff;padding:22px;border-radius:8px;width:400px;max-width:90%;box-shadow:0 4px 20px rgba(0,0,0,.25);}
        .modal-box h3{margin:0 0 14px;font-size:15px;}
        .modal-box label{display:block;margin-bottom:6px;font-size:12px;color:#555;}
        .modal-box textarea{width:100%;padding:8px;margin-bottom:10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:13px;resize:vertical;}
        .modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:6px;}
        .modal-actions button{padding:7px 14px;border:none;border-radius:4px;cursor:pointer;font-size:12px;}
        .btn-cancel{background:#e0e0e0;} .btn-cancel:hover{background:#d5d5d5;}
        .btn-save{background:#1976d2;color:#fff;} .btn-save:hover{background:#1565c0;}
        @media print{
            body{background:#fff;padding:0;}
            .container{box-shadow:none;border:none;max-width:100%;padding:10px;}
            .no-print{display:none !important;}
            .table-wrap{overflow:visible;border:none;}
            th,td:first-child{position:static !important;}
        }
        .day-subheader th{background:#e3f2fd;font-weight:400;font-size:7px;padding:2px 1px;}
        .sub-col{min-width:15px;}
        .head-row td{background:#f3e5f5;font-weight:600;text-align:left;padding-left:8px;}
        .comment-cell{background:#f3e5f5;text-align:left;padding-left:6px;font-size:8px;max-width:200px;word-wrap:break-word;white-space:normal;}
        .manager-comment-cell{background:transparent;text-align:left;padding-left:6px;font-size:8px;max-width:200px;word-wrap:break-word;white-space:normal;}
        .cell-day.weekend{background:#f0f0f0 !important;color:#999 !important;}
        .absence-mark{background:#e0e0e0 !important;color:#555 !important;}
    </style>
</head>
<body>

<div class="nav no-print">
    <a href="dashboard.php" class="logo">🚀 SZB</a>
    <a href="dashboard.php">📊 Дашборд</a>
    <a href="team.php">👥 Команда</a>
    <a href="territories.php">🌍 Территории</a>
    <a href="export_inn.php">📋 ИНН</a>
    <a href="quests.php">🎯 Квесты</a>
    <a href="ai.php">🤖 AI</a>
    <?php if ($role === 'admin'): ?><a href="admin.php">⚙️ Админ</a><?php endif; ?>
    <span class="user">👤 <?= htmlspecialchars($_SESSION['name'] ?? $user_name) ?></span>
    <a href="logout.php" class="logout">🚪 Выйти</a>
</div>

<div class="container">
    <h1>📋 Ежедневные продажи: по ГОСБ</h1>
    <div class="subtitle">Период: <?= $month_name ?> <?= $year ?> (по <?= $selected_date ?>) | Пользователь: <?= htmlspecialchars((string)($user_name ?? '')) ?></div>

    <div class="top-bar no-print">
        <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
            <label>Дата:
                <input type="date" name="date" value="<?= $selected_date ?>" style="width:160px;">
            </label>
            <label>Территория:
                <select name="territory">
                    <option value="0">Все</option>
                    <?php foreach ($territories as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $territory_filter == $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="btn-primary">🔄 Обновить</button>
        </form>
        <div style="flex:1"></div>
        <div class="color-settings">
            <span style="font-size:13px;font-weight:600;">🎨 Границы:</span>
            <label>🔴 до <input type="number" id="red_max" value="<?= (int) ($colors['red_max'] ?? 1) ?>" min="0" max="99" style="width:42px;"></label>
            <label>🟡 до <input type="number" id="yellow_max" value="<?= (int) ($colors['yellow_max'] ?? 2) ?>" min="0" max="99" style="width:42px;"></label>
            <button class="btn-gray" onclick="saveColors()">💾 Сохранить</button>
        </div>
        <button class="btn-excel" onclick="exportExcel()">📊 Excel</button>
        <button class="btn-pdf" onclick="exportPDF()">📄 PDF</button>
    </div>

    <?php if (empty($structure)): ?>
        <div class="no-data">😕 Нет данных</div>
    <?php else: ?>
        <!-- Сводная таблица -->
        <h2 style="margin:20px 0 10px;font-size:18px;">📊 Сводка по ГОСБ</h2>
        <div class="table-wrap" id="summary-table">
            <table>
                <thead>
                    <tr>
                        <th>ГОСБ</th>
                        <th>Начальник отдела</th>
                        <th>План месяц</th>
                        <th>Факт месяц</th>
                        <th>RR</th>
                        <th>ВП</th>
                        <th>РП</th>
                        <?php foreach ($days_reverse as $d): 
                            $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                            $weekday_num = date('N', strtotime($date_str)) - 1;
                            $weekday = $weekdays_ru[$weekday_num];
                        ?>
                            <th colspan="4" class="<?= date('N', strtotime($date_str)) >= 6 ? 'weekend' : '' ?>">
                                <?= $d ?><br><small><?= $weekday ?></small>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th></th><th></th><th></th><th></th><th></th><th></th><th></th>
                        <?php foreach ($days_reverse as $d): ?>
                            <th class="sub-col">ИНН</th>
                            <th class="sub-col">Кл</th>
                            <th class="sub-col">Кс</th>
                            <th class="sub-col">РП</th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($structure as $terr_id => $terr): 
                    $daily = $terr['daily_totals'] ?? array_fill_keys($display_days, ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0]);
                    foreach ($terr['heads'] as $head_name => $head_group):
                        $head_daily = array_fill_keys($display_days, ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0]);
                        foreach ($head_group['managers'] as $m) {
                            $t = $m['tabel_key'];
                            if (empty($t)) continue;
                            foreach ($display_days as $d) {
                                $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                                $cnt = isset($sales[$t][$date_str]) ? $sales[$t][$date_str] : ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0];
                                $head_daily[$d]['total'] += $cnt['total'];
                                $head_daily[$d]['keyv'] += $cnt['keyv'];
                                $head_daily[$d]['kas']  += $cnt['kas'];
                                $head_daily[$d]['target'] += $cnt['target'];
                            }
                        }
                ?>
                    <tr>
                        <td style="text-align:left;padding-left:8px;font-weight:600;"><?= htmlspecialchars($terr['name']) ?></td>
                        <td style="text-align:left;padding-left:8px;"><?= htmlspecialchars($head_name) ?></td>
                        <td><?= (int) $head_group['total_plan'] ?></td>
                        <td><?= (int) $head_group['total_fact'] ?></td>
                        <td style="font-weight:700;color:<?= (int) $head_group['total_rr'] >= (int) $head_group['total_plan'] ? '#2e7d32' : ((int) $head_group['total_rr'] >= (int) $head_group['total_plan']*0.7 ? '#ed6c02' : '#d32f2f') ?>"><?= (int) $head_group['total_rr'] ?></td>
                        <td style="font-weight:700;color:<?= (int) $head_group['total_vp'] >= 100 ? '#2e7d32' : ((int) $head_group['total_vp'] >= 70 ? '#ed6c02' : '#d32f2f') ?>"><?= (int) $head_group['total_vp'] ?>%</td>
                        <td><?= (int) $head_group['total_cs'] ?></td>
                        <?php foreach ($days_reverse as $d): ?>
                            <td><?= isset($head_daily[$d]['total']) ? (int) $head_daily[$d]['total'] : '' ?></td>
                            <td><?= isset($head_daily[$d]['keyv']) ? (int) $head_daily[$d]['keyv'] : '' ?></td>
                            <td><?= isset($head_daily[$d]['kas']) ? (int) $head_daily[$d]['kas'] : '' ?></td>
                            <td><?= isset($head_daily[$d]['target']) ? (int) $head_daily[$d]['target'] : '' ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                <!-- Итог по территории -->
                <tr class="totals-row">
                    <td style="text-align:left;padding-left:8px;font-weight:700;">🎯 ИТОГО по <?= htmlspecialchars($terr['name']) ?></td>
                    <td></td>
                    <td><?= (int) $terr['total_plan'] ?></td>
                    <td><?= (int) $terr['total_fact'] ?></td>
                    <td style="font-weight:700;color:<?= (int) $terr['total_rr'] >= (int) $terr['total_plan'] ? '#2e7d32' : ((int) $terr['total_rr'] >= (int) $terr['total_plan']*0.7 ? '#ed6c02' : '#d32f2f') ?>"><?= (int) $terr['total_rr'] ?></td>
                    <td style="font-weight:700;color:<?= (int) $terr['total_vp'] >= 100 ? '#2e7d32' : ((int) $terr['total_vp'] >= 70 ? '#ed6c02' : '#d32f2f') ?>"><?= (int) $terr['total_vp'] ?>%</td>
                    <td><?= (int) $terr['total_cs'] ?></td>
                    <?php foreach ($days_reverse as $d): ?>
                        <td><?= isset($daily[$d]['total']) ? (int) $daily[$d]['total'] : '' ?></td>
                        <td><?= isset($daily[$d]['keyv']) ? (int) $daily[$d]['keyv'] : '' ?></td>
                        <td><?= isset($daily[$d]['kas']) ? (int) $daily[$d]['kas'] : '' ?></td>
                        <td><?= isset($daily[$d]['target']) ? (int) $daily[$d]['target'] : '' ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                <tr class="grand-totals">
                    <td style="text-align:left;padding-left:8px;">🎯 ВСЕГО</td>
                    <td></td>
                    <td><?= $grand_plan ?></td>
                    <td><?= $grand_fact ?></td>
                    <td style="font-weight:800;color:<?= $grand_rr >= $grand_plan ? '#2e7d32' : ($grand_rr >= $grand_plan*0.7 ? '#ed6c02' : '#d32f2f') ?>"><?= $grand_rr ?></td>
                    <td style="font-weight:800;color:<?= $grand_vp >= 100 ? '#2e7d32' : ($grand_vp >= 70 ? '#ed6c02' : '#d32f2f') ?>"><?= $grand_vp ?>%</td>
                    <td><?= $grand_cs ?></td>
                    <?php foreach ($days_reverse as $d): ?>
                        <td><?= isset($grand_daily[$d]['total']) ? (int) $grand_daily[$d]['total'] : '' ?></td>
                        <td><?= isset($grand_daily[$d]['keyv']) ? (int) $grand_daily[$d]['keyv'] : '' ?></td>
                        <td><?= isset($grand_daily[$d]['kas']) ? (int) $grand_daily[$d]['kas'] : '' ?></td>
                        <td><?= isset($grand_daily[$d]['target']) ? (int) $grand_daily[$d]['target'] : '' ?></td>
                    <?php endforeach; ?>
                </tr>
                </tbody>
            </table>
        </div>

        <!-- Детальные таблицы по каждой территории -->
        <?php foreach ($structure as $terr_id => $terr): ?>
            <h3 style="margin:30px 0 8px;font-size:16px;background:#e3f2fd;padding:6px 12px;border-radius:4px;">
                🏢 <?= htmlspecialchars($terr['name']) ?>
                <span style="font-weight:400;font-size:13px;color:#555;margin-left:10px;">
                    План: <?= (int) $terr['total_plan'] ?>, Факт: <?= (int) $terr['total_fact'] ?>, RR: <?= (int) $terr['total_rr'] ?>
                </span>
            </h3>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ФИО Руководителя</th>
                            <th>Минипротокол</th>
                            <th>ФИО менеджера</th>
                            <th>Стаж (Г.М)</th>
                            <th>RR</th>
                            <th>ВП</th>
                            <th>РП</th>
                            <?php foreach ($days_reverse as $d): 
                                $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                                $weekday_num = date('N', strtotime($date_str)) - 1;
                                $weekday = $weekdays_ru[$weekday_num];
                            ?>
                                <th colspan="4" class="<?= date('N', strtotime($date_str)) >= 6 ? 'weekend' : '' ?>">
                                    <?= $d ?><br><small><?= $weekday ?></small>
                                </th>
                            <?php endforeach; ?>
                            <th>План</th><th>Факт</th>
                        </tr>
                        <tr>
                            <th></th><th></th><th></th><th></th><th></th><th></th><th></th>
                            <?php foreach ($days_reverse as $d): ?>
                                <th class="sub-col">ИНН</th>
                                <th class="sub-col">Кл</th>
                                <th class="sub-col">Кс</th>
                                <th class="sub-col">РП</th>
                            <?php endforeach; ?>
                            <th></th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $total_cols = 11 + 4 * count($display_days); 
                    foreach ($terr['heads'] as $head_name => $head_group):
                        $managers_list = $head_group['managers'] ?? [];
                        $head_tabel = $head_group['head_tabel'] ?? '';
                        $head_plan = $head_group['total_plan'] ?? 0;
                        $head_fact = $head_group['total_fact'] ?? 0;
                        $head_rr   = $head_group['total_rr'] ?? 0;
                        $head_vp   = $head_group['total_vp'] ?? 0;
                        $head_cs   = $head_group['total_cs'] ?? 0;
                        $head_comments = getAllComments($pdo, $head_tabel, 'head');
                    ?>
                        <tr class="head-row">
                            <td style="background:#f3e5f5;font-weight:600;text-align:left;padding-left:8px;">
                                <span class="comment-icon no-print" title="Редактировать комментарий руководителя" onclick="openCommentModal('<?= htmlspecialchars($head_tabel) ?>','<?= htmlspecialchars($head_name) ?>','head','<?= $selected_date ?>')">💬</span>
                                <?= htmlspecialchars($head_name) ?>
                            </td>
                            <td class="comment-cell" style="background:#f3e5f5;">
                                <?php if(empty($head_comments)): ?>—<?php else: ?>
                                    <?php foreach ($head_comments as $c): ?>
                                        <div><?= date('d.m.Y', strtotime($c['comment_date'])) ?>: <?= htmlspecialchars($c['comment']) ?></div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td colspan="<?= $total_cols - 2 ?>" style="background:#f3e5f5;"></td>
                        </tr>

                        <tr style="font-weight:bold;background:#fff8e1;">
                            <td colspan="2" style="text-align:left;padding-left:8px;">ИТОГО по <?= htmlspecialchars($head_name) ?></td>
                            <td></td>
                            <td></td>
                            <td><?= $head_rr ?></td>
                            <td><?= $head_vp ?>%</td>
                            <td><?= $head_cs ?></td>
                            <?php 
                            $head_daily = array_fill_keys($display_days, ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0]);
                            foreach ($managers_list as $m) {
                                $t = $m['tabel_key'];
                                if (empty($t)) continue;
                                foreach ($display_days as $d) {
                                    $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                                    $cnt = isset($sales[$t][$date_str]) ? $sales[$t][$date_str] : ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0];
                                    $head_daily[$d]['total'] += $cnt['total'];
                                    $head_daily[$d]['keyv'] += $cnt['keyv'];
                                    $head_daily[$d]['kas']  += $cnt['kas'];
                                    $head_daily[$d]['target'] += $cnt['target'];
                                }
                            }
                            foreach ($days_reverse as $d): ?>
                                <td><?= $head_daily[$d]['total'] ?></td>
                                <td><?= $head_daily[$d]['keyv'] ?></td>
                                <td><?= $head_daily[$d]['kas'] ?></td>
                                <td><?= $head_daily[$d]['target'] ?></td>
                            <?php endforeach; ?>
                            <td><?= $head_plan ?></td>
                            <td><?= $head_fact ?></td>
                        </tr>

                        <?php foreach ($managers_list as $m):
                            $t = $m['tabel_key'];
                            if (empty($t)) continue;
                            $plan = (int) ($m['plan'] ?? 0);
                            $fact = (int) ($m['fact'] ?? 0);
                            $rr   = (int) ($m['rr'] ?? 0);
                            $vp   = (int) ($m['vp'] ?? 0);
                            $cs   = (int) ($m['target'] ?? 0); 
                            $staz = $m['staz'] ?? '000/00';
                            $manager_comments_list = $manager_comments[$t] ?? [];
                        ?>
                            <tr data-tabel="<?= htmlspecialchars((string)$t) ?>">
                                <td></td>
                                <td class="manager-comment-cell">
                                    <?php if(empty($manager_comments_list)): ?>—<?php else: ?>
                                        <?php foreach ($manager_comments_list as $c): ?>
                                            <div><?= date('d.m.Y', strtotime($c['comment_date'])) ?>: <?= htmlspecialchars($c['comment']) ?></div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="name-col">
                                    <span class="comment-icon no-print" title="Редактировать комментарий менеджера" onclick="openCommentModal('<?= htmlspecialchars((string)$t) ?>','<?= htmlspecialchars((string)($m['full_name'] ?? '')) ?>','manager','<?= $selected_date ?>')">💬</span>
                                    <?= htmlspecialchars((string)($m['full_name'] ?? '')) ?>
                                    <span class="edit-icon no-print" title="Изменить дату ввода"
                                          onclick="openPositionModal('<?= htmlspecialchars((string)$t) ?>','<?= htmlspecialchars((string)($m['full_name'] ?? '')) ?>','<?= htmlspecialchars((string)((!empty($m['position_start_date'])) ? $m['position_start_date'] : ($m['created_at'] ?? ''))) ?>')">✏️</span>
                                </td>
                                <td class="staz-col"><?= $staz ?></td>
                                <td class="rr-col" style="color:<?= $rr >= $plan ? '#2e7d32' : ($rr >= $plan*0.7 ? '#ed6c02' : '#d32f2f') ?>"><?= $rr ?></td>
                                <td style="color:<?= $vp >= 100 ? '#2e7d32' : ($vp >= 70 ? '#ed6c02' : '#d32f2f') ?>"><?= $vp ?>%</td>
                                <td><?= $cs ?></td>
                                <?php foreach ($days_reverse as $d):
                                    $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                                    $is_weekend = date('N', strtotime($date_str)) >= 6;
                                    $absent = isset($absences[$t][$date_str]) ? true : false;
                                    $cnt = isset($sales[$t][$date_str]) ? $sales[$t][$date_str] : ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0];
                                    $total_products = $cnt['total'];
                                    $keyv  = $cnt['keyv'];
                                    $kas   = $cnt['kas'];
                                    $target = $cnt['target'];
                                    $cell_class = 'cell-day';
                                    if ($is_weekend) $cell_class .= ' weekend';
                                    if ($absent) {
                                        $cell_class .= ' absence-mark';
                                        $display_total = 'Н';
                                        $display_keyv  = 'Н';
                                        $display_kas   = 'Н';
                                        $display_target = 'Н';
                                        $style = '';
                                    } else {
                                        $display_total = $total_products;
                                        $display_keyv  = $keyv;
                                        $display_kas   = $kas;
                                        $display_target = $target;
                                        $c = getDayColor($total_products, $colors);
                                        $style = "background:{$c['bg']};color:{$c['txt']}";
                                    }
                                ?>
                                    <td class="<?= $cell_class ?>" style="<?= $style ?>" data-date="<?= $date_str ?>" data-tabel="<?= htmlspecialchars((string)$t) ?>" onclick="toggleAbsence('<?= htmlspecialchars((string)$t) ?>','<?= $date_str ?>')"><?= $display_total ?></td>
                                    <td class="<?= $cell_class ?>" style="<?= $style ?>" data-date="<?= $date_str ?>" data-tabel="<?= htmlspecialchars((string)$t) ?>"><?= $display_keyv ?></td>
                                    <td class="<?= $cell_class ?>" style="<?= $style ?>" data-date="<?= $date_str ?>" data-tabel="<?= htmlspecialchars((string)$t) ?>"><?= $display_kas ?></td>
                                    <td class="<?= $cell_class ?>" style="<?= $style ?>" data-date="<?= $date_str ?>" data-tabel="<?= htmlspecialchars((string)$t) ?>"><?= $display_target ?></td>
                                <?php endforeach; ?>
                                <td style="font-weight:600;"><?= $plan ?></td>
                                <td style="font-weight:600;"><?= $fact ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <tr class="totals-row">
                        <td colspan="2" style="text-align:left;font-weight:700;background:#fff8e1 !important;">🎯 ИТОГО по <?= htmlspecialchars($terr['name']) ?></td>
                        <td></td>
                        <td></td>
                        <td style="background:#fff8e1 !important;"><?= (int) $terr['total_rr'] ?></td>
                        <td style="background:#fff8e1 !important;"><?= (int) $terr['total_vp'] ?>%</td>
                        <td style="background:#fff8e1 !important;"><?= (int) $terr['total_cs'] ?></td>
                        <?php foreach ($days_reverse as $d):
                            $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                            $day_total = ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0];
                            foreach ($terr['heads'] as $head_group) {
                                foreach ($head_group['managers'] as $m) {
                                    if (isset($sales[$m['tabel_key']][$date_str])) {
                                        $cnt = $sales[$m['tabel_key']][$date_str];
                                        $day_total['total'] += $cnt['total'];
                                        $day_total['keyv'] += $cnt['keyv'];
                                        $day_total['kas']  += $cnt['kas'];
                                        $day_total['target'] += $cnt['target'];
                                    }
                                }
                            }
                        ?>
                            <td style="font-weight:700;background:#fff8e1 !important;"><?= $day_total['total'] > 0 ? $day_total['total'] : '' ?></td>
                            <td style="font-weight:700;background:#fff8e1 !important;"><?= $day_total['keyv'] > 0 ? $day_total['keyv'] : '' ?></td>
                            <td style="font-weight:700;background:#fff8e1 !important;"><?= $day_total['kas'] > 0 ? $day_total['kas'] : '' ?></td>
                            <td style="font-weight:700;background:#fff8e1 !important;"><?= $day_total['target'] > 0 ? $day_total['target'] : '' ?></td>
                        <?php endforeach; ?>
                        <td style="font-weight:700;background:#fff8e1 !important;"><?= (int) $terr['total_plan'] ?></td>
                        <td style="font-weight:700;background:#fff8e1 !important;"><?= (int) $terr['total_fact'] ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="no-print" style="margin-top:14px;font-size:12px;color:#888;line-height:1.6;">
        💡 <b>Как пользоваться:</b> Клик на ячейку дня → отметить/снять отсутствие. Клик на ✏️ → дата ввода в должность. 💬 у руководителя (слева от ФИО) → минипротокол. 💬 у менеджера (слева от фамилии) → личный комментарий (отображается в столбце «Минипротокол»). Настрой цвета → Сохранить. Excel/PDF → выгрузка.
    </div>
</div>

<!-- Модалки -->
<div id="commentModal" class="modal">
    <div class="modal-box">
        <h3 id="commentModalTitle">💬 Комментарий</h3>
        <div id="commentModalInfo" style="font-size:13px;color:#666;margin-bottom:8px;"></div>
        <label>Дата: <input type="date" id="commentDate" style="width:100%;"></label>
        <label>Текст:</label>
        <textarea id="commentText" rows="4"></textarea>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeModal('commentModal')">Отмена</button>
            <button class="btn-save" onclick="saveComment()">💾 Сохранить</button>
        </div>
    </div>
</div>

<div id="positionModal" class="modal">
    <div class="modal-box">
        <h3>✏️ Дата ввода в должность</h3>
        <div id="positionInfo" style="font-size:13px;color:#666;margin-bottom:12px;"></div>
        <label>Дата (ГГГГ-ММ-ДД):</label>
        <input type="date" id="positionDate">
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeModal('positionModal')">Отмена</button>
            <button class="btn-save" onclick="savePositionDate()">💾 Сохранить</button>
        </div>
    </div>
</div>

<div id="absenceModal" class="modal">
    <div class="modal-box">
        <h3>📌 Отметка отсутствия</h3>
        <div id="absenceInfo" style="font-size:13px;color:#666;margin-bottom:12px;"></div>
        <label><input type="checkbox" id="absenceCheck"> Отсутствует</label>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeModal('absenceModal')">Отмена</button>
            <button class="btn-save" onclick="saveAbsence()">💾 Сохранить</button>
        </div>
    </div>
</div>

<script>
let currentUserTabel = '', currentDate = '', currentRole = '';
let currentTabel = '', currentDateAbsence = '';

function openCommentModal(userTabel, userName, role, defaultDate) {
    currentUserTabel = userTabel;
    currentRole = role;
    const title = (role === 'head') ? '💬 Минипротокол (руководитель)' : '💬 Личный комментарий менеджера';
    document.getElementById('commentModalTitle').textContent = title;
    document.getElementById('commentModalInfo').textContent = (role === 'head' ? 'Руководитель: ' : 'Менеджер: ') + userName;
    document.getElementById('commentDate').value = defaultDate;
    fetch('api_terman.php?action=get_comment&user_tabel='+encodeURIComponent(userTabel)+'&date='+encodeURIComponent(defaultDate)+'&role='+encodeURIComponent(role))
        .then(res => res.json())
        .then(data => {
            document.getElementById('commentText').value = data.comment || '';
        })
        .catch(() => { document.getElementById('commentText').value = ''; });
    document.getElementById('commentModal').classList.add('active');
}

async function saveComment() {
    const date = document.getElementById('commentDate').value;
    const comment = document.getElementById('commentText').value;
    if (!currentUserTabel || !date) { alert('Не хватает данных'); return; }
    
    // Не сохраняем пустые комментарии, чтобы не затереть старые
    if (!comment.trim()) {
        alert('⚠️ Комментарий пустой, сохранение отменено, чтобы не удалить старую запись.');
        return;
    }

    const res = await fetch('api_terman.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=save_comment&user_tabel='+encodeURIComponent(currentUserTabel)
            +'&date='+encodeURIComponent(date)
            +'&comment='+encodeURIComponent(comment)
            +'&role='+encodeURIComponent(currentRole)
    });
    const data = await res.json();
    if(data.success) { alert('✅ Комментарий сохранён!'); location.reload(); }
    else alert('Ошибка: '+(data.error||'неизвестная'));
}

function toggleAbsence(tabel, date) {
    currentTabel = tabel; currentDateAbsence = date;
    const cell = document.querySelector(`td[data-date="${date}"][data-tabel="${tabel}"]`);
    const isAbsent = cell && cell.classList.contains('absence-mark');
    document.getElementById('absenceInfo').textContent = 'Сотрудник: ' + tabel + ' | ' + date;
    document.getElementById('absenceCheck').checked = isAbsent;
    document.getElementById('absenceModal').classList.add('active');
}
async function saveAbsence() {
    const checked = document.getElementById('absenceCheck').checked;
    const type = checked ? 'X' : '';
    const res = await fetch('api_terman.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=save_absence&tabel='+encodeURIComponent(currentTabel)
            +'&date='+encodeURIComponent(currentDateAbsence)
            +'&type='+encodeURIComponent(type)
    });
    const data = await res.json();
    if(data.success) location.reload();
    else alert('Ошибка: '+(data.error||'неизвестная'));
}

function openPositionModal(tabel, name, date) {
    currentTabel = tabel;
    document.getElementById('positionInfo').textContent = name;
    document.getElementById('positionDate').value = date || '';
    document.getElementById('positionModal').classList.add('active');
}
async function savePositionDate() {
    const date = document.getElementById('positionDate').value;
    const res = await fetch('api_terman.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=save_position_date&tabel='+encodeURIComponent(currentTabel)
            +'&date='+encodeURIComponent(date)
    });
    const data = await res.json();
    if(data.success) location.reload();
    else alert('Ошибка: '+(data.error||'неизвестная'));
}

async function saveColors() {
    const red = parseInt(document.getElementById('red_max').value);
    const yellow = parseInt(document.getElementById('yellow_max').value);
    if(red >= yellow) { alert('🔴 Красный порог должен быть меньше жёлтого!'); return; }
    const res = await fetch('api_terman.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=save_colors&red_max='+encodeURIComponent(red)
            +'&yellow_max='+encodeURIComponent(yellow)
    });
    const data = await res.json();
    if(data.success) { alert('✅ Настройки сохранены!'); location.reload(); }
    else alert('Ошибка: '+(data.error||'неизвестная'));
}

function exportExcel() {
    const url = 'export_terman_excel.php?' + new URLSearchParams({
        year: <?= $year ?>,
        month: <?= $month ?>,
        territory: <?= $territory_filter ?>,
        date: '<?= $selected_date ?>'
    });
    window.open(url, '_blank');
}
function exportPDF() {
    const url = 'export_terman_pdf.php?' + new URLSearchParams({
        year: <?= $year ?>,
        month: <?= $month ?>,
        territory: <?= $territory_filter ?>,
        date: '<?= $selected_date ?>'
    });
    window.open(url, '_blank');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}
document.addEventListener('keydown', e => { if(e.key==='Escape') document.querySelectorAll('.modal').forEach(m=>m.classList.remove('active')); });
document.querySelectorAll('.modal').forEach(m=>{ m.addEventListener('click', e=>{ if(e.target===m) m.classList.remove('active'); }); });
</script>
</body>
</html>