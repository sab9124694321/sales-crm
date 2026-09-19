<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$role = $_SESSION['role'] ?? '';
$user_name = $_SESSION['full_name'] ?? 'Пользователь';
$user_id = $_SESSION['user_id'];

$allowed = ['head', 'territory_head', 'admin', 'terman'];
if (!in_array($role, $allowed)) die('🚫 Доступ запрещён.');

$product_mode = $_GET['product_mode'] ?? 'product';
if (!in_array($product_mode, ['product', 'tips'])) $product_mode = 'product';
$unit = $_GET['unit'] ?? 'rub';
if (!in_array($unit, ['rub', 'ths', 'mln'])) $unit = 'rub';

function fmtMoney($v, $unit) {
    $v = (float)$v;
    if ($unit === 'ths') return number_format($v / 1000, 1, '.', ' ');
    if ($unit === 'mln') return number_format($v / 1000000, 2, '.', ' ');
    return number_format($v, 0, '.', ' ');
}
function unitLabel($unit) {
    if ($unit === 'ths') return 'тыс ₽';
    if ($unit === 'mln') return 'млн ₽';
    return '₽';
}
$unit_label = unitLabel($unit);

$check_date = null;
$stmt = $pdo->prepare("SELECT performed_at FROM check_history WHERE check_type = 'performance' ORDER BY performed_at DESC LIMIT 1");
$stmt->execute();
$row = $stmt->fetch();
if ($row) $check_date = substr($row['performed_at'], 0, 10);

