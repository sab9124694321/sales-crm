<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once 'db.php';

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$tabel = $_SESSION['tabel'];
$user_name = $_SESSION['name'];

// --- Проверка ролей ---
$is_manager = in_array($role, ['manager', 'ubr_middle', 'mmb_manager']);
$is_head = in_array($role, ['head', 'territory_head', 'admin']);
$is_terman = ($role === 'terman');

if (!$is_manager && !$is_head && !$is_terman) {
    die('Доступ запрещен');
}

// --- Период ---
$filter_month = $_GET['month'] ?? date('Y-m');
$filter_date_from = $_GET['date_from'] ?? date('Y-m-01');
$filter_date_to = $_GET['date_to'] ?? date('Y-m-d');

// --- Фильтры ---
$filter_status = $_GET['status'] ?? 'На проверке';
$filter_territory = $_GET['territory'] ?? '';
$filter_employee_tabel = $_GET['filter_employee_tabel'] ?? '';

// --- Функция получения плана звонков ---
function getCallPlan($pdo, $tabel_num) {
    $stmt = $pdo->prepare("SELECT calls_plan FROM plans WHERE tabel_number = ? AND period = strftime('%Y-%m', 'now')");
    $stmt->execute([$tabel_num]);
    $month_plan = $stmt->fetchColumn() ?: 350;
    $work_days = 22;
    return ceil($month_plan / $work_days);
}

// --- Функция получения статистики сотрудника (расширенная) ---
function getEmployeeStats($pdo, $emp_id, $emp_tabel, $date_from, $date_to) {
    $daily_plan = getCallPlan($pdo, $emp_tabel);

    $stmt = $pdo->prepare("SELECT calls_plan FROM plans WHERE tabel_number = ? AND period = strftime('%Y-%m', 'now')");
    $stmt->execute([$emp_tabel]);
    $month_plan = $stmt->fetchColumn() ?: 350;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM epk_tasks WHERE user_tabel = ?");
    $stmt->execute([$emp_tabel]);
    $total_tasks = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(calls),0) FROM daily_reports WHERE user_id = ? AND report_date BETWEEN ? AND ?");
    $stmt->execute([$emp_id, $date_from, $date_to]);
    $calls_done_period = (int)$stmt->fetchColumn();

    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(calls),0) FROM daily_reports WHERE user_id = ? AND report_date = ?");
    $stmt->execute([$emp_id, $today]);
    $calls_done_today = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_call_comments,
            SUM(CASE WHEN call_result NOT IN ('noanswer', 'nocontact') THEN 1 ELSE 0 END) as effective_calls,
            SUM(CASE WHEN call_result = 'contract' THEN 1 ELSE 0 END) as contract_count,
            SUM(CASE WHEN call_result = 'signed' THEN 1 ELSE 0 END) as signed_count,
            SUM(CASE WHEN call_result = 'reject' THEN 1 ELSE 0 END) as rejects,
            SUM(CASE WHEN call_result = 'think' THEN 1 ELSE 0 END) as thinks,
            SUM(CASE WHEN call_result = 'noanswer' THEN 1 ELSE 0 END) as noanswers,
            SUM(CASE WHEN call_result = 'nocontact' THEN 1 ELSE 0 END) as nocontact_count,
            SUM(CASE WHEN call_result = 'recall' THEN 1 ELSE 0 END) as recall_count
        FROM call_comments 
        WHERE user_id = ? AND date(created_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$emp_id, $date_from, $date_to]);
    $call_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM rop_control_queue WHERE user_id = ? AND date(created_at) BETWEEN ? AND ?");
    $stmt->execute([$emp_id, $date_from, $date_to]);
    $on_control = (int)$stmt->fetchColumn();

    return [
        'daily_plan' => $daily_plan,
        'calls_done_today' => $calls_done_today,
        'calls_done_period' => $calls_done_period,
        'month_plan' => $month_plan,
        'total_tasks' => $total_tasks,
        'effective_calls' => (int)($call_stats['effective_calls'] ?? 0),
        'contract_count' => (int)($call_stats['contract_count'] ?? 0),
        'signed_count' => (int)($call_stats['signed_count'] ?? 0),
        'total_sales' => (int)($call_stats['contract_count'] ?? 0) + (int)($call_stats['signed_count'] ?? 0),
        'rejects' => (int)($call_stats['rejects'] ?? 0),
        'thinks' => (int)($call_stats['thinks'] ?? 0),
        'noanswers' => (int)($call_stats['noanswers'] ?? 0),
        'nocontact_count' => (int)($call_stats['nocontact_count'] ?? 0),
        'recall_count' => (int)($call_stats['recall_count'] ?? 0),
        'on_control' => $on_control
    ];
}

// --- Сбор данных ---
$employees = [];
$territories_data = [];
$employee_filter_options = [];

