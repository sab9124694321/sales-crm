<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once 'db.php';
require_once 'ai_classify.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['name'];
$user_role = $_SESSION['role'];
$tabel_number = $_SESSION['tabel'];

// --- Количество свободных лидов и моих лидов (только для менеджеров) ---
$free_leads_count = 0;
$my_leads_count = 0;
if (in_array($user_role, ['manager', 'head', 'admin', 'mmb_tp_head'])) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM hunter_leads WHERE status = 'new'");
    $stmt->execute();
    $free_leads_count = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM hunter_leads WHERE manager_id = ? AND status IN ('assigned', 'converted', 'rejected')");
    $stmt->execute([$user_id]);
    $my_leads_count = (int)$stmt->fetchColumn();
}

// --- Функция подсчёта рабочих дней (пн-пт) между двумя датами (включительно) ---
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

// --- Рейтинг пользователя ---
$game = $pdo->prepare("SELECT rank, total_points, level, experience, next_level_exp FROM users WHERE tabel_number = ?");
$game->execute([$tabel_number]);
$g = $game->fetch();
if (!$g) {
    $g = ['rank' => 'Новичок', 'total_points' => 0, 'level' => 1, 'experience' => 0, 'next_level_exp' => 100];
}

// --- Количество доступных квестов ---
$quest_count = 0;
if (in_array($user_role, ['manager', 'head', 'admin', 'mmb_tp_head'])) {
    $qc_group = $pdo->prepare("SELECT COUNT(*) FROM quests q
        WHERE q.is_active = 1
        AND q.type = 'group'
        AND NOT EXISTS (SELECT 1 FROM quest_takers qt WHERE qt.quest_id = q.id AND qt.employee_tabel = ?)");
    $qc_group->execute([$tabel_number]);
    $group_count = $qc_group->fetchColumn();

    $qc_pending = $pdo->prepare("SELECT COUNT(*) FROM quests q
        JOIN quest_takers qt ON q.id = qt.quest_id
        WHERE q.is_active = 1
        AND q.type = 'individual'
        AND qt.employee_tabel = ?
        AND qt.status = 'pending'");
    $qc_pending->execute([$tabel_number]);
    $pending_count = $qc_pending->fetchColumn();

    $quest_count = $group_count + $pending_count;
}

// --- AI-наставник ---
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT recommendation, advice_book, updated_at FROM ai_recommendations WHERE employee_tabel = ?");
$stmt->execute([$tabel_number]);
$rec = $stmt->fetch();

if (!$rec || date('Y-m-d', strtotime($rec['updated_at'])) !== $today) {
    $cat = classifyEmployee($pdo, $tabel_number, 10);
    $stmt = $pdo->prepare("SELECT advice_text FROM ai_advice_cache WHERE category = ? ORDER BY RANDOM() LIMIT 1");
    $stmt->execute([$cat]);
    $advice = $stmt->fetchColumn();
    if (!$advice) $advice = 'Продолжайте в том же духе!';
    $stmt = $pdo->prepare("SELECT book_title, book_author FROM ai_book_cache WHERE category = ? ORDER BY RANDOM() LIMIT 1");
    $stmt->execute([$cat]);
    $book = $stmt->fetch();
    if ($book) {
        $bookText = $book['book_title'] . ($book['book_author'] ? ' | ' . $book['book_author'] : '');
    } else {
        $bookText = 'Спросите у руководителя рекомендацию';
    }
    $pdo->prepare("INSERT OR REPLACE INTO ai_recommendations (employee_tabel, state_number, recommendation, advice_book, updated_at) VALUES (?, ?, ?, ?, datetime('now'))")->execute([$tabel_number, $cat, $advice, $bookText]);
    $rec = ['recommendation' => $advice, 'advice_book' => $bookText];
}

// --- Выбор периода ---
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_month = date('Y-m', strtotime($selected_date));

// --- Генерация мотивационных уведомлений (только для текущего дня) ---
if ($selected_date == date('Y-m-d')) {
    $today_str = date('Y-m-d');
    $check = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_tabel = ? AND date(created_at) = ?");
    $check->execute([$tabel_number, $today_str]);
    if ($check->fetchColumn() == 0) {
        generateMotivationalNotifications($pdo, $tabel_number);
    }
}

function generateMotivationalNotifications($pdo, $tabel) {
    $yesterday = date('Y-m-d', strtotime('-1 weekday'));
    $all = $pdo->query("SELECT tabel_number, full_name FROM users WHERE role = 'manager' AND is_active = 1")->fetchAll();
    if (empty($all)) return;

    $stats = [];
    foreach ($all as $u) {
        $stmt = $pdo->prepare("SELECT calls, contracts, turnover FROM daily_reports WHERE tabel_number = ? AND report_date = ?");
        $stmt->execute([$u['tabel_number'], $yesterday]);
        $row = $stmt->fetch();
        if ($row) {
            $stats[$u['tabel_number']] = [
                'name' => $u['full_name'],
                'calls' => $row['calls'],
                'contracts' => $row['contracts'],
                'turnover' => $row['turnover']
            ];
        }
    }

    if (!isset($stats[$tabel])) return;
    $my = $stats[$tabel];

    $leaders = [];
    foreach (['calls','contracts','turnover'] as $metric) {
        $max = 0;
        $leader = null;
        foreach ($stats as $t => $s) {
            if ($s[$metric] > $max) {
                $max = $s[$metric];
                $leader = $s['name'];
            }
        }
        $leaders[$metric] = ['name' => $leader, 'value' => $max];
    }

    $messages = [];

    if ($leaders['calls']['value'] > 0) {
        if ($my['calls'] == $leaders['calls']['value']) {
            $messages[] = "🔥 Вчера ты был настоящим охотником! Твои {$my['calls']} звонков — лучший результат в команде. Продолжай в том же духе!";
        } else {
            $messages[] = "📞 Вчера по звонкам ты на {$my['calls']}, а лучший результат у {$leaders['calls']['name']} — {$leaders['calls']['value']}. Ты можешь его догнать, я верю!";
        }
    }

    if ($leaders['contracts']['value'] > 0) {
        if ($my['contracts'] == $leaders['contracts']['value']) {
            $messages[] = "💰 Поздравляю! Вчера ты заключил {$my['contracts']} договоров — это рекорд дня. Ты настоящий мастер переговоров!";
        } else {
            $messages[] = "📄 Вчера ты заключил {$my['contracts']} договоров. Лидер — {$leaders['contracts']['name']} с {$leaders['contracts']['value']}. Но каждый день — новый шанс быть первым!";
        }
    }

    if ($leaders['turnover']['value'] > 0) {
        $my_turnover = number_format($my['turnover'], 0, '.', ' ');
        $lead_turnover = number_format($leaders['turnover']['value'], 0, '.', ' ');
        if ($my['turnover'] == $leaders['turnover']['value']) {
            $messages[] = "🍵 Твой оборот чаевых вчера {$my_turnover} ₽ — это просто бомба! Ты лучший, и это заслуженно.";
        } else {
            $messages[] = "💸 Вчера оборот чаевых у тебя {$my_turnover} ₽. А у {$leaders['turnover']['name']} — {$lead_turnover} ₽. Ничего, ты ещё покажешь класс!";
        }
    }

    if (count($messages) < 5) {
        $messages[] = "⭐ Твой труд вчера не остался незамеченным! Каждый звонок приближает тебя к большой цели. У тебя всё получится!";
    }
    if (count($messages) < 5) {
        $messages[] = "🚀 Вчера был отличный день! Сегодня ты можешь сделать ещё больше. Я в тебя верю!";
    }

    $insert = $pdo->prepare("INSERT INTO notifications (user_tabel, message, type) VALUES (?, ?, 'motivation')");
    $count = 0;
    foreach ($messages as $msg) {
        if ($count >= 5) break;
        $insert->execute([$tabel, $msg]);
        $count++;
    }
}

// -------- ОСНОВНЫЕ ДАННЫЕ (с учётом выбранной даты и РАБОЧИХ дней) --------
$stmt = $pdo->prepare("SELECT * FROM plans WHERE tabel_number = ? AND period = ?");
$stmt->execute([$tabel_number, $selected_month]);
$plans = $stmt->fetch();
if (!$plans) {
    $plans = [
        'calls_plan'=>350, 'calls_answered_plan'=>245, 'meetings_plan'=>35,
        'contracts_plan'=>21, 'registrations_plan'=>15, 'smart_cash_plan'=>10,
        'pos_systems_plan'=>5, 'inn_leads_plan'=>5, 'teams_plan'=>3, 'turnover_plan'=>1500000,
        'rko_plan'=>0
    ];
}

$month_start = date('Y-m-01', strtotime($selected_date));
$days_passed = getWorkingDaysCount($month_start, $selected_date);
$work_days_total = getWorkingDaysCount($month_start, date('Y-m-t', strtotime($selected_date)));
$days_left = max(0, $work_days_total - $days_passed);

$stmt = $pdo->prepare("SELECT 
    SUM(calls) as calls_fact, SUM(calls_answered) as calls_answered_fact,
    SUM(meetings) as meetings_fact, SUM(contracts) as contracts_fact,
    SUM(registrations) as registrations_fact, SUM(smart_cash) as smart_cash_fact,
    SUM(pos_systems) as pos_systems_fact, SUM(inn_leads) as inn_leads_fact,
    SUM(teams) as teams_fact, SUM(turnover) as turnover_fact,
    SUM(rko) as rko_fact, SUM(ai_calls) as ai_calls_fact
    FROM daily_reports WHERE user_id = ? AND strftime('%Y-%m', report_date) = ?");
$stmt->execute([$user_id, $selected_month]);
$facts = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$facts) $facts = array_fill_keys(['calls_fact','calls_answered_fact','meetings_fact','contracts_fact','registrations_fact','smart_cash_fact','pos_systems_fact','inn_leads_fact','teams_fact','turnover_fact','rko_fact','ai_calls_fact'], 0);

function calc($p, $f, $dp, $dl, $wd) {
    if ($p<=0) return ['f'=>$p,'d'=>0,'s'=>'none'];
    $ideal = ceil($p / max(1, $wd));
    $should = $ideal * $dp;
    $dev = $should - $f;
    if ($dev > 0 && $dl > 0) $daily = ceil(($p - $f) / $dl);
    else $daily = $ideal;
    $forecast = ($dp > 0 && $f > 0) ? round($f + ($f / max(1, $dp)) * $dl) : $p;
    if ($f >= $p) $status = 'success';
    elseif ($forecast >= $p) $status = 'warning';
    else $status = 'danger';
    return ['f'=>$forecast, 'd'=>$daily, 's'=>$status];
}

$today_data = [];
$stmt = $pdo->prepare("SELECT 
    calls, calls_answered, meetings, contracts, registrations, smart_cash, pos_systems, inn_leads, teams, turnover, rko, ai_calls
    FROM daily_reports WHERE user_id = ? AND report_date = ?");
$stmt->execute([$user_id, $selected_date]);
$today_data = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$today_data) {
    $today_data = [
        'calls'=>0, 'calls_answered'=>0, 'meetings'=>0, 'contracts'=>0,
        'registrations'=>0, 'smart_cash'=>0, 'pos_systems'=>0, 'inn_leads'=>0,
        'teams'=>0, 'turnover'=>0, 'rko'=>0, 'ai_calls'=>0
    ];
}

$metrics = [
    'calls' => ['fact'=>$facts['calls_fact'], 'plan'=>$plans['calls_plan'], 'label'=>'Звонки', 'icon'=>'📞', 'unit'=>''],
    'calls_answered' => ['fact'=>$facts['calls_answered_fact'], 'plan'=>$plans['calls_answered_plan'], 'label'=>'Дозвоны', 'icon'=>'✅', 'unit'=>'', 'ai_calls'=>$facts['ai_calls_fact']],
    'meetings' => ['fact'=>$facts['meetings_fact'], 'plan'=>$plans['meetings_plan'], 'label'=>'Встречи', 'icon'=>'🤝', 'unit'=>''],
    'contracts' => ['fact'=>$facts['contracts_fact'], 'plan'=>$plans['contracts_plan'], 'label'=>'Договоры', 'icon'=>'📄', 'unit'=>''],
    'turnover' => ['fact'=>$facts['turnover_fact'], 'plan'=>$plans['turnover_plan'], 'label'=>'Оборот чаевых', 'icon'=>'💰', 'unit'=>'₽'],
    'registrations' => ['fact'=>$facts['registrations_fact'], 'plan'=>$plans['registrations_plan'], 'label'=>'ТЭ', 'icon'=>'📝', 'unit'=>''],
    'pos_systems' => ['fact'=>$facts['pos_systems_fact'], 'plan'=>$plans['pos_systems_plan'], 'label'=>'ПОС', 'icon'=>'🖥️', 'unit'=>''],
    'smart_cash' => ['fact'=>$facts['smart_cash_fact'], 'plan'=>$plans['smart_cash_plan'], 'label'=>'Смарт', 'icon'=>'💳', 'unit'=>''],
    'inn_leads' => ['fact'=>$facts['inn_leads_fact'], 'plan'=>$plans['inn_leads_plan'], 'label'=>'ИНН чаевые', 'icon'=>'🍵', 'unit'=>''],
    'rko' => ['fact'=>$facts['rko_fact'], 'plan'=>$plans['rko_plan'], 'label'=>'РКО', 'icon'=>'🏦', 'unit'=>'₽']
];
foreach ($metrics as $k=>$m) {
    $calc = calc($m['plan'], $m['fact'], $days_passed, $days_left, $work_days_total);
    $metrics[$k]['forecast'] = $calc['f'];
    $metrics[$k]['daily_target'] = $calc['d'];
    $metrics[$k]['status'] = $calc['s'];
    $metrics[$k]['percent'] = $m['plan']>0 ? round(($m['fact']/$m['plan'])*100) : 0;
}

$history = $pdo->prepare("SELECT report_date, calls, calls_answered, meetings, contracts, registrations, smart_cash, pos_systems, inn_leads, teams, turnover, rko, ai_calls FROM daily_reports WHERE user_id = ? ORDER BY report_date DESC LIMIT 30");
$history->execute([$user_id]);
$history_rows = $history->fetchAll();

// Непрочитанные уведомления
$motivNotifications = [];
if ($selected_date == date('Y-m-d')) {
    $notif_stmt = $pdo->prepare("SELECT id, message FROM notifications WHERE user_tabel = ? AND is_read = 0 AND type = 'motivation' AND date(created_at) = ? ORDER BY id DESC LIMIT 5");
    $notif_stmt->execute([$tabel_number, $selected_date]);
    $motivNotifications = $notif_stmt->fetchAll();
}

// ---------- ДАШБОРД КОМАНДЫ (ТЭ+Смарт+ПОС) – с учётом рабочих дней ----------
$team_from = $_GET['team_from'] ?? date('Y-m-01');
$team_to   = $_GET['team_to']   ?? date('Y-m-d');
$tomorrow   = date('Y-m-d', strtotime('+1 day'));

// Формирование списка сотрудников для дашборда команды
if ($user_role == 'head') {
    $stmt = $pdo->prepare("SELECT id, full_name, tabel_number FROM users WHERE is_active = 1 AND role IN ('manager', 'mmb_manager', 'ubr_middle') AND manager_id = ? ORDER BY full_name");
    $stmt->execute([$user_id]);
    $team_members = $stmt->fetchAll();
} elseif ($user_role == 'mmb_tp_head') {
    $stmt = $pdo->prepare("SELECT id, full_name, tabel_number FROM users WHERE is_active = 1 AND role IN ('mmb_manager', 'manager') AND manager_id = ? ORDER BY full_name");
    $stmt->execute([$user_id]);
    $team_members = $stmt->fetchAll();
} elseif (in_array($user_role, ['manager', 'mmb_manager', 'ubr_middle'])) {
    $stmt = $pdo->prepare("SELECT manager_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $my_manager_id = $stmt->fetchColumn();
    if ($my_manager_id) {
        $stmt = $pdo->prepare("SELECT id, full_name, tabel_number FROM users WHERE is_active = 1 AND manager_id = ? ORDER BY full_name");
        $stmt->execute([$my_manager_id]);
        $team_members = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT id, full_name, tabel_number FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $team_members = $stmt->fetchAll();
    }
} else {
    $team_members = $pdo->query("SELECT id, full_name, tabel_number FROM users WHERE (role = 'manager' OR role = 'mmb_manager' OR role = 'ubr_middle') AND is_active = 1 ORDER BY full_name")->fetchAll();
}

// Все календарные дни месяца (для шапки таблицы)
$team_month = date('m', strtotime($team_to));
$team_year = date('Y', strtotime($team_to));
$days_in_month = (int)date('t', strtotime("$team_year-$team_month-01"));
$calendar_days = [];
for ($d = 1; $d <= $days_in_month; $d++) {
    $calendar_days[] = sprintf('%04d-%02d-%02d', $team_year, $team_month, $d);
}

// Функция проверки рабочего дня
function isWorkingDay($date) { return date('N', strtotime($date)) < 6; }

// Расчёт рабочих дней через уже существующую функцию getWorkingDaysCount
$month_start = date('Y-m-01', strtotime($team_to));
$total_work_days = getWorkingDaysCount($month_start, date('Y-m-t', strtotime($team_to)));
$passed_work_days = getWorkingDaysCount($month_start, $team_to);
$remaining_work_days = getWorkingDaysCount(date('Y-m-d', strtotime($team_to . ' +1 day')), date('Y-m-t', strtotime($team_to)));

// Предыдущие месяцы (если период захватывает)
$prev_months = [];
$period_start = new DateTime($team_from);
$team_month_start = new DateTime(date('Y-m-01', strtotime($team_to)));
if ($period_start < $team_month_start) {
    $interval = DateInterval::createFromDateString('1 month');
    $period = new DatePeriod($period_start, $interval, $team_month_start);
    foreach ($period as $dt) {
        $prev_months[] = $dt->format('Y-m');
    }
}

$team_rows = [];
$daily_totals = array_fill_keys($calendar_days, 0);
$prev_month_totals = [];
$total_period = 0;
$total_plan = 0;
$total_fact_work = 0;

foreach ($team_members as $m) {
    $daily_vals = [];
    $member_total_period = 0;
    $member_total_work = 0;

    foreach ($calendar_days as $date_str) {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(registrations),0) + COALESCE(SUM(smart_cash),0) + COALESCE(SUM(pos_systems),0) FROM daily_reports WHERE user_id = ? AND report_date = ?");
        $stmt->execute([$m['id'], $date_str]);
        $val = (int)$stmt->fetchColumn();
        $daily_vals[$date_str] = $val;
        if ($date_str >= $team_from && $date_str <= $team_to) {
            $member_total_period += $val;
        }
        if (isWorkingDay($date_str) && $date_str <= $team_to) {
            $member_total_work += $val;
        }
        $daily_totals[$date_str] += $val;
    }

    $prev_data = [];
    foreach ($prev_months as $ym) {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(registrations),0) + COALESCE(SUM(smart_cash),0) + COALESCE(SUM(pos_systems),0) FROM daily_reports WHERE user_id = ? AND strftime('%Y-%m', report_date) = ? AND report_date BETWEEN ? AND ?");
        $stmt->execute([$m['id'], $ym, $team_from, $team_to]);
        $val = (int)$stmt->fetchColumn();
        $prev_data[$ym] = $val;
        $member_total_period += $val;
        if (!isset($prev_month_totals[$ym])) {
            $prev_month_totals[$ym] = ['total' => 0, 'members' => []];
        }
        $prev_month_totals[$ym]['total'] += $val;
    }

    $plan_stmt = $pdo->prepare("SELECT COALESCE(registrations_plan,0) + COALESCE(smart_cash_plan,0) + COALESCE(pos_systems_plan,0) AS total_plan FROM plans WHERE tabel_number = ? AND period = ?");
    $plan_stmt->execute([$m['tabel_number'], date('Y-m')]);
    $plan = $plan_stmt->fetchColumn() ?: 0;
    $total_plan += $plan;
    $total_fact_work += $member_total_work;

    $avg_per_work_day = ($passed_work_days > 0) ? $member_total_work / $passed_work_days : 0;
    $forecast = round($avg_per_work_day * $total_work_days);
    $gap = max(0, $plan - $member_total_work);
    $daily_target = ($remaining_work_days > 0 && $gap > 0) ? ceil($gap / $remaining_work_days) : 0;

    $exp_stmt = $pdo->prepare("SELECT expected_calls FROM daily_forecasts WHERE tabel_number = ? AND forecast_date = ?");
    $exp_stmt->execute([$m['tabel_number'], $tomorrow]);
    $expected = $exp_stmt->fetchColumn();

    $team_rows[] = [
        'name' => $m['full_name'],
        'tab' => $m['tabel_number'],
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
<html><head><meta charset="UTF-8"><title>📊 Дашборд</title><meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=yes"><style>
*{margin:0;padding:0;box-sizing:border-box}body{background:#f0f2f5;font-family:system-ui;padding:12px}.container{max-width:1400px;margin:0 auto}.navbar{background:linear-gradient(135deg,#1a1a2e,#16213e);color:#fff;padding:12px 16px;border-radius:16px;margin-bottom:20px;display:flex;justify-content:space-between;flex-wrap:wrap;align-items:center}.logo{font-size:1.3rem;font-weight:bold}.nav-links{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.nav-links a{color:#ccc;text-decoration:none;font-size:0.85rem}.nav-links .user-info{color:#fff;font-weight:bold;margin-left:auto;font-size:0.9rem}.date-form{display:flex;gap:8px;align-items:center;margin-left:12px}.date-form input[type="date"]{padding:5px 8px;border-radius:8px;border:none;font-size:0.85rem}.date-form button{background:#fff;color:#1a1a2e;border:none;padding:5px 12px;border-radius:8px;font-weight:bold;cursor:pointer;font-size:0.85rem}.rank-card{background:linear-gradient(135deg,#f5af19,#f12711);color:#fff;padding:10px 16px;border-radius:16px;margin-bottom:20px;display:flex;justify-content:space-between;font-weight:bold}.ai-card{background:#e6f7ff;border-left:5px solid #1890ff;padding:12px 16px;border-radius:12px;margin-bottom:20px;display:flex;align-items:center;gap:12px}.notif-card{background:#fff3e0;border-left:5px solid #ff9800;padding:12px 16px;border-radius:12px;margin-bottom:20px;display:flex;flex-direction:column;gap:8px}.notif-item{display:flex;justify-content:space-between;align-items:center;gap:12px;font-size:0.9rem}.notif-text{flex:1}.notif-close{background:none;border:none;color:#888;cursor:pointer;font-size:1.2rem;line-height:1}.read-all-btn{background:#ff9800;color:#fff;border:none;padding:6px 14px;border-radius:16px;cursor:pointer;font-size:0.8rem;font-weight:bold;align-self:flex-end}.quest-card{background:#f0f9ff;border-left:5px solid #7c3aed;padding:12px 16px;border-radius:12px;margin-bottom:20px;display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;cursor:pointer}.leads-card{background:#f0f4ff;border-left:5px solid #1a73e8;padding:12px 16px;border-radius:12px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center}.leads-card a{background:#1a73e8;color:#fff;padding:8px 16px;border-radius:20px;text-decoration:none;font-weight:bold}.metrics-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;margin-bottom:30px}.metric-card{background:#fff;border-radius:16px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,0.05)}.metric-header{display:flex;justify-content:space-between;margin-bottom:8px;font-size:0.8rem;color:#555}.metric-title{font-weight:600}.metric-value-row{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px}.metric-value{font-size:1.9rem;font-weight:800;line-height:1.2}.metric-value.success{color:#2e7d32}.metric-value.warning{color:#ed6c02}.metric-value.danger{color:#d32f2f}.metric-daily-target{font-size:1.9rem;font-weight:800;color:#1a1a2e}.metric-sub{font-size:0.7rem;color:#666;margin-top:6px}.metric-plan-fact{font-size:0.7rem;color:#555;margin-top:4px;display:flex;justify-content:space-between}.progress-bar{background:#e0e0e0;border-radius:10px;height:6px;margin-top:8px;overflow:hidden}.progress-fill{height:100%;border-radius:10px}.progress-fill.success{background:#2e7d32}.progress-fill.warning{background:#ed6c02}.progress-fill.danger{background:#d32f2f}.report-form{background:#fff;border-radius:16px;padding:20px;margin-bottom:30px}.form-row{display:flex;flex-wrap:wrap;gap:16px;margin-bottom:20px}.form-group{flex:1;min-width:140px}.form-group label{font-size:0.7rem;font-weight:600;color:#444;display:block;margin-bottom:4px}.form-group input{width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:12px;font-size:1rem}.inn-group{display:flex;gap:8px;margin-top:6px;align-items:center}.inn-group input{flex:2;padding:10px 8px}.inn-group select{flex:1;padding:10px 4px}.add-btn{background:#28a745;color:#fff;border:none;padding:10px 16px;border-radius:12px;cursor:pointer;font-size:0.85rem;white-space:nowrap;font-weight:bold}.readonly-input{background:#f5f5f5;cursor:default}.save-btn{background:#1890ff;color:#fff;border:none;padding:12px 20px;border-radius:30px;cursor:pointer;font-size:1rem;width:100%}.history-table{overflow-x:auto}table{width:100%;background:#fff;border-radius:16px;border-collapse:collapse}th,td{padding:10px 6px;text-align:center;border-bottom:1px solid #eee;font-size:0.75rem}th{background:#f8f9fa}.edit-link{color:#1a73e8;cursor:pointer;font-size:0.85rem;text-decoration:underline}.team-dashboard { margin-bottom:30px; overflow-x:auto; background:#fff; border-radius:16px; padding:16px; }
.team-dashboard h3 { margin-bottom:15px; }
.team-dashboard table { font-size:0.7rem; width:100%; border-collapse:collapse; }
.team-dashboard th,.team-dashboard td { padding:6px 4px; border:1px solid #eee; text-align:center; }
.team-dashboard th { background:#f8f9fa; }
.weekend { background-color:#f5f5f5; }
.team-period-form { display:flex; gap:10px; margin-bottom:12px; align-items:center; flex-wrap:wrap; }
.team-period-form input[type="date"] { padding:5px 8px; border-radius:8px; border:1px solid #ccc; font-size:0.85rem; }
.team-period-form button { background:#1a73e8; color:#fff; border:none; padding:6px 12px; border-radius:8px; cursor:pointer; font-size:0.85rem; }
.expected-input { width:55px; padding:2px; font-size:0.7rem; }
@media (max-width:640px){.metrics-grid{grid-template-columns:1fr}.metric-value{font-size:1.6rem}.metric-daily-target{font-size:1.6rem}.navbar{flex-direction:column;gap:8px;align-items:stretch}.form-row{flex-direction:column;gap:12px}}
</style></head>
<body><div class="container"><div class="navbar">
<div class="logo">🚀 SZB</div>
<div class="nav-links">
    <a href="dashboard.php">Дашборд</a>
    <a href="team.php">Команда</a>
    <a href="territories.php">Территории</a>
    <a href="export_inn.php">ИНН</a>
    <?php if (in_array($user_role, ['manager', 'head', 'admin', 'mmb_tp_head'])): ?>
    <a href="leads.php">📋 Лиды <?php if ($free_leads_count > 0 && $user_role == 'manager'): ?><span style="background:#ff6b6b; color:#fff; border-radius:10px; padding:0 6px; font-size:0.7rem;"><?= $free_leads_count ?></span><?php endif; ?></a>
    <?php endif; ?>
    <a href="quests.php">Квесты</a>
    <a href="calls.php">📞 Я звоню</a>
    <a href="ai_dashboard.php">AI</a>
    <?php if ($user_role=='admin'): ?>
        <a href="admin.php">Админ</a>
        <a href="bot_settings.php">🤖 Бот</a>
    <?php endif; ?>
    <?php if (in_array($user_role, ['mmb_manager', 'mmb_tp_head'])): ?>
        <a href="mmb_dashboard.php">🆘 Поддержка ММБ</a>
    <?php endif; ?>
    <?php if ($user_role == 'ubr_middle'): ?>
        <a href="ubr_dashboard.php">🛠️ Обращения УБР</a>
    <?php endif; ?>
    <?php if ($user_role == 'head' || $user_role == 'admin'): ?>
        <a href="support_settings.php">⚙️ Настройки поддержки</a>
        <a href="ubr_stats_dashboard.php">📊 Отчёты УБР</a>
    <?php endif; ?>
    <?php if ($user_role == 'mmb_tp_head'): ?>
        <a href="mmb_head_dashboard.php">📈 Отчёт ММБ</a>
    <?php endif; ?>
    <a href="logout.php">Выйти</a>
    <span class="user-info">👤 <?= htmlspecialchars($user_name) ?></span>
    <form class="date-form" method="GET" action="dashboard.php">
        <input type="date" name="date" value="<?= $selected_date ?>">
        <button type="submit">📅 Смотреть</button>
    </form>
</div>
</div>

<!-- Динамический рейтинг -->
<div class="rank-card">
    <div>🏆 <?= htmlspecialchars($g['rank']) ?> | Ур. <?= $g['level'] ?></div>
    <div>⭐ <?= number_format($g['total_points'], 0, '.', ' ') ?></div>
</div>

<!-- AI-наставник -->
<div class="ai-card">
    <div style="font-size:2rem">🤖</div>
    <div style="flex:1;">
        <strong>AI Наставник</strong><br>
        <?= htmlspecialchars($rec['recommendation'] ?? '') ?>
        <?php if (!empty($rec['advice_book'])): ?>
            <br><small style="color:#555;">📚 <?= htmlspecialchars($rec['advice_book'] ?? '') ?></small>
            <button onclick="markBookRead()" style="margin-left:10px; background:none; border:1px solid #1a73e8; color:#1a73e8; border-radius:10px; padding:2px 8px; cursor:pointer; font-size:0.8rem;">Прочитал</button>
        <?php endif; ?>
    </div>
</div>

<!-- Лиды (для менеджеров и руководителей) -->
<?php if (in_array($user_role, ['manager', 'head', 'admin', 'mmb_tp_head'])): ?>
<div class="leads-card">
    <div>
        <strong>📋 Лиды</strong><br>
        <span style="font-size:0.9rem;">Свободно: <strong><?= $free_leads_count ?></strong> | В работе: <strong><?= $my_leads_count ?></strong></span>
    </div>
    <a href="leads.php">Перейти</a>
</div>
<?php endif; ?>

<!-- Квесты (только для менеджеров) -->
<?php if ($user_role == 'manager'): ?>
<a href="quests.php" class="quest-card">
    <div style="font-size:2rem;">🎯</div>
    <div>
        <strong>Квесты</strong><br>
        Доступно квестов: <span style="font-weight:bold; color:#7c3aed;"><?= $quest_count ?></span>
    </div>
</a>
<?php endif; ?>

<?php if (!empty($motivNotifications)): ?>
<div id="motiv-block" class="notif-card">
  <div style="display:flex; justify-content:space-between; align-items:center;">
    <strong>🔥 Вчерашние результаты</strong>
    <button class="notif-close" onclick="markAllRead()" title="Прочитано всё">✖</button>
  </div>
  <?php foreach ($motivNotifications as $n): ?>
    <div class="notif-item">
      <span class="notif-text"><?= htmlspecialchars($n['message']) ?></span>
      <button class="notif-close" onclick="markOneRead(<?= $n['id'] ?>, this)">✕</button>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="metrics-grid">
<?php foreach ($metrics as $k => $m): 
    $today_value = $today_data[$k] ?? 0;
    $avg_per_day = $days_passed > 0 ? round($m['fact'] / $days_passed, 1) : 0;
    if ($k == 'turnover' || $k == 'rko') {
        $avg_per_day_formatted = number_format($avg_per_day, 2, '.', ' ');
        $today_value_formatted = number_format((float)$today_value, 0, '.', ' ');
        $plan_formatted = number_format((float)$m['plan'], 0, '.', ' ');
        $fact_formatted = number_format((float)$m['fact'], 0, '.', ' ');
    } else {
        $avg_per_day_formatted = number_format($avg_per_day, 1, '.', ' ');
        $today_value_formatted = number_format((float)$today_value, 0, '.', ' ');
        $plan_formatted = number_format((float)$m['plan'], 0, '.', ' ');
        $fact_formatted = number_format((float)$m['fact'], 0, '.', ' ');
    }
?>
<div class="metric-card">
    <div class="metric-header"><span class="metric-title"><?= $m['icon'] ?> <?= $m['label'] ?></span><span><?= $m['percent'] ?>%</span></div>
    <div class="metric-value-row">
        <span class="metric-value <?= $m['status'] ?>"><?= $today_value_formatted ?></span>
        <span class="metric-daily-target"><?= number_format((float)$m['daily_target'], 0, '.', ' ') ?></span>
    </div>
    <div class="progress-bar"><div class="progress-fill <?= $m['status'] ?>" style="width:<?= min(100,$m['percent']) ?>%"></div></div>
    <div class="metric-plan-fact">
        <span>📊 План: <?= $plan_formatted ?> <?= $m['unit'] ?></span>
        <span>✅ Факт: <?= $fact_formatted ?> <?= $m['unit'] ?></span>
    </div>
    <div class="metric-plan-fact" style="margin-top:2px;">
        <span>📈 Среднее за день: <?= $avg_per_day_formatted ?> <?= $m['unit'] ?></span>
    </div>
    <?php if ($k == 'calls_answered'): ?>
        <div class="metric-sub" style="color:#0d6efd;">🤖 AI-звонки: <?= $today_data['ai_calls'] ?? 0 ?></div>
    <?php endif; ?>
    <div class="metric-sub">📅 Прогноз: <?= number_format((float)$m['forecast'], 0, '.', ' ') ?> <?= $m['unit'] ?></div>
</div>
<?php endforeach; ?>
</div>

<form method="POST" action="save_report.php">
<div class="report-form">
    <h3>📝 Отчёт за <?= date('d.m.Y', strtotime($selected_date)) ?></h3>
    <input type="hidden" name="edit_date" value="<?= $selected_date ?>">
    <div class="form-row">
        <div class="form-group"><label>📞 Звонки</label><input type="number" name="calls" value="<?= $today_data['calls']??0 ?>"></div>
        <div class="form-group"><label>✅ Дозвоны</label><input type="number" name="calls_answered" value="<?= $today_data['calls_answered']??0 ?>"></div>
        <div class="form-group"><label>🤝 Встречи</label><input type="number" name="meetings" value="<?= $today_data['meetings']??0 ?>"></div>
        <div class="form-group"><label>📄 Договоры</label><input type="number" name="contracts" value="<?= $today_data['contracts']??0 ?>"></div>
        <div class="form-group"><label>💰 Оборот чаевых</label><input type="number" name="turnover" value="<?= $today_data['turnover']??0 ?>"></div>
        <div class="form-group"><label>🏦 РКО</label><input type="number" name="rko" value="<?= $today_data['rko']??0 ?>"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label>📝 ТЭ</label><input type="text" name="registrations_display" id="reg_display" class="readonly-input" readonly value="<?= $today_data['registrations']??0 ?>"><input type="hidden" name="registrations" id="reg_val" value="<?= $today_data['registrations']??0 ?>">
            <div class="inn-group"><input type="text" id="inn_reg" placeholder="ИНН"><select id="prod_reg"><option>ТЭ</option></select><button type="button" class="add-btn" onclick="addInn('reg')">+1</button></div>
        </div>
        <div class="form-group"><label>🖥️ ПОС</label><input type="text" name="pos_systems_display" id="pos_display" class="readonly-input" readonly value="<?= $today_data['pos_systems']??0 ?>"><input type="hidden" name="pos_systems" id="pos_val" value="<?= $today_data['pos_systems']??0 ?>">
            <div class="inn-group"><input type="text" id="inn_pos" placeholder="ИНН"><select id="prod_pos"><option>ПОС</option></select><button type="button" class="add-btn" onclick="addInn('pos')">+1</button></div>
        </div>
        <div class="form-group"><label>💳 Смарт</label><input type="text" name="smart_cash_display" id="smart_display" class="readonly-input" readonly value="<?= $today_data['smart_cash']??0 ?>"><input type="hidden" name="smart_cash" id="smart_val" value="<?= $today_data['smart_cash']??0 ?>">
            <div class="inn-group"><input type="text" id="inn_smart" placeholder="ИНН"><select id="prod_smart"><option>Смарт</option></select><button type="button" class="add-btn" onclick="addInn('smart')">+1</button></div>
        </div>
        <div class="form-group"><label>🍵 ИНН чаевые</label><input type="text" name="inn_leads_display" id="inn_display" class="readonly-input" readonly value="<?= $today_data['inn_leads']??0 ?>"><input type="hidden" name="inn_leads" id="inn_val" value="<?= $today_data['inn_leads']??0 ?>">
            <div class="inn-group"><input type="text" id="inn_tea" placeholder="ИНН"><select id="prod_tea"><option>Чаевые</option></select><button type="button" class="add-btn" onclick="addInn('tea')">+1</button></div>
        </div>
    </div>
    <button type="submit" class="save-btn">💾 Сохранить</button>
</div>
</form>

<!-- ========== ДАШБОРД КОМАНДЫ (ТЭ+Смарт+ПОС) ========== -->
<div class="team-dashboard">
    <h3>📅 Дашборд команды (ТЭ+Смарт+ПОС)</h3>
    <form method="get" class="team-period-form" action="dashboard.php">
        <label>с</label>
        <input type="date" name="team_from" value="<?= $team_from ?>">
        <label>по</label>
        <input type="date" name="team_to" value="<?= $team_to ?>">
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
                        <th class="<?= isWorkingDay($date_str) ? '' : 'weekend' ?>"><?= date('j', strtotime($date_str)) ?></th>
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
                        <td class="<?= isWorkingDay($date_str) ? '' : 'weekend' ?>"><?= $row['daily'][$date_str] ?: '' ?></td>
                    <?php endforeach; ?>
                    <td><strong><?= $row['total'] ?></strong></td>
                    <td><?= $row['plan'] ?></td>
                    <td><?= $row['forecast'] ?></td>
                    <td style="color:<?= $row['gap'] < 0 ? 'green' : 'red' ?>"><?= $row['gap'] ?></td>
                    <td><?= $row['daily_target'] ?></td>
                    <td><input type="number" min="0" value="<?= $row['expected'] ?>" data-tab="<?= $row['tab'] ?>" class="expected-input"></td>
                </tr>
            <?php endforeach; ?>
            <tr style="font-weight:bold; background:#f0f0f0;">
                <td>ИТОГО</td>
                <?php if (!empty($prev_months)): ?>
                    <?php foreach ($prev_months as $ym): ?>
                        <td><?= $prev_month_totals[$ym]['total'] ?? 0 ?></td>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php foreach ($calendar_days as $date_str): ?>
                    <td class="<?= isWorkingDay($date_str) ? '' : 'weekend' ?>"><?= $daily_totals[$date_str] ?></td>
                <?php endforeach; ?>
                <td><?= $total_period ?></td>
                <td><?= $total_plan ?></td>
                <td><?= round(($total_fact_work / max(1, $passed_work_days)) * $total_work_days) ?></td>
                <td><?= $total_gap ?></td>
                <td><?= $total_daily_target ?></td>
                <td><?= $total_expected ?></td>
            </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="history-table"><h3>📋 История</h3><?php if ($history_rows): ?><table><thead><tr><th>ДАТА</th><th>📞</th><th>✅</th><th>🤝</th><th>📄</th><th>📝</th><th>💳</th><th>🖥️</th><th>🍵</th><th>👥</th><th>💰</th><th>🏦 РКО</th><th></th></tr></thead><tbody><?php foreach ($history_rows as $row): ?><tr>
    <td><?= date('d.m', strtotime($row['report_date'])) ?></td>
    <td><?= $row['calls'] ?></td>
    <td><?= $row['calls_answered'] ?> <?= $row['ai_calls'] ? '<span style="color:#0d6efd;">🤖</span>' : '' ?></td>
    <td><?= $row['meetings'] ?></td>
    <td><?= $row['contracts'] ?></td>
    <td><?= $row['registrations'] ?></td>
    <td><?= $row['smart_cash'] ?></td>
    <td><?= $row['pos_systems'] ?></td>
    <td><?= $row['inn_leads'] ?></td>
    <td><?= $row['teams'] ?></td>
    <td><?= number_format((float)($row['turnover']??0), 0, '.', ' ') ?>₽</td>
    <td><?= number_format((float)($row['rko']??0), 0, '.', ' ') ?></td>
    <td><span class="edit-link" onclick="editReport('<?= $row['report_date'] ?>', <?= $row['calls'] ?>, <?= $row['calls_answered'] ?>, <?= $row['meetings'] ?>, <?= $row['contracts'] ?>, <?= $row['registrations'] ?>, <?= $row['smart_cash'] ?>, <?= $row['pos_systems'] ?>, <?= $row['inn_leads'] ?>, <?= $row['teams'] ?>, <?= $row['turnover'] ?>, <?= $row['rko'] ?? 0 ?>)">✏️</span></td>
            </tr><?php endforeach; ?></tbody></table><?php else: ?><p>Нет отчётов</p><?php endif; ?></div></div>

<!-- Модальное окно редактирования -->
<div id="editModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000;">
  <div style="background:#fff; border-radius:16px; padding:24px; max-width:600px; margin:60px auto; box-shadow:0 8px 24px rgba(0,0,0,0.2);">
    <h3 style="margin-top:0">✏️ Редактирование отчёта</h3>
    <form method="POST" action="save_report.php" style="margin-top:16px;">
      <input type="hidden" name="edit_date" id="edit_date">
      <div class="form-row" style="margin-bottom:12px;">
        <div class="form-group"><label>📞 Звонки</label><input type="number" id="edit_calls" name="calls" min="0"></div>
        <div class="form-group"><label>✅ Дозвоны</label><input type="number" id="edit_answered" name="calls_answered" min="0"></div>
        <div class="form-group"><label>🤝 Встречи</label><input type="number" id="edit_meetings" name="meetings" min="0"></div>
        <div class="form-group"><label>📄 Договоры</label><input type="number" id="edit_contracts" name="contracts" min="0"></div>
      </div>
      <div class="form-row" style="margin-bottom:12px;">
        <div class="form-group"><label>📝 ТЭ</label><input type="number" id="edit_reg" name="registrations" min="0"></div>
        <div class="form-group"><label>💳 Смарт</label><input type="number" id="edit_smart" name="smart_cash" min="0"></div>
        <div class="form-group"><label>🖥️ ПОС</label><input type="number" id="edit_pos" name="pos_systems" min="0"></div>
        <div class="form-group"><label>🍵 ИНН чаевые</label><input type="number" id="edit_inn" name="inn_leads" min="0"></div>
      </div>
      <div class="form-row" style="margin-bottom:16px;">
        <div class="form-group"><label>👥 Команды</label><input type="number" id="edit_teams" name="teams" min="0"></div>
        <div class="form-group"><label>💰 Оборот чаевых</label><input type="number" id="edit_turnover" name="turnover" min="0" step="0.01"></div>
        <div class="form-group"><label>🏦 РКО</label><input type="number" id="edit_rko" name="rko" min="0" step="0.01"></div>
      </div>
      <div style="display:flex; gap:12px;">
        <button type="submit" class="save-btn" style="width:auto; padding:10px 24px;">💾 Сохранить</button>
        <button type="button" class="save-btn" style="width:auto; background:#adb5bd; padding:10px 24px;" onclick="document.getElementById('editModal').style.display='none'">Отмена</button>
      </div>
    </form>
  </div>
</div>

<script>
function addInn(type) {
    let inn='', prod='', field='', display='';
    if(type==='reg'){ inn=document.getElementById('inn_reg').value; prod=document.getElementById('prod_reg').value; field='reg_val'; display='reg_display'; }
    else if(type==='pos'){ inn=document.getElementById('inn_pos').value; prod=document.getElementById('prod_pos').value; field='pos_val'; display='pos_display'; }
    else if(type==='smart'){ inn=document.getElementById('inn_smart').value; prod=document.getElementById('prod_smart').value; field='smart_val'; display='smart_display'; }
    else if(type==='tea'){ inn=document.getElementById('inn_tea').value; prod=document.getElementById('prod_tea').value; field='inn_val'; display='inn_display'; }
    if(!inn){ alert('Введите ИНН'); return; }
    fetch('/api_add_inn.php',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({inn:inn, product:prod, field_type:type})
    })
    .then(r=>r.json())
    .then(d=>{
        if(d.success){
            let input = document.getElementById(field);
            let disp = document.getElementById(display);
            if(input && disp){
                let newVal = (parseInt(input.value)||0) + 1;
                input.value = newVal;
                disp.value = newVal;
            }
            document.getElementById('inn_'+type).value='';
            alert('✅ Добавлено');
        } else alert('Ошибка: '+d.error);
    })
    .catch(e=>alert('Ошибка соединения'));
}

function editReport(date, c, a, m, ct, r, s, p, i, t, tr, rko = 0) {
    document.getElementById('edit_date').value = date;
    document.getElementById('edit_calls').value = c;
    document.getElementById('edit_answered').value = a;
    document.getElementById('edit_meetings').value = m;
    document.getElementById('edit_contracts').value = ct;
    document.getElementById('edit_reg').value = r;
    document.getElementById('edit_smart').value = s;
    document.getElementById('edit_pos').value = p;
    document.getElementById('edit_inn').value = i;
    document.getElementById('edit_teams').value = t;
    document.getElementById('edit_turnover').value = tr;
    document.getElementById('edit_rko').value = rko;
    document.getElementById('editModal').style.display = 'block';
}

function markAllRead() {
    fetch('api_mark_all_read.php', {method:'POST'})
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            document.getElementById('motiv-block').style.display = 'none';
        }
    });
}

function markOneRead(notifId, btn) {
    fetch('api_mark_all_read.php?id='+notifId, {method:'POST'})
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            btn.parentNode.style.display = 'none';
            let block = document.getElementById('motiv-block');
            if (block && block.querySelectorAll('.notif-item:not([style*="display: none"])').length === 0) {
                block.style.display = 'none';
            }
        }
    });
}

function markBookRead() {
    fetch('api_mark_book_read.php', {method:'POST'})
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            const bookElement = document.querySelector('.ai-card small');
            if (bookElement) {
                bookElement.textContent = '📚 ' + d.book;
            }
        } else {
            alert('Не удалось получить новую книгу');
        }
    });
}

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
</div></body></html>