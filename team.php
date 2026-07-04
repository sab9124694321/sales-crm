<?php
error_reporting(E_ALL & ~E_WARNING);
session_start();

// Закрываем доступ для менеджеров
if ($_SESSION['role'] == 'manager') {
    header('Location: dashboard.php');
    exit;
}

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once 'db.php';

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$user_name = $_SESSION['name'];

// --- Период (по умолчанию с начала месяца по сегодня) ---
$date_to   = $_GET['date_to']   ?? $_GET['date'] ?? date('Y-m-d');
$date_from = $_GET['date_from'] ?? date('Y-m-01', strtotime($date_to));
$tomorrow  = date('Y-m-d', strtotime('+1 day'));
$selected_month = date('Y-m', strtotime($date_from));

// ------------------------------------------------------------------
// Функция подсчёта количества рабочих дней (пн-пт) между двумя датами (включительно)
// ------------------------------------------------------------------
function getWorkingDaysCount($start_date, $end_date) {
    $start = new DateTime($start_date);
    $end   = new DateTime($end_date);
    $end->modify('+1 day'); // включаем конечную дату
    $interval = DateInterval::createFromDateString('1 day');
    $period = new DatePeriod($start, $interval, $end);
    $workingDays = 0;
    foreach ($period as $dt) {
        $dayOfWeek = $dt->format('N');
        if ($dayOfWeek < 6) $workingDays++;
    }
    return $workingDays;
}

$month_start = date('Y-m-01', strtotime($date_to));
$days_passed      = getWorkingDaysCount($month_start, $date_to);          // прошедшие рабочие дни
$work_days_total  = getWorkingDaysCount($month_start, date('Y-m-t', strtotime($date_to))); // всего рабочих дней в месяце
$days_left        = max(0, $work_days_total - $days_passed);               // оставшиеся рабочие дни

// ------------------------------------------------------------------
// Функция расчёта метрик (прогноз, цель на день, статус) – использует РАБОЧИЕ дни
// ------------------------------------------------------------------
function calcTeamMetrics($plan, $fact, $days_passed, $days_left, $work_days_total) {
    if ($plan <= 0) {
        return ['forecast' => 0, 'daily_target' => 0, 'status' => 'none', 'percent' => 0];
    }
    $percent = ($plan > 0) ? round(($fact / $plan) * 100) : 0;
    
    // Если план уже выполнен или перевыполнен
    if ($fact >= $plan) {
        return [
            'forecast' => $fact,
            'daily_target' => 0,
            'status' => 'success',
            'percent' => min(100, $percent)
        ];
    }
    
    // Если нет прошедших рабочих дней (начало месяца)
    if ($days_passed == 0) {
        $daily_target = ceil($plan / max(1, $work_days_total));
        $forecast = $plan;
        $status = 'warning';
        return [
            'forecast' => $forecast,
            'daily_target' => $daily_target,
            'status' => $status,
            'percent' => $percent
        ];
    }
    
    // Если оставшихся дней нет (конец месяца)
    if ($days_left == 0) {
        return [
            'forecast' => $fact,
            'daily_target' => 0,
            'status' => 'danger',
            'percent' => $percent
        ];
    }
    
    // Основной расчёт
    $ideal = ceil($plan / max(1, $work_days_total));
    $should_be = $ideal * $days_passed;
    $deviation = $should_be - $fact;
    
    if ($deviation > 0) {
        $daily_target = ceil(($plan - $fact) / $days_left);
    } else {
        $daily_target = $ideal;
    }
    
    $avg_per_day = $fact / $days_passed;
    $forecast = round($avg_per_day * $work_days_total);
    
    $status = ($forecast >= $plan) ? 'warning' : 'danger';
    
    return [
        'forecast' => $forecast,
        'daily_target' => $daily_target,
        'status' => $status,
        'percent' => $percent
    ];
}

$level = $_GET['level'] ?? 'root';
$selected_id = $_GET['id'] ?? 0;

$territories = [];
$managers = [];
$employees = [];