if ($is_manager) {
    $employees[] = [
        'id' => $user_id,
        'tabel' => $tabel,
        'name' => $user_name,
        'stats' => getEmployeeStats($pdo, $user_id, $tabel, $filter_date_from, $filter_date_to)
    ];
} elseif ($is_head) {
    $stmt = $pdo->prepare("SELECT id, full_name, tabel_number FROM users WHERE is_active = 1 AND role IN ('manager', 'mmb_manager', 'ubr_middle') AND manager_id = ? ORDER BY full_name");
    $stmt->execute([$user_id]);
    $team = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $employee_filter_options = array_map(function($m) {
        return ['tabel' => $m['tabel_number'], 'name' => $m['full_name']];
    }, $team);
    array_unshift($employee_filter_options, ['tabel' => '', 'name' => '— Все —']);

    if (!empty($filter_employee_tabel)) {
        $team = array_filter($team, function($m) use ($filter_employee_tabel) {
            return $m['tabel_number'] === $filter_employee_tabel;
        });
    }

    foreach ($team as $member) {
        $employees[] = [
            'id' => $member['id'],
            'tabel' => $member['tabel_number'],
            'name' => $member['full_name'],
            'stats' => getEmployeeStats($pdo, $member['id'], $member['tabel_number'], $filter_date_from, $filter_date_to)
        ];
    }
} elseif ($is_terman) {
    $stmt = $pdo->query("SELECT id, full_name, tabel_number FROM users WHERE role IN ('manager', 'mmb_manager', 'ubr_middle') AND is_active = 1 ORDER BY full_name");
    $all_managers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all_managers as $m) {
        $employees[] = [
            'id' => $m['id'],
            'tabel' => $m['tabel_number'],
            'name' => $m['full_name'],
            'stats' => getEmployeeStats($pdo, $m['id'], $m['tabel_number'], $filter_date_from, $filter_date_to)
        ];
    }

    $stmt = $pdo->query("SELECT id, name FROM territories WHERE name IS NOT NULL ORDER BY name");
    $territories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($territories as $territory) {
        $territory_heads = [];
        $stmt = $pdo->prepare("SELECT id, full_name, tabel_number FROM users WHERE is_active = 1 AND role = 'head' AND territory_id = ? ORDER BY full_name");
        $stmt->execute([$territory['id']]);
        $territory_head_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($territory_head_list as $head) {
            $team_members = [];
            $stmt = $pdo->prepare("SELECT id, full_name, tabel_number FROM users WHERE is_active = 1 AND role IN ('manager', 'mmb_manager', 'ubr_middle') AND manager_id = ? ORDER BY full_name");
            $stmt->execute([$head['id']]);
            $team = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($team as $member) {
                $team_members[] = [
                    'id' => $member['id'],
                    'tabel' => $member['tabel_number'],
                    'name' => $member['full_name'],
                    'stats' => getEmployeeStats($pdo, $member['id'], $member['tabel_number'], $filter_date_from, $filter_date_to)
                ];
            }

            $team_totals = [
                'daily_plan' => 0, 'calls_done_today' => 0, 'calls_done_period' => 0,
                'month_plan' => 0, 'total_tasks' => 0, 'effective_calls' => 0,
                'contract_count' => 0, 'signed_count' => 0, 'total_sales' => 0,
                'rejects' => 0, 'thinks' => 0, 'noanswers' => 0,
                'nocontact_count' => 0, 'recall_count' => 0, 'on_control' => 0
            ];
            foreach ($team_members as $m) {
                $s = $m['stats'];
                $team_totals['daily_plan'] += $s['daily_plan'];
                $team_totals['calls_done_today'] += $s['calls_done_today'];
                $team_totals['calls_done_period'] += $s['calls_done_period'];
                $team_totals['month_plan'] += $s['month_plan'];
                $team_totals['total_tasks'] += $s['total_tasks'];
                $team_totals['effective_calls'] += $s['effective_calls'];
                $team_totals['contract_count'] += $s['contract_count'];
                $team_totals['signed_count'] += $s['signed_count'];
                $team_totals['total_sales'] += $s['total_sales'];
                $team_totals['rejects'] += $s['rejects'];
                $team_totals['thinks'] += $s['thinks'];
                $team_totals['noanswers'] += $s['noanswers'];
                $team_totals['nocontact_count'] += $s['nocontact_count'];
                $team_totals['recall_count'] += $s['recall_count'];
                $team_totals['on_control'] += $s['on_control'];
            }

            $territory_heads[] = [
                'id' => $head['id'],
                'name' => $head['full_name'],
                'tabel' => $head['tabel_number'],
                'team' => $team_members,
                'team_totals' => $team_totals
            ];
        }

        $territories_data[] = [
            'id' => $territory['id'],
            'name' => $territory['name'],
            'heads' => $territory_heads
        ];
    }
}

// --- Итоги для head ---
$totals = null;
if ($is_head && !empty($employees)) {
    $totals = [
        'daily_plan' => 0, 'calls_done_today' => 0, 'calls_done_period' => 0,
        'month_plan' => 0, 'total_tasks' => 0, 'effective_calls' => 0,
        'contract_count' => 0, 'signed_count' => 0, 'total_sales' => 0,
        'rejects' => 0, 'thinks' => 0, 'noanswers' => 0,
        'nocontact_count' => 0, 'recall_count' => 0, 'on_control' => 0
    ];
    foreach ($employees as $e) {
        $s = $e['stats'];
        $totals['daily_plan'] += $s['daily_plan'];
        $totals['calls_done_today'] += $s['calls_done_today'];
        $totals['calls_done_period'] += $s['calls_done_period'];
        $totals['month_plan'] += $s['month_plan'];
        $totals['total_tasks'] += $s['total_tasks'];
        $totals['effective_calls'] += $s['effective_calls'];
        $totals['contract_count'] += $s['contract_count'];
        $totals['signed_count'] += $s['signed_count'];
        $totals['total_sales'] += $s['total_sales'];
        $totals['rejects'] += $s['rejects'];
        $totals['thinks'] += $s['thinks'];
        $totals['noanswers'] += $s['noanswers'];
        $totals['nocontact_count'] += $s['nocontact_count'];
        $totals['recall_count'] += $s['recall_count'];
        $totals['on_control'] += $s['on_control'];
    }
}

