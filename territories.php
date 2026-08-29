<?php
error_reporting(E_ALL & ~E_WARNING);
session_start();

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once 'db.php';

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$user_name = $_SESSION['name'];
$user_tabel = $_SESSION['tabel'];

// --- Фильтры (аналогично dashboard.php и team.php) ---
$filter_key = $_GET['filter_key'] ?? 'all';          // all, key, nonkey
$filter_station = $_GET['filter_station'] ?? 'all';  // all, pirate, target, newreg
$filter_client = $_GET['filter_client'] ?? 'all';    // all, new, expansion

// --- Функция подсчёта рабочих дней ---
function getWorkingDaysCount($start_date, $end_date) {
    $start = new DateTime($start_date);
    $end   = new DateTime($end_date);
    $end->modify('+1 day');
    $interval = DateInterval::createFromDateString('1 day');
    $period = new DatePeriod($start, $interval, $end);
    $workingDays = 0;
    foreach ($period as $dt) {
        $dayOfWeek = $dt->format('N');
        if ($dayOfWeek < 6) $workingDays++;
    }
    return $workingDays;
}

// --- Период (по умолчанию с начала месяца по сегодня) ---
$date_to   = $_GET['date_to']   ?? $_GET['date'] ?? date('Y-m-d');
$date_from = $_GET['date_from'] ?? date('Y-m-01', strtotime($date_to));
$tomorrow  = date('Y-m-d', strtotime('+1 day'));
$selected_month = date('Y-m', strtotime($date_from));

$month_start = date('Y-m-01', strtotime($date_to));
$days_passed = getWorkingDaysCount($month_start, $date_to);
$work_days_total = getWorkingDaysCount($month_start, date('Y-m-t', strtotime($date_to)));
$days_left = max(0, $work_days_total - $days_passed);

function calcTeamMetrics($plan, $fact, $days_passed, $days_left, $work_days_total) {
    if ($plan <= 0) return ['forecast'=>0,'daily_target'=>0,'status'=>'none','percent'=>0];
    $percent = ($plan > 0) ? round(($fact / $plan) * 100) : 0;
    if ($fact >= $plan) {
        return ['forecast'=>$fact,'daily_target'=>0,'status'=>'success','percent'=>min(100,$percent)];
    }
    $ideal = ceil($plan / $work_days_total);
    $should_be = $ideal * $days_passed;
    $deviation = $should_be - $fact;
    $daily_target = ($deviation > 0) ? ceil(($plan - $fact) / $days_left) : $ideal;
    $avg_per_day = $fact / $days_passed;
    $forecast = round($avg_per_day * $work_days_total);
    $status = ($forecast >= $plan) ? 'warning' : 'danger';
    return ['forecast'=>$forecast,'daily_target'=>$daily_target,'status'=>$status,'percent'=>$percent];
}

// ------------------------------------------------------------------
// Функция получения продуктов из inn_records (включая expected_turnover)
// ------------------------------------------------------------------
function getProductSumsFromInn($pdo, $user_tabel, $date_from, $date_to, $filter_key, $filter_station, $filter_client) {
    $cols = $pdo->query("PRAGMA table_info(inn_records)")->fetchAll(PDO::FETCH_COLUMN, 1);
    $hasKey = in_array('is_key', $cols);
    $hasStation = in_array('station_type', $cols);
    $hasClient = in_array('client_type', $cols);
    $hasTurnover = in_array('expected_turnover', $cols);

    $sql = "SELECT 
                SUM(CASE WHEN product = 'ТЭ' THEN 1 ELSE 0 END) as registrations,
                SUM(CASE WHEN product = 'Смарт' THEN 1 ELSE 0 END) as smart_cash,
                SUM(CASE WHEN product = 'ПОС' THEN 1 ELSE 0 END) as pos_systems,
                SUM(CASE WHEN product = 'Чаевые' THEN 1 ELSE 0 END) as inn_leads";
    if ($hasTurnover) {
        $sql .= ", SUM(expected_turnover) as expected_turnover_sum";
    } else {
        $sql .= ", 0 as expected_turnover_sum";
    }
    $sql .= " FROM inn_records
            WHERE DATE(sale_date) BETWEEN :date_from AND :date_to
            AND employee_tabel = :user_tabel";
    $params = [
        ':date_from' => $date_from,
        ':date_to' => $date_to,
        ':user_tabel' => $user_tabel
    ];

    if ($hasKey) {
        if ($filter_key == 'key') $sql .= " AND is_key = 1";
        elseif ($filter_key == 'nonkey') $sql .= " AND is_key = 0";
    }
    if ($hasStation) {
        if ($filter_station == 'pirate') $sql .= " AND station_type = 'pirate'";
        elseif ($filter_station == 'target') $sql .= " AND station_type = 'target'";
        elseif ($filter_station == 'newreg') $sql .= " AND station_type = 'newreg'";
    }
    if ($hasClient && $filter_client !== 'all') {
        $sql .= " AND client_type = '" . addslashes($filter_client) . "'";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) $row = ['registrations'=>0,'smart_cash'=>0,'pos_systems'=>0,'inn_leads'=>0,'expected_turnover_sum'=>0];
    return $row;
}