// ------------------------------------------------------------------
// Функция агрегации метрик для набора пользователей
// ------------------------------------------------------------------
function getAggregatedMetrics($user_ids, $pdo, $selected_month, $date_from, $date_to, $days_passed, $days_left, $work_days_total) {
    $total_plans = [
        'calls_plan'=>0, 'calls_answered_plan'=>0, 'meetings_plan'=>0, 'contracts_plan'=>0,
        'registrations_plan'=>0, 'smart_cash_plan'=>0, 'pos_systems_plan'=>0,
        'inn_leads_plan'=>0, 'teams_plan'=>0, 'turnover_plan'=>0, 'rko_plan'=>0
    ];
    $total_fact_period = [
        'calls'=>0, 'calls_answered'=>0, 'meetings'=>0, 'contracts'=>0,
        'registrations'=>0, 'smart_cash'=>0, 'pos_systems'=>0,
        'inn_leads'=>0, 'teams'=>0, 'turnover'=>0, 'rko'=>0, 'ai_calls'=>0
    ];
    $total_fact_today = [
        'calls'=>0, 'calls_answered'=>0, 'meetings'=>0, 'contracts'=>0,
        'registrations'=>0, 'smart_cash'=>0, 'pos_systems'=>0,
        'inn_leads'=>0, 'teams'=>0, 'turnover'=>0, 'rko'=>0
    ];
    foreach ($user_ids as $uid) {
        $stmt = $pdo->prepare("SELECT tabel_number FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        $tabel = $stmt->fetchColumn();
        if (!$tabel) continue;
        $stmt = $pdo->prepare("SELECT * FROM plans WHERE tabel_number = ? AND period = ?");
        $stmt->execute([$tabel, $selected_month]);
        $plans = $stmt->fetch();
        if ($plans) {
            foreach (['calls','calls_answered','meetings','contracts','registrations','smart_cash','pos_systems','inn_leads','teams','turnover','rko'] as $f) {
                $total_plans[$f.'_plan'] += $plans[$f.'_plan'] ?? 0;
            }
        }
        $stmt = $pdo->prepare("SELECT 
            COALESCE(SUM(calls),0) as calls, COALESCE(SUM(calls_answered),0) as calls_answered,
            COALESCE(SUM(meetings),0) as meetings, COALESCE(SUM(contracts),0) as contracts,
            COALESCE(SUM(registrations),0) as registrations, COALESCE(SUM(smart_cash),0) as smart_cash,
            COALESCE(SUM(pos_systems),0) as pos_systems, COALESCE(SUM(inn_leads),0) as inn_leads,
            COALESCE(SUM(teams),0) as teams, COALESCE(SUM(turnover),0) as turnover,
            COALESCE(SUM(rko),0) as rko, COALESCE(SUM(ai_calls),0) as ai_calls
            FROM daily_reports WHERE tabel_number = ? AND report_date BETWEEN ? AND ?
        ");
        $stmt->execute([$tabel, $date_from, $date_to]);
        $factP = $stmt->fetch();
        if ($factP) {
            foreach (['calls','calls_answered','meetings','contracts','registrations','smart_cash','pos_systems','inn_leads','teams','turnover','rko','ai_calls'] as $f) {
                $total_fact_period[$f] += $factP[$f];
            }
        }
        $stmt = $pdo->prepare("SELECT calls, calls_answered, meetings, contracts, registrations, smart_cash, pos_systems, inn_leads, teams, turnover, rko FROM daily_reports WHERE tabel_number = ? AND report_date = ?");
        $stmt->execute([$tabel, $date_to]);
        $factD = $stmt->fetch();
        if ($factD) {
            foreach (['calls','calls_answered','meetings','contracts','registrations','smart_cash','pos_systems','inn_leads','teams','turnover','rko'] as $f) {
                $total_fact_today[$f] += $factD[$f];
            }
        }
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
        'rko'            => ['plan'=>$total_plans['rko_plan'], 'fact'=>$total_fact_period['rko'], 'today_fact'=>$total_fact_today['rko'], 'label'=>'РКО', 'icon'=>'🏦', 'unit'=>'₽']
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
// Рекурсивный сбор ID команды
// ------------------------------------------------------------------
function getTeamIds($head_id, $pdo) {
    $ids = [$head_id];
    $stmt = $pdo->prepare("SELECT id FROM users WHERE manager_id = ? AND role IN ('manager','employee','ubr_middle')");
    $stmt->execute([$head_id]);
    $subs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $ids = array_merge($ids, $subs);
    foreach ($subs as $sub_id) {
        $stmt2 = $pdo->prepare("SELECT id FROM users WHERE manager_id = ? AND role IN ('manager','employee','ubr_middle')");
        $stmt2->execute([$sub_id]);
        $subsubs = $stmt2->fetchAll(PDO::FETCH_COLUMN);
        $ids = array_merge($ids, $subsubs);
    }
    return array_unique($ids);
}

// ------------------------------------------------------------------
// Логика показа в зависимости от роли и уровня
// ------------------------------------------------------------------
if ($user_role == 'admin') {
    $stmt = $pdo->query("SELECT id, name FROM territories");
    $territories = $stmt->fetchAll();
    if ($level == 'root') {
        $show_territories = true;
        $stmt = $pdo->query("SELECT id FROM users WHERE role != 'admin'");
        $all_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $company_metrics = getAggregatedMetrics($all_ids, $pdo, $selected_month, $date_from, $date_to, $days_passed, $days_left, $work_days_total);
        $show_company_summary = true;
    } elseif ($level == 'territory') {
        $stmt = $pdo->prepare("SELECT id, tabel_number, full_name, role FROM users WHERE role IN ('head','territory_head') AND territory_id = ?");
        $stmt->execute([$selected_id]);
        $managers = $stmt->fetchAll();
        $all_user_ids = [];
        foreach ($managers as $m) {
            $team_ids = getTeamIds($m['id'], $pdo);
            $all_user_ids = array_merge($all_user_ids, $team_ids);
        }
        $territory_metrics = getAggregatedMetrics(array_unique($all_user_ids), $pdo, $selected_month, $date_from, $date_to, $days_passed, $days_left, $work_days_total);
        $show_territory_summary = true;
        $show_managers = true;
    } elseif ($level == 'manager') {
        $team_ids = getTeamIds($selected_id, $pdo);
        $manager_metrics = getAggregatedMetrics($team_ids, $pdo, $selected_month, $date_from, $date_to, $days_passed, $days_left, $work_days_total);
        $show_manager_summary = true;
        $stmt = $pdo->prepare("SELECT id, tabel_number, full_name, role FROM users WHERE manager_id = ? AND role IN ('manager','employee','ubr_middle') ORDER BY full_name");
        $stmt->execute([$selected_id]);
        $employees = $stmt->fetchAll();
        $show_table = true;
    }
}
elseif ($user_role == 'terman') {
    $stmt = $pdo->prepare("SELECT t.id, t.name FROM territories t JOIN territory_managers tm ON t.id = tm.territory_id WHERE tm.manager_id = ?");
    $stmt->execute([$user_id]);
    $territories = $stmt->fetchAll();
    if ($level == 'root') {
        $show_territories = true;
    } elseif ($level == 'territory') {
        $stmt = $pdo->prepare("SELECT id, tabel_number, full_name, role FROM users WHERE role IN ('head','territory_head') AND territory_id = ?");
        $stmt->execute([$selected_id]);
        $managers = $stmt->fetchAll();
        $all_user_ids = [];
        foreach ($managers as $m) {
            $team_ids = getTeamIds($m['id'], $pdo);
            $all_user_ids = array_merge($all_user_ids, $team_ids);
        }
        $territory_metrics = getAggregatedMetrics(array_unique($all_user_ids), $pdo, $selected_month, $date_from, $date_to, $days_passed, $days_left, $work_days_total);
        $show_territory_summary = true;
        $show_managers = true;
    } elseif ($level == 'manager') {
        $team_ids = getTeamIds($selected_id, $pdo);
        $manager_metrics = getAggregatedMetrics($team_ids, $pdo, $selected_month, $date_from, $date_to, $days_passed, $days_left, $work_days_total);
        $show_manager_summary = true;
        $stmt = $pdo->prepare("SELECT id, tabel_number, full_name, role FROM users WHERE manager_id = ? AND role IN ('manager','employee','ubr_middle') ORDER BY full_name");
        $stmt->execute([$selected_id]);
        $employees = $stmt->fetchAll();
        $show_table = true;
    }
}
elseif ($user_role == 'head') {
    $team_ids = getTeamIds($user_id, $pdo);
    $manager_metrics = getAggregatedMetrics($team_ids, $pdo, $selected_month, $date_from, $date_to, $days_passed, $days_left, $work_days_total);
    $show_manager_summary = true;
    $stmt = $pdo->prepare("SELECT id, tabel_number, full_name, role FROM users WHERE manager_id = ? AND role IN ('manager','employee','ubr_middle') ORDER BY full_name");
    $stmt->execute([$user_id]);
    $employees = $stmt->fetchAll();
    $show_table = true;
}
elseif ($user_role == 'mmb_tp_head') {
    $stmt = $pdo->prepare("SELECT id, tabel_number, full_name, role FROM users WHERE manager_id = ? AND role IN ('mmb_manager','manager') ORDER BY full_name");
    $stmt->execute([$user_id]);
    $employees = $stmt->fetchAll();
    $show_table = true;
    $team_ids = getTeamIds($user_id, $pdo);
    $manager_metrics = getAggregatedMetrics($team_ids, $pdo, $selected_month, $date_from, $date_to, $days_passed, $days_left, $work_days_total);
    $show_manager_summary = true;
}
else {
    $show_table = false;
}

// ------------------------------------------------------------------
// Функции отображения (рендеринг)
// ------------------------------------------------------------------
function renderMetricsGrid($metrics, $title = '', $days_passed = 0) {
    if ($title) echo "<h3 style='margin: 20px 0 10px 0;'>$title</h3>";
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
        $avg_per_day = $days_passed > 0 ? ($m['plan'] > 0 ? round($m['fact'] / $days_passed, 1) : 0) : 0;
        if ($m['unit'] == '₽') {
            $avg_per_day_formatted = number_format($avg_per_day, 2, '.', ' ');
        } else {
            $avg_per_day_formatted = number_format($avg_per_day, 1, '.', ' ');
        }
        $aiCalls = isset($m['ai_calls']) ? (int)$m['ai_calls'] : 0;

        echo "<div class='metric-card'>";
        echo "<div class='metric-header'><span class='metric-title'>$icon $label</span><span>{$percent}%</span></div>";
        echo "<div class='metric-value-row'><span class='metric-value $status'>$today_fact</span><span class='metric-daily-target' title='Дневная цель с учётом гэпа'>$daily_target</span></div>";
        echo "<div class='progress-bar'><div class='progress-fill $status' style='width:{$percent}%'></div></div>";
        echo "<div class='metric-plan-fact'><span>📊 План: $plan $unit</span><span>✅ Факт: $fact $unit</span></div>";
        echo "<div class='metric-plan-fact' style='margin-top:2px;'><span>📈 Среднее за день: $avg_per_day_formatted $unit</span></div>";
        echo "<div class='metric-sub'>📅 Прогноз: $forecast $unit</div>";
        if ($label === 'Дозвоны' && $aiCalls > 0) {
            echo "<div class='metric-sub' style='color:#0d6efd;'>🤖 AI-звонки: $aiCalls</div>";
        }
        echo "</div>";
    }
    echo '</div>';
}

function renderCards($items, $type, $date_from = '', $date_to = '') {
    echo '<div class="card-list">';
    foreach ($items as $item) {
        $url = "?level=".($type=='territory'?'territory':'manager')."&id={$item['id']}";
        if ($date_from) $url .= '&date_from='.$date_from;
        if ($date_to)   $url .= '&date_to='.$date_to;
        if ($type == 'territory') {
            echo "<div class='card' onclick=\"location.href='{$url}'\"><h3>🌍 {$item['name']}</h3><div class='metric-sub'>👆 Клик для просмотра начальников</div></div>";
        } else {
            echo "<div class='card' onclick=\"location.href='{$url}'\"><h3>👔 {$item['full_name']}</h3><p>Таб. {$item['tabel_number']}</p><div class='metric-sub'>👆 Клик для просмотра сотрудников</div></div>";
        }
    }
    echo '</div>';
}

// ------------------------------------------------------------------
// Таблица сотрудников (использует РАБОЧИЕ дни)
// ------------------------------------------------------------------
function renderEmployeesTable($employees, $pdo, $days_passed, $days_left, $selected_month, $date_from, $date_to, $work_days_total) {
    if (empty($employees)) { echo "<p>Нет сотрудников для отображения</p>"; return; }

    $allColumns = [
        'calls' => '📞 Звонки',
        'calls_answered' => '✅ Дозвоны',
        'meetings' => '🤝 Встречи',
        'contracts' => '📄 Договоры',
        'registrations' => '📝 ТЭ',
        'smart_cash' => '💳 Смарт',
        'pos_systems' => '🖥️ ПОС',
        'inn_leads' => '🍵 Чаевые',
        'teams' => '👥 Команды',
        'turnover' => '💰 Оборот чаевых',
        'rko' => '🏦 РКО'
    ];

    $visibleColumns = $_GET['cols'] ?? implode(',', array_keys($allColumns));
    $visibleArray = explode(',', $visibleColumns);
    $visibleArray = array_intersect($visibleArray, array_keys($allColumns));
    if (empty($visibleArray)) $visibleArray = array_keys($allColumns);

    echo '<div class="column-toggles">';
    foreach ($allColumns as $col => $label) {
        $checked = in_array($col, $visibleArray) ? 'checked' : '';
        echo "<label style='margin-right:12px; font-size:0.75rem; cursor:pointer;'><input type='checkbox' class='col-toggle' data-col='{$col}' {$checked}> {$label}</label>";
    }
    echo '</div>';

    echo '<div class="table-wrapper"><table id="employeesTable">';
    echo '<thead><tr><th>Сотрудник</th><th>Таб.</th>';
    foreach ($visibleArray as $col) {
        echo "<th class='col-{$col}'>{$allColumns[$col]}</th>";
    }
    echo '</thead><tbody>';

    foreach ($employees as $emp) {
        $stmt = $pdo->prepare("SELECT * FROM plans WHERE tabel_number = ? AND period = ?");
        $stmt->execute([$emp['tabel_number'], $selected_month]);
        $plans = $stmt->fetch();
        if (!$plans) {
            $plans = [
                'calls_plan'=>350, 'calls_answered_plan'=>245, 'meetings_plan'=>35, 'contracts_plan'=>21,
                'registrations_plan'=>15, 'smart_cash_plan'=>10, 'pos_systems_plan'=>5, 'inn_leads_plan'=>5,
                'teams_plan'=>3, 'turnover_plan'=>1500000, 'rko_plan'=>0
            ];
        }
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(calls),0) as calls, COALESCE(SUM(calls_answered),0) as calls_answered,
                COALESCE(SUM(meetings),0) as meetings, COALESCE(SUM(contracts),0) as contracts,
                COALESCE(SUM(registrations),0) as registrations, COALESCE(SUM(smart_cash),0) as smart_cash,
                COALESCE(SUM(pos_systems),0) as pos_systems, COALESCE(SUM(inn_leads),0) as inn_leads,
                COALESCE(SUM(teams),0) as teams, COALESCE(SUM(turnover),0) as turnover,
                COALESCE(SUM(rko),0) as rko, COALESCE(SUM(ai_calls),0) as ai_calls
            FROM daily_reports WHERE tabel_number = ? AND report_date BETWEEN ? AND ?
        ");
        $stmt->execute([$emp['tabel_number'], $date_from, $date_to]);
        $factM = $stmt->fetch();
        if (!$factM) $factM = array_fill_keys(['calls','calls_answered','meetings','contracts','registrations','smart_cash','pos_systems','inn_leads','teams','turnover','rko','ai_calls'], 0);
        $stmt = $pdo->prepare("SELECT * FROM daily_reports WHERE tabel_number = ? AND report_date = ?");
        $stmt->execute([$emp['tabel_number'], $date_to]);
        $factD = $stmt->fetch();
        if (!$factD) $factD = array_fill_keys(['calls','calls_answered','meetings','contracts','registrations','smart_cash','pos_systems','inn_leads','teams','turnover','rko'], 0);

        echo '<tr>';
        echo "<td><strong>{$emp['full_name']}</strong><br><span style='font-size:0.6rem;color:#888;'>факт мес | план мес | прогноз<br>факт день | цель/день | сред/день</span></td>";
        echo "<td>{$emp['tabel_number']}</td>";

        foreach ($visibleArray as $col) {
            $plan_month = $plans[$col.'_plan'] ?? 0;
            $fact_month = $factM[$col] ?? 0;
            $fact_day   = $factD[$col] ?? 0;
            $calc = calcTeamMetrics($plan_month, $fact_month, $days_passed, $days_left, $work_days_total);
            $daily_target = number_format((float)$calc['daily_target'],0,'.',' ');
            $forecast = number_format((float)$calc['forecast'],0,'.',' ');
            $avg_per_day = $days_passed > 0 ? round($fact_month / $days_passed, 1) : 0;
            if (in_array($col, ['turnover','rko'])) $avg_per_day = number_format($avg_per_day, 2, '.', ' ');
            else $avg_per_day = number_format($avg_per_day, 1, '.', ' ');

            $plan_day = ($work_days_total > 0) ? ceil($plan_month / $work_days_total) : 0;
            $dayColor = ($fact_day >= $plan_day) ? '#2e7d32' : '#d32f2f';
            $statusColor = match($calc['status']) {
                'success' => '#2e7d32',
                'warning' => '#ed6c02',
                'danger'  => '#d32f2f',
                default   => '#555'
            };
            $aiCalls = ($col === 'calls_answered') ? ((int)($factM['ai_calls'] ?? 0)) : 0;
            $aiHtml = ($col === 'calls_answered' && $aiCalls > 0) ? '<br><span style="font-size:0.6rem;color:#0d6efd;">🤖'.$aiCalls.'</span>' : '';

            echo "<td class='col-{$col}' style='font-size:0.7rem; line-height:1.3; padding:4px; white-space:nowrap;'>";
            echo "<span style='font-weight:bold;'>{$fact_month}</span> | <span style='color:#888;'>{$plan_month}</span> | <span style='font-weight:bold; color:{$statusColor};'>{$forecast}</span><br>";
            echo "<span style='font-weight:bold; color:{$dayColor};'>{$fact_day}</span> | <span style='color:#1a73e8;'>{$daily_target}</span> | <span style='color:#555;'>{$avg_per_day}</span>";
            echo "<div style='background:#e0e0e0; height:4px; margin-top:4px;'><div style='width:{$calc['percent']}%; height:4px; background:{$statusColor};'></div></div>";
            echo $aiHtml;
            echo "</td>";
        }
        echo '</tr>';
    }
    echo '</tbody>;</div>';
}

// ------------------------------------------------------------------
// ДАШБОРД КОМАНДЫ (ТЭ+Смарт+ПОС) – только рабочие дни (ИСПРАВЛЕН)
// ------------------------------------------------------------------
$team_members = [];

if (isset($show_manager_summary) && $level == 'manager' && $selected_id > 0) {
    $stmt = $pdo->prepare("SELECT id, full_name, tabel_number FROM users WHERE manager_id = ? AND role IN ('manager','employee','ubr_middle') AND is_active = 1 ORDER BY full_name");
    $stmt->execute([$selected_id]);
    $team_members = $stmt->fetchAll();
} else {
    if ($user_role == 'head') {
        $stmt = $pdo->prepare("SELECT id, full_name, tabel_number FROM users WHERE is_active = 1 AND role IN ('manager', 'mmb_manager', 'ubr_middle') AND manager_id = ? ORDER BY full_name");
        $stmt->execute([$user_id]);
        $team_members = $stmt->fetchAll();
    } elseif ($user_role == 'mmb_tp_head') {
        $stmt = $pdo->prepare("SELECT id, full_name, tabel_number FROM users WHERE is_active = 1 AND role IN ('mmb_manager', 'manager') AND manager_id = ? ORDER BY full_name");
        $stmt->execute([$user_id]);
        $team_members = $stmt->fetchAll();
    } else {
        $team_members = $pdo->query("SELECT id, full_name, tabel_number FROM users WHERE (role = 'manager' OR role = 'mmb_manager' OR role = 'ubr_middle') AND is_active = 1 ORDER BY full_name")->fetchAll();
    }
}

// ------------------------------------------------------------------
// Все календарные дни ВЫБРАННОГО месяца (для шапки таблицы)
// ------------------------------------------------------------------
$selected_month_cal = date('m', strtotime($date_to));
$selected_year_cal = date('Y', strtotime($date_to));
$days_in_month = (int)date('t', strtotime("$selected_year_cal-$selected_month_cal-01"));
$calendar_days = [];
for ($d = 1; $d <= $days_in_month; $d++) {
    $date_str = sprintf('%04d-%02d-%02d', $selected_year_cal, $selected_month_cal, $d);
    $calendar_days[] = $date_str;
}

// Функция проверки рабочего дня (оставляем для фильтрации)
function isWorkingDay($date) { return date('N', strtotime($date)) < 6; }

// ========== ИСПРАВЛЕННЫЙ РАСЧЁТ РАБОЧИХ ДНЕЙ через getWorkingDaysCount ==========
$month_start = date('Y-m-01', strtotime($date_to));
$total_work_days = getWorkingDaysCount($month_start, date('Y-m-t', strtotime($date_to)));
$passed_work_days = getWorkingDaysCount($month_start, $date_to);
$remaining_work_days = getWorkingDaysCount(date('Y-m-d', strtotime($date_to . ' +1 day')), date('Y-m-t', strtotime($date_to)));

// ------------------------------------------------------------------
// ИСПРАВЛЕННО: предыдущие месяцы (только строго меньше, чем месяц date_to)
// ------------------------------------------------------------------
$prev_months = [];
$temp = new DateTime($date_from);
$temp->modify('first day of this month');
$current_month_start = new DateTime(date('Y-m-01', strtotime($date_to)));
while ($temp < $current_month_start) {
    $prev_months[] = $temp->format('Y-m');
    $temp->modify('+1 month');
}

$team_rows = [];
$daily_totals = array_fill_keys($calendar_days, 0);
$prev_month_totals = [];
$total_period = 0;
$total_plan = 0;
$total_fact_work = 0;

foreach ($team_members as $m) {
    $tab = $m['tabel_number'];
    $daily_vals = [];
    $member_total_period = 0;
    $member_total_work = 0;

    foreach ($calendar_days as $date_str) {
        // ИСПРАВЛЕНО: используем tabel_number вместо user_id
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(registrations),0) + COALESCE(SUM(smart_cash),0) + COALESCE(SUM(pos_systems),0) FROM daily_reports WHERE tabel_number = ? AND report_date = ?");
        $stmt->execute([$tab, $date_str]);
        $val = (int)$stmt->fetchColumn();
        $daily_vals[$date_str] = $val;
        if ($date_str >= $date_from && $date_str <= $date_to) {
            $member_total_period += $val;
        }
        if (isWorkingDay($date_str) && $date_str <= $date_to) {
            $member_total_work += $val;
        }
        $daily_totals[$date_str] += $val;
    }

    // Данные за предыдущие месяцы (только если есть)
    $prev_data = [];
    foreach ($prev_months as $ym) {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(registrations),0) + COALESCE(SUM(smart_cash),0) + COALESCE(SUM(pos_systems),0) FROM daily_reports WHERE tabel_number = ? AND strftime('%Y-%m', report_date) = ? AND report_date BETWEEN ? AND ?");
        $stmt->execute([$tab, $ym, $date_from, $date_to]);
        $val = (int)$stmt->fetchColumn();
        $prev_data[$ym] = $val;
        $member_total_period += $val;
        if (!isset($prev_month_totals[$ym])) {
            $prev_month_totals[$ym] = ['total' => 0, 'members' => []];
        }
        $prev_month_totals[$ym]['total'] += $val;
    }

    // План
    $plan_stmt = $pdo->prepare("SELECT COALESCE(registrations_plan,0) + COALESCE(smart_cash_plan,0) + COALESCE(pos_systems_plan,0) AS total_plan FROM plans WHERE tabel_number = ? AND period = ?");
    $plan_stmt->execute([$tab, date('Y-m')]);
    $plan = $plan_stmt->fetchColumn() ?: 0;
    $total_plan += $plan;
    $total_fact_work += $member_total_work;

    // Прогноз: (факт_за_рабочие_дни / прошедшие_рабочие_дни) * всего_рабочих_дней
    $avg_per_work_day = ($passed_work_days > 0) ? $member_total_work / $passed_work_days : 0;
    $forecast = round($avg_per_work_day * $total_work_days);

    $gap = max(0, $plan - $member_total_work);
    // ЦЕЛЬ НА ДЕНЬ – корректно
    $daily_target = ($remaining_work_days > 0 && $gap > 0) ? ceil($gap / $remaining_work_days) : 0;

    $exp_stmt = $pdo->prepare("SELECT expected_calls FROM daily_forecasts WHERE tabel_number = ? AND forecast_date = ?");
    $exp_stmt->execute([$tab, $tomorrow]);
    $expected = $exp_stmt->fetchColumn();

    $team_rows[] = [
        'name' => $m['full_name'],
        'tab' => $tab,
        'daily' => $daily_vals,
        'prev' => $prev_data,
        'total' => $member_total_period,
        'plan' => $plan,
        'forecast' => $forecast,
        'gap' => $gap,
        'daily_target' => $daily_target,
        'expected' => $expected !== false ? (int)$expected : 0
    ];
    $total_period += $member_total_period;
}