function getProductCounts($pdo, $tabel, $date_from, $date_to, $only_checked = false, $productivity_only = false) {
    $cols = $pdo->query("PRAGMA table_info(inn_records)")->fetchAll(PDO::FETCH_COLUMN, 1);
    $hasClient = in_array('client_type', $cols);
    $hasStation = in_array('station_type', $cols);
    $hasTurnover = in_array('expected_turnover', $cols);
    $turnoverSql = $hasTurnover
        ? "SUM(CASE WHEN station_type IN ('pirate', 'target') THEN COALESCE(expected_turnover, 0) ELSE 0 END)"
        : "0";
    $sql = "
        SELECT
            SUM(CASE WHEN product IN ('ТЭ', 'Смарт', 'ПОС') THEN 1 ELSE 0 END) AS total,
            SUM(CASE WHEN is_key = 1 AND product IN ('ТЭ', 'Смарт', 'ПОС') THEN 1 ELSE 0 END) AS keyv,
            SUM(CASE WHEN product IN ('ПОС', 'Смарт') THEN 1 ELSE 0 END) AS kas,
            SUM(CASE WHEN station_type = 'target' THEN 1 ELSE 0 END) AS target,
            {$turnoverSql} AS pirate_turnover
        FROM inn_records
        WHERE employee_tabel = ? AND DATE(sale_date) BETWEEN ? AND ?";
    $params = [$tabel, $date_from, $date_to];
    if ($only_checked) $sql .= " AND checked_performance = 1";
    // Проверка NOT (client_type = 'expansion' AND station_type != 'pirate') УБРАНА
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'total' => (int)($row['total'] ?? 0),
        'keyv' => (int)($row['keyv'] ?? 0),
        'kas' => (int)($row['kas'] ?? 0),
        'target' => (int)($row['target'] ?? 0),
        'pirate_turnover' => (float)($row['pirate_turnover'] ?? 0),
    ];
}
function getTipsTurnover($pdo, $tabel, $date_from, $date_to) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(turnover), 0) FROM daily_reports WHERE tabel_number = ? AND report_date BETWEEN ? AND ?");
    $stmt->execute([$tabel, $date_from, $date_to]);
    return (float)$stmt->fetchColumn();
}
function calcDailyTarget($plan, $fact, $days_passed, $days_left, $total_work_days) {
    if ($plan <= 0) return 0;
    if ($fact >= $plan) return 0;
    if ($days_passed == 0) return (int)ceil($plan / max(1, $total_work_days));
    if ($days_left <= 0) return 0;
    $ideal = (int)ceil($plan / max(1, $total_work_days));
    $should_be = $ideal * $days_passed;
    $deviation = $should_be - $fact;
    return ($deviation > 0) ? (int)ceil(($plan - $fact) / $days_left) : $ideal;
}
function getAllComments($pdo, $user_tabel, $target_role) {
    $stmt = $pdo->prepare("SELECT comment, comment_date FROM head_comments WHERE head_tabel = ? AND target_role = ? ORDER BY comment_date DESC");
    $stmt->execute([$user_tabel, $target_role]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function countWorkingDays($start, $end) {
    $start = new DateTime($start); $end = new DateTime($end);
    $end->modify('+1 day');
    $days = 0;
    foreach (new DatePeriod($start, new DateInterval('P1D'), $end) as $dt) {
        if ((int)$dt->format('N') < 6) $days++;
    }
    return $days;
}

$selected_date = $_GET['date'] ?? date('Y-m-d', strtotime('-1 day'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) $selected_date = date('Y-m-d', strtotime('-1 day'));
if ($selected_date > date('Y-m-d')) $selected_date = date('Y-m-d');

$year = (int)date('Y', strtotime($selected_date));
$month = (int)date('m', strtotime($selected_date));
$max_day = (int)date('d', strtotime($selected_date));
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
if ($max_day > $days_in_month) $max_day = $days_in_month;
$today_day = (int)date('d'); $today_month = (int)date('m'); $today_year = (int)date('Y');
if ($year == $today_year && $month == $today_month && $max_day > $today_day) $max_day = $today_day;
if ($max_day < 1) $max_day = 1;

$display_days = range(1, $max_day);
$month_names = ['', 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
$month_name = $month_names[$month];
$period = sprintf('%04d-%02d', $year, $month);
$date_from = sprintf('%04d-%02d-01', $year, $month);
$date_to = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);

$last_working_date = null;
foreach (array_reverse($display_days) as $d) {
    $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
    if ((int)date('N', strtotime($date_str)) < 6) { $last_working_date = $date_str; break; }
}

$territory_filter = isset($_GET['territory']) ? (int)$_GET['territory'] : 0;
$territories = $pdo->query("SELECT id, name FROM territories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$sql = "
    SELECT u.tabel_number, u.full_name, u.territory_id, u.head_tabel,
           u.position_start_date, u.created_at,
           t.name AS territory_name, h.full_name AS head_name
    FROM users u
    LEFT JOIN territories t ON u.territory_id = t.id
    LEFT JOIN users h ON u.head_tabel = h.tabel_number
    WHERE u.role = 'manager' AND u.is_active = 1";
if ($territory_filter > 0) $sql .= " AND u.territory_id = " . (int)$territory_filter;
$sql .= " ORDER BY t.name, h.full_name, u.full_name";
$managers = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
if (empty($managers)) die('Нет активных менеджеров');

$plans = $plans_oborot = $plans_tips_oborot = [];
$stmt = $pdo->prepare("SELECT tabel_number, contracts_plan, COALESCE(expected_turnover_plan, 0) AS oborot_plan, COALESCE(turnover_plan, 0) AS tips_oborot_plan FROM plans WHERE period = ?");
$stmt->execute([$period]);
while ($r = $stmt->fetch()) {
    $t = (string)$r['tabel_number'];
    $plans[$t] = (int)$r['contracts_plan'];
    $plans_oborot[$t] = (float)$r['oborot_plan'];
    $plans_tips_oborot[$t] = (float)$r['tips_oborot_plan'];
}

$sales = $sales_checked = $sales_productivity = $sales_productivity_checked = [];
$oborot = $absences = [];
foreach ($managers as $m) {
    $t = trim((string)$m['tabel_number']);
    foreach ($display_days as $d) {
        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $sales[$t][$date_str] = getProductCounts($pdo, $t, $date_str, $date_str, false, false);
        $sales_checked[$t][$date_str] = getProductCounts($pdo, $t, $date_str, $date_str, true, false);
        $sales_productivity[$t][$date_str] = getProductCounts($pdo, $t, $date_str, $date_str, false, true);
        $sales_productivity_checked[$t][$date_str] = getProductCounts($pdo, $t, $date_str, $date_str, true, true);
        $oborot[$t][$date_str] = getTipsTurnover($pdo, $t, $date_str, $date_str);
    }
    $stmt = $pdo->prepare("SELECT absence_date FROM employee_absences WHERE TRIM(employee_tabel) = ? AND DATE(absence_date) BETWEEN ? AND ?");
    $stmt->execute([$t, $date_from, $date_to]);
    $absences[$t] = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
}

$fact_month = $fact_checked_month = $fact_adjusted_month = [];
$productivity_adjusted_month = $oborot_plan_month = $oborot_fact_month = [];
$tips_plan_month = $tips_fact_month = $tips_adjusted_month = [];
$manager_count_per_territory = [];

foreach ($managers as $m) {
    $t = trim((string)$m['tabel_number']);
    $terr_id = (int)($m['territory_id'] ?? 0);
    $manager_count_per_territory[$terr_id] = ($manager_count_per_territory[$terr_id] ?? 0) + 1;
    $tm = $tc = $ta = $pa = $oa = $tm_tips = $ta_tips = 0;
    foreach ($display_days as $d) {
        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $manual = $sales[$t][$date_str]['total'] ?? 0;
        $checked = $sales_checked[$t][$date_str]['total'] ?? 0;
        $prod_man = $sales_productivity[$t][$date_str]['total'] ?? 0;
        $prod_chk = $sales_productivity_checked[$t][$date_str]['total'] ?? 0;
        $pir_man = $sales[$t][$date_str]['pirate_turnover'] ?? 0;
        $pir_chk = $sales_checked[$t][$date_str]['pirate_turnover'] ?? 0;
        $tips_day = $oborot[$t][$date_str] ?? 0;
        $tm += $manual; $tc += $checked; $tm_tips += $tips_day;
        if ($check_date && $date_str <= $check_date) { $ta += $checked; $pa += $prod_chk; $oa += $pir_chk; }
        else { $ta += $manual; $pa += $prod_man; $oa += $pir_man; }
        $ta_tips += $tips_day;
    }
    $fact_month[$t] = $tm;
    $fact_checked_month[$t] = $tc;
    $fact_adjusted_month[$t] = $ta;
    $productivity_adjusted_month[$t] = $pa;
    $oborot_plan_month[$t] = $plans_oborot[$t] ?? 0;
    $oborot_fact_month[$t] = $oa;
    $tips_plan_month[$t] = $plans_tips_oborot[$t] ?? 0;
    $tips_fact_month[$t] = $tm_tips;
    $tips_adjusted_month[$t] = $ta_tips;
}

$colors = $pdo->query("SELECT red_max, yellow_max FROM terman_color_settings WHERE territory_id IS NULL ORDER BY id DESC LIMIT 1")->fetch();
if (!$colors) $colors = ['red_max' => 1, 'yellow_max' => 2];
function calcStaz($date) {
    if (empty($date)) return '000/00';
    try { $d1 = new DateTime($date); $d2 = new DateTime(); $diff = $d1->diff($d2); return sprintf('%03d/%02d', $diff->y, $diff->m); }
    catch (Exception $e) { return '000/00'; }
}
function getDayColor($cnt, $colors) {
    $red = (int)($colors['red_max'] ?? 1); $yellow = (int)($colors['yellow_max'] ?? 2);
    if ($cnt <= $red) return ['bg' => '#ff6b6b', 'txt' => '#4a0000'];
    if ($cnt <= $yellow) return ['bg' => '#ffd93d', 'txt' => '#5a3e00'];
    return ['bg' => '#51cf66', 'txt' => '#003d00'];
}

$structure = [];
foreach ($managers as $m) {
    $terr_id = (int)($m['territory_id'] ?? 0);
    $terr_name = $m['territory_name'] ?? 'Без территории';
    $head_name = $m['head_name'] ?? 'Без руководителя';
    $head_tabel = (string)($m['head_tabel'] ?? '');
    $tabel_key = trim((string)$m['tabel_number']);
    if (!isset($structure[$terr_id])) {
        $structure[$terr_id] = [
            'name' => $terr_name, 'heads' => [],
            'daily_totals' => array_fill_keys($display_days, ['total'=>0,'keyv'=>0,'kas'=>0,'target'=>0,'pirate_turnover'=>0]),
            'total_plan' => 0, 'total_fact' => 0, 'total_checked_fact' => 0, 'total_adjusted_fact' => 0,
            'total_target' => 0, 'total_cs' => 0,
            'total_oborot_plan' => 0, 'total_oborot_fact' => 0,
            'total_tips_plan' => 0, 'total_tips_fact' => 0,
            'productivity_adjusted' => 0, 'manager_count' => 0,
        ];
    }
    if (!isset($structure[$terr_id]['heads'][$head_name])) {
        $structure[$terr_id]['heads'][$head_name] = [
            'head_tabel' => $head_tabel, 'managers' => [],
            'total_plan' => 0, 'total_fact' => 0, 'total_checked_fact' => 0, 'total_adjusted_fact' => 0,
            'total_target' => 0, 'total_cs' => 0,
            'total_oborot_plan' => 0, 'total_oborot_fact' => 0,
            'total_tips_plan' => 0, 'total_tips_fact' => 0,
            'productivity_adjusted' => 0,
        ];
    }
    $structure[$terr_id]['heads'][$head_name]['managers'][] = array_merge($m, ['tabel_key' => $tabel_key]);
    $structure[$terr_id]['manager_count'] = $manager_count_per_territory[$terr_id] ?? 0;
}

$month_start = sprintf('%04d-%02d-01', $year, $month);
$total_working_days = countWorkingDays($month_start, date('Y-m-t', strtotime($month_start)));
$working_days_passed = countWorkingDays($month_start, $selected_date);
$remaining_working_days = max(0, $total_working_days - $working_days_passed);

$grand = [
    'plan' => 0, 'fact' => 0, 'checked_fact' => 0, 'adjusted_fact' => 0,
    'target' => 0, 'oborot_plan' => 0, 'oborot_fact' => 0,
    'tips_plan' => 0, 'tips_fact' => 0,
    'productivity_adjusted' => 0, 'tips_adjusted' => 0,
    'daily' => array_fill_keys($display_days, ['total'=>0,'keyv'=>0,'kas'=>0,'target'=>0,'pirate_turnover'=>0]),
];

foreach ($structure as $terr_id => &$terr) {
    $t_plan = $t_fact = $t_checked = $t_adjusted = $t_target = 0;
    $t_oborot_plan = $t_oborot_fact = $t_tips_plan = $t_tips_fact = 0;
    $t_prod_adjusted = $t_tips_adjusted = 0;
    $t_daily = array_fill_keys($display_days, ['total'=>0,'keyv'=>0,'kas'=>0,'target'=>0,'pirate_turnover'=>0]);

    foreach ($terr['heads'] as $head_name => &$hg) {
        $h_plan = $h_fact = $h_checked = $h_adjusted = $h_target = 0;
        $h_oborot_plan = $h_oborot_fact = $h_tips_plan = $h_tips_fact = 0;
        $h_prod_adjusted = $h_tips_adjusted = 0;

        foreach ($hg['managers'] as &$m) {
            $t = $m['tabel_key']; if (empty($t)) continue;
            $plan = $plans[$t] ?? 0;
            $fact = $fact_month[$t] ?? 0;
            $checked_fact = $fact_checked_month[$t] ?? 0;
            $adjusted_fact = $fact_adjusted_month[$t] ?? 0;
            $prod_adjusted = $productivity_adjusted_month[$t] ?? 0;
            $target = 0;
            foreach ($display_days as $d) {
                $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $target += $sales[$t][$date_str]['target'] ?? 0;
            }
            $ob_plan = $oborot_plan_month[$t] ?? 0;
            $ob_fact = $oborot_fact_month[$t] ?? 0;
            $ob_last = $last_working_date ? ($sales[$t][$last_working_date]['pirate_turnover'] ?? 0) : 0;
            $tips_plan = $tips_plan_month[$t] ?? 0;
            $tips_fact = $tips_fact_month[$t] ?? 0;
            $tips_adjusted = $tips_adjusted_month[$t] ?? 0;
            $tips_last = $last_working_date ? ($oborot[$t][$last_working_date] ?? 0) : 0;

            $m['plan'] = $plan; $m['fact'] = $fact; $m['checked_fact'] = $checked_fact;
            $m['adjusted_fact'] = $adjusted_fact; $m['target'] = $target;
            $m['vp'] = $plan > 0 ? round(($adjusted_fact / $plan) * 100) : 0;
            $m['rr'] = ($working_days_passed > 0) ? round(($adjusted_fact / $working_days_passed) * $total_working_days) : 0;
            $m['productivity_rr'] = ($working_days_passed > 0) ? round(($prod_adjusted / $working_days_passed) * $total_working_days) : 0;
            $m['plan_day_units'] = calcDailyTarget($plan, $adjusted_fact, $working_days_passed, $remaining_working_days, $total_working_days);
            $m['oborot_plan'] = $ob_plan; $m['oborot_fact'] = $ob_fact;
            $m['oborot_rr'] = ($working_days_passed > 0) ? round(($ob_fact / $working_days_passed) * $total_working_days) : 0;
            $m['oborot_plan_day'] = calcDailyTarget($ob_plan, $ob_fact, $working_days_passed, $remaining_working_days, $total_working_days);
            $m['oborot_last_day'] = $ob_last;
            $m['tips_plan'] = $tips_plan; $m['tips_fact'] = $tips_fact;
            $m['tips_rr'] = ($working_days_passed > 0) ? round(($tips_adjusted / $working_days_passed) * $total_working_days) : 0;
            $m['tips_vp'] = $tips_plan > 0 ? round(($tips_fact / $tips_plan) * 100) : 0;
            $m['tips_plan_day'] = calcDailyTarget($tips_plan, $tips_fact, $working_days_passed, $remaining_working_days, $total_working_days);
            $m['tips_last_day'] = $tips_last;
            $start_date = (!empty($m['position_start_date'])) ? $m['position_start_date'] : ($m['created_at'] ?? '');
            $m['staz'] = calcStaz($start_date);

            $h_plan += $plan; $h_fact += $fact; $h_checked += $checked_fact; $h_adjusted += $adjusted_fact;
            $h_target += $target; $h_prod_adjusted += $prod_adjusted;
            $h_oborot_plan += $ob_plan; $h_oborot_fact += $ob_fact;
            $h_tips_plan += $tips_plan; $h_tips_fact += $tips_fact; $h_tips_adjusted += $tips_adjusted;

            foreach ($display_days as $d) {
                $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $cnt = $sales[$t][$date_str] ?? ['total'=>0,'keyv'=>0,'kas'=>0,'target'=>0,'pirate_turnover'=>0];
                $t_daily[$d]['total'] += $cnt['total']; $t_daily[$d]['keyv'] += $cnt['keyv'];
                $t_daily[$d]['kas'] += $cnt['kas']; $t_daily[$d]['target'] += $cnt['target'];
                $t_daily[$d]['pirate_turnover'] += $cnt['pirate_turnover'];
                $grand['daily'][$d]['total'] += $cnt['total']; $grand['daily'][$d]['keyv'] += $cnt['keyv'];
                $grand['daily'][$d]['kas'] += $cnt['kas']; $grand['daily'][$d]['target'] += $cnt['target'];
                $grand['daily'][$d]['pirate_turnover'] += $cnt['pirate_turnover'];
            }
        }
        $hg['total_plan'] = $h_plan; $hg['total_fact'] = $h_fact;
        $hg['total_checked_fact'] = $h_checked; $hg['total_adjusted_fact'] = $h_adjusted;
        $hg['total_target'] = $h_target; $hg['total_cs'] = $h_target;
        $hg['total_rr'] = ($working_days_passed > 0 && $h_adjusted > 0) ? round(($h_adjusted / $working_days_passed) * $total_working_days) : 0;
        $hg['total_vp'] = $h_plan > 0 ? round(($h_adjusted / $h_plan) * 100) : 0;
        $hg['productivity_rr'] = ($working_days_passed > 0 && $h_prod_adjusted > 0) ? round(($h_prod_adjusted / $working_days_passed) * $total_working_days) : 0;
        $hg['productivity_per_manager'] = count($hg['managers']) > 0 ? round($hg['productivity_rr'] / count($hg['managers']), 1) : 0;
        $hg['plan_day_units'] = calcDailyTarget($h_plan, $h_adjusted, $working_days_passed, $remaining_working_days, $total_working_days);
        $hg['total_oborot_plan'] = $h_oborot_plan; $hg['total_oborot_fact'] = $h_oborot_fact;
        $hg['oborot_rr'] = ($working_days_passed > 0) ? round(($h_oborot_fact / $working_days_passed) * $total_working_days) : 0;
        $hg['oborot_plan_day'] = calcDailyTarget($h_oborot_plan, $h_oborot_fact, $working_days_passed, $remaining_working_days, $total_working_days);
        $hg['oborot_avg_day'] = ($working_days_passed > 0) ? round($h_oborot_fact / $working_days_passed) : 0;
        $hg['oborot_last_day'] = $last_working_date ? array_sum(array_map(function($mm) use ($last_working_date, $sales) {
            return $sales[$mm['tabel_key']][$last_working_date]['pirate_turnover'] ?? 0;
        }, $hg['managers'])) : 0;
        $hg['total_tips_plan'] = $h_tips_plan; $hg['total_tips_fact'] = $h_tips_fact;
        $hg['tips_rr'] = ($working_days_passed > 0 && $h_tips_adjusted > 0) ? round(($h_tips_adjusted / $working_days_passed) * $total_working_days) : 0;
        $hg['tips_per_manager'] = count($hg['managers']) > 0 ? round($hg['tips_rr'] / count($hg['managers']), 0) : 0;
        $hg['tips_plan_day'] = calcDailyTarget($h_tips_plan, $h_tips_fact, $working_days_passed, $remaining_working_days, $total_working_days);
        $hg['tips_last_day'] = $last_working_date ? array_sum(array_map(function($mm) use ($last_working_date, $oborot) {
            return $oborot[$mm['tabel_key']][$last_working_date] ?? 0;
        }, $hg['managers'])) : 0;

        $t_plan += $h_plan; $t_fact += $h_fact; $t_checked += $h_checked; $t_adjusted += $h_adjusted;
        $t_target += $h_target; $t_prod_adjusted += $h_prod_adjusted;
        $t_oborot_plan += $h_oborot_plan; $t_oborot_fact += $h_oborot_fact;
        $t_tips_plan += $h_tips_plan; $t_tips_fact += $h_tips_fact; $t_tips_adjusted += $h_tips_adjusted;
    }
    $terr['total_plan'] = $t_plan; $terr['total_fact'] = $t_fact;
    $terr['total_checked_fact'] = $t_checked; $terr['total_adjusted_fact'] = $t_adjusted;
    $terr['total_target'] = $t_target; $terr['total_cs'] = $t_target;
    $terr['total_rr'] = ($working_days_passed > 0 && $t_adjusted > 0) ? round(($t_adjusted / $working_days_passed) * $total_working_days) : 0;
    $terr['total_vp'] = $t_plan > 0 ? round(($t_adjusted / $t_plan) * 100) : 0;
    $terr['daily_totals'] = $t_daily;
    $terr['productivity_rr'] = ($working_days_passed > 0 && $t_prod_adjusted > 0) ? round(($t_prod_adjusted / $working_days_passed) * $total_working_days) : 0;
    $terr['productivity_per_manager'] = ($terr['manager_count'] ?? 1) > 0 ? round($terr['productivity_rr'] / $terr['manager_count'], 1) : 0;
    $terr['plan_day_units'] = calcDailyTarget($t_plan, $t_adjusted, $working_days_passed, $remaining_working_days, $total_working_days);
    $terr['total_oborot_plan'] = $t_oborot_plan; $terr['total_oborot_fact'] = $t_oborot_fact;
    $terr['oborot_rr'] = ($working_days_passed > 0) ? round(($t_oborot_fact / $working_days_passed) * $total_working_days) : 0;
    $terr['oborot_plan_day'] = calcDailyTarget($t_oborot_plan, $t_oborot_fact, $working_days_passed, $remaining_working_days, $total_working_days);
    $terr['oborot_avg_day'] = ($working_days_passed > 0) ? round($t_oborot_fact / $working_days_passed) : 0;
    $terr['oborot_last_day'] = $last_working_date ? array_sum(array_map(function($hgg) use ($last_working_date, $sales) {
        return array_sum(array_map(function($mm) use ($last_working_date, $sales) {
            return $sales[$mm['tabel_key']][$last_working_date]['pirate_turnover'] ?? 0;
        }, $hgg['managers']));
    }, $terr['heads'])) : 0;
    $terr['total_tips_plan'] = $t_tips_plan; $terr['total_tips_fact'] = $t_tips_fact;
    $terr['tips_rr'] = ($working_days_passed > 0 && $t_tips_adjusted > 0) ? round(($t_tips_adjusted / $working_days_passed) * $total_working_days) : 0;
    $terr['tips_per_manager'] = ($terr['manager_count'] ?? 1) > 0 ? round($terr['tips_rr'] / $terr['manager_count'], 0) : 0;
    $terr['tips_plan_day'] = calcDailyTarget($t_tips_plan, $t_tips_fact, $working_days_passed, $remaining_working_days, $total_working_days);
    $terr['tips_last_day'] = $last_working_date ? array_sum(array_map(function($hgg) use ($last_working_date, $oborot) {
        return array_sum(array_map(function($mm) use ($last_working_date, $oborot) {
            return $oborot[$mm['tabel_key']][$last_working_date] ?? 0;
        }, $hgg['managers']));
    }, $terr['heads'])) : 0;

    $grand['plan'] += $t_plan; $grand['fact'] += $t_fact; $grand['checked_fact'] += $t_checked; $grand['adjusted_fact'] += $t_adjusted;
    $grand['target'] += $t_target;
    $grand['oborot_plan'] += $t_oborot_plan; $grand['oborot_fact'] += $t_oborot_fact;
    $grand['tips_plan'] += $t_tips_plan; $grand['tips_fact'] += $t_tips_fact;
    $grand['productivity_adjusted'] += $t_prod_adjusted;
    $grand['tips_adjusted'] += $t_tips_adjusted;
}
unset($terr, $hg, $m);

$grand['rr'] = ($working_days_passed > 0 && $grand['adjusted_fact'] > 0) ? round(($grand['adjusted_fact'] / $working_days_passed) * $total_working_days) : 0;
$grand['vp'] = $grand['plan'] > 0 ? round(($grand['adjusted_fact'] / $grand['plan']) * 100) : 0;
$grand['cs'] = $grand['target'];
$grand['productivity_rr'] = ($working_days_passed > 0 && $grand['productivity_adjusted'] > 0) ? round(($grand['productivity_adjusted'] / $working_days_passed) * $total_working_days) : 0;
$grand['productivity_per_manager'] = count($managers) > 0 ? round($grand['productivity_rr'] / count($managers), 1) : 0;
$grand['plan_day_units'] = calcDailyTarget($grand['plan'], $grand['adjusted_fact'], $working_days_passed, $remaining_working_days, $total_working_days);
$grand['oborot_rr'] = ($working_days_passed > 0) ? round(($grand['oborot_fact'] / $working_days_passed) * $total_working_days) : 0;
$grand['oborot_plan_day'] = calcDailyTarget($grand['oborot_plan'], $grand['oborot_fact'], $working_days_passed, $remaining_working_days, $total_working_days);
$grand['oborot_avg_day'] = ($working_days_passed > 0) ? round($grand['oborot_fact'] / $working_days_passed) : 0;
$grand['oborot_last_day'] = $last_working_date ? array_sum(array_map(function($mm) use ($last_working_date, $sales) {
    return $sales[trim((string)$mm['tabel_number'])][$last_working_date]['pirate_turnover'] ?? 0;
}, $managers)) : 0;
$grand['tips_rr'] = ($working_days_passed > 0 && $grand['tips_adjusted'] > 0) ? round(($grand['tips_adjusted'] / $working_days_passed) * $total_working_days) : 0;
$grand['tips_per_manager'] = count($managers) > 0 ? round($grand['tips_rr'] / count($managers), 0) : 0;
$grand['tips_plan_day'] = calcDailyTarget($grand['tips_plan'], $grand['tips_fact'], $working_days_passed, $remaining_working_days, $total_working_days);
$grand['tips_last_day'] = $last_working_date ? array_sum(array_map(function($mm) use ($last_working_date, $oborot) {
    return $oborot[trim((string)$mm['tabel_number'])][$last_working_date] ?? 0;
}, $managers)) : 0;

$days_reverse = array_reverse($display_days);
$weekdays_ru = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
$manager_comments = [];
foreach ($managers as $m) {
    $t = trim((string)$m['tabel_number']);
    $manager_comments[$t] = getAllComments($pdo, $t, 'manager');
}
$is_tips = ($product_mode === 'tips');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>📋 Отчёт термена — <?= $selected_date ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        body{font-family:system-ui,sans-serif;background:#f5f5f5;padding:12px;margin:0;}
        .container{max-width:100%;margin:0 auto;background:#fff;padding:12px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1);}
        .nav{display:flex;align-items:center;padding:10px 16px;background:linear-gradient(135deg,#1a1a2e,#16213e);color:#fff;border-radius:12px;margin-bottom:14px;gap:10px;flex-wrap:wrap;}
        .nav a{color:#ccc;text-decoration:none;padding:6px 10px;border-radius:6px;font-size:12px;}
        .nav a:hover,.nav a.active{background:rgba(255,255,255,0.1);color:#fff;}
        .nav .logo{font-size:18px;font-weight:700;color:#fff;margin-right:auto;}
        .nav .user{margin-left:auto;color:#aaa;font-size:12px;}
        .nav a.logout{color:#e03131;}
        h1{margin:0 0 6px;font-size:18px;}
        .subtitle{color:#666;margin-bottom:14px;font-size:13px;}
        .top-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px;padding:10px;background:#fafafa;border-radius:6px;border:1px solid #e0e0e0;}
        .top-bar label{font-size:12px;color:#555;white-space:nowrap;}
        .top-bar input,.top-bar select{padding:4px 8px;border:1px solid #ccc;border-radius:4px;font-size:12px;}
        .top-bar button{padding:6px 12px;border:none;border-radius:4px;cursor:pointer;font-size:12px;}
        .btn-primary{background:#1976d2;color:#fff;} .btn-gray{background:#757575;color:#fff;}
        .btn-excel{background:#1e7e34;color:#fff;} .btn-pdf{background:#b22222;color:#fff;}
        .color-settings{display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
        .table-wrap{overflow-x:auto;margin-top:8px;border:1px solid #ddd;border-radius:6px;margin-bottom:20px;}
        table{border-collapse:collapse;width:100%;font-size:8px;}
        th,td{border:1px solid #ddd;padding:1px 2px;text-align:center;white-space:nowrap;}
        th{background:#f0f0f0;font-weight:600;color:#333;position:sticky;top:0;z-index:2;}
        th:first-child,td:first-child{position:sticky;left:0;background:#fff;z-index:3;}
        th:first-child{z-index:4;}
        .group-th{background:#e3f2fd !important;font-size:8px;}
        .group-th-ob{background:#fff3e0 !important;font-size:8px;}
        .cell-day{min-width:14px;height:16px;cursor:pointer;font-weight:600;font-size:8px;}
        .name-col{text-align:left;min-width:110px;padding-left:4px !important;}
        .staz-col{min-width:30px;}
        .rr-col{min-width:28px;font-weight:700;}
        .weekend{background:#f5f5f5 !important;color:#999 !important;}
        .totals-row td{font-weight:700;background:#fff8e1 !important;}
        .grand-totals td{font-weight:800;background:#ffecb3 !important;font-size:9px;}
        .edit-icon{cursor:pointer;color:#1976d2;font-size:10px;margin-left:2px;opacity:.7;}
        .comment-icon{cursor:pointer;color:#6c757d;font-size:10px;margin-right:3px;opacity:.7;}
        .comment-icon:hover{opacity:1;color:#0d6efd;}
        .absence-mark{background:#e0e0e0 !important;color:#555 !important;}
        .no-data{padding:40px;text-align:center;color:#888;font-size:16px;background:#fafafa;border-radius:6px;border:1px dashed #ccc;}
        .modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100;justify-content:center;align-items:center;}
        .modal.active{display:flex;}
        .modal-box{background:#fff;padding:22px;border-radius:8px;width:400px;max-width:90%;}
        .modal-box h3{margin:0 0 14px;font-size:15px;}
        .modal-box label{display:block;margin-bottom:6px;font-size:12px;color:#555;}
        .modal-box textarea{width:100%;padding:8px;margin-bottom:10px;border:1px solid #ccc;border-radius:4px;font-size:13px;}
        .modal-actions{display:flex;gap:8px;justify-content:flex-end;}
        .modal-actions button{padding:7px 14px;border:none;border-radius:4px;cursor:pointer;font-size:12px;}
        .btn-cancel{background:#e0e0e0;} .btn-save{background:#1976d2;color:#fff;}
        @media print{body{background:#fff;padding:0;}.container{box-shadow:none;border:none;}.no-print{display:none !important;}.table-wrap{overflow:visible;border:none;}th,td:first-child{position:static !important;}}
        .sub-col{min-width:13px;font-size:7px;}
        .head-row td{background:#f3e5f5;font-weight:600;text-align:left;padding-left:6px;}
        .comment-cell{background:#f3e5f5;text-align:left;padding-left:4px;font-size:8px;max-width:200px;word-wrap:break-word;white-space:normal;}
        .manager-comment-cell{text-align:left;padding-left:4px;font-size:8px;max-width:200px;word-wrap:break-word;white-space:normal;}
        .check-date-info{background:#e3f2fd;padding:6px 12px;border-radius:4px;margin-bottom:10px;font-size:12px;color:#0d47a1;border-left:4px solid #1976d2;}
        .comment-preview{display:flex;align-items:center;gap:4px;flex-wrap:wrap;}
        .comment-text{word-break:break-word;}
        .toggle-comments{cursor:pointer;color:#1976d2;font-size:9px;white-space:nowrap;}
        .comment-full{display:none;background:#f9f9f9;padding:4px;border-radius:4px;margin-top:4px;font-size:8px;max-height:100px;overflow-y:auto;width:100%;}
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

    <?php if ($check_date): ?>
        <div class="check-date-info">
            ✅ Дата последней массовой проверки: <strong><?= date('d.m.Y', strtotime($check_date)) ?></strong>
            (данные до этой даты – по проверенным ИНН, после – по ручному вводу)
        </div>
    <?php else: ?>
        <div class="check-date-info" style="background:#fff3cd;border-left-color:#ffc107;color:#856404;">
            ⚠️ Массовая проверка ещё не проводилась. RR считается по ручным данным.
        </div>
    <?php endif; ?>

    <div class="top-bar no-print">
        <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <label>Дата: <input type="date" name="date" value="<?= $selected_date ?>" style="width:130px;"></label>
            <label>Территория:
                <select name="territory">
                    <option value="0">Все</option>
                    <?php foreach ($territories as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $territory_filter == $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Показатель:
                <select name="product_mode">
                    <option value="product" <?= $product_mode=='product'?'selected':'' ?>>ТЭ / Смарт / ПОС</option>
                    <option value="tips" <?= $product_mode=='tips'?'selected':'' ?>>Чаевые</option>
                </select>
            </label>
            <label>Единицы:
                <select name="unit">
                    <option value="rub" <?= $unit=='rub'?'selected':'' ?>>₽</option>
                    <option value="ths" <?= $unit=='ths'?'selected':'' ?>>тыс ₽</option>
                    <option value="mln" <?= $unit=='mln'?'selected':'' ?>>млн ₽</option>
                </select>
            </label>
            <button type="submit" class="btn-primary">🔄 Обновить</button>
        </form>
        <div style="flex:1"></div>
        <div class="color-settings">
            <span style="font-size:12px;font-weight:600;">🎨 Границы:</span>
            <label>🔴 до <input type="number" id="red_max" value="<?= (int)($colors['red_max'] ?? 1) ?>" min="0" max="99" style="width:38px;"></label>
            <label>🟡 до <input type="number" id="yellow_max" value="<?= (int)($colors['yellow_max'] ?? 2) ?>" min="0" max="99" style="width:38px;"></label>
            <button class="btn-gray" onclick="saveColors()">💾</button>
        </div>
        <button class="btn-excel" onclick="exportExcel()">📊 Excel</button>
        <button class="btn-pdf" onclick="exportPDF()">📄 PDF</button>
    </div>

    <?php if (empty($structure)): ?>
        <div class="no-data">😕 Нет данных</div>
    <?php else: ?>

    <?php if (!$is_tips): ?>
    <!-- ═══ РЕЖИМ ТЭ/СМАРТ/ПОС ═══ -->
    <h2 style="margin:14px 0 8px;font-size:16px;">📊 Сводка по ГОСБ</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th rowspan="2">ГОСБ</th>
                    <th rowspan="2">Начальник отдела</th>
                    <th colspan="2" class="group-th">План, шт</th>
                    <th colspan="4" class="group-th">ФАКТ, шт</th>
                    <th colspan="2" class="group-th">Прогноз, шт</th>
                    <th colspan="4" class="group-th-ob">Оборот месяц, <?= $unit_label ?></th>
                    <th colspan="2" class="group-th-ob">Оборот день, <?= $unit_label ?></th>
                    <?php foreach ($days_reverse as $d):
                        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                        $weekday_num = date('N', strtotime($date_str)) - 1;
                    ?>
                        <th colspan="5" class="<?= date('N', strtotime($date_str)) >= 6 ? 'weekend' : '' ?>"><?= $d ?><br><small><?= $weekdays_ru[$weekday_num] ?></small></th>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <th class="sub-col">мес</th><th class="sub-col">пр/день</th>
                    <th class="sub-col">ГОСБ</th><th class="sub-col">ЦА</th><th class="sub-col">ЦС</th><th class="sub-col">пр/МПП</th>
                    <th class="sub-col">RR, шт</th><th class="sub-col">ВП, %</th>
                    <th class="sub-col">план</th><th class="sub-col">факт</th><th class="sub-col">RR, об</th><th class="sub-col">ср/день</th>
                    <th class="sub-col">план</th><th class="sub-col">факт</th>
                    <?php foreach ($days_reverse as $d): ?>
                        <th class="sub-col">ИНН</th><th class="sub-col">Кл</th><th class="sub-col">Кс</th><th class="sub-col">ЦС</th><th class="sub-col">Об</th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($structure as $terr_id => $terr):
                $daily = $terr['daily_totals'];
                foreach ($terr['heads'] as $head_name => $hg):
                    $hd = array_fill_keys($display_days, ['total'=>0,'keyv'=>0,'kas'=>0,'target'=>0,'pirate_turnover'=>0]);
                    foreach ($hg['managers'] as $m) {
                        $t = $m['tabel_key']; if (empty($t)) continue;
                        foreach ($display_days as $d) {
                            $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                            $cnt = $sales[$t][$date_str] ?? ['total'=>0,'keyv'=>0,'kas'=>0,'target'=>0,'pirate_turnover'=>0];
                            $hd[$d]['total'] += $cnt['total']; $hd[$d]['keyv'] += $cnt['keyv'];
                            $hd[$d]['kas'] += $cnt['kas']; $hd[$d]['target'] += $cnt['target'];
                            $hd[$d]['pirate_turnover'] += $cnt['pirate_turnover'];
                        }
                    }
            ?>
                <tr>
                    <td style="text-align:left;padding-left:4px;font-weight:600;"><?= htmlspecialchars($terr['name']) ?></td>
                    <td style="text-align:left;padding-left:4px;"><?= htmlspecialchars($head_name) ?></td>
                    <td><?= (int)$hg['total_plan'] ?></td>
                    <td><?= (int)$hg['plan_day_units'] ?></td>
                    <td><?= (int)$hg['total_fact'] ?></td>
                    <td><?= (int)$hg['total_checked_fact'] ?></td>
                    <td><?= (int)$hg['total_cs'] ?></td>
                    <td><?= number_format($hg['productivity_per_manager'] ?? 0, 1) ?></td>
                    <td style="font-weight:700;"><?= (int)$hg['total_rr'] ?></td>
                    <td><?= (int)$hg['total_vp'] ?>%</td>
                    <td><?= fmtMoney($hg['total_oborot_plan'], $unit) ?></td>
                    <td><?= fmtMoney($hg['total_oborot_fact'], $unit) ?></td>
                    <td><?= fmtMoney($hg['oborot_rr'], $unit) ?></td>
                    <td><?= fmtMoney($hg['oborot_avg_day'], $unit) ?></td>
                    <td><?= fmtMoney($hg['oborot_plan_day'], $unit) ?></td>
                    <td><?= fmtMoney($hg['oborot_last_day'], $unit) ?></td>
                    <?php foreach ($days_reverse as $d): ?>
                        <td><?= $hd[$d]['total'] ?: '' ?></td>
                        <td><?= $hd[$d]['keyv'] ?: '' ?></td>
                        <td><?= $hd[$d]['kas'] ?: '' ?></td>
                        <td><?= $hd[$d]['target'] ?: '' ?></td>
                        <td><?= $hd[$d]['pirate_turnover'] > 0 ? fmtMoney($hd[$d]['pirate_turnover'], $unit) : '' ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
                <tr class="totals-row">
                    <td style="text-align:left;padding-left:4px;font-weight:700;">🎯 ИТОГО по <?= htmlspecialchars($terr['name']) ?></td>
                    <td></td>
                    <td><?= (int)$terr['total_plan'] ?></td>
                    <td><?= (int)$terr['plan_day_units'] ?></td>
                    <td><?= (int)$terr['total_fact'] ?></td>
                    <td><?= (int)$terr['total_checked_fact'] ?></td>
                    <td><?= (int)$terr['total_cs'] ?></td>
                    <td><?= number_format($terr['productivity_per_manager'] ?? 0, 1) ?></td>
                    <td><?= (int)$terr['total_rr'] ?></td>
                    <td><?= (int)$terr['total_vp'] ?>%</td>
                    <td><?= fmtMoney($terr['total_oborot_plan'], $unit) ?></td>
                    <td><?= fmtMoney($terr['total_oborot_fact'], $unit) ?></td>
                    <td><?= fmtMoney($terr['oborot_rr'], $unit) ?></td>
                    <td><?= fmtMoney($terr['oborot_avg_day'], $unit) ?></td>
                    <td><?= fmtMoney($terr['oborot_plan_day'], $unit) ?></td>
                    <td><?= fmtMoney($terr['oborot_last_day'], $unit) ?></td>
                    <?php foreach ($days_reverse as $d): ?>
                        <td><?= $daily[$d]['total'] ?: '' ?></td>
                        <td><?= $daily[$d]['keyv'] ?: '' ?></td>
                        <td><?= $daily[$d]['kas'] ?: '' ?></td>
                        <td><?= $daily[$d]['target'] ?: '' ?></td>
                        <td><?= $daily[$d]['pirate_turnover'] > 0 ? fmtMoney($daily[$d]['pirate_turnover'], $unit) : '' ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
                <tr class="grand-totals">
                    <td style="text-align:left;padding-left:4px;">🎯 ВСЕГО</td>
                    <td></td>
                    <td><?= $grand['plan'] ?></td>
                    <td><?= $grand['plan_day_units'] ?></td>
                    <td><?= $grand['fact'] ?></td>
                    <td><?= $grand['checked_fact'] ?></td>
                    <td><?= $grand['cs'] ?></td>
                    <td><?= number_format($grand['productivity_per_manager'], 1) ?></td>
                    <td><?= $grand['rr'] ?></td>
                    <td><?= $grand['vp'] ?>%</td>
                    <td><?= fmtMoney($grand['oborot_plan'], $unit) ?></td>
                    <td><?= fmtMoney($grand['oborot_fact'], $unit) ?></td>
                    <td><?= fmtMoney($grand['oborot_rr'], $unit) ?></td>
                    <td><?= fmtMoney($grand['oborot_avg_day'], $unit) ?></td>
                    <td><?= fmtMoney($grand['oborot_plan_day'], $unit) ?></td>
                    <td><?= fmtMoney($grand['oborot_last_day'], $unit) ?></td>
                    <?php foreach ($days_reverse as $d): ?>
                        <td><?= $grand['daily'][$d]['total'] ?: '' ?></td>
                        <td><?= $grand['daily'][$d]['keyv'] ?: '' ?></td>
                        <td><?= $grand['daily'][$d]['kas'] ?: '' ?></td>
                        <td><?= $grand['daily'][$d]['target'] ?: '' ?></td>
                        <td><?= $grand['daily'][$d]['pirate_turnover'] > 0 ? fmtMoney($grand['daily'][$d]['pirate_turnover'], $unit) : '' ?></td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Детальные таблицы -->
    <?php foreach ($structure as $terr_id => $terr): ?>
        <h3 style="margin:20px 0 6px;font-size:14px;background:#e3f2fd;padding:4px 10px;border-radius:4px;">
            🏢 <?= htmlspecialchars($terr['name']) ?>
        </h3>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th rowspan="2">ФИО Рук.</th>
                        <th rowspan="2">Минипр.</th>
                        <th rowspan="2">ФИО менеджера</th>
                        <th rowspan="2">Стаж</th>
                        <th colspan="2" class="group-th">План, шт</th>
                        <th colspan="4" class="group-th">ФАКТ, шт</th>
                        <th colspan="2" class="group-th">Прогноз, шт</th>
                        <th colspan="3" class="group-th-ob">Оборот месяц, <?= $unit_label ?></th>
                        <th colspan="2" class="group-th-ob">Оборот день, <?= $unit_label ?></th>
                        <?php foreach ($days_reverse as $d):
                            $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                            $weekday_num = date('N', strtotime($date_str)) - 1;
                        ?>
                            <th colspan="5" class="<?= date('N', strtotime($date_str)) >= 6 ? 'weekend' : '' ?>"><?= $d ?><br><small><?= $weekdays_ru[$weekday_num] ?></small></th>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th class="sub-col">мес</th><th class="sub-col">пр/день</th>
                        <th class="sub-col">ГОСБ</th><th class="sub-col">ЦА</th><th class="sub-col">ЦС</th><th class="sub-col">пр/МПП</th>
                        <th class="sub-col">RR, шт</th><th class="sub-col">ВП, %</th>
                        <th class="sub-col">план</th><th class="sub-col">факт</th><th class="sub-col">RR, об</th>
                        <th class="sub-col">план</th><th class="sub-col">факт</th>
                        <?php foreach ($days_reverse as $d): ?>
                            <th class="sub-col">ИНН</th><th class="sub-col">Кл</th><th class="sub-col">Кс</th><th class="sub-col">ЦС</th><th class="sub-col">Об</th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php
                $total_cols = 17 + 5 * count($display_days);
                foreach ($terr['heads'] as $head_name => $hg):
                    $managers_list = $hg['managers'] ?? [];
                    $head_tabel = $hg['head_tabel'] ?? '';
                    $head_comments = getAllComments($pdo, $head_tabel, 'head');
                ?>
                    <tr class="head-row">
                        <td style="background:#f3e5f5;font-weight:600;text-align:left;padding-left:4px;">
                            <span class="comment-icon no-print" onclick="openCommentModal('<?= htmlspecialchars($head_tabel) ?>','<?= htmlspecialchars($head_name) ?>','head','<?= $selected_date ?>')">💬</span>
                            <?= htmlspecialchars($head_name) ?>
                        </td>
                        <td class="comment-cell" style="background:#f3e5f5;">
                            <?php if(empty($head_comments)): ?>—<?php else:
                                $last = $head_comments[0];
                                $display = mb_strlen($last['comment']) > 50 ? mb_substr($last['comment'],0,50).'...' : $last['comment'];
                                $tid = 'head-'.$head_tabel;
                            ?>
                                <div class="comment-preview" data-target="<?= $tid ?>">
                                    <span class="comment-text"><?= date('d.m.Y', strtotime($last['comment_date'])) ?>: <?= htmlspecialchars($display) ?></span>
                                    <?php if(count($head_comments)>1): ?><span class="toggle-comments"> [ещё <?= count($head_comments)-1 ?>]</span><?php endif; ?>
                                </div>
                                <div class="comment-full" id="<?= $tid ?>">
                                    <?php foreach ($head_comments as $c): ?><div><?= date('d.m.Y', strtotime($c['comment_date'])) ?>: <?= htmlspecialchars($c['comment']) ?></div><?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td colspan="<?= $total_cols - 2 ?>" style="background:#f3e5f5;"></td>
                    </tr>
                    <tr style="font-weight:bold;background:#fff8e1;">
                        <td colspan="2" style="text-align:left;padding-left:4px;">ИТОГО по <?= htmlspecialchars($head_name) ?></td>
                        <td></td><td></td>
                        <td><?= $hg['total_plan'] ?></td>
                        <td><?= $hg['plan_day_units'] ?></td>
                        <td><?= $hg['total_fact'] ?></td>
                        <td><?= $hg['total_checked_fact'] ?></td>
                        <td><?= $hg['total_cs'] ?></td>
                        <td><?= number_format($hg['productivity_per_manager'], 1) ?></td>
                        <td><?= $hg['total_rr'] ?></td>
                        <td><?= $hg['total_vp'] ?>%</td>
                        <td><?= fmtMoney($hg['total_oborot_plan'], $unit) ?></td>
                        <td><?= fmtMoney($hg['total_oborot_fact'], $unit) ?></td>
                        <td><?= fmtMoney($hg['oborot_rr'], $unit) ?></td>
                        <td><?= fmtMoney($hg['oborot_plan_day'], $unit) ?></td>
                        <td><?= fmtMoney($hg['oborot_last_day'], $unit) ?></td>
                        <?php
                        $hd = array_fill_keys($display_days, ['total'=>0,'keyv'=>0,'kas'=>0,'target'=>0,'pirate_turnover'=>0]);
                        foreach ($managers_list as $m) {
                            $t = $m['tabel_key']; if (empty($t)) continue;
                            foreach ($display_days as $d) {
                                $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                                $cnt = $sales[$t][$date_str] ?? ['total'=>0,'keyv'=>0,'kas'=>0,'target'=>0,'pirate_turnover'=>0];
                                $hd[$d]['total'] += $cnt['total']; $hd[$d]['keyv'] += $cnt['keyv'];
                                $hd[$d]['kas'] += $cnt['kas']; $hd[$d]['target'] += $cnt['target'];
                                $hd[$d]['pirate_turnover'] += $cnt['pirate_turnover'];
                            }
                        }
                        foreach ($days_reverse as $d): ?>
                            <td><?= $hd[$d]['total'] ?></td>
                            <td><?= $hd[$d]['keyv'] ?></td>
                            <td><?= $hd[$d]['kas'] ?></td>
                            <td><?= $hd[$d]['target'] ?></td>
                            <td><?= $hd[$d]['pirate_turnover'] > 0 ? fmtMoney($hd[$d]['pirate_turnover'], $unit) : '' ?></td>
                        <?php endforeach; ?>
                    </tr>

                    <?php foreach ($managers_list as $m):
                        $t = $m['tabel_key']; if (empty($t)) continue;
                        $manager_comments_list = $manager_comments[$t] ?? [];
                    ?>
                        <tr data-tabel="<?= htmlspecialchars((string)$t) ?>">
                            <td></td>
                            <td class="manager-comment-cell">
                                <?php if(empty($manager_comments_list)): ?>—<?php else:
                                    $last = $manager_comments_list[0];
                                    $display = mb_strlen($last['comment']) > 50 ? mb_substr($last['comment'],0,50).'...' : $last['comment'];
                                    $tid = 'mgr-'.$t;
                                ?>
                                    <div class="comment-preview" data-target="<?= $tid ?>">
                                        <span class="comment-text"><?= date('d.m.Y', strtotime($last['comment_date'])) ?>: <?= htmlspecialchars($display) ?></span>
                                        <?php if(count($manager_comments_list)>1): ?><span class="toggle-comments"> [ещё <?= count($manager_comments_list)-1 ?>]</span><?php endif; ?>
                                    </div>
                                    <div class="comment-full" id="<?= $tid ?>">
                                        <?php foreach ($manager_comments_list as $c): ?><div><?= date('d.m.Y', strtotime($c['comment_date'])) ?>: <?= htmlspecialchars($c['comment']) ?></div><?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="name-col">
                                <span class="comment-icon no-print" onclick="openCommentModal('<?= htmlspecialchars((string)$t) ?>','<?= htmlspecialchars((string)($m['full_name'] ?? '')) ?>','manager','<?= $selected_date ?>')">💬</span>
                                <?= htmlspecialchars((string)($m['full_name'] ?? '')) ?>
                                <span class="edit-icon no-print" onclick="openPositionModal('<?= htmlspecialchars((string)$t) ?>','<?= htmlspecialchars((string)($m['full_name'] ?? '')) ?>','<?= htmlspecialchars((string)((!empty($m['position_start_date'])) ? $m['position_start_date'] : ($m['created_at'] ?? ''))) ?>')">✏️</span>
                            </td>
                            <td class="staz-col"><?= $m['staz'] ?? '000/00' ?></td>
                            <td><?= (int)$m['plan'] ?></td>
                            <td><?= (int)$m['plan_day_units'] ?></td>
                            <td><?= (int)$m['fact'] ?></td>
                            <td><?= (int)$m['checked_fact'] ?></td>
                            <td><?= (int)$m['target'] ?></td>
                            <td><?= number_format($m['productivity_rr'] ?? 0, 1) ?></td>
                            <td class="rr-col"><?= (int)$m['rr'] ?></td>
                            <td><?= (int)$m['vp'] ?>%</td>
                            <td><?= fmtMoney($m['oborot_plan'], $unit) ?></td>
                            <td><?= fmtMoney($m['oborot_fact'], $unit) ?></td>
                            <td><?= fmtMoney($m['oborot_rr'], $unit) ?></td>
                            <td><?= fmtMoney($m['oborot_plan_day'], $unit) ?></td>
                            <td><?= fmtMoney($m['oborot_last_day'], $unit) ?></td>
                            <?php foreach ($days_reverse as $d):
                                $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                                $is_weekend = date('N', strtotime($date_str)) >= 6;
                                $absent = isset($absences[$t][$date_str]);
                                $cnt = $sales[$t][$date_str] ?? ['total'=>0,'keyv'=>0,'kas'=>0,'target'=>0,'pirate_turnover'=>0];
                                $cell_class = 'cell-day';
                                if ($is_weekend) $cell_class .= ' weekend';
                                if ($absent) {
                                    $cell_class .= ' absence-mark';
                                    $d_total = $d_keyv = $d_kas = $d_target = 'Н'; $d_ob = 'Н'; $style = '';
                                } else {
                                    $d_total = $cnt['total']; $d_keyv = $cnt['keyv']; $d_kas = $cnt['kas']; $d_target = $cnt['target'];
                                    $d_ob = $cnt['pirate_turnover'] > 0 ? fmtMoney($cnt['pirate_turnover'], $unit) : '';
                                    $c = getDayColor($cnt['total'], $colors);
                                    $style = "background:{$c['bg']};color:{$c['txt']}";
                                }
                            ?>
                                <td class="<?= $cell_class ?>" style="<?= $style ?>" data-date="<?= $date_str ?>" data-tabel="<?= htmlspecialchars((string)$t) ?>" onclick="toggleAbsence('<?= htmlspecialchars((string)$t) ?>','<?= $date_str ?>')"><?= $d_total ?></td>
                                <td class="<?= $cell_class ?>" style="<?= $style ?>" data-date="<?= $date_str ?>" data-tabel="<?= htmlspecialchars((string)$t) ?>"><?= $d_keyv ?></td>
                                <td class="<?= $cell_class ?>" style="<?= $style ?>" data-date="<?= $date_str ?>" data-tabel="<?= htmlspecialchars((string)$t) ?>"><?= $d_kas ?></td>
                                <td class="<?= $cell_class ?>" style="<?= $style ?>" data-date="<?= $date_str ?>" data-tabel="<?= htmlspecialchars((string)$t) ?>"><?= $d_target ?></td>
                                <td class="<?= $cell_class ?>" style="<?= $style ?>" data-date="<?= $date_str ?>" data-tabel="<?= htmlspecialchars((string)$t) ?>"><?= $d_ob ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>

    <?php else: ?>
    <!-- ═══ РЕЖИМ ЧАЕВЫЕ ═══ -->
    <h2 style="margin:14px 0 8px;font-size:16px;">📊 Сводка по ГОСБ — Оборот чаевых (<?= $unit_label ?>)</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th rowspan="2">ГОСБ</th>
                    <th rowspan="2">Начальник отдела</th>
                    <th colspan="2" class="group-th-ob">План, <?= $unit_label ?></th>
                    <th colspan="2" class="group-th-ob">Факт, <?= $unit_label ?></th>
                    <th colspan="2" class="group-th-ob">Прогноз</th>
                    <th rowspan="2">Ср/день</th>
                    <?php foreach ($days_reverse as $d):
                        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                        $weekday_num = date('N', strtotime($date_str)) - 1;
                    ?>
                        <th class="<?= date('N', strtotime($date_str)) >= 6 ? 'weekend' : '' ?>"><?= $d ?><br><small><?= $weekdays_ru[$weekday_num] ?></small></th>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <th class="sub-col">мес</th><th class="sub-col">пр/день</th>
                    <th class="sub-col">мес</th><th class="sub-col">день</th>
                    <th class="sub-col">RR, об</th><th class="sub-col">ВП, %</th>
                    <?php foreach ($days_reverse as $d): ?>
                        <th class="sub-col"></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($structure as $terr_id => $terr):
                foreach ($terr['heads'] as $head_name => $hg):
            ?>
                <tr>
                    <td style="text-align:left;padding-left:4px;font-weight:600;"><?= htmlspecialchars($terr['name']) ?></td>
                    <td style="text-align:left;padding-left:4px;"><?= htmlspecialchars($head_name) ?></td>
                    <td><?= fmtMoney($hg['total_tips_plan'], $unit) ?></td>
                    <td><?= fmtMoney($hg['tips_plan_day'], $unit) ?></td>
                    <td><?= fmtMoney($hg['total_tips_fact'], $unit) ?></td>
                    <td><?= fmtMoney($hg['tips_last_day'], $unit) ?></td>
                    <td style="font-weight:700;"><?= fmtMoney($hg['tips_rr'], $unit) ?></td>
                    <td><?= $hg['total_tips_plan'] > 0 ? round(($hg['total_tips_fact'] / $hg['total_tips_plan']) * 100) : 0 ?>%</td>
                    <td><?= $working_days_passed > 0 ? fmtMoney($hg['total_tips_fact'] / $working_days_passed, $unit) : 0 ?></td>
                    <?php
                    $hd_tips = array_fill_keys($display_days, 0);
                    foreach ($hg['managers'] as $m) {
                        $t = $m['tabel_key']; if (empty($t)) continue;
                        foreach ($display_days as $d) {
                            $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                            $hd_tips[$d] += $oborot[$t][$date_str] ?? 0;
                        }
                    }
                    foreach ($days_reverse as $d): ?>
                        <td><?= $hd_tips[$d] > 0 ? fmtMoney($hd_tips[$d], $unit) : '' ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
                <tr class="totals-row">
                    <td style="text-align:left;padding-left:4px;font-weight:700;">🎯 ИТОГО по <?= htmlspecialchars($terr['name']) ?></td>
                    <td></td>
                    <td><?= fmtMoney($terr['total_tips_plan'], $unit) ?></td>
                    <td><?= fmtMoney($terr['tips_plan_day'], $unit) ?></td>
                    <td><?= fmtMoney($terr['total_tips_fact'], $unit) ?></td>
                    <td><?= fmtMoney($terr['tips_last_day'], $unit) ?></td>
                    <td><?= fmtMoney($terr['tips_rr'], $unit) ?></td>
                    <td><?= $terr['total_tips_plan'] > 0 ? round(($terr['total_tips_fact'] / $terr['total_tips_plan']) * 100) : 0 ?>%</td>
                    <td><?= $working_days_passed > 0 ? fmtMoney($terr['total_tips_fact'] / $working_days_passed, $unit) : 0 ?></td>
                    <?php foreach ($days_reverse as $d):
                        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                        $dt = 0;
                        foreach ($terr['heads'] as $hgg) {
                            foreach ($hgg['managers'] as $m) {
                                $t = $m['tabel_key'];
                                $dt += $oborot[$t][$date_str] ?? 0;
                            }
                        }
                    ?>
                        <td><?= $dt > 0 ? fmtMoney($dt, $unit) : '' ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
                <tr class="grand-totals">
                    <td style="text-align:left;padding-left:4px;">🎯 ВСЕГО</td>
                    <td></td>
                    <td><?= fmtMoney($grand['tips_plan'], $unit) ?></td>
                    <td><?= fmtMoney($grand['tips_plan_day'], $unit) ?></td>
                    <td><?= fmtMoney($grand['tips_fact'], $unit) ?></td>
                    <td><?= fmtMoney($grand['tips_last_day'], $unit) ?></td>
                    <td><?= fmtMoney($grand['tips_rr'], $unit) ?></td>
                    <td><?= $grand['tips_plan'] > 0 ? round(($grand['tips_fact'] / $grand['tips_plan']) * 100) : 0 ?>%</td>
                    <td><?= $working_days_passed > 0 ? fmtMoney($grand['tips_fact'] / $working_days_passed, $unit) : 0 ?></td>
                    <?php foreach ($days_reverse as $d):
                        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                        $dt = 0;
                        foreach ($managers as $m) {
                            $t = trim((string)$m['tabel_number']);
                            $dt += $oborot[$t][$date_str] ?? 0;
                        }
                    ?>
                        <td><?= $dt > 0 ? fmtMoney($dt, $unit) : '' ?></td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Детальные таблицы чаевых -->
    <?php foreach ($structure as $terr_id => $terr): ?>
        <h3 style="margin:20px 0 6px;font-size:14px;background:#e3f2fd;padding:4px 10px;border-radius:4px;">
            🏢 <?= htmlspecialchars($terr['name']) ?> — Оборот чаевых
        </h3>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th rowspan="2">ФИО Рук.</th>
                        <th rowspan="2">Минипр.</th>
                        <th rowspan="2">ФИО менеджера</th>
                        <th rowspan="2">Стаж</th>
                        <th colspan="2" class="group-th-ob">План, <?= $unit_label ?></th>
                        <th colspan="2" class="group-th-ob">Факт, <?= $unit_label ?></th>
                        <th colspan="2" class="group-th-ob">Прогноз</th>
                        <?php foreach ($days_reverse as $d):
                            $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                            $weekday_num = date('N', strtotime($date_str)) - 1;
                        ?>
                            <th class="<?= date('N', strtotime($date_str)) >= 6 ? 'weekend' : '' ?>"><?= $d ?><br><small><?= $weekdays_ru[$weekday_num] ?></small></th>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th class="sub-col">мес</th><th class="sub-col">пр/день</th>
                        <th class="sub-col">мес</th><th class="sub-col">день</th>
                        <th class="sub-col">RR, об</th><th class="sub-col">ВП, %</th>
                        <?php foreach ($days_reverse as $d): ?>
                            <th class="sub-col"></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php
                $total_cols_tips = 10 + count($display_days);
                foreach ($terr['heads'] as $head_name => $hg):
                    $managers_list = $hg['managers'] ?? [];
                    $head_tabel = $hg['head_tabel'] ?? '';
                ?>
                    <tr class="head-row">
                        <td style="background:#f3e5f5;font-weight:600;text-align:left;padding-left:4px;">
                            <span class="comment-icon no-print" onclick="openCommentModal('<?= htmlspecialchars($head_tabel) ?>','<?= htmlspecialchars($head_name) ?>','head','<?= $selected_date ?>')">💬</span>
                            <?= htmlspecialchars($head_name) ?>
                        </td>
                        <td class="comment-cell" style="background:#f3e5f5;">—</td>
                        <td colspan="<?= $total_cols_tips - 2 ?>" style="background:#f3e5f5;"></td>
                    </tr>
                    <tr style="font-weight:bold;background:#fff8e1;">
                        <td colspan="2" style="text-align:left;padding-left:4px;">ИТОГО по <?= htmlspecialchars($head_name) ?></td>
                        <td></td><td></td>
                        <td><?= fmtMoney($hg['total_tips_plan'], $unit) ?></td>
                        <td><?= fmtMoney($hg['tips_plan_day'], $unit) ?></td>
                        <td><?= fmtMoney($hg['total_tips_fact'], $unit) ?></td>
                        <td><?= fmtMoney($hg['tips_last_day'], $unit) ?></td>
                        <td><?= fmtMoney($hg['tips_rr'], $unit) ?></td>
                        <td><?= $hg['total_tips_plan'] > 0 ? round(($hg['total_tips_fact'] / $hg['total_tips_plan']) * 100) : 0 ?>%</td>
                        <?php
                        $hdt = array_fill_keys($display_days, 0);
                        foreach ($managers_list as $m) {
                            $t = $m['tabel_key']; if (empty($t)) continue;
                            foreach ($display_days as $d) {
                                $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                                $hdt[$d] += $oborot[$t][$date_str] ?? 0;
                            }
                        }
                        foreach ($days_reverse as $d): ?>
                            <td><?= $hdt[$d] > 0 ? fmtMoney($hdt[$d], $unit) : '' ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php foreach ($managers_list as $m):
                        $t = $m['tabel_key']; if (empty($t)) continue;
                    ?>
                        <tr data-tabel="<?= htmlspecialchars((string)$t) ?>">
                            <td></td>
                            <td class="manager-comment-cell">—</td>
                            <td class="name-col"><?= htmlspecialchars((string)($m['full_name'] ?? '')) ?></td>
                            <td class="staz-col"><?= $m['staz'] ?? '000/00' ?></td>
                            <td><?= fmtMoney($m['tips_plan'], $unit) ?></td>
                            <td><?= fmtMoney($m['tips_plan_day'], $unit) ?></td>
                            <td><?= fmtMoney($m['tips_fact'], $unit) ?></td>
                            <td><?= fmtMoney($m['tips_last_day'], $unit) ?></td>
                            <td class="rr-col"><?= fmtMoney($m['tips_rr'], $unit) ?></td>
                            <td><?= $m['tips_vp'] ?>%</td>
                            <?php foreach ($days_reverse as $d):
                                $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                                $is_weekend = date('N', strtotime($date_str)) >= 6;
                                $absent = isset($absences[$t][$date_str]);
                                $val = $oborot[$t][$date_str] ?? 0;
                                $cell_class = 'cell-day';
                                if ($is_weekend) $cell_class .= ' weekend';
                                if ($absent) { $cell_class .= ' absence-mark'; $d_ob = 'Н'; $style=''; }
                                else {
                                    $d_ob = $val > 0 ? fmtMoney($val, $unit) : '';
                                    $c = getDayColor($val / 1000, $colors);
                                    $style = "background:{$c['bg']};color:{$c['txt']}";
                                }
                            ?>
                                <td class="<?= $cell_class ?>" style="<?= $style ?>" data-date="<?= $date_str ?>" data-tabel="<?= htmlspecialchars((string)$t) ?>" onclick="toggleAbsence('<?= htmlspecialchars((string)$t) ?>','<?= $date_str ?>')"><?= $d_ob ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php endif; ?>

    <div class="no-print" style="margin-top:10px;font-size:11px;color:#888;line-height:1.5;">
        💡 Клик на ячейку дня → отсутствие. ✏️ → дата ввода. 💬 → комментарий. «Показатель» — ТЭ/Смарт/ПОС или Чаевые. «Единицы» — формат денег.
    </div>
</div>

<div id="commentModal" class="modal">
    <div class="modal-box">
        <h3 id="commentModalTitle">💬 Комментарий</h3>
        <div id="commentModalInfo" style="font-size:13px;color:#666;margin-bottom:8px;"></div>
        <label>Дата: <input type="date" id="commentDate" style="width:100%;"></label>
        <label>Текст:</label>
        <textarea id="commentText" rows="4" <?= ($role === 'terman') ? '' : 'disabled' ?>></textarea>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeModal('commentModal')">Отмена</button>
            <?php if ($role === 'terman'): ?><button class="btn-save" onclick="saveComment()">💾 Сохранить</button><?php endif; ?>
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
let currentUserTabel = '', currentRole = '';
let currentTabel = '', currentDateAbsence = '';

function openCommentModal(userTabel, userName, role, defaultDate) {
    currentUserTabel = userTabel; currentRole = role;
    document.getElementById('commentModalTitle').textContent = (role === 'head') ? '💬 Минипротокол (руководитель)' : '💬 Личный комментарий менеджера';
    document.getElementById('commentModalInfo').textContent = (role === 'head' ? 'Руководитель: ' : 'Менеджер: ') + userName;
    document.getElementById('commentDate').value = defaultDate;
    fetch('api_terman.php?action=get_comment&user_tabel='+encodeURIComponent(userTabel)+'&date='+encodeURIComponent(defaultDate)+'&role='+encodeURIComponent(role))
        .then(res => res.json()).then(data => { document.getElementById('commentText').value = data.comment || ''; })
        .catch(() => { document.getElementById('commentText').value = ''; });
    document.getElementById('commentModal').classList.add('active');
}
async function saveComment() {
    const date = document.getElementById('commentDate').value;
    const comment = document.getElementById('commentText').value;
    if (!currentUserTabel || !date) { alert('Не хватает данных'); return; }
    if (!comment.trim()) { alert('⚠️ Комментарий пустой'); return; }
    const res = await fetch('api_terman.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=save_comment&user_tabel='+encodeURIComponent(currentUserTabel)+'&date='+encodeURIComponent(date)+'&comment='+encodeURIComponent(comment)+'&role='+encodeURIComponent(currentRole)});
    const data = await res.json();
    if(data.success) { alert('✅ Сохранено!'); location.reload(); } else alert('Ошибка: '+(data.error||'неизвестная'));
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
    const res = await fetch('api_terman.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=save_absence&tabel='+encodeURIComponent(currentTabel)+'&date='+encodeURIComponent(currentDateAbsence)+'&type='+encodeURIComponent(type)});
    const data = await res.json();
    if(data.success) location.reload(); else alert('Ошибка: '+(data.error||'неизвестная'));
}
function openPositionModal(tabel, name, date) {
    currentTabel = tabel;
    document.getElementById('positionInfo').textContent = name;
    document.getElementById('positionDate').value = date || '';
    document.getElementById('positionModal').classList.add('active');
}
async function savePositionDate() {
    const date = document.getElementById('positionDate').value;
    const res = await fetch('api_terman.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=save_position_date&tabel='+encodeURIComponent(currentTabel)+'&date='+encodeURIComponent(date)});
    const data = await res.json();
    if(data.success) location.reload(); else alert('Ошибка: '+(data.error||'неизвестная'));
}
async function saveColors() {
    const red = parseInt(document.getElementById('red_max').value);
    const yellow = parseInt(document.getElementById('yellow_max').value);
    if(red >= yellow) { alert('🔴 Красный порог должен быть меньше жёлтого!'); return; }
    const res = await fetch('api_terman.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=save_colors&red_max='+encodeURIComponent(red)+'&yellow_max='+encodeURIComponent(yellow)});
    const data = await res.json();
    if(data.success) { alert('✅ Сохранено!'); location.reload(); } else alert('Ошибка: '+(data.error||'неизвестная'));
}
function exportExcel() {
    const u = new URLSearchParams({year:<?= $year ?>, month:<?= $month ?>, territory:<?= $territory_filter ?>, date:'<?= $selected_date ?>', product_mode:'<?= $product_mode ?>', unit:'<?= $unit ?>'});
    window.open('export_terman_excel.php?' + u, '_blank');
}
function exportPDF() {
    const u = new URLSearchParams({year:<?= $year ?>, month:<?= $month ?>, territory:<?= $territory_filter ?>, date:'<?= $selected_date ?>', product_mode:'<?= $product_mode ?>', unit:'<?= $unit ?>'});
    window.open('export_terman_pdf.php?' + u, '_blank');
}
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
document.addEventListener('keydown', e => { if(e.key==='Escape') document.querySelectorAll('.modal').forEach(m=>m.classList.remove('active')); });
document.querySelectorAll('.modal').forEach(m=>{ m.addEventListener('click', e=>{ if(e.target===m) m.classList.remove('active'); }); });
document.addEventListener('click', function(e) {
    const toggle = e.target.closest('.toggle-comments');
    if (toggle) {
        e.preventDefault();
        const parent = toggle.closest('.comment-preview');
        if (parent) {
            const fullBlock = document.getElementById(parent.dataset.target);
            if (fullBlock) {
                if (fullBlock.style.display === 'none' || fullBlock.style.display === '') {
                    fullBlock.style.display = 'block'; toggle.textContent = ' [скрыть]';
                } else {
                    fullBlock.style.display = 'none';
                    toggle.textContent = ' [ещё ' + fullBlock.querySelectorAll('div').length + ']';
                }
            }
        }
    }
});
</script>
</body>
</html>