// --- Задачи на контроле для head и admin (фильтр по дате для всех) ---
$control_tasks = [];
if ($is_head) {
    $team_ids = array_column($employees, 'id');
    if (!empty($team_ids)) {
        $placeholders = implode(',', array_fill(0, count($team_ids), '?'));
        $sql = "
            SELECT rcq.*, u.full_name as manager_name, t.name as territory_name, et.status as task_status
            FROM rop_control_queue rcq
            LEFT JOIN users u ON rcq.user_id = u.id
            LEFT JOIN users mgr ON u.manager_id = mgr.id
            LEFT JOIN territories t ON mgr.territory_id = t.id
            LEFT JOIN epk_tasks et ON rcq.task_id = et.task_id
            WHERE rcq.user_id IN ($placeholders)
        ";
        $params = $team_ids;
        if ($filter_status !== 'Все') {
            $sql .= " AND rcq.status = ?";
            $params[] = $filter_status;
        }
        $sql .= " AND date(rcq.created_at) BETWEEN ? AND ?";
        $params[] = $filter_date_from;
        $params[] = $filter_date_to;
        $sql .= " ORDER BY rcq.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $control_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif ($role === 'admin') {
    $stmt = $pdo->query("SELECT id, full_name, tabel_number FROM users WHERE role IN ('manager', 'mmb_manager', 'ubr_middle') AND is_active = 1 ORDER BY full_name");
    $all_managers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $employee_filter_options = array_map(function($m) {
        return ['tabel' => $m['tabel_number'], 'name' => $m['full_name']];
    }, $all_managers);
    array_unshift($employee_filter_options, ['tabel' => '', 'name' => '— Все —']);

    if (!empty($filter_employee_tabel)) {
        $all_managers = array_filter($all_managers, function($m) use ($filter_employee_tabel) {
            return $m['tabel_number'] === $filter_employee_tabel;
        });
    }
    $manager_ids = array_column($all_managers, 'id');
    if (!empty($manager_ids)) {
        $placeholders = implode(',', array_fill(0, count($manager_ids), '?'));
        $sql = "
            SELECT rcq.*, u.full_name as manager_name, t.name as territory_name, et.status as task_status
            FROM rop_control_queue rcq
            LEFT JOIN users u ON rcq.user_id = u.id
            LEFT JOIN users mgr ON u.manager_id = mgr.id
            LEFT JOIN territories t ON mgr.territory_id = t.id
            LEFT JOIN epk_tasks et ON rcq.task_id = et.task_id
            WHERE rcq.user_id IN ($placeholders)
        ";
        $params = $manager_ids;
        if ($filter_status !== 'Все') {
            $sql .= " AND rcq.status = ?";
            $params[] = $filter_status;
        }
        $sql .= " AND date(rcq.created_at) BETWEEN ? AND ?";
        $params[] = $filter_date_from;
        $params[] = $filter_date_to;
        $sql .= " ORDER BY rcq.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $control_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// --- CSV ВЫГРУЗКА ЗАДАЧ НА КОНТРОЛЕ ---
if (isset($_GET['export_tasks']) && ($is_head || $role === 'admin' || $role === 'terman')) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=tasks_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, ['ID', 'Задача', 'Менеджер', 'Территория', 'Фрод-скор', 'Статус РОП', 'Статус задачи', 'Верхнеуровневый статус', 'Комментарий', 'Комментарий РОП', 'Дата создания', 'Дата проверки'], ',', '"', '\\');

    $csv_sql = "
        SELECT rcq.*, u.full_name as manager_name, t.name as territory_name, et.status as task_status
        FROM rop_control_queue rcq
        LEFT JOIN users u ON rcq.user_id = u.id
        LEFT JOIN users mgr ON u.manager_id = mgr.id
        LEFT JOIN territories t ON mgr.territory_id = t.id
        LEFT JOIN epk_tasks et ON rcq.task_id = et.task_id
        WHERE 1=1
    ";
    $csv_params = [];

    if ($filter_status !== 'Все') {
        $csv_sql .= " AND rcq.status = :status";
        $csv_params[':status'] = $filter_status;
    }
    $csv_sql .= " AND date(rcq.created_at) BETWEEN :date_from AND :date_to";
    $csv_params[':date_from'] = $filter_date_from;
    $csv_params[':date_to'] = $filter_date_to;

    if ($is_head) {
        $team_ids = array_column($employees, 'id');
        if (!empty($team_ids)) {
            $placeholders = implode(',', array_fill(0, count($team_ids), '?'));
            $csv_sql .= " AND rcq.user_id IN ($placeholders)";
            $csv_params = array_merge($csv_params, $team_ids);
        } else {
            $csv_sql .= " AND 1=0";
        }
    }

    if ($role === 'admin' && !empty($filter_employee_tabel)) {
        $csv_sql .= " AND u.tabel_number = :employee_tabel";
        $csv_params[':employee_tabel'] = $filter_employee_tabel;
    }

    $csv_sql .= " ORDER BY rcq.created_at DESC";
    $csv_stmt = $pdo->prepare($csv_sql);
    $csv_stmt->execute($csv_params);

    while ($task = $csv_stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $task['id'],
            $task['task_id'],
            $task['manager_name'] ?? '',
            $task['territory_name'] ?? '',
            $task['fraud_score'],
            $task['status'],
            $task['task_status'] ?? '',
            $task['top_status'] ?? 'active',
            $task['comment_text'],
            $task['rop_comment'] ?? '',
            $task['created_at'],
            $task['checked_at'] ?? ''
        ], ',', '"', '\\');
    }
    fclose($output);
    exit;
}

// --- CSV ВЫГРУЗКА ВСЕХ ЗАДАЧ ---
if (isset($_GET['export_all_tasks']) && ($is_head || $role === 'admin' || $role === 'terman')) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=all_tasks_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, [
        'ID задачи', 'Менеджер', 'Табельный номер', 'Территория', 'Статус', 'Верхнеуровневый статус',
        'Кол-во звонков', 'Дата импорта', 'Дата обновления', 'Следующий контакт',
        'Последний результат', 'Последний комментарий'
    ], ',', '"', '\\');

    $sql = "
        SELECT 
            et.task_id,
            u.full_name as manager_name,
            u.tabel_number,
            t.name as territory_name,
            et.status,
            et.top_status,
            et.call_count,
            et.imported_at,
            et.updated_at,
            et.next_call_date,
            (SELECT call_result FROM call_comments WHERE task_id = et.task_id ORDER BY created_at DESC LIMIT 1) as last_result,
            (SELECT comment_text FROM call_comments WHERE task_id = et.task_id ORDER BY created_at DESC LIMIT 1) as last_comment
        FROM epk_tasks et
        LEFT JOIN users u ON et.user_tabel = u.tabel_number
        LEFT JOIN users mgr ON u.manager_id = mgr.id
        LEFT JOIN territories t ON mgr.territory_id = t.id
        WHERE 1=1
    ";
    $params = [];

    if ($is_head) {
        $team_ids = array_column($employees, 'id');
        if (!empty($team_ids)) {
            $placeholders = implode(',', array_fill(0, count($team_ids), '?'));
            $sql .= " AND u.id IN ($placeholders)";
            $params = array_merge($params, $team_ids);
        } else {
            fclose($output);
            exit;
        }
    }

    if ($role === 'admin' && !empty($filter_employee_tabel)) {
        $sql .= " AND u.tabel_number = :employee_tabel";
        $params[':employee_tabel'] = $filter_employee_tabel;
    }

    $sql .= " ORDER BY et.imported_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['task_id'],
            $row['manager_name'] ?? '',
            $row['tabel_number'] ?? '',
            $row['territory_name'] ?? '',
            $row['status'],
            $row['top_status'],
            $row['call_count'],
            $row['imported_at'],
            $row['updated_at'],
            $row['next_call_date'] ?? '',
            $row['last_result'] ?? '',
            $row['last_comment'] ?? ''
        ], ',', '"', '\\');
    }
    fclose($output);
    exit;
}

