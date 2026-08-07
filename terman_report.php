<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$role = $_SESSION['role'] ?? '';
$user_name = $_SESSION['full_name'] ?? 'Пользователь';
$user_id = $_SESSION['user_id'];

$allowed = ['head', 'territory_head', 'admin', 'terman'];
if (!in_array($role, $allowed)) die('🚫 Доступ запрещён.');

// ── Функция получения продуктов из inn_records (ТЭ+Смарт+ПОС) ──
function getProductSumsFromInn($pdo, $tabel, $date_from, $date_to) {
    $sql = "
        SELECT 
            COALESCE(SUM(CASE WHEN product IN ('ТЭ', 'Смарт', 'ПОС') THEN 1 ELSE 0 END), 0) AS total
        FROM inn_records
        WHERE employee_tabel = ?
          AND DATE(sale_date) BETWEEN ? AND ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tabel, $date_from, $date_to]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int)($row['total'] ?? 0);
}

// ── Параметры месяца ──────────────────────────────────────
$last_data = $pdo->query("SELECT MAX(sale_date) as max_date FROM inn_records WHERE sale_date IS NOT NULL")->fetch();
$last_year  = $last_data && $last_data['max_date'] ? (int)date('Y', strtotime($last_data['max_date'])) : (int)date('Y');
$last_month = $last_data && $last_data['max_date'] ? (int)date('m', strtotime($last_data['max_date'])) : (int)date('m');