// ------------------------------------------------------------------
// Функция агрегации метрик для набора пользователей (использует inn_records)
// ------------------------------------------------------------------
function getAggregatedMetrics($user_ids, $pdo, $selected_month, $date_from, $date_to, $days_passed, $days_left, $work_days_total, $filter_key, $filter_station, $filter_client) {
    $total_plans = [
        'calls_plan'=>0, 'calls_answered_plan'=>0, 'meetings_plan'=>0, 'contracts_plan'=>0,
        'registrations_plan'=>0, 'smart_cash_plan'=>0, 'pos_systems_plan'=>0,
        'inn_leads_plan'=>0, 'teams_plan'=>0, 'turnover_plan'=>0, 'rko_plan'=>0,
        'expected_turnover_plan'=>0
    ];
    $total_fact_period = [
        'calls'=>0, 'calls_answered'=>0, 'meetings'=>0, 'contracts'=>0,
        'registrations'=>0, 'smart_cash'=>0, 'pos_systems'=>0,
        'inn_leads'=>0, 'teams'=>0, 'turnover'=>0, 'rko'=>0, 'ai_calls'=>0,
        'expected_turnover'=>0
    ];
    $total_fact_today = [
        'calls'=>0, 'calls_answered'=>0, 'meetings'=>0, 'contracts'=>0,
        'registrations'=>0, 'smart_cash'=>0, 'pos_systems'=>0,
        'inn_leads'=>0, 'teams'=>0, 'turnover'=>0, 'rko'=>0,
        'expected_turnover'=>0
    ];
    foreach ($user_ids as $uid) {
        $stmt = $pdo->prepare("SELECT tabel_number FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        $tabel = $stmt->fetchColumn();
        if (!$tabel) continue;

        // Планы
        $stmt = $pdo->prepare("SELECT * FROM plans WHERE tabel_number = ? AND period = ?");
        $stmt->execute([$tabel, $selected_month]);
        $plans = $stmt->fetch();
        if ($plans) {
            foreach (['calls','calls_answered','meetings','contracts','registrations','smart_cash','pos_systems','inn_leads','teams','turnover','rko','expected_turnover'] as $f) {
                $total_plans[$f.'_plan'] += $plans[$f.'_plan'] ?? 0;
            }
        } else {
            $total_plans['expected_turnover_plan'] += 16000000;
        }

        // Факт за период (из daily_reports)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(calls),0) as calls,
                COALESCE(SUM(calls_answered),0) as calls_answered,
                COALESCE(SUM(meetings),0) as meetings,
                COALESCE(SUM(contracts),0) as contracts,
                COALESCE(SUM(teams),0) as teams,
                COALESCE(SUM(turnover),0) as turnover,
                COALESCE(SUM(rko),0) as rko,
                COALESCE(SUM(ai_calls),0) as ai_calls
            FROM daily_reports
            WHERE tabel_number = ? AND report_date BETWEEN ? AND ?
        ");
        $stmt->execute([$tabel, $date_from, $date_to]);
        $factP = $stmt->fetch();
        if ($factP) {
            foreach (['calls','calls_answered','meetings','contracts','teams','turnover','rko','ai_calls'] as $f) {
                $total_fact_period[$f] += $factP[$f];
            }
        }

        // Продукты из inn_records (за период)
        $prod = getProductSumsFromInn($pdo, $tabel, $date_from, $date_to, $filter_key, $filter_station, $filter_client);
        foreach (['registrations','smart_cash','pos_systems','inn_leads'] as $f) {
            $total_fact_period[$f] += $prod[$f];
        }
        $total_fact_period['expected_turnover'] += $prod['expected_turnover_sum'];

        // Факт за сегодня (текущий день)
        $stmt = $pdo->prepare("SELECT calls, calls_answered, meetings, contracts, teams, turnover, rko FROM daily_reports WHERE tabel_number = ? AND report_date = ?");
        $stmt->execute([$tabel, $date_to]);
        $factD = $stmt->fetch();
        if ($factD) {
            foreach (['calls','calls_answered','meetings','contracts','teams','turnover','rko'] as $f) {
                $total_fact_today[$f] += $factD[$f];
            }
        }
        // Продукты за сегодня (из inn_records)
        $prodToday = getProductSumsFromInn($pdo, $tabel, $date_to, $date_to, $filter_key, $filter_station, $filter_client);
        foreach (['registrations','smart_cash','pos_systems','inn_leads'] as $f) {
            $total_fact_today[$f] += $prodToday[$f];
        }
        $total_fact_today['expected_turnover'] += $prodToday['expected_turnover_sum'];
    }

    $metrics = [
        'calls'          => ['plan'=>$total_plans['calls_plan'], 'fact'=>$total_fact_period['calls'], 'today_fact'=>$total_fact_today['calls'], 'label'=>'Звонки', 'icon'=>'📞', 'unit'=>''],
        'calls_answered' => ['plan'=>$total_plans['calls_answered_plan'], 'fact'=>$total_fact_period['calls_answered'], 'today_fact'=>$total_fact_today['calls_answered'], 'label'=>'Дозвоны', 'icon'=>'✅', 'unit'=>'', 'ai_calls'=>$total_fact_period['ai_calls']],
        'meetings'       => ['plan'=>$total_plans['meetings_plan'], 'fact'=>$total_fact_period['meetings'], 'today_fact'=>$total_fact_today['meetings'], 'label'=>'Встречи', 'icon'=>'🤝', 'unit'=>''],
        'contracts'      => ['plan'=>$total_plans['contracts_plan'], 'fact'=>$total_fact_period['contracts'], 'today_fact'=>$total_fact_today['contracts'], 'label'=>'Договоры', 'icon'=>'📄', 'unit'=>''],
        'turnover'       => ['plan'=>$total_plans['turnover_plan'], 'fact'=>$total_fact_period['turnover'], 'today_fact'=>$total_fact_today['turnover'], 'label'=>'Оборот чаевых', 'icon'=>'💰', 'unit'=>'₽'],
        'registrations'  => ['plan'=>$total_plans['registrations_plan'], 'fact'=>$total_fact_period['registrations'], 'today_fact'=>$total_fact_today['registrations'], 'label'=>'ТЭ', 'icon'=>'📝', 'unit'=>''],
        'pos_systems'    => ['plan'=>$total_plans['pos_systems_plan'], 'fact'=>$total_fact_period['pos_systems'], 'today_fact'=>$total_fact_today['pos_systems'], 'label'=>'ПОС', 'icon'=>'🖥️', 'unit'=>''],
        'smart_cash'     => ['plan'=>$total_plans['smart_cash_plan'], 'fact'=>$total_fact_period['smart_cash'], 'today_fact'=>$total_fact_today['smart_cash'], 'label'=>'Смарт', 'icon'=>'💳', 'unit'=>''],
        'inn_leads'      => ['plan'=>$total_plans['inn_leads_plan'], 'fact'=>$total_fact_period['inn_leads'], 'today_fact'=>$total_fact_today['inn_leads'], 'label'=>'ИНН чаевые', 'icon'=>'🍵', 'unit'=>''],
        'teams'          => ['plan'=>$total_plans['teams_plan'], 'fact'=>$total_fact_period['teams'], 'today_fact'=>$total_fact_today['teams'], 'label'=>'Команды', 'icon'=>'👥', 'unit'=>''],
        'rko'            => ['plan'=>$total_plans['rko_plan'], 'fact'=>$total_fact_period['rko'], 'today_fact'=>$total_fact_today['rko'], 'label'=>'РКО', 'icon'=>'🏦', 'unit'=>'₽'],
        'expected_turnover' => ['plan'=>$total_plans['expected_turnover_plan'], 'fact'=>$total_fact_period['expected_turnover'], 'today_fact'=>$total_fact_today['expected_turnover'], 'label'=>'Оборот по терминалам', 'icon'=>'💳', 'unit'=>'₽']
    ];

    $result = [];
    foreach ($metrics as $key => $m) {
        $calc = calcTeamMetrics($m['plan'], $m['fact'], $days_passed, $days_left, $work_days_total);
        $result[$key] = [
            'today_fact' => $m['today_fact'],
            'fact' => $m['fact'],
            'plan' => $m['plan'],
            'percent' => $calc['percent'],
            'forecast' => $calc['forecast'],
            'daily_target' => $calc['daily_target'],
            'status' => $calc['status'],
            'icon' => $m['icon'],
            'label' => $m['label'],
            'unit' => $m['unit'],
            'ai_calls' => $m['ai_calls'] ?? 0
        ];
    }
    return $result;
}

// ------------------------------------------------------------------
// Определение уровня навигации
// ------------------------------------------------------------------
$level = $_GET['level'] ?? 'root';
$selected_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$show_territories = false;
$show_managers = false;
$show_employees = false;
$current_metrics = [];
$team_members = [];
$territories = [];
$managers = [];
$back_territory_id = null;

// Получаем список доступных территорий
if ($user_role == 'admin') {
    $territories = $pdo->query("SELECT id, name, code FROM territories ORDER BY name")->fetchAll();
} elseif ($user_role == 'terman') {
    $stmt = $pdo->prepare("SELECT t.id, t.name, t.code FROM territories t JOIN terman_territories tm ON t.id = tm.territory_id WHERE tm.terman_tabel = ?");
    $stmt->execute([$user_tabel]);
    $territories = $stmt->fetchAll();
} elseif (in_array($user_role, ['head', 'territory_head'])) {
    $stmt = $pdo->prepare("SELECT territory_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $terr_id = $stmt->fetchColumn();
    if ($terr_id) {
        $stmt2 = $pdo->prepare("SELECT id, name, code FROM territories WHERE id = ?");
        $stmt2->execute([$terr_id]);
        $territories = $stmt2->fetchAll();
    }
} else {
    header('Location: dashboard.php');
    exit;
}

// --- Определение списка сотрудников для дашборда ---
if ($level == 'root') {
    $show_territories = true;
    $team_members = $pdo->query("
        SELECT id, full_name, tabel_number FROM users 
        WHERE (role = 'manager' OR role = 'mmb_manager' OR role = 'ubr_middle') 
        AND is_active = 1 ORDER BY full_name
    ")->fetchAll();
    $all_ids = $pdo->query("SELECT id FROM users WHERE role != 'admin'")->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($all_ids)) {
        $company_metrics = getAggregatedMetrics($all_ids, $pdo, $selected_month, $date_from, $date_to, $days_passed, $days_left, $work_days_total, $filter_key, $filter_station, $filter_client);
    }
} elseif ($level == 'territory' && $selected_id > 0) {
    $stmt = $pdo->prepare("SELECT id, tabel_number, full_name, role FROM users WHERE role IN ('head','territory_head') AND territory_id = ? AND is_active = 1 ORDER BY full_name");
    $stmt->execute([$selected_id]);
    $managers = $stmt->fetchAll();
    $show_managers = true;
    $all_ids = [];
    foreach ($managers as $m) {
        $all_ids[] = $m['id'];
        $stmt2 = $pdo->prepare("SELECT id FROM users WHERE manager_id = ? AND role IN ('manager','employee','ubr_middle') AND is_active = 1");
        $stmt2->execute([$m['id']]);
        $subs = $stmt2->fetchAll(PDO::FETCH_COLUMN);
        $all_ids = array_merge($all_ids, $subs);
    }
    if (!empty($all_ids)) {
        $current_metrics = getAggregatedMetrics($all_ids, $pdo, $selected_month, $date_from, $date_to, $days_passed, $days_left, $work_days_total, $filter_key, $filter_station, $filter_client);
        $placeholders = implode(',', array_fill(0, count($all_ids), '?'));
        $stmt = $pdo->prepare("SELECT id, full_name, tabel_number FROM users WHERE id IN ($placeholders) AND role IN ('manager','mmb_manager','ubr_middle') ORDER BY full_name");
        $stmt->execute($all_ids);
        $team_members = $stmt->fetchAll();
    }
} elseif ($level == 'manager' && $selected_id > 0) {
    $stmt_head = $pdo->prepare("SELECT territory_id FROM users WHERE id = ?");
    $stmt_head->execute([$selected_id]);
    $back_territory_id = $stmt_head->fetchColumn();
    $stmt = $pdo->prepare("SELECT id, tabel_number, full_name, role FROM users WHERE manager_id = ? AND role IN ('manager','mmb_manager','ubr_middle') AND is_active = 1 ORDER BY full_name");
    $stmt->execute([$selected_id]);
    $employees = $stmt->fetchAll();
    $show_employees = true;
    $team_ids = [$selected_id];
    $stmt2 = $pdo->prepare("SELECT id FROM users WHERE manager_id = ? AND role IN ('manager','mmb_manager','ubr_middle') AND is_active = 1");
    $stmt2->execute([$selected_id]);
    $subs = $stmt2->fetchAll(PDO::FETCH_COLUMN);
    $team_ids = array_merge($team_ids, $subs);
    if (!empty($team_ids)) {
        $current_metrics = getAggregatedMetrics($team_ids, $pdo, $selected_month, $date_from, $date_to, $days_passed, $days_left, $work_days_total, $filter_key, $filter_station, $filter_client);
        $stmt3 = $pdo->prepare("SELECT id, full_name, tabel_number FROM users WHERE manager_id = ? AND role IN ('manager','mmb_manager','ubr_middle') AND is_active = 1 ORDER BY full_name");
        $stmt3->execute([$selected_id]);
        $team_members = $stmt3->fetchAll();
    }
}

// ------------------------------------------------------------------
// Функция рендера метрик (сетка)
// ------------------------------------------------------------------
function renderMetricsGrid($metrics, $title = '', $days_passed = 0) {
    if ($title) echo "<h3 style='margin: 20px 0 10px 0;'>$title</h3>";
    if (empty($metrics)) { echo "<p>Нет данных для отображения</p>"; return; }
    echo '<div class="metrics-grid">';
    foreach ($metrics as $m) {
        $today_fact = number_format((float)$m['today_fact'], 0, '.', ' ');
        $fact = number_format((float)$m['fact'], 0, '.', ' ');
        $plan = number_format((float)$m['plan'], 0, '.', ' ');
        $forecast = number_format((float)$m['forecast'], 0, '.', ' ');
        $daily_target = number_format((float)$m['daily_target'], 0, '.', ' ');
        $percent = (int)$m['percent'];
        $status = $m['status'];
        $icon = $m['icon'];
        $label = $m['label'];
        $unit = $m['unit'];
        $avg_per_day = $days_passed > 0 ? round($m['fact'] / $days_passed, 1) : 0;
        if ($unit == '₽') $avg_per_day = number_format($avg_per_day, 2, '.', ' ');
        else $avg_per_day = number_format($avg_per_day, 1, '.', ' ');
        echo "<div class='metric-card'>";
        echo "<div class='metric-header'><span class='metric-title'>$icon $label</span><span>{$percent}%</span></div>";
        echo "<div class='metric-value-row'><span class='metric-value $status'>$today_fact</span><span class='metric-daily-target'>$daily_target</span></div>";
        echo "<div class='progress-bar'><div class='progress-fill $status' style='width:{$percent}%'></div></div>";
        echo "<div class='metric-plan-fact'><span>📊 План: $plan $unit</span><span>✅ Факт: $fact $unit</span></div>";
        echo "<div class='metric-plan-fact' style='margin-top:2px;'><span>📈 Среднее за день: $avg_per_day $unit</span></div>";
        echo "<div class='metric-sub'>📅 Прогноз: $forecast $unit</div>";
        if ($label == 'Дозвоны' && isset($m['ai_calls']) && $m['ai_calls'] > 0) {
            echo "<div class='metric-sub' style='color:#0d6efd;'>🤖 AI-звонки: {$m['ai_calls']}</div>";
        }
        echo "</div>";
    }
    echo '</div>';
}

// ------------------------------------------------------------------
// Функция рендера таблицы ТЭ+Смарт+ПОС с добавлением колонки «Оборот»
// ------------------------------------------------------------------
function renderTeamDashboard($team_members, $pdo, $date_from, $date_to, $tomorrow, $work_days_total, $days_passed, $days_left, $filter_key, $filter_station, $filter_client) {
    if (empty($team_members)) {
        echo "<p>Нет сотрудников для отображения</p>";
        return;
    }
    $current_month = date('m');
    $current_year = date('Y');
    $days_in_month = (int)date('t', strtotime("$current_year-$current_month-01"));
    $calendar_days = [];
    for ($d = 1; $d <= $days_in_month; $d++) {
        $calendar_days[] = sprintf('%04d-%02d-%02d', $current_year, $current_month, $d);
    }
    
    function isWorkingDay($date) { return date('N', strtotime($date)) < 6; }
    $working_days = array_filter($calendar_days, 'isWorkingDay');
    $total_work_days = count($working_days);
    
    $month_start = date('Y-m-01', strtotime($date_to));
    $passed_work_days = getWorkingDaysCount($month_start, $date_to);
    $remaining_work_days = getWorkingDaysCount(date('Y-m-d', strtotime($date_to . ' +1 day')), date('Y-m-t', strtotime($date_to)));
    
    $prev_months = [];
    $period_start = new DateTime($date_from);
    $current_month_start = new DateTime(date('Y-m-01'));
    if ($period_start < $current_month_start) {
        $interval = DateInterval::createFromDateString('1 month');
        $period = new DatePeriod($period_start, $interval, $current_month_start);
        foreach ($period as $dt) {
            $prev_months[] = $dt->format('Y-m');
        }
    }
    
    $team_rows = [];
    $daily_totals = array_fill_keys($calendar_days, 0);
    $daily_turnover_totals = array_fill_keys($calendar_days, 0);
    $prev_month_totals = [];
    $total_period = 0;
    $total_turnover_period = 0;
    $total_plan = 0;
    $total_turnover_plan = 0;
    $total_fact_work = 0;
    
    foreach ($team_members as $m) {
        $daily_vals = [];
        $daily_turnover_vals = [];
        $member_total_period = 0;
        $member_total_turnover = 0;
        $member_total_work = 0;
        $tab = $m['tabel_number'] ?? null;
        if (!$tab) continue;
        
        foreach ($calendar_days as $date_str) {
            // Сумма ТЭ+Смарт+ПОС из daily_reports (как было)
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(registrations),0) + COALESCE(SUM(smart_cash),0) + COALESCE(SUM(pos_systems),0) FROM daily_reports WHERE tabel_number = ? AND report_date = ?");
            $stmt->execute([$tab, $date_str]);
            $val = (int)$stmt->fetchColumn();
            $daily_vals[$date_str] = $val;
            
            // Оборот из inn_records
            $prod = getProductSumsFromInn($pdo, $tab, $date_str, $date_str, $filter_key, $filter_station, $filter_client);
            $turnover = $prod['expected_turnover_sum'];
            $daily_turnover_vals[$date_str] = $turnover;
            
            if ($date_str >= $date_from && $date_str <= $date_to) {
                $member_total_period += $val;
                $member_total_turnover += $turnover;
            }
            if (isWorkingDay($date_str) && $date_str <= $date_to) {
                $member_total_work += $val;
            }
            $daily_totals[$date_str] += $val;
            $daily_turnover_totals[$date_str] += $turnover;
        }
        
        $prev_data = [];
        $prev_turnover_data = [];
        foreach ($prev_months as $ym) {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(registrations),0) + COALESCE(SUM(smart_cash),0) + COALESCE(SUM(pos_systems),0) FROM daily_reports WHERE tabel_number = ? AND strftime('%Y-%m', report_date) = ? AND report_date BETWEEN ? AND ?");
            $stmt->execute([$tab, $ym, $date_from, $date_to]);
            $val = (int)$stmt->fetchColumn();
            $prev_data[$ym] = $val;
            $member_total_period += $val;
            // Оборот за предыдущий месяц
            $prodPrev = getProductSumsFromInn($pdo, $tab, $date_from, $date_to, $filter_key, $filter_station, $filter_client);
            $turnoverPrev = $prodPrev['expected_turnover_sum'];
            $prev_turnover_data[$ym] = $turnoverPrev;
            $member_total_turnover += $turnoverPrev;
            
            if (!isset($prev_month_totals[$ym])) {
                $prev_month_totals[$ym] = ['total' => 0, 'turnover' => 0];
            }
            $prev_month_totals[$ym]['total'] += $val;
            $prev_month_totals[$ym]['turnover'] += $turnoverPrev;
        }
        
        // План
        $plan_stmt = $pdo->prepare("SELECT COALESCE(registrations_plan,0) + COALESCE(smart_cash_plan,0) + COALESCE(pos_systems_plan,0) AS total_plan, COALESCE(expected_turnover_plan,0) AS turnover_plan FROM plans WHERE tabel_number = ? AND period = ?");
        $plan_stmt->execute([$tab, date('Y-m')]);
        $plan_row = $plan_stmt->fetch();
        $plan = $plan_row ? $plan_row['total_plan'] : 0;
        $turnover_plan = $plan_row ? $plan_row['turnover_plan'] : 16000000;
        $total_plan += $plan;
        $total_turnover_plan += $turnover_plan;
        $total_fact_work += $member_total_work;
        
        $avg_per_work_day = ($passed_work_days > 0) ? $member_total_work / $passed_work_days : 0;
        $forecast = round($avg_per_work_day * $total_work_days);
        $gap = max(0, $plan - $member_total_work);
        $daily_target = ($remaining_work_days > 0 && $gap > 0) ? ceil($gap / $remaining_work_days) : 0;
        
        $exp_stmt = $pdo->prepare("SELECT expected_calls FROM daily_forecasts WHERE tabel_number = ? AND forecast_date = ?");
        $exp_stmt->execute([$tab, $tomorrow]);
        $expected = $exp_stmt->fetchColumn();
        
        $team_rows[] = [
            'name' => $m['full_name'],
            'tab' => $tab,
            'daily' => $daily_vals,
            'daily_turnover' => $daily_turnover_vals,
            'prev' => $prev_data,
            'prev_turnover' => $prev_turnover_data,
            'total' => $member_total_period,
            'total_turnover' => $member_total_turnover,
            'plan' => $plan,
            'turnover_plan' => $turnover_plan,
            'forecast' => $forecast,
            'gap' => $gap,
            'daily_target' => $daily_target,
            'expected' => $expected !== false ? (int)$expected : 0
        ];
        $total_period += $member_total_period;
        $total_turnover_period += $member_total_turnover;
    }
    $total_gap = max(0, $total_plan - $total_fact_work);
    $total_daily_target = ($remaining_work_days > 0 && $total_gap > 0) ? ceil($total_gap / $remaining_work_days) : 0;
    $total_expected = array_sum(array_column($team_rows, 'expected'));
    ?>
    <div class="team-dashboard">
        <h3>📅 Дашборд команды (ТЭ+Смарт+ПОС + Оборот по терминалам)</h3>
        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>Сотрудник</th>
                        <?php if (!empty($prev_months)): ?>
                            <?php foreach ($prev_months as $ym): ?>
                                <th>Итого <?= htmlspecialchars($ym) ?></th>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php foreach ($calendar_days as $date_str): ?>
                            <th><?= date('j', strtotime($date_str)) ?></th>
                        <?php endforeach; ?>
                        <th>Итого</th>
                        <th>Оборот</th>
                        <th>План</th>
                        <th>Прогноз</th>
                        <th>Гэп</th>
                        <th>🎯 цель/день</th>
                        <th>Ожид. завтра</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($team_rows as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <?php if (!empty($prev_months)): ?>
                            <?php foreach ($prev_months as $ym): ?>
                                <td><?= $row['prev'][$ym] ?? '' ?></td>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php foreach ($calendar_days as $date_str): ?>
                            <td><?= $row['daily'][$date_str] ?? '' ?></td>
                        <?php endforeach; ?>
                        <td><strong><?= $row['total'] ?></strong></td>
                        <td><strong><?= number_format($row['total_turnover'],0,'.',' ') ?> ₽</strong></td>
                        <td><?= $row['plan'] ?> / <?= number_format($row['turnover_plan'],0,'.',' ') ?> ₽</td>
                        <td><?= $row['forecast'] ?></td>
                        <td style="color:<?= $row['gap'] < 0 ? 'green' : 'red' ?>"><?= $row['gap'] ?></td>
                        <td><?= $row['daily_target'] ?></td>
                        <td><input type="number" min="0" value="<?= $row['expected'] ?>" data-tab="<?= $row['tab'] ?>" class="expected-input" style="width:70px;"></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!empty($team_rows)): ?>
                    <tr style="font-weight:bold; background:#f0f0f0;">
                        <td>ИТОГО</td>
                        <?php if (!empty($prev_months)): ?>
                            <?php foreach ($prev_months as $ym): ?>
                                <td><?= $prev_month_totals[$ym]['total'] ?? 0 ?></td>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php foreach ($calendar_days as $date_str): ?>
                            <td><?= $daily_totals[$date_str] ?></td>
                        <?php endforeach; ?>
                        <td><?= $total_period ?></td>
                        <td><?= number_format($total_turnover_period,0,'.',' ') ?> ₽</td>
                        <td><?= $total_plan ?> / <?= number_format($total_turnover_plan,0,'.',' ') ?> ₽</td>
                        <td><?= round(($total_fact_work / max(1, $passed_work_days)) * $total_work_days) ?></td>
                        <td><?= $total_gap ?></td>
                        <td><?= $total_daily_target ?></td>
                        <td><?= $total_expected ?></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>🌍 Территории — SZB CRM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}body{background:#f0f2f5;font-family:system-ui;padding:12px}.container{max-width:1400px;margin:0 auto}.navbar{background:linear-gradient(135deg,#1a1a2e,#16213e);color:#fff;padding:12px 16px;border-radius:16px;margin-bottom:20px;display:flex;justify-content:space-between;flex-wrap:wrap;align-items:center}.logo{font-size:1.3rem;font-weight:bold}.nav-links{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.nav-links a{color:#ccc;text-decoration:none;font-size:0.85rem}.user-info{color:#fff;font-weight:bold;margin-left:auto;font-size:0.9rem}.date-form{display:flex;gap:8px;align-items:center;margin-left:12px}.date-form input[type="date"]{padding:5px 8px;border-radius:8px;border:none;font-size:0.85rem}.date-form button{background:#fff;color:#1a1a2e;border:none;padding:5px 12px;border-radius:8px;font-weight:bold;cursor:pointer;font-size:0.85rem}.filter-bar{background:#f8f9fa;border-radius:16px;padding:12px 16px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:16px;align-items:center}.filter-bar select{padding:6px 10px;border-radius:8px;border:1px solid #ccc;font-size:0.85rem}.filter-bar label{font-weight:600;font-size:0.85rem;color:#333}.card-list{display:flex;flex-wrap:wrap;gap:20px;margin-bottom:30px}.territory-card,.manager-card{background:#fff;border-radius:16px;padding:16px;width:280px;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,0.08);transition:0.2s;text-align:center}.territory-card:hover,.manager-card:hover{transform:translateY(-3px);box-shadow:0 4px 12px rgba(0,0,0,0.1)}.territory-card h3,.manager-card h3{margin-bottom:6px;font-size:1.1rem}.metrics-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;margin-bottom:30px}.metric-card{background:#fff;border-radius:16px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,0.05)}.metric-header{display:flex;justify-content:space-between;margin-bottom:8px;font-size:0.8rem;color:#555}.metric-title{font-weight:600}.metric-value-row{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px}.metric-value{font-size:1.8rem;font-weight:800;line-height:1.2}.metric-value.success{color:#2e7d32}.metric-value.warning{color:#ed6c02}.metric-value.danger{color:#d32f2f}.metric-daily-target{font-size:1.8rem;font-weight:800;color:#1a1a2e;cursor:help}.progress-bar{background:#e0e0e0;border-radius:10px;height:6px;margin-top:8px;overflow:hidden}.progress-fill{height:100%;border-radius:10px}.progress-fill.success{background:#2e7d32}.progress-fill.warning{background:#ed6c02}.progress-fill.danger{background:#d32f2f}.metric-plan-fact{font-size:0.7rem;color:#555;margin-top:6px;display:flex;justify-content:space-between}.metric-sub{font-size:0.7rem;color:#666;margin-top:4px}.back-link{display:inline-block;margin-bottom:15px;color:#667eea;text-decoration:none;font-weight:bold;background:#fff;padding:6px 12px;border-radius:20px;box-shadow:0 1px 2px rgba(0,0,0,0.05)}.team-dashboard{margin-bottom:30px;overflow-x:auto;background:#fff;border-radius:16px;padding:16px;}.team-dashboard h3{margin-bottom:15px;}.team-dashboard table{font-size:0.7rem;width:100%;border-collapse:collapse;}.team-dashboard th,.team-dashboard td{padding:6px 4px;border:1px solid #eee;text-align:center;}.team-dashboard th{background:#f8f9fa;}.expected-input{width:70px;padding:2px;font-size:0.7rem;}
    </style>
</head>
<body>
<div class="container">
    <div class="navbar">
        <div class="logo">🚀 SZB</div>
        <div class="nav-links">
            <a href="dashboard.php">Дашборд</a>
            <a href="team.php">Команда</a>
            <a href="territories.php" class="active">Территории</a>
            <a href="export_inn.php">ИНН</a>
            <a href="quests.php">Квесты</a>
            <a href="ai.php">AI</a>
            <?php if ($user_role=='admin'): ?><a href="admin.php">Админ</a><?php endif; ?>
            <span class="user-info">👤 <?= htmlspecialchars($user_name) ?></span>
            <form class="date-form" method="GET" action="territories.php">
                <input type="hidden" name="level" value="<?= $level ?>">
                <?php if ($selected_id) echo '<input type="hidden" name="id" value="'.$selected_id.'">'; ?>
                <input type="date" name="date_from" value="<?= $date_from ?>">
                <input type="date" name="date_to" value="<?= $date_to ?>">
                <button type="submit">📅 Смотреть</button>
            </form>
            <a href="logout.php">Выйти</a>
        </div>
    </div>

    <!-- ===== ФИЛЬТРЫ ===== -->
    <form method="GET" class="filter-bar" action="territories.php">
        <input type="hidden" name="level" value="<?= $level ?>">
        <?php if ($selected_id) echo '<input type="hidden" name="id" value="'.$selected_id.'">'; ?>
        <input type="hidden" name="date_from" value="<?= $date_from ?>">
        <input type="hidden" name="date_to" value="<?= $date_to ?>">
        
        <label>Ключевой:</label>
        <select name="filter_key" onchange="this.form.submit()">
            <option value="all" <?= $filter_key == 'all' ? 'selected' : '' ?>>Все</option>
            <option value="key" <?= $filter_key == 'key' ? 'selected' : '' ?>>Ключевой</option>
            <option value="nonkey" <?= $filter_key == 'nonkey' ? 'selected' : '' ?>>Неключевой</option>
        </select>

        <label>Станция:</label>
        <select name="filter_station" onchange="this.form.submit()">
            <option value="all" <?= $filter_station == 'all' ? 'selected' : '' ?>>Все</option>
            <option value="pirate" <?= $filter_station == 'pirate' ? 'selected' : '' ?>>Пиратская</option>
            <option value="target" <?= $filter_station == 'target' ? 'selected' : '' ?>>Целевой список</option>
            <option value="newreg" <?= $filter_station == 'newreg' ? 'selected' : '' ?>>Новорег</option>
        </select>

        <label>Тип клиента:</label>
        <select name="filter_client" onchange="this.form.submit()">
            <option value="all" <?= $filter_client == 'all' ? 'selected' : '' ?>>Все</option>
            <option value="new" <?= $filter_client == 'new' ? 'selected' : '' ?>>Новый</option>
            <option value="expansion" <?= $filter_client == 'expansion' ? 'selected' : '' ?>>Расширение</option>
        </select>

        <button type="submit" style="background:#1a73e8; color:#fff; border:none; padding:6px 12px; border-radius:8px; cursor:pointer;">Применить</button>
    </form>

    <h2>🌍 Территории</h2>

    <?php if ($level == 'root' && $show_territories): ?>
        <div class="card-list">
            <?php foreach ($territories as $t): 
                $stmt = $pdo->prepare("SELECT id FROM users WHERE role IN ('head','territory_head') AND territory_id = ? AND is_active = 1");
                $stmt->execute([$t['id']]);
                $heads = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $all_ids = $heads;
                foreach ($heads as $hid) {
                    $stmt2 = $pdo->prepare("SELECT id FROM users WHERE manager_id = ? AND role IN ('manager','employee','ubr_middle') AND is_active = 1");
                    $stmt2->execute([$hid]);
                    $subs = $stmt2->fetchAll(PDO::FETCH_COLUMN);
                    $all_ids = array_merge($all_ids, $subs);
                }
                $terr_metrics = [];
                if (!empty($all_ids)) {
                    $terr_metrics = getAggregatedMetrics($all_ids, $pdo, $selected_month, $date_from, $date_to, $days_passed, $days_left, $work_days_total, $filter_key, $filter_station, $filter_client);
                }
                $contracts = $terr_metrics['contracts'] ?? null;
                $turnover = $terr_metrics['turnover'] ?? null;
                $expected_turnover = $terr_metrics['expected_turnover'] ?? null;
            ?>
                <div class="territory-card" onclick="location.href='?level=territory&id=<?= $t['id'] ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&filter_key=<?= $filter_key ?>&filter_station=<?= $filter_station ?>&filter_client=<?= $filter_client ?>'">
                    <h3>🌍 <?= htmlspecialchars($t['name']) ?></h3>
                    <?php if ($contracts): ?>
                        <div>📄 Договоры: <?= number_format($contracts['fact'],0,'.',' ') ?> / <?= number_format($contracts['plan'],0,'.',' ') ?> (<?= $contracts['percent'] ?>%)</div>
                    <?php endif; ?>
                    <?php if ($turnover): ?>
                        <div>💰 Оборот чаевых: <?= number_format($turnover['fact'],0,'.',' ') ?> / <?= number_format($turnover['plan'],0,'.',' ') ?> ₽</div>
                    <?php endif; ?>
                    <?php if ($expected_turnover): ?>
                        <div>💳 Оборот по терминалам: <?= number_format($expected_turnover['fact'],0,'.',' ') ?> / <?= number_format($expected_turnover['plan'],0,'.',' ') ?> ₽</div>
                    <?php endif; ?>
                    <div class="metric-sub" style="margin-top:8px;">👆 Клик для просмотра начальников</div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (isset($company_metrics)) renderMetricsGrid($company_metrics, '📊 Сводка по компании', $days_passed); ?>
        <?php renderTeamDashboard($team_members, $pdo, $date_from, $date_to, $tomorrow, $work_days_total, $days_passed, $days_left, $filter_key, $filter_station, $filter_client); ?>
    <?php endif; ?>

    <?php if ($level == 'territory' && $show_managers): ?>
        <a href="territories.php?date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&filter_key=<?= $filter_key ?>&filter_station=<?= $filter_station ?>&filter_client=<?= $filter_client ?>" class="back-link">← Назад к территориям</a>
        <?php if (!empty($current_metrics)) renderMetricsGrid($current_metrics, '📊 Сводка по территории', $days_passed); ?>
        <div class="card-list">
            <?php foreach ($managers as $m): ?>
                <div class="manager-card" onclick="location.href='?level=manager&id=<?= $m['id'] ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&filter_key=<?= $filter_key ?>&filter_station=<?= $filter_station ?>&filter_client=<?= $filter_client ?>'">
                    <h3>👔 <?= htmlspecialchars($m['full_name']) ?></h3>
                    <p>Таб. <?= htmlspecialchars($m['tabel_number']) ?></p>
                    <div class="metric-sub">👆 Клик для просмотра сотрудников</div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php renderTeamDashboard($team_members, $pdo, $date_from, $date_to, $tomorrow, $work_days_total, $days_passed, $days_left, $filter_key, $filter_station, $filter_client); ?>
    <?php endif; ?>

    <?php if ($level == 'manager' && $show_employees): ?>
        <a href="territories.php?level=territory&id=<?= $back_territory_id ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&filter_key=<?= $filter_key ?>&filter_station=<?= $filter_station ?>&filter_client=<?= $filter_client ?>" class="back-link">← Назад к начальникам</a>
        <?php if (!empty($current_metrics)) renderMetricsGrid($current_metrics, '👔 Сводка по команде', $days_passed); ?>
        <div class="card">
            <table class="employee-table" style="width:100%; border-collapse:collapse; background:#fff; border-radius:16px; overflow:hidden;">
                <thead>
                    <tr><th>Сотрудник</th><th>Таб. номер</th><th>Роль</th></tr>
                </thead>
                <tbody>
                <?php foreach ($employees as $emp): ?>
                    <tr>
                        <td><?= htmlspecialchars($emp['full_name']) ?></td>
                        <td><?= htmlspecialchars($emp['tabel_number']) ?></td>
                        <td><?= htmlspecialchars($emp['role']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php renderTeamDashboard($team_members, $pdo, $date_from, $date_to, $tomorrow, $work_days_total, $days_passed, $days_left, $filter_key, $filter_station, $filter_client); ?>
    <?php endif; ?>
</div>
<script>
document.querySelectorAll('.expected-input').forEach(input => {
    input.addEventListener('change', function() {
        const tab = this.dataset.tab;
        const val = this.value;
        const date = '<?= $tomorrow ?>';
        fetch('api_save_forecast.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'tabel=' + encodeURIComponent(tab) + '&date=' + encodeURIComponent(date) + '&calls=' + val
        }).then(r => r.json()).then(d => { if (!d.success) alert('Ошибка сохранения'); });
    });
});
</script>
</body>
</html>