$total_gap = max(0, $total_plan - $total_fact_work);
$total_daily_target = ($remaining_work_days > 0 && $total_gap > 0) ? ceil($total_gap / $remaining_work_days) : 0;
$total_expected = array_sum(array_column($team_rows, 'expected'));
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>👥 Команда</title><meta name="viewport" content="width=device-width, initial-scale=1"><style>
*{margin:0;padding:0;box-sizing:border-box}body{background:#f0f2f5;font-family:system-ui;padding:12px}.container{max-width:1400px;margin:0 auto}.header{background:linear-gradient(135deg,#1a1a2e,#16213e);color:#fff;padding:12px 16px;border-radius:16px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap}.nav-links{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.nav-links a{color:#ccc;text-decoration:none;font-size:0.85rem}.user-info{color:#fff;font-weight:bold;margin-left:auto;font-size:0.9rem}.date-form{display:flex;gap:8px;align-items:center;margin-left:12px}.date-form input[type="date"]{padding:5px 8px;border-radius:8px;border:none;font-size:0.85rem}.date-form button{background:#fff;color:#1a1a2e;border:none;padding:5px 12px;border-radius:8px;font-weight:bold;cursor:pointer;font-size:0.85rem}.card-list{display:flex;flex-wrap:wrap;gap:20px;margin-bottom:30px}.card{background:#fff;border-radius:16px;padding:16px;width:280px;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,0.08);transition:0.2s;text-align:center}.card:hover{transform:translateY(-3px);box-shadow:0 4px 12px rgba(0,0,0,0.1)}.card h3{margin-bottom:6px;font-size:1.1rem}.metrics-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;margin-bottom:30px}.metric-card{background:#fff;border-radius:16px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,0.05)}.metric-header{display:flex;justify-content:space-between;margin-bottom:8px;font-size:0.8rem;color:#555}.metric-title{font-weight:600}.metric-value-row{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px}.metric-value{font-size:1.8rem;font-weight:800;line-height:1.2}.metric-value.success{color:#2e7d32}.metric-value.warning{color:#ed6c02}.metric-value.danger{color:#d32f2f}.metric-daily-target{font-size:1.8rem;font-weight:800;color:#1a1a2e;cursor:help}.progress-bar{background:#e0e0e0;border-radius:10px;height:6px;margin-top:8px;overflow:hidden}.progress-fill{height:100%;border-radius:10px}.progress-fill.success{background:#2e7d32}.progress-fill.warning{background:#ed6c02}.progress-fill.danger{background:#d32f2f}.metric-plan-fact{font-size:0.7rem;color:#555;margin-top:6px;display:flex;justify-content:space-between}.metric-sub{font-size:0.7rem;color:#666;margin-top:4px}.back-link{display:inline-block;margin-bottom:15px;color:#667eea;text-decoration:none;font-weight:bold;background:#fff;padding:6px 12px;border-radius:20px;box-shadow:0 1px 2px rgba(0,0,0,0.05)}.column-toggles { margin-bottom: 12px; display: flex; flex-wrap: wrap; gap: 4px; }
.table-wrapper{overflow-x:auto;background:#fff;border-radius:16px;padding:12px;margin-top:20px}table{width:100%;border-collapse:collapse}th,td{padding:6px 4px;text-align:left;border-bottom:1px solid #eee;font-size:0.7rem}th{background:#f8f9fa; font-weight:600; vertical-align:bottom;}td strong{font-size:0.85rem}@media (max-width:640px){.metrics-grid{grid-template-columns:1fr} .table-wrapper table{font-size:0.65rem} th,td{padding:4px 2px}}
</style></head>
<body><div class="container"><div class="header">
<div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
  <h1 style="margin:0;font-size:1.4rem;">👥 Команда</h1>
  <span class="user-info">👤 <?= htmlspecialchars($user_name) ?> (<?= htmlspecialchars($user_role) ?>)</span>
</div>
<div class="nav-links">
    <a href="dashboard.php">📊 Дашборд</a>
    <?php if (in_array($user_role, ['admin', 'head', 'territory_head', 'terman'])): ?>
        <a href="team_report.php?date=<?= $date_to ?>">📋 Протокол</a>
    <?php endif; ?>
    <a href="employee_meeting.php">📋 Встреча с сотрудником</a>
    <a href="logout.php">🚪 Выйти</a>
    <form class="date-form" method="GET" action="team.php">
        <?php if (!empty($_GET['level'])) { echo '<input type="hidden" name="level" value="'.htmlspecialchars($_GET['level']).'">'; } ?>
        <?php if (!empty($_GET['id'])) { echo '<input type="hidden" name="id" value="'.htmlspecialchars($_GET['id']).'">'; } ?>
        <input type="date" name="date_from" value="<?= $date_from ?>">
        <input type="date" name="date_to" value="<?= $date_to ?>">
        <button type="submit">📅 Смотреть</button>
    </form>
</div>
</div>

<?php if (isset($show_company_summary)): ?>
    <?php renderMetricsGrid($company_metrics, '📊 Сводка по компании', $days_passed); ?>
<?php endif; ?>

<?php if (isset($show_territories)): ?>
    <div class="card-list">
        <?php foreach ($territories as $t): ?>
            <div class="card" onclick="location.href='?level=territory&id=<?=$t['id']?>&date_from=<?=$date_from?>&date_to=<?=$date_to?>'">
                <h3>🌍 <?= htmlspecialchars($t['name']) ?></h3>
                <div class="metric-sub">👆 Клик для просмотра начальников</div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (isset($show_territory_summary)): ?>
    <a href="team.php?date_from=<?=$date_from?>&date_to=<?=$date_to?>" class="back-link">← Назад к территориям</a>
    <?php renderMetricsGrid($territory_metrics, '🌍 Сводка по территории', $days_passed); ?>
<?php endif; ?>

<?php if (isset($show_managers)): ?>
    <div class="card-list">
        <?php foreach ($managers as $m): ?>
            <div class="card" onclick="location.href='?level=manager&id=<?=$m['id']?>&date_from=<?=$date_from?>&date_to=<?=$date_to?>'">
                <h3>👔 <?= htmlspecialchars($m['full_name']) ?></h3>
                <p>Таб. <?= htmlspecialchars($m['tabel_number']) ?></p>
                <div class="metric-sub">👆 Клик для просмотра сотрудников</div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (isset($show_manager_summary)): ?>
    <a href="team.php?level=territory&id=<?=$selected_id?>&date_from=<?=$date_from?>&date_to=<?=$date_to?>" class="back-link">← Назад к начальникам</a>
    <?php renderMetricsGrid($manager_metrics, '👔 Сводка по команде', $days_passed); ?>
<?php endif; ?>

<div class="team-dashboard">
    <h3>📅 Дашборд команды (ТЭ+Смарт+ПОС)</h3>
    <form method="get" class="team-period-form" action="team.php">
        <?php if (!empty($_GET['level'])) echo '<input type="hidden" name="level" value="'.htmlspecialchars($_GET['level']).'">'; ?>
        <?php if (!empty($_GET['id'])) echo '<input type="hidden" name="id" value="'.htmlspecialchars($_GET['id']).'">'; ?>
        <input type="date" name="date_from" value="<?= $date_from ?>">
        <input type="date" name="date_to" value="<?= $date_to ?>">
        <button type="submit">Показать</button>
    </form>
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
                        <td><?= $row['daily'][$date_str] ?: '' ?></td>
                    <?php endforeach; ?>
                    <td><strong><?= $row['total'] ?></strong></td>
                    <td><?= $row['plan'] ?></td>
                    <td><?= $row['forecast'] ?></td>
                    <td style="color:<?= $row['gap'] < 0 ? 'green' : 'red' ?>"><?= $row['gap'] ?></td>
                    <td><?= $row['daily_target'] ?></td>
                    <td><input type="number" min="0" value="<?= $row['expected'] ?>" data-tab="<?= $row['tab'] ?>" class="expected-input"></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!empty($team_rows)): ?>
            <tr style="font-weight:bold; background:#f0f0f0;">
                <td>ИТОГО</th>
                <?php if (!empty($prev_months)): ?>
                    <?php foreach ($prev_months as $ym): ?>
                        <td><?= $prev_month_totals[$ym]['total'] ?? 0 ?></td>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php foreach ($calendar_days as $date_str): ?>
                    <td><?= $daily_totals[$date_str] ?></td>
                <?php endforeach; ?>
                <td><?= $total_period ?></td>
                <td><?= $total_plan ?></td>
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

<?php if (isset($show_table)): ?>
    <?php renderEmployeesTable($employees, $pdo, $passed_work_days, $remaining_work_days, $selected_month, $date_from, $date_to, $total_work_days); ?>
<?php endif; ?>

</div>
<script>
document.querySelectorAll('.col-toggle').forEach(cb => {
    cb.addEventListener('change', function() {
        let visible = [];
        document.querySelectorAll('.col-toggle:checked').forEach(c => visible.push(c.dataset.col));
        const url = new URL(window.location.href);
        url.searchParams.set('cols', visible.join(','));
        window.location.href = url.toString();
    });
});
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
</body></html>