$year  = isset($_GET['year'])  ? (int)$_GET['year']  : $last_year;
$month = isset($_GET['month']) ? (int)$_GET['month'] : $last_month;
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$month_names = ['','Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
$month_name = $month_names[$month];
$period = sprintf('%04d-%02d', $year, $month);
$date_from = "$year-$month-01";
$date_to   = "$year-$month-$days_in_month";

// ── Получение менеджеров ──────────────────────────────────
if ($role === 'terman') {
    $stmt = $pdo->prepare("SELECT territory_id FROM territory_managers WHERE manager_id = ?");
    $stmt->execute([$user_id]);
    $territories = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $territory_ids = empty($territories) ? [0] : $territories;
    $placeholders = implode(',', array_fill(0, count($territory_ids), '?'));
    $sql = "
        SELECT u.tabel_number, u.full_name, u.territory_id, u.head_tabel,
               u.position_start_date, u.created_at,
               t.name AS territory_name, h.full_name AS head_name
        FROM users u
        LEFT JOIN territories t ON u.territory_id = t.id
        LEFT JOIN users h ON u.head_tabel = h.tabel_number
        WHERE u.role = 'manager' AND u.is_active = 1 AND u.territory_id IN ($placeholders)
        ORDER BY t.name, h.full_name, u.full_name
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($territory_ids);
    $managers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $managers = $pdo->query("
        SELECT u.tabel_number, u.full_name, u.territory_id, u.head_tabel,
               u.position_start_date, u.created_at,
               t.name AS territory_name, h.full_name AS head_name
        FROM users u
        LEFT JOIN territories t ON u.territory_id = t.id
        LEFT JOIN users h ON u.head_tabel = h.tabel_number
        WHERE u.role = 'manager' AND u.is_active = 1
        ORDER BY t.name, h.full_name, u.full_name
    ")->fetchAll(PDO::FETCH_ASSOC);
}
if (empty($managers)) die('Нет активных менеджеров');

// ── Планы ──────────────────────────────────────────────
$plans = [];
$stmt = $pdo->prepare("SELECT tabel_number, inn_leads_plan FROM plans WHERE period = ?");
$stmt->execute([$period]);
while ($r = $stmt->fetch()) {
    $plans[(string)$r['tabel_number']] = (int)$r['inn_leads_plan'];
}

// ── Продажи по дням для каждого менеджера ──────────────
$sales = [];
$absences = [];
foreach ($managers as $m) {
    $t = (string)$m['tabel_number'];
    // Для каждого дня месяца получаем сумму ТЭ+Смарт+ПОС
    for ($d = 1; $d <= $days_in_month; $d++) {
        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $sum = getProductSumsFromInn($pdo, $t, $date_str, $date_str);
        $sales[$t][$date_str] = $sum;
    }
    // Отсутствия
    $stmt = $pdo->prepare("SELECT absence_date FROM employee_absences WHERE employee_tabel = ? AND absence_date BETWEEN ? AND ?");
    $stmt->execute([$t, $date_from, $date_to]);
    $abs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $absences[$t] = array_fill_keys($abs, true);
}

// ── Факт за месяц ─────────────────────────────────────────
$fact_month = [];
foreach ($sales as $t => $days) {
    $fact_month[$t] = array_sum($days);
}

// ── Настройки цветов (глобальные, territory_id = NULL) ──
$colors = $pdo->query("SELECT red_max, yellow_max FROM terman_color_settings WHERE territory_id IS NULL ORDER BY id DESC LIMIT 1")->fetch();
if (!$colors) $colors = ['red_max' => 1, 'yellow_max' => 2]; // ← изменено

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
    $red    = (int)($colors['red_max'] ?? 1);
    $yellow = (int)($colors['yellow_max'] ?? 2);
    if ($cnt <= $red)   return ['bg'=>'#ffcdd2','txt'=>'#b71c1c'];
    if ($cnt <= $yellow)return ['bg'=>'#fff9c4','txt'=>'#f57f17'];
    return ['bg'=>'#c8e6c9','txt'=>'#1b5e20'];
}

// ── ГРУППИРОВКА ────────────────────────────────────────────
$structure = [];
foreach ($managers as $m) {
    $terr_id   = (int)($m['territory_id'] ?? 0);
    $terr_name = $m['territory_name'] ?? 'Без территории';
    $head_name = $m['head_name'] ?? 'Без руководителя';
    $tabel_key = (string)$m['tabel_number'];

    if (!isset($structure[$terr_id]) || !is_array($structure[$terr_id])) {
        $structure[$terr_id] = [
            'name'  => $terr_name,
            'heads' => [],
            'daily_totals' => array_fill(1, $days_in_month, 0),
            'total_plan' => 0,
            'total_fact' => 0,
            'total_rr'   => 0,
        ];
    }
    if (!isset($structure[$terr_id]['heads'][$head_name]) || !is_array($structure[$terr_id]['heads'][$head_name])) {
        $structure[$terr_id]['heads'][$head_name] = [
            'managers' => [],
            'total_plan' => 0,
            'total_fact' => 0,
            'total_rr'   => 0,
        ];
    }
    $structure[$terr_id]['heads'][$head_name]['managers'][] = array_merge($m, ['tabel_key' => $tabel_key]);
}

// ── ВЫЧИСЛЕНИЕ ИТОГОВ ──────────────────────────────────────
$grand_plan = 0;
$grand_fact = 0;
$grand_daily = array_fill(1, $days_in_month, 0);

foreach ($structure as $terr_id => &$terr) {
    $terr_plan = 0;
    $terr_fact = 0;
    $terr_daily = array_fill(1, $days_in_month, 0);

    foreach ($terr['heads'] as $head_name => &$head_group) {
        $head_plan = 0;
        $head_fact = 0;

        foreach ($head_group['managers'] as &$m) {
            $t = $m['tabel_key'];
            if (empty($t)) continue;

            $plan = $plans[$t] ?? 0;
            $fact = $fact_month[$t] ?? 0;
            $m['plan'] = $plan;
            $m['fact'] = $fact;
            $m['rr']   = $plan > 0 ? round(($fact / $plan) * 100) : 0;
            $start_date = (!empty($m['position_start_date'])) ? $m['position_start_date'] : ($m['created_at'] ?? '');
            $m['staz'] = calcStaz($start_date);

            $head_plan += $plan;
            $head_fact += $fact;

            for ($d = 1; $d <= $days_in_month; $d++) {
                $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $cnt = isset($sales[$t][$date_str]) ? (int)$sales[$t][$date_str] : 0;
                $terr_daily[$d] += $cnt;
                $grand_daily[$d] += $cnt;
            }
        }

        $head_group['total_plan'] = $head_plan;
        $head_group['total_fact'] = $head_fact;
        $head_group['total_rr']   = $head_plan > 0 ? round(($head_fact / $head_plan) * 100) : 0;

        $terr_plan += $head_plan;
        $terr_fact += $head_fact;
    }

    $terr['total_plan'] = $terr_plan;
    $terr['total_fact'] = $terr_fact;
    $terr['total_rr']   = $terr_plan > 0 ? round(($terr_fact / $terr_plan) * 100) : 0;
    $terr['daily_totals'] = $terr_daily;

    $grand_plan += $terr_plan;
    $grand_fact += $terr_fact;
}
unset($terr, $head_group, $m);
$grand_rr = $grand_plan > 0 ? round(($grand_fact / $grand_plan) * 100) : 0;
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>📋 Отчёт термена — <?= $month_name ?> <?= $year ?></title>
<link rel="stylesheet" href="style.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<style>
body{font-family:system-ui,sans-serif;background:#f5f5f5;padding:20px;margin:0;}
.container{max-width:1600px;margin:0 auto;background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1);}
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
.btn-success{background:#388e3c;color:#fff;} .btn-success:hover{background:#2e7d32;}
.btn-gray{background:#757575;color:#fff;} .btn-gray:hover{background:#616161;}
.color-settings{display:flex;gap:18px;align-items:center;flex-wrap:wrap;}
.table-wrap{overflow-x:auto;margin-top:12px;border:1px solid #ddd;border-radius:6px;margin-bottom:30px;}
table{border-collapse:collapse;width:100%;font-size:11px;}
th,td{border:1px solid #ddd;padding:3px 4px;text-align:center;white-space:nowrap;}
th{background:#f0f0f0;font-weight:600;color:#333;position:sticky;top:0;z-index:2;}
th:first-child,td:first-child{position:sticky;left:0;background:#fff;z-index:3;}
th:first-child{z-index:4;}
.cell-day{width:28px;height:28px;cursor:pointer;transition:.12s;font-weight:600;font-size:10px;}
.cell-day:hover{transform:scale(1.1);box-shadow:0 0 4px rgba(0,0,0,.2);z-index:1;position:relative;}
.name-col{text-align:left;min-width:150px;padding-left:6px !important;}
.staz-col{min-width:45px;}
.rr-col{min-width:38px;font-weight:700;}
.weekend{background:#f5f5f5 !important;color:#999 !important;}
.totals-row td{font-weight:700;background:#fff8e1 !important;}
.grand-totals td{font-weight:800;background:#ffecb3 !important;font-size:12px;}
.edit-icon{cursor:pointer;color:#1976d2;font-size:12px;margin-left:3px;opacity:.7;}
.edit-icon:hover{opacity:1;}
.absence-mark{background:#ffffff !important;color:#555 !important;border:1px solid #ddd !important;}
.no-data{padding:40px;text-align:center;color:#888;font-size:16px;background:#fafafa;border-radius:6px;border:1px dashed #ccc;}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100;justify-content:center;align-items:center;}
.modal.active{display:flex;}
.modal-box{background:#fff;padding:22px;border-radius:8px;width:300px;box-shadow:0 4px 20px rgba(0,0,0,.25);}
.modal-box h3{margin:0 0 14px;font-size:15px;}
.modal-box label{display:block;margin-bottom:6px;font-size:12px;color:#555;}
.modal-box select,.modal-box input{width:100%;padding:8px;margin-bottom:10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:13px;}
.modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:6px;}
.modal-actions button{padding:7px 14px;border:none;border-radius:4px;cursor:pointer;font-size:12px;}
.btn-cancel{background:#e0e0e0;} .btn-cancel:hover{background:#d5d5d5;}
.btn-save{background:#1976d2;color:#fff;} .btn-save:hover{background:#1565c0;}
.btn-delete{background:#d32f2f;color:#fff;} .btn-delete:hover{background:#c62828;}
@media print{
  body{background:#fff;padding:0;}
  .container{box-shadow:none;border:none;max-width:100%;padding:10px;}
  .no-print{display:none !important;}
  .table-wrap{overflow:visible;border:none;}
  th,td:first-child{position:static !important;}
}
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
    <div class="subtitle">Период: <?= $month_name ?> <?= $year ?> | Пользователь: <?= htmlspecialchars((string)($user_name ?? '')) ?></div>

    <div class="top-bar no-print">
        <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
            <label>Год: <input type="number" name="year" value="<?= $year ?>" min="2024" max="2030" style="width:70px;"></label>
            <label>Месяц:
                <select name="month">
                    <?php for($i=1;$i<=12;$i++): ?>
                        <option value="<?=$i?>" <?= $i==$month?'selected':'' ?>><?=$month_names[$i]?></option>
                    <?php endfor; ?>
                </select>
            </label>
            <button type="submit" class="btn-primary">🔄 Обновить</button>
        </form>
        <div style="flex:1"></div>
        <div class="color-settings">
            <span style="font-size:13px;font-weight:600;">🎨 Границы:</span>
            <label>🔴 до <input type="number" id="red_max" value="<?= (int)($colors['red_max'] ?? 1) ?>" min="0" max="99" style="width:42px;"></label>
            <label>🟡 до <input type="number" id="yellow_max" value="<?= (int)($colors['yellow_max'] ?? 2) ?>" min="0" max="99" style="width:42px;"></label>
            <button class="btn-gray" onclick="saveColors()">💾 Сохранить</button>
        </div>
        <button class="btn-success" onclick="downloadPDF()">📄 Скачать PDF</button>
    </div>

    <?php if (empty($structure)): ?>
        <div class="no-data">😕 Нет данных</div>
    <?php else: ?>
        <!-- Сводная таблица -->
        <h2 style="margin:20px 0 10px;font-size:18px;">📊 Сводка по ГОСБ</h2>
        <div class="table-wrap" id="summary-table">
            <table>
                <thead><tr><th>ГОСБ</th><th>План</th><th>Факт</th><th>RR,%</th>
                <?php for($d=1; $d<=$days_in_month; $d++): ?>
                    <th class="<?= date('N', strtotime("$year-$month-$d"))>=6?'weekend':'' ?>"><?= $d ?></th>
                <?php endfor; ?></tr></thead>
                <tbody>
                <?php foreach ($structure as $terr_id => $terr): 
                    $daily = (isset($terr['daily_totals']) && is_array($terr['daily_totals'])) ? $terr['daily_totals'] : array_fill(1, $days_in_month, 0);
                ?>
                    <tr>
                        <td style="text-align:left;padding-left:8px;font-weight:600;"><?= htmlspecialchars((string)($terr['name'] ?? '')) ?></td>
                        <td><?= (int)($terr['total_plan'] ?? 0) ?></td>
                        <td><?= (int)($terr['total_fact'] ?? 0) ?></td>
                        <td style="font-weight:700;color:<?= (int)($terr['total_rr'] ?? 0)>=100?'#2e7d32':((int)($terr['total_rr'] ?? 0)>=70?'#ed6c02':'#d32f2f') ?>"><?= (int)($terr['total_rr'] ?? 0) ?>%</td>
                        <?php for($d=1; $d<=$days_in_month; $d++): ?>
                            <td><?= isset($daily[$d]) ? (int)$daily[$d] : '' ?></td>
                        <?php endfor; ?>
                    </tr>
                <?php endforeach; ?>
                <tr class="grand-totals">
                    <td style="text-align:left;padding-left:8px;">🎯 ИТОГО</td>
                    <td><?= $grand_plan ?></td>
                    <td><?= $grand_fact ?></td>
                    <td style="color:<?= $grand_rr>=100?'#2e7d32':($grand_rr>=70?'#ed6c02':'#d32f2f') ?>"><?= $grand_rr ?>%</td>
                    <?php for($d=1; $d<=$days_in_month; $d++): ?>
                        <td><?= isset($grand_daily[$d]) ? (int)$grand_daily[$d] : '' ?></td>
                    <?php endfor; ?>
                </tr>
                </tbody>
            </table>
        </div>

        <!-- Детальные таблицы по каждой территории -->
        <?php foreach ($structure as $terr_id => $terr): ?>
            <h3 style="margin:30px 0 8px;font-size:16px;background:#e3f2fd;padding:6px 12px;border-radius:4px;">
                🏢 <?= htmlspecialchars((string)($terr['name'] ?? '')) ?>
                <span style="font-weight:400;font-size:13px;color:#555;margin-left:10px;">
                    План: <?= (int)($terr['total_plan'] ?? 0) ?>, Факт: <?= (int)($terr['total_fact'] ?? 0) ?>, RR: <?= (int)($terr['total_rr'] ?? 0) ?>%
                </span>
            </h3>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ФИО Руководителя</th>
                            <th>ФИО менеджера</th>
                            <th>Стаж (Г.М)</th>
                            <th>RR мес,%</th>
                            <?php for($d=1; $d<=$days_in_month; $d++): ?>
                                <th class="<?= date('N', strtotime("$year-$month-$d"))>=6?'weekend':'' ?>"><?= $d ?></th>
                            <?php endfor; ?>
                            <th>План</th><th>Факт</th><th>ВП,%</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $terr_total_plan = 0;
                    $terr_total_fact = 0;
                    foreach ($terr['heads'] as $head_name => $head_group):
                        $managers_list = $head_group['managers'] ?? [];
                        $rowspan = count($managers_list);
                        $first = true;
                        foreach ($managers_list as $m):
                            $t = $m['tabel_key'];
                            if (empty($t)) continue;
                            $plan = (int)($m['plan'] ?? 0);
                            $fact = (int)($m['fact'] ?? 0);
                            $rr   = (int)($m['rr'] ?? 0);
                            $staz = $m['staz'] ?? '000/00';
                            $terr_total_plan += $plan;
                            $terr_total_fact += $fact;
                    ?>
                        <tr data-tabel="<?= htmlspecialchars((string)$t) ?>">
                            <?php if ($first): ?>
                                <td rowspan="<?= $rowspan ?>" style="background:#f3e5f5;font-weight:600;text-align:left;padding-left:8px;"><?= htmlspecialchars((string)$head_name) ?></td>
                            <?php endif; ?>
                            <td class="name-col">
                                <?= htmlspecialchars((string)($m['full_name'] ?? '')) ?>
                                <span class="edit-icon no-print" title="Изменить дату ввода"
                                      onclick="openPositionModal('<?= htmlspecialchars((string)$t) ?>','<?= htmlspecialchars((string)($m['full_name'] ?? '')) ?>','<?= htmlspecialchars((string)((!empty($m['position_start_date'])) ? $m['position_start_date'] : ($m['created_at'] ?? ''))) ?>')">✏️</span>
                            </td>
                            <td class="staz-col"><?= $staz ?></td>
                            <td class="rr-col" style="color:<?= $rr>=100?'#2e7d32':($rr>=70?'#ed6c02':'#d32f2f') ?>"><?= $rr ?></td>
                            <?php for($d=1; $d<=$days_in_month; $d++):
                                $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                                $is_weekend = date('N', strtotime($date_str)) >= 6;
                                $absent = isset($absences[$t][$date_str]) ? true : false;
                                $cnt = isset($sales[$t][$date_str]) ? (int)$sales[$t][$date_str] : 0;
                                if ($absent) { $display = 'Н'; $style = 'background:#ffffff;color:#555;border:1px solid #ddd;'; }
                                else { $display = $cnt > 0 ? $cnt : ''; $c = getDayColor($cnt, $colors); $style = "background:{$c['bg']};color:{$c['txt']}"; }
                            ?>
                                <td class="cell-day <?= $is_weekend?'weekend':'' ?>" style="<?= $style ?>" data-date="<?= $date_str ?>" onclick="toggleAbsence('<?= htmlspecialchars((string)$t) ?>','<?= $date_str ?>')"><?= $display ?></td>
                            <?php endfor; ?>
                            <td style="font-weight:600;"><?= $plan ?></td>
                            <td style="font-weight:600;"><?= $fact ?></td>
                            <td style="font-weight:700;color:<?= $rr>=100?'#2e7d32':($rr>=70?'#ed6c02':'#d32f2f') ?>"><?= $rr ?>%</td>
                        </tr>
                    <?php $first = false; endforeach; endforeach; ?>
                    <tr class="totals-row">
                        <td colspan="3" style="text-align:left;font-weight:700;background:#fff8e1 !important;">🎯 ИТОГО по <?= htmlspecialchars((string)($terr['name'] ?? '')) ?></td>
                        <td style="background:#fff8e1 !important;"><?= (int)($terr['total_rr'] ?? 0) ?></td>
                        <?php for($d=1; $d<=$days_in_month; $d++):
                            $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                            $day_total = 0;
                            foreach ($terr['heads'] as $head_group) {
                                foreach ($head_group['managers'] as $m) {
                                    $day_total += isset($sales[$m['tabel_key']][$date_str]) ? (int)$sales[$m['tabel_key']][$date_str] : 0;
                                }
                            }
                        ?>
                            <td style="font-weight:700;background:#fff8e1 !important;"><?= $day_total > 0 ? $day_total : '' ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:700;background:#fff8e1 !important;"><?= (int)($terr['total_plan'] ?? 0) ?></td>
                        <td style="font-weight:700;background:#fff8e1 !important;"><?= (int)($terr['total_fact'] ?? 0) ?></td>
                        <td style="font-weight:700;background:#fff8e1 !important;"><?= (int)($terr['total_rr'] ?? 0) ?>%</td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="no-print" style="margin-top:14px;font-size:12px;color:#888;line-height:1.6;">
        💡 <b>Как пользоваться:</b> Клик на ячейку дня → отметить/снять отсутствие. Клик на ✏️ → дата ввода в должность. Настрой цвета → Сохранить. PDF → скачать отчёт.
    </div>
</div>

<!-- Модалки -->
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

<script>
let currentTabel = '', currentDate = '';

function toggleAbsence(tabel, date) {
    currentTabel = tabel; currentDate = date;
    const cell = document.querySelector(`td[data-date="${date}"][data-tabel="${tabel}"]`);
    const isAbsent = cell && cell.classList.contains('absence-mark');
    document.getElementById('absenceInfo').textContent = 'Сотрудник: ' + tabel + ' | ' + date;
    document.getElementById('absenceCheck').checked = isAbsent;
    document.getElementById('absenceModal').classList.add('active');
}
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
async function saveAbsence() {
    const checked = document.getElementById('absenceCheck').checked;
    const type = checked ? 'X' : '';
    const res = await fetch('api_terman.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=save_absence&tabel='+encodeURIComponent(currentTabel)
            +'&date='+encodeURIComponent(currentDate)
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
async function downloadPDF() {
    const tables = document.querySelectorAll('.table-wrap');
    if (!tables.length) { alert('Нет данных для PDF'); return; }
    const btn = document.querySelector('button[onclick="downloadPDF()"]');
    const oldText = btn.textContent;
    btn.textContent = '⏳ Генерация...';
    try {
        const wrapper = document.createElement('div');
        wrapper.style.padding = '10px';
        wrapper.style.background = '#fff';
        wrapper.style.width = '100%';
        wrapper.style.display = 'inline-block';
        tables.forEach(t => {
            const clone = t.cloneNode(true);
            clone.style.marginBottom = '20px';
            clone.style.width = '100%';
            clone.style.overflow = 'visible';
            wrapper.appendChild(clone);
        });
        document.body.appendChild(wrapper);
        await new Promise(resolve => setTimeout(resolve, 200));
        const canvas = await html2canvas(wrapper, {
            scale: 2,
            useCORS: true,
            backgroundColor: '#ffffff',
            logging: false,
            width: wrapper.scrollWidth,
            height: wrapper.scrollHeight,
            windowWidth: wrapper.scrollWidth,
            windowHeight: wrapper.scrollHeight
        });
        document.body.removeChild(wrapper);
        const imgData = canvas.toDataURL('image/png');
        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF('l', 'mm', 'a4');
        const pageW = pdf.internal.pageSize.getWidth();
        const pageH = pdf.internal.pageSize.getHeight();
        const imgW = canvas.width;
        const imgH = canvas.height;
        const ratio = Math.min((pageW - 20) / imgW, (pageH - 20) / imgH);
        const w = imgW * ratio;
        const h = imgH * ratio;
        const x = (pageW - w) / 2;
        const y = (pageH - h) / 2;
        pdf.addImage(imgData, 'PNG', x, y, w, h);
        pdf.save('terman_report_<?= $year ?>_<?= sprintf("%02d", $month) ?>.pdf');
    } catch(e) {
        alert('Ошибка генерации PDF: '+e.message);
        console.error(e);
    }
    btn.textContent = oldText;
}
document.addEventListener('keydown', e => { if(e.key==='Escape') document.querySelectorAll('.modal').forEach(m=>m.classList.remove('active')); });
document.querySelectorAll('.modal').forEach(m=>{ m.addEventListener('click', e=>{ if(e.target===m) m.classList.remove('active'); }); });
</script>
</body>
</html>