// --- CSV ВЫГРУЗКА СТАТИСТИКИ ---
if (isset($_GET['export_stats']) && ($is_head || $role === 'admin' || $role === 'terman')) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=stats_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, [
        'Сотрудник', 'Табель',
        'Цель дня', 'Факт дня', 'Компания продаж',
        'Цель месяца', 'Факт месяца (отчёты)', 'Кол-во задач', 'Факт месяца (отработ.)',
        'Нет контакта', 'Договор подписан', 'Согласен', 'Думает', 'Отказ', 'Недозвон', 'Перезвонить', 'На контроле'
    ], ',', '"', '\\');

    $dataSource = [];
    if ($is_head) {
        $dataSource = $employees;
    } elseif ($role === 'admin') {
        $all_managers = $pdo->query("SELECT id, full_name, tabel_number FROM users WHERE role IN ('manager', 'mmb_manager', 'ubr_middle') AND is_active = 1 ORDER BY full_name")->fetchAll();
        if (!empty($filter_employee_tabel)) {
            $all_managers = array_filter($all_managers, function($m) use ($filter_employee_tabel) {
                return $m['tabel_number'] === $filter_employee_tabel;
            });
        }
        $dataSource = array_map(function($m) use ($pdo, $filter_date_from, $filter_date_to) {
            return [
                'name' => $m['full_name'],
                'tabel' => $m['tabel_number'],
                'stats' => getEmployeeStats($pdo, $m['id'], $m['tabel_number'], $filter_date_from, $filter_date_to)
            ];
        }, $all_managers);
    } elseif ($role === 'terman') {
        $all_managers = $pdo->query("SELECT id, full_name, tabel_number FROM users WHERE role IN ('manager', 'mmb_manager', 'ubr_middle') AND is_active = 1 ORDER BY full_name")->fetchAll();
        $dataSource = array_map(function($m) use ($pdo, $filter_date_from, $filter_date_to) {
            return [
                'name' => $m['full_name'],
                'tabel' => $m['tabel_number'],
                'stats' => getEmployeeStats($pdo, $m['id'], $m['tabel_number'], $filter_date_from, $filter_date_to)
            ];
        }, $all_managers);
    }

    foreach ($dataSource as $item) {
        $s = $item['stats'];
        fputcsv($output, [
            $item['name'], $item['tabel'],
            $s['daily_plan'],
            $s['calls_done_today'],
            $s['total_sales'],
            $s['month_plan'],
            $s['calls_done_period'],
            $s['total_tasks'],
            $s['effective_calls'],
            $s['nocontact_count'],
            $s['contract_count'],
            $s['signed_count'],
            $s['thinks'],
            $s['rejects'],
            $s['noanswers'],
            $s['recall_count'],
            $s['on_control']
        ], ',', '"', '\\');
    }

    if ($totals) {
        fputcsv($output, [
            'ИТОГО', '',
            $totals['daily_plan'],
            $totals['calls_done_today'],
            $totals['total_sales'],
            $totals['month_plan'],
            $totals['calls_done_period'],
            $totals['total_tasks'],
            $totals['effective_calls'],
            $totals['nocontact_count'],
            $totals['contract_count'],
            $totals['signed_count'],
            $totals['thinks'],
            $totals['rejects'],
            $totals['noanswers'],
            $totals['recall_count'],
            $totals['on_control']
        ], ',', '"', '\\');
    }

    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Контроль — SZB CRM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { background:#f0f2f5; font-family:system-ui, -apple-system, sans-serif; padding:12px; }
        .container { max-width:1400px; margin:0 auto; }
        .nav { display:flex; align-items:center; padding:12px 20px; background:#fff; border-radius:12px; margin-bottom:12px; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
        .nav a { color:#1a73e8; text-decoration:none; font-weight:500; margin-right:20px; }
        .nav a:hover { text-decoration:underline; }
        .panel { background:#fff; border-radius:12px; padding:16px; margin-bottom:12px; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
        .panel h3 { margin-bottom:12px; font-size:1rem; color:#202124; }
        .table-wrap { overflow-x: auto; }
        table { width:100%; border-collapse:collapse; font-size:0.7rem; }
        th, td { padding:4px 6px; text-align:left; border-bottom:1px solid #e8eaed; white-space:nowrap; }
        th { background:#f8f9fa; font-weight:600; color:#5f6368; font-size:0.65rem; }
        .total-row { background:#e8f0fe; font-weight:600; }
        .btn { padding:4px 10px; border:none; border-radius:6px; cursor:pointer; font-size:0.7rem; margin:2px; }
        .btn-success { background:#e6f4ea; color:#188038; }
        .btn-danger { background:#fce8e6; color:#c5221f; }
        .btn-warning { background:#fef3e8; color:#b06000; }
        .btn-secondary { background:#f1f3f4; color:#3c4043; }
        .control-card { border:1px solid #e8eaed; border-radius:8px; padding:10px; margin-bottom:8px; }
        .control-card .meta { font-size:0.7rem; color:#5f6368; margin-bottom:4px; }
        .control-card .comment { font-size:0.8rem; margin-bottom:6px; }
        .control-card .comment strong { font-weight:600; color:#202124; }
        .control-card .actions { display:flex; gap:4px; flex-wrap:wrap; }
        .comment-field { width:100%; padding:4px 8px; border:1px solid #dadce0; border-radius:6px; font-size:0.75rem; margin-top:4px; }
        .filters { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px; align-items:center; }
        .filters select, .filters input { padding:4px 8px; border:1px solid #dadce0; border-radius:6px; font-size:0.8rem; }
        .territory-block { margin-bottom:12px; }
        .territory-name { font-weight:600; font-size:0.95rem; color:#1a73e8; margin-bottom:6px; }
        .hidden { display:none; }
        .show { display:table-row; }
        .csv-links { margin-bottom:10px; }
        .csv-links a { display:inline-block; padding:4px 10px; background:#e8f0fe; color:#1a73e8; border-radius:6px; text-decoration:none; font-size:0.75rem; margin-right:6px; }
        .csv-links a:hover { background:#d2e3fc; }
        .stat-badge { display:inline-block; padding:2px 6px; border-radius:12px; font-size:0.65rem; font-weight:500; margin-right:2px; }
        .badge-contract { background:#e6f4ea; color:#188038; }
        .badge-reject { background:#fce8e6; color:#c5221f; }
        .badge-think { background:#fef3e8; color:#b06000; }
        .badge-noanswer { background:#f3e8fd; color:#9334e6; }
        .rop-comment { background:#f1f3f4; padding:6px; border-radius:6px; margin-top:4px; }

        /* Вертикальные заголовки с поворотом на 180 градусов */
        th.rotate {
            height: auto;
            min-height: 80px;
            min-width: 30px;
            max-width: 30px;
            text-align: center;
            vertical-align: middle;
            padding: 4px 2px;
            font-weight: 600;
            color: #5f6368;
            font-size: 0.6rem;
            background: #f8f9fa;
            writing-mode: vertical-lr;
            word-break: break-word;
            white-space: normal;
        }
        th.rotate span {
            display: inline-block;
            transform: rotate(180deg);
        }

        @media (max-width: 768px) {
            .filters { flex-direction: column; align-items: stretch; }
            .filters select, .filters input { width:100%; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="dashboard.php">Дашборд</a>
        <a href="calls.php">Я звоню</a>
        <a href="rop_control.php">Контроль</a>
        <span style="margin-left:auto; font-size:0.8rem; color:#5f6368;"><?= htmlspecialchars($user_name) ?></span>
    </div>

    <!-- Фильтры -->
    <div class="panel">
        <form method="GET" action="rop_control.php">
        <div class="filters">
            <input type="month" name="month" value="<?= $filter_month ?>" onchange="this.form.submit()">
            <input type="date" name="date_from" value="<?= $filter_date_from ?>" onchange="this.form.submit()">
            <input type="date" name="date_to" value="<?= $filter_date_to ?>" onchange="this.form.submit()">
            <?php if ($is_head || $role === 'admin' || $role === 'terman'): ?>
            <select name="status" onchange="this.form.submit()">
                <option value="На проверке" <?= $filter_status==='На проверке'?'selected':'' ?>>На проверке</option>
                <option value="Подтверждено" <?= $filter_status==='Подтверждено'?'selected':'' ?>>Подтверждено</option>
                <option value="Отклонено" <?= $filter_status==='Отклонено'?'selected':'' ?>>Отклонено</option>
                <option value="Перепрозвон" <?= $filter_status==='Перепрозвон'?'selected':'' ?>>Перепрозвон</option>
                <option value="Отказ подтверждён" <?= $filter_status==='Отказ подтверждён'?'selected':'' ?>>Отказ подтверждён</option>
                <option value="Все" <?= $filter_status==='Все'?'selected':'' ?>>Все</option>
            </select>
            <?php endif; ?>

            <?php if ($is_head || $role === 'admin'): ?>
            <select name="filter_employee_tabel" onchange="this.form.submit()">
                <?php foreach ($employee_filter_options as $opt): ?>
                    <option value="<?= htmlspecialchars($opt['tabel']) ?>" <?= $filter_employee_tabel == $opt['tabel'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($opt['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
        </div>
        </form>

        <?php if ($is_head || $role === 'admin' || $role === 'terman'): ?>
        <div class="csv-links">
            <a href="?export_stats=1&date_from=<?= $filter_date_from ?>&date_to=<?= $filter_date_to ?>&filter_employee_tabel=<?= urlencode($filter_employee_tabel) ?>">📊 Статистика CSV</a>
            <a href="?export_tasks=1&status=<?= urlencode($filter_status) ?>&date_from=<?= $filter_date_from ?>&date_to=<?= $filter_date_to ?>&filter_employee_tabel=<?= urlencode($filter_employee_tabel) ?>">📋 Задачи на контроле CSV</a>
            <a href="?export_all_tasks=1&filter_employee_tabel=<?= urlencode($filter_employee_tabel) ?>">📋 Все задачи CSV</a>
        </div>
        <?php endif; ?>
    </div>

    <?php
    // --- Статистика с вертикальными заголовками (кроме "Сотрудник") ---
    $statisticsHtml = '';
    ob_start();
    ?>
    <div class="panel">
        <h3>📊 Статистика</h3>
        <div class="table-wrap">
        <?php if ($is_manager): ?>
        <?php $s = $employees[0]['stats']; ?>
        <table>
            <tr>
                <th>Сотрудник</th>
                <th class="rotate"><span>Цель дня</span></th>
                <th class="rotate"><span>Факт дня</span></th>
                <th class="rotate"><span>Компания продаж</span></th>
                <th class="rotate"><span>Цель месяца</span></th>
                <th class="rotate"><span>Факт месяца (отчёты)</span></th>
                <th class="rotate"><span>Кол-во задач</span></th>
                <th class="rotate"><span>Факт месяца (отработ.)</span></th>
                <th class="rotate"><span>Нет контакта</span></th>
                <th class="rotate"><span>Договор подписан</span></th>
                <th class="rotate"><span>Согласен</span></th>
                <th class="rotate"><span>Думает</span></th>
                <th class="rotate"><span>Отказ</span></th>
                <th class="rotate"><span>Недозвон</span></th>
                <th class="rotate"><span>Перезвонить</span></th>
                <th class="rotate"><span>На контроле</span></th>
            </tr>
            <tr>
                <td style="font-weight:bold;"><?= htmlspecialchars($employees[0]['name']) ?></td>
                <td><?= $s['daily_plan'] ?></td>
                <td><?= $s['calls_done_today'] ?></td>
                <td style="color:#1a73e8;"><strong><?= $s['total_sales'] ?></strong></td>
                <td><?= $s['month_plan'] ?></td>
                <td><?= $s['calls_done_period'] ?></td>
                <td><?= $s['total_tasks'] ?></td>
                <td style="color:#1a73e8;"><strong><?= $s['effective_calls'] ?></strong></td>
                <td style="color:#9334e6;"><?= $s['nocontact_count'] ?></td>
                <td style="color:#188038;"><?= $s['contract_count'] ?></td>
                <td style="color:#188038;"><?= $s['signed_count'] ?></td>
                <td style="color:#b06000;"><?= $s['thinks'] ?></td>
                <td style="color:#c5221f;"><?= $s['rejects'] ?></td>
                <td style="color:#9334e6;"><?= $s['noanswers'] ?></td>
                <td style="color:#1a73e8;"><?= $s['recall_count'] ?></td>
                <td style="color:<?= $s['on_control']>0?'#c5221f':'#188038' ?>;"><?= $s['on_control'] ?></td>
            </tr>
        </table>

        <?php elseif ($is_head): ?>
        <table>
            <tr>
                <th>Сотрудник</th>
                <th class="rotate"><span>Цель дня</span></th>
                <th class="rotate"><span>Факт дня</span></th>
                <th class="rotate"><span>Компания продаж</span></th>
                <th class="rotate"><span>Цель месяца</span></th>
                <th class="rotate"><span>Факт месяца (отчёты)</span></th>
                <th class="rotate"><span>Кол-во задач</span></th>
                <th class="rotate"><span>Факт месяца (отработ.)</span></th>
                <th class="rotate"><span>Нет контакта</span></th>
                <th class="rotate"><span>Договор подписан</span></th>
                <th class="rotate"><span>Согласен</span></th>
                <th class="rotate"><span>Думает</span></th>
                <th class="rotate"><span>Отказ</span></th>
                <th class="rotate"><span>Недозвон</span></th>
                <th class="rotate"><span>Перезвонить</span></th>
                <th class="rotate"><span>На контроле</span></th>
            </tr>
            <?php foreach ($employees as $emp): $s = $emp['stats']; ?>
            <tr>
                <td><?= htmlspecialchars($emp['name']) ?></td>
                <td><?= $s['daily_plan'] ?></td>
                <td><?= $s['calls_done_today'] ?></td>
                <td style="color:#1a73e8;"><strong><?= $s['total_sales'] ?></strong></td>
                <td><?= $s['month_plan'] ?></td>
                <td><?= $s['calls_done_period'] ?></td>
                <td><?= $s['total_tasks'] ?></td>
                <td style="color:#1a73e8;"><strong><?= $s['effective_calls'] ?></strong></td>
                <td style="color:#9334e6;"><?= $s['nocontact_count'] ?></td>
                <td style="color:#188038;"><?= $s['contract_count'] ?></td>
                <td style="color:#188038;"><?= $s['signed_count'] ?></td>
                <td style="color:#b06000;"><?= $s['thinks'] ?></td>
                <td style="color:#c5221f;"><?= $s['rejects'] ?></td>
                <td style="color:#9334e6;"><?= $s['noanswers'] ?></td>
                <td style="color:#1a73e8;"><?= $s['recall_count'] ?></td>
                <td style="color:<?= $s['on_control']>0?'#c5221f':'#188038' ?>;"><?= $s['on_control'] ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if ($totals): ?>
            <tr class="total-row">
                <td>ИТОГО</td>
                <td><?= $totals['daily_plan'] ?></td>
                <td><?= $totals['calls_done_today'] ?></td>
                <td style="color:#1a73e8;"><strong><?= $totals['total_sales'] ?></strong></td>
                <td><?= $totals['month_plan'] ?></td>
                <td><?= $totals['calls_done_period'] ?></td>
                <td><?= $totals['total_tasks'] ?></td>
                <td style="color:#1a73e8;"><strong><?= $totals['effective_calls'] ?></strong></td>
                <td><?= $totals['nocontact_count'] ?></td>
                <td><?= $totals['contract_count'] ?></td>
                <td><?= $totals['signed_count'] ?></td>
                <td><?= $totals['thinks'] ?></td>
                <td><?= $totals['rejects'] ?></td>
                <td><?= $totals['noanswers'] ?></td>
                <td><?= $totals['recall_count'] ?></td>
                <td><?= $totals['on_control'] ?></td>
            </tr>
            <?php endif; ?>
        </table>

        <?php elseif ($is_terman): ?>
        <table>
            <tr>
                <th>Сотрудник</th>
                <th class="rotate"><span>Цель дня</span></th>
                <th class="rotate"><span>Факт дня</span></th>
                <th class="rotate"><span>Компания продаж</span></th>
                <th class="rotate"><span>Цель месяца</span></th>
                <th class="rotate"><span>Факт месяца (отчёты)</span></th>
                <th class="rotate"><span>Кол-во задач</span></th>
                <th class="rotate"><span>Факт месяца (отработ.)</span></th>
                <th class="rotate"><span>Нет контакта</span></th>
                <th class="rotate"><span>Договор подписан</span></th>
                <th class="rotate"><span>Согласен</span></th>
                <th class="rotate"><span>Думает</span></th>
                <th class="rotate"><span>Отказ</span></th>
                <th class="rotate"><span>Недозвон</span></th>
                <th class="rotate"><span>Перезвонить</span></th>
                <th class="rotate"><span>На контроле</span></th>
            </tr>
            <?php foreach ($employees as $emp): $s = $emp['stats']; ?>
            <tr>
                <td><?= htmlspecialchars($emp['name']) ?></td>
                <td><?= $s['daily_plan'] ?></td>
                <td><?= $s['calls_done_today'] ?></td>
                <td style="color:#1a73e8;"><strong><?= $s['total_sales'] ?></strong></td>
                <td><?= $s['month_plan'] ?></td>
                <td><?= $s['calls_done_period'] ?></td>
                <td><?= $s['total_tasks'] ?></td>
                <td style="color:#1a73e8;"><strong><?= $s['effective_calls'] ?></strong></td>
                <td style="color:#9334e6;"><?= $s['nocontact_count'] ?></td>
                <td style="color:#188038;"><?= $s['contract_count'] ?></td>
                <td style="color:#188038;"><?= $s['signed_count'] ?></td>
                <td style="color:#b06000;"><?= $s['thinks'] ?></td>
                <td style="color:#c5221f;"><?= $s['rejects'] ?></td>
                <td style="color:#9334e6;"><?= $s['noanswers'] ?></td>
                <td style="color:#1a73e8;"><?= $s['recall_count'] ?></td>
                <td style="color:<?= $s['on_control']>0?'#c5221f':'#188038' ?>;"><?= $s['on_control'] ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
        </div>
    </div>
    <?php
    $statisticsHtml = ob_get_clean();
    ?>

    <!-- Статистика (все, кроме термена) -->
    <?php if (!$is_terman) echo $statisticsHtml; ?>

    <?php if ($is_head || $role === 'admin'): ?>
    <div class="panel">
        <h3>📌 Задачи на контроле</h3>
        <?php if (empty($control_tasks)): ?>
            <p style="color:#80868b; text-align:center; padding:20px;">Нет задач</p>
        <?php else: ?>
            <?php foreach ($control_tasks as $task): ?>
            <div class="control-card">
                <div class="meta">
                    <strong>Задача:</strong> ...<?= htmlspecialchars(substr($task['task_id'], -8)) ?> |
                    <a href="https://new-tortuga.sigma.sbrf.ru/tort/tasks/sales/<?= htmlspecialchars($task['task_id']) ?>" target="_blank" style="color:#1a73e8; text-decoration:none; font-size:0.75rem;">🔗 Открыть в Ритм</a> |
                    <strong>Менеджер:</strong> <?= htmlspecialchars($task['manager_name'] ?? '—') ?> |
                    <strong>Территория:</strong> <?= htmlspecialchars($task['territory_name'] ?? '—') ?> |
                    <strong>Фрод-скор:</strong> <span style="color:<?= $task['fraud_score']<40?'#c5221f':($task['fraud_score']<70?'#f9ab00':'#188038') ?>"><?= $task['fraud_score'] ?></span> |
                    <strong>Статус:</strong> <?= $task['status'] ?>
                    <?php if ($task['top_status'] && $task['top_status'] !== 'active'): ?>
                        | <span class="stat-badge badge-<?= $task['top_status'] === 'signed' ? 'contract' : ($task['top_status'] === 'rejected_confirmed' ? 'reject' : 'think') ?>"><?= $task['top_status'] === 'signed' ? 'Договор' : ($task['top_status'] === 'rejected_confirmed' ? 'Отказ подтверждён' : $task['top_status']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="comment">
                    <strong>Комментарий менеджера:</strong><br>
                    <?= nl2br(htmlspecialchars($task['comment_text'])) ?>
                </div>

                <?php if (!empty($task['rop_comment'])): ?>
                <div class="comment rop-comment">
                    <strong>Комментарий РОПа:</strong><br>
                    <?= nl2br(htmlspecialchars($task['rop_comment'])) ?>
                </div>
                <?php endif; ?>

                <div class="actions">
                    <?php if ($task['status'] === 'На проверке'): ?>
                        <button class="btn btn-success" onclick="ropAction(<?= $task['id'] ?>, 'confirm')">Подтвердить</button>
                        <button class="btn btn-danger" onclick="ropAction(<?= $task['id'] ?>, 'reject')">Отклонить</button>
                        <button class="btn btn-warning" onclick="ropAction(<?= $task['id'] ?>, 'recall')">Перепрозвон</button>
                        <?php if (strpos($task['comment_text'] ?? '', 'Отказ') !== false || $task['top_status'] === 'active'): ?>
                            <button class="btn btn-danger" onclick="ropAction(<?= $task['id'] ?>, 'confirm_reject')" style="background:#c5221f; color:#fff;">Подтвердить отказ</button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <textarea class="comment-field" id="comment_<?= $task['id'] ?>" placeholder="Комментарий РОПа..."></textarea>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($is_terman): ?>
    <!-- Иерархия территорий -->
    <div class="panel">
        <h3>🏢 Иерархия территорий</h3>
        <?php foreach ($territories_data as $territory): ?>
        <div class="territory-block">
            <div class="territory-name"><?= htmlspecialchars($territory['name']) ?></div>
            <button class="btn btn-secondary" onclick="toggleHeads(<?= $territory['id'] ?>)">Показать руководителей</button>
            <div class="table-wrap">
            <table class="hidden" id="heads_<?= $territory['id'] ?>">
                <tr>
                    <th>Руководитель</th>
                    <th class="rotate"><span>Цель дня</span></th>
                    <th class="rotate"><span>Факт дня</span></th>
                    <th class="rotate"><span>Компания продаж</span></th>
                    <th class="rotate"><span>Цель месяца</span></th>
                    <th class="rotate"><span>Факт месяца (отчёты)</span></th>
                    <th class="rotate"><span>Кол-во задач</span></th>
                    <th class="rotate"><span>Факт месяца (отработ.)</span></th>
                    <th class="rotate"><span>Нет контакта</span></th>
                    <th class="rotate"><span>Договор подписан</span></th>
                    <th class="rotate"><span>Согласен</span></th>
                    <th class="rotate"><span>Думает</span></th>
                    <th class="rotate"><span>Отказ</span></th>
                    <th class="rotate"><span>Недозвон</span></th>
                    <th class="rotate"><span>Перезвонить</span></th>
                    <th class="rotate"><span>На контроле</span></th>
                </tr>
                <?php foreach ($territory['heads'] as $head): ?>
                <tr>
                    <td><?= htmlspecialchars($head['name']) ?></td>
                    <td><?= $head['team_totals']['daily_plan'] ?></td>
                    <td><?= $head['team_totals']['calls_done_today'] ?></td>
                    <td style="color:#1a73e8;"><strong><?= $head['team_totals']['total_sales'] ?></strong></td>
                    <td><?= $head['team_totals']['month_plan'] ?></td>
                    <td><?= $head['team_totals']['calls_done_period'] ?></td>
                    <td><?= $head['team_totals']['total_tasks'] ?></td>
                    <td style="color:#1a73e8;"><strong><?= $head['team_totals']['effective_calls'] ?></strong></td>
                    <td style="color:#9334e6;"><?= $head['team_totals']['nocontact_count'] ?></td>
                    <td style="color:#188038;"><?= $head['team_totals']['contract_count'] ?></td>
                    <td style="color:#188038;"><?= $head['team_totals']['signed_count'] ?></td>
                    <td style="color:#b06000;"><?= $head['team_totals']['thinks'] ?></td>
                    <td style="color:#c5221f;"><?= $head['team_totals']['rejects'] ?></td>
                    <td style="color:#9334e6;"><?= $head['team_totals']['noanswers'] ?></td>
                    <td style="color:#1a73e8;"><?= $head['team_totals']['recall_count'] ?></td>
                    <td style="color:<?= $head['team_totals']['on_control']>0?'#c5221f':'#188038' ?>;"><?= $head['team_totals']['on_control'] ?></td>
                </tr>
                <tr>
                    <td colspan="16" style="padding:0;">
                        <button class="btn btn-secondary" onclick="toggleTeam(<?= $head['id'] ?>)" style="margin:6px;">Команда</button>
                        <div class="table-wrap">
                        <table class="hidden" id="team_<?= $head['id'] ?>" style="margin:6px; width:calc(100% - 12px);">
                            <tr>
                                <th>Сотрудник</th>
                                <th class="rotate"><span>Цель дня</span></th>
                                <th class="rotate"><span>Факт дня</span></th>
                                <th class="rotate"><span>Компания продаж</span></th>
                                <th class="rotate"><span>Цель месяца</span></th>
                                <th class="rotate"><span>Факт месяца (отчёты)</span></th>
                                <th class="rotate"><span>Кол-во задач</span></th>
                                <th class="rotate"><span>Факт месяца (отработ.)</span></th>
                                <th class="rotate"><span>Нет контакта</span></th>
                                <th class="rotate"><span>Договор подписан</span></th>
                                <th class="rotate"><span>Согласен</span></th>
                                <th class="rotate"><span>Думает</span></th>
                                <th class="rotate"><span>Отказ</span></th>
                                <th class="rotate"><span>Недозвон</span></th>
                                <th class="rotate"><span>Перезвонить</span></th>
                                <th class="rotate"><span>На контроле</span></th>
                            </tr>
                            <?php foreach ($head['team'] as $emp): $s = $emp['stats']; ?>
                            <tr>
                                <td><?= htmlspecialchars($emp['name']) ?></td>
                                <td><?= $s['daily_plan'] ?></td>
                                <td><?= $s['calls_done_today'] ?></td>
                                <td style="color:#1a73e8;"><strong><?= $s['total_sales'] ?></strong></td>
                                <td><?= $s['month_plan'] ?></td>
                                <td><?= $s['calls_done_period'] ?></td>
                                <td><?= $s['total_tasks'] ?></td>
                                <td style="color:#1a73e8;"><strong><?= $s['effective_calls'] ?></strong></td>
                                <td style="color:#9334e6;"><?= $s['nocontact_count'] ?></td>
                                <td style="color:#188038;"><?= $s['contract_count'] ?></td>
                                <td style="color:#188038;"><?= $s['signed_count'] ?></td>
                                <td style="color:#b06000;"><?= $s['thinks'] ?></td>
                                <td style="color:#c5221f;"><?= $s['rejects'] ?></td>
                                <td style="color:#9334e6;"><?= $s['noanswers'] ?></td>
                                <td style="color:#1a73e8;"><?= $s['recall_count'] ?></td>
                                <td style="color:<?= $s['on_control']>0?'#c5221f':'#188038' ?>;"><?= $s['on_control'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td>ИТОГО</td>
                                <td><?= $head['team_totals']['daily_plan'] ?></td>
                                <td><?= $head['team_totals']['calls_done_today'] ?></td>
                                <td style="color:#1a73e8;"><strong><?= $head['team_totals']['total_sales'] ?></strong></td>
                                <td><?= $head['team_totals']['month_plan'] ?></td>
                                <td><?= $head['team_totals']['calls_done_period'] ?></td>
                                <td><?= $head['team_totals']['total_tasks'] ?></td>
                                <td style="color:#1a73e8;"><strong><?= $head['team_totals']['effective_calls'] ?></strong></td>
                                <td><?= $head['team_totals']['nocontact_count'] ?></td>
                                <td><?= $head['team_totals']['contract_count'] ?></td>
                                <td><?= $head['team_totals']['signed_count'] ?></td>
                                <td><?= $head['team_totals']['thinks'] ?></td>
                                <td><?= $head['team_totals']['rejects'] ?></td>
                                <td><?= $head['team_totals']['noanswers'] ?></td>
                                <td><?= $head['team_totals']['recall_count'] ?></td>
                                <td><?= $head['team_totals']['on_control'] ?></td>
                            </tr>
                        </table>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Для термена показываем статистику внизу -->
    <?php echo $statisticsHtml; ?>
    <?php endif; ?>
</div>

<script>
function toggleTeam(headId) {
    const table = document.getElementById('team_' + headId);
    const btn = event.target;
    if (table.classList.contains('show')) {
        table.classList.remove('show');
        btn.textContent = 'Команда';
    } else {
        table.classList.add('show');
        btn.textContent = 'Скрыть';
    }
}
function toggleHeads(territoryId) {
    const table = document.getElementById('heads_' + territoryId);
    const btn = event.target;
    if (table.classList.contains('show')) {
        table.classList.remove('show');
        btn.textContent = 'Показать руководителей';
    } else {
        table.classList.add('show');
        btn.textContent = 'Скрыть руководителей';
    }
}

function ropAction(controlId, action) {
    const commentField = document.getElementById('comment_' + controlId);

    if (action === 'confirm') {
        sendRopAction(controlId, action, commentField.value.trim());
    } else {
        const comment = commentField.value.trim();
        if (!comment) {
            alert('Комментарий обязателен!');
            commentField.focus();
            return;
        }
        sendRopAction(controlId, action, comment);
    }
}

function sendRopAction(controlId, action, comment) {
    if (!confirm('Подтвердить действие?')) return;

    fetch('api_rop_action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({control_id: controlId, action: action, comment: comment})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Успешно: ' + data.status);
            location.reload();
        } else {
            alert('Ошибка: ' + (data.error || 'Неизвестная ошибка'));
        }
    })
    .catch(err => {
        alert('Ошибка сети: ' + err);
    });
}
</script>
</body>
</html>