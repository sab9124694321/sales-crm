<?php
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

// --- Функция получения плана звонков ---
function getCallPlan($pdo, $tabel_num) {
    $stmt = $pdo->prepare("SELECT calls_plan FROM plans WHERE tabel_number = ? AND period = strftime('%Y-%m', 'now')");
    $stmt->execute([$tabel_num]);
    $month_plan = $stmt->fetchColumn() ?: 350;
    $work_days = 22;
    return ceil($month_plan / $work_days);
}

// --- Функция получения статистики сотрудника ---
function getEmployeeStats($pdo, $emp_id, $emp_tabel, $date_from, $date_to) {
    // План на день
    $daily_plan = getCallPlan($pdo, $emp_tabel);

    // Всего задач
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM epk_tasks WHERE user_tabel = ?");
    $stmt->execute([$emp_tabel]);
    $total_tasks = (int)$stmt->fetchColumn();

    // Выполнено звонков (из daily_reports)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(calls),0) FROM daily_reports WHERE user_id = ? AND report_date BETWEEN ? AND ?");
    $stmt->execute([$emp_id, $date_from, $date_to]);
    $calls_done = (int)$stmt->fetchColumn();

    // На контроле РОП
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM rop_control_queue WHERE user_id = ? AND date(created_at) BETWEEN ? AND ?");
    $stmt->execute([$emp_id, $date_from, $date_to]);
    $on_control = (int)$stmt->fetchColumn();

    // Подтверждено
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM rop_control_queue WHERE user_id = ? AND status = 'Подтверждено' AND date(checked_at) BETWEEN ? AND ?");
    $stmt->execute([$emp_id, $date_from, $date_to]);
    $confirmed = (int)$stmt->fetchColumn();

    // Отклонено
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM rop_control_queue WHERE user_id = ? AND status = 'Отклонено' AND date(checked_at) BETWEEN ? AND ?");
    $stmt->execute([$emp_id, $date_from, $date_to]);
    $rejected = (int)$stmt->fetchColumn();

    // Перепрозвон
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM rop_control_queue WHERE user_id = ? AND status = 'Перепрозвон' AND date(checked_at) BETWEEN ? AND ?");
    $stmt->execute([$emp_id, $date_from, $date_to]);
    $recall = (int)$stmt->fetchColumn();

    return [
        'daily_plan' => $daily_plan,
        'total_tasks' => $total_tasks,
        'calls_done' => $calls_done,
        'on_control' => $on_control,
        'confirmed' => $confirmed,
        'rejected' => $rejected,
        'recall' => $recall
    ];
}

// --- Собираем данные в зависимости от роли ---
$employees = [];
$heads = [];
$territories_data = [];

if ($is_manager) {
    // Сотрудник видит только себя
    $employees[] = [
        'id' => $user_id,
        'tabel' => $tabel,
        'name' => $user_name,
        'stats' => getEmployeeStats($pdo, $user_id, $tabel, $filter_date_from, $filter_date_to)
    ];
} elseif ($is_head) {
    // Руководитель видит свою команду
    $stmt = $pdo->prepare("SELECT id, full_name, tabel_number FROM users WHERE is_active = 1 AND role IN ('manager', 'mmb_manager', 'ubr_middle') AND manager_id = ? ORDER BY full_name");
    $stmt->execute([$user_id]);
    $team = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($team as $member) {
        $employees[] = [
            'id' => $member['id'],
            'tabel' => $member['tabel_number'],
            'name' => $member['full_name'],
            'stats' => getEmployeeStats($pdo, $member['id'], $member['tabel_number'], $filter_date_from, $filter_date_to)
        ];
    }
} elseif ($is_terman) {
    // Термен видит территории
    $stmt = $pdo->query("SELECT id, name FROM territories WHERE name IS NOT NULL AND name != '' ORDER BY name");
    $territories_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($territories_list as $territory) {
        // Находим руководителей этой территории
        $stmt = $pdo->prepare("SELECT id, full_name, tabel_number, territory_id FROM users WHERE is_active = 1 AND role IN ('head', 'territory_head') AND territory_id = ? ORDER BY full_name");
        $stmt->execute([$territory['id']]);
        $territory_heads = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $territory_heads_data = [];
        $territory_totals = ['daily_plan'=>0, 'total_tasks'=>0, 'calls_done'=>0, 'on_control'=>0, 'confirmed'=>0, 'rejected'=>0, 'recall'=>0];

        foreach ($territory_heads as $head) {
            $head_stats = getEmployeeStats($pdo, $head['id'], $head['tabel_number'], $filter_date_from, $filter_date_to);

            // Команда руководителя
            $stmt = $pdo->prepare("SELECT id, full_name, tabel_number FROM users WHERE is_active = 1 AND role IN ('manager', 'mmb_manager', 'ubr_middle') AND manager_id = ? ORDER BY full_name");
            $stmt->execute([$head['id']]);
            $team = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $team_members = [];
            $team_totals = ['daily_plan'=>0, 'total_tasks'=>0, 'calls_done'=>0, 'on_control'=>0, 'confirmed'=>0, 'rejected'=>0, 'recall'=>0];

            foreach ($team as $member) {
                $member_stats = getEmployeeStats($pdo, $member['id'], $member['tabel_number'], $filter_date_from, $filter_date_to);
                $team_members[] = [
                    'id' => $member['id'],
                    'tabel' => $member['tabel_number'],
                    'name' => $member['full_name'],
                    'stats' => $member_stats
                ];
                // Суммируем в итоги команды
                $team_totals['daily_plan'] += $member_stats['daily_plan'];
                $team_totals['total_tasks'] += $member_stats['total_tasks'];
                $team_totals['calls_done'] += $member_stats['calls_done'];
                $team_totals['on_control'] += $member_stats['on_control'];
                $team_totals['confirmed'] += $member_stats['confirmed'];
                $team_totals['rejected'] += $member_stats['rejected'];
                $team_totals['recall'] += $member_stats['recall'];
            }

            // Суммируем в итоги территории
            $territory_totals['daily_plan'] += $head_stats['daily_plan'] + $team_totals['daily_plan'];
            $territory_totals['total_tasks'] += $head_stats['total_tasks'] + $team_totals['total_tasks'];
            $territory_totals['calls_done'] += $head_stats['calls_done'] + $team_totals['calls_done'];
            $territory_totals['on_control'] += $head_stats['on_control'] + $team_totals['on_control'];
            $territory_totals['confirmed'] += $head_stats['confirmed'] + $team_totals['confirmed'];
            $territory_totals['rejected'] += $head_stats['rejected'] + $team_totals['rejected'];
            $territory_totals['recall'] += $head_stats['recall'] + $team_totals['recall'];

            $territory_heads_data[] = [
                'id' => $head['id'],
                'tabel' => $head['tabel_number'],
                'name' => $head['full_name'],
                'stats' => $head_stats,
                'team' => $team_members,
                'team_totals' => $team_totals
            ];
        }

        $territories_data[] = [
            'id' => $territory['id'],
            'name' => $territory['name'],
            'heads' => $territory_heads_data,
            'totals' => $territory_totals
        ];
    }
}

// --- Выгрузка CSV статистики ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="control_stats_' . date('Ymd') . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, ['Сотрудник', 'Табель', 'План/день', 'Задач всего', 'Звонков', 'На контроле', 'Подтверждено', 'Отклонено', 'Перепрозвон']);

    if ($is_terman) {
        foreach ($territories_data as $territory) {
            fputcsv($output, ['=== ТЕРРИТОРИЯ: ' . $territory['name'] . ' ===', '', '', '', '', '', '', '', '']);
            foreach ($territory['heads'] as $head) {
                fputcsv($output, ['-- ' . $head['name'] . ' --', '', '', '', '', '', '', '', '']);
                foreach ($head['team'] as $emp) {
                    fputcsv($output, [
                        $emp['name'], $emp['tabel'], $emp['stats']['daily_plan'], $emp['stats']['total_tasks'],
                        $emp['stats']['calls_done'], $emp['stats']['on_control'], $emp['stats']['confirmed'],
                        $emp['stats']['rejected'], $emp['stats']['recall']
                    ]);
                }
                fputcsv($output, ['ИТОГО КОМАНДЫ', '', $head['team_totals']['daily_plan'], $head['team_totals']['total_tasks'], $head['team_totals']['calls_done'], $head['team_totals']['on_control'], $head['team_totals']['confirmed'], $head['team_totals']['rejected'], $head['team_totals']['recall']]);
            }
            fputcsv($output, ['ИТОГО ТЕРРИТОРИИ', '', $territory['totals']['daily_plan'], $territory['totals']['total_tasks'], $territory['totals']['calls_done'], $territory['totals']['on_control'], $territory['totals']['confirmed'], $territory['totals']['rejected'], $territory['totals']['recall']]);
        }
    } elseif ($is_head) {
        foreach ($employees as $emp) {
            fputcsv($output, [
                $emp['name'], $emp['tabel'], $emp['stats']['daily_plan'], $emp['stats']['total_tasks'],
                $emp['stats']['calls_done'], $emp['stats']['on_control'], $emp['stats']['confirmed'],
                $emp['stats']['rejected'], $emp['stats']['recall']
            ]);
        }
    } else {
        foreach ($employees as $emp) {
            fputcsv($output, [
                $emp['name'], $emp['tabel'], $emp['stats']['daily_plan'], $emp['stats']['total_tasks'],
                $emp['stats']['calls_done'], $emp['stats']['on_control'], $emp['stats']['confirmed'],
                $emp['stats']['rejected'], $emp['stats']['recall']
            ]);
        }
    }
    fclose($output);
    exit;
}

// --- Выгрузка CSV задач с комментариями ---
if (isset($_GET['export']) && $_GET['export'] === 'csv_tasks') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tasks_comments_' . date('Ymd') . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, ['Номер задачи', 'Менеджер', 'Статус задачи', 'Статус звонка', 'Комментарий', 'Дата']);

    $task_sql = "
        SELECT 
            e.task_id,
            u.full_name as manager_name,
            e.status as task_status,
            COALESCE(c.call_result, 'Нет звонка') as call_status,
            COALESCE(c.comment_text, '') as comment_text,
            COALESCE(c.created_at, e.imported_at) as date
        FROM epk_tasks e
        JOIN users u ON e.user_tabel = u.tabel_number
        LEFT JOIN call_comments c ON e.task_id = c.task_id
        WHERE date(e.imported_at) BETWEEN ? AND ?
        ORDER BY e.imported_at DESC
    ";
    $task_params = [$filter_date_from, $filter_date_to];

    if ($is_manager) {
        $task_sql = "
            SELECT 
                e.task_id,
                u.full_name as manager_name,
                e.status as task_status,
                COALESCE(c.call_result, 'Нет звонка') as call_status,
                COALESCE(c.comment_text, '') as comment_text,
                COALESCE(c.created_at, e.imported_at) as date
            FROM epk_tasks e
            JOIN users u ON e.user_tabel = u.tabel_number
            LEFT JOIN call_comments c ON e.task_id = c.task_id
            WHERE e.user_tabel = ? AND date(e.imported_at) BETWEEN ? AND ?
            ORDER BY e.imported_at DESC
        ";
        $task_params = [$tabel, $filter_date_from, $filter_date_to];
    } elseif ($is_head) {
        $task_sql = "
            SELECT 
                e.task_id,
                u.full_name as manager_name,
                e.status as task_status,
                COALESCE(c.call_result, 'Нет звонка') as call_status,
                COALESCE(c.comment_text, '') as comment_text,
                COALESCE(c.created_at, e.imported_at) as date
            FROM epk_tasks e
            JOIN users u ON e.user_tabel = u.tabel_number
            LEFT JOIN call_comments c ON e.task_id = c.task_id
            WHERE u.manager_id = ? AND date(e.imported_at) BETWEEN ? AND ?
            ORDER BY e.imported_at DESC
        ";
        $task_params = [$user_id, $filter_date_from, $filter_date_to];
    } elseif ($is_terman) {
        $task_sql = "
            SELECT 
                e.task_id,
                u.full_name as manager_name,
                e.status as task_status,
                COALESCE(c.call_result, 'Нет звонка') as call_status,
                COALESCE(c.comment_text, '') as comment_text,
                COALESCE(c.created_at, e.imported_at) as date
            FROM epk_tasks e
            JOIN users u ON e.user_tabel = u.tabel_number
            LEFT JOIN call_comments c ON e.task_id = c.task_id
            WHERE date(e.imported_at) BETWEEN ? AND ?
            ORDER BY e.imported_at DESC
        ";
        $task_params = [$filter_date_from, $filter_date_to];
    }

    $stmt = $pdo->prepare($task_sql);
    $stmt->execute($task_params);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['task_id'],
            $row['manager_name'],
            $row['task_status'],
            $row['call_status'],
            mb_substr($row['comment_text'], 0, 500),
            $row['date']
        ]);
    }

    fclose($output);
    exit;
}

// --- Список территорий для фильтра ---
$stmt = $pdo->query("SELECT DISTINCT name FROM territories WHERE name IS NOT NULL AND name != '' ORDER BY name");
$territories = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>🛡️ Контроль звонков — SZB CRM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { background:#f0f2f5; font-family:system-ui, -apple-system, sans-serif; padding:12px; }
        .container { max-width:1400px; margin:0 auto; }
        .nav { display:flex; align-items:center; padding:12px 20px; background:linear-gradient(135deg,#1a1a2e,#16213e); color:#fff; border-radius:16px; margin-bottom:20px; gap:12px; flex-wrap:wrap; }
        .nav a { color:#ccc; text-decoration:none; padding:8px 14px; border-radius:8px; font-size:13px; font-weight:500; }
        .nav a:hover, .nav a.active { background:rgba(255,255,255,0.1); color:#fff; }
        .nav .logo { font-size:20px; font-weight:700; color:#fff; margin-right:auto; }
        .nav .user { margin-left:auto; color:#aaa; font-size:13px; }

        .stats-bar { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px,1fr)); gap:12px; margin-bottom:20px; }
        .stat-card { background:#fff; border-radius:16px; padding:16px; box-shadow:0 1px 3px rgba(0,0,0,0.05); text-align:center; }
        .stat-card .value { font-size:2rem; font-weight:800; }
        .stat-card .label { font-size:0.8rem; color:#666; }
        .stat-card.plan { border-left:4px solid #1a73e8; }
        .stat-card.done { border-left:4px solid #28a745; }
        .stat-card.control { border-left:4px solid #dc3545; }
        .stat-card.pending { border-left:4px solid #ffc107; }

        .card { background:#fff; border-radius:16px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:16px; }
        .card h3 { margin-bottom:16px; font-size:1.1rem; display:flex; align-items:center; gap:8px; }

        .filters { display:flex; gap:12px; margin-bottom:16px; flex-wrap:wrap; align-items:end; }
        .filters label { font-size:0.8rem; font-weight:600; color:#555; display:block; margin-bottom:4px; }
        .filters input { padding:8px 12px; border:1px solid #ddd; border-radius:8px; font-size:0.85rem; }
        .filters button, .filters a { padding:8px 16px; border:none; border-radius:8px; font-size:0.85rem; cursor:pointer; font-weight:600; text-decoration:none; display:inline-block; }
        .btn-primary { background:#1a73e8; color:#fff; }
        .btn-outline { background:#fff; color:#1a73e8; border:1px solid #1a73e8; }

        .tb-table { width:100%; border-collapse:collapse; font-size:0.85rem; }
        .tb-table th { background:#f8f9fa; padding:10px; text-align:left; font-weight:600; color:#555; border-bottom:2px solid #e0e0e0; }
        .tb-table td { padding:10px; border-bottom:1px solid #eee; }
        .tb-table tr:hover { background:#f8f9fa; }
        .tb-table .total-row { font-weight:bold; background:#f0f0f0; }

        .score-badge { padding:4px 12px; border-radius:20px; font-weight:700; font-size:0.85rem; color:#fff; }
        .score-high { background:#28a745; }
        .score-mid { background:#ffc107; color:#333; }
        .score-low { background:#dc3545; }

        .head-section { margin-bottom:24px; }
        .head-title { font-size:1.1rem; font-weight:600; color:#1a1a2e; margin-bottom:12px; padding:8px 12px; background:#e8f0fe; border-radius:8px; }
        .territory-title { font-size:1.2rem; font-weight:700; color:#1a1a2e; margin-bottom:16px; padding:12px 16px; background:#d2e3fc; border-radius:8px; display:flex; justify-content:space-between; align-items:center; }

        .toggle-btn { background:none; border:none; color:#1a73e8; cursor:pointer; font-size:0.85rem; margin-left:8px; }
        .team-table { margin-left:20px; margin-top:8px; display:none; }
        .team-table.show { display:table; }
        .heads-table { margin-left:20px; margin-top:8px; display:none; }
        .heads-table.show { display:table; }

        .empty-state { text-align:center; padding:40px; color:#888; }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="dashboard.php" class="logo">🚀 SZB</a>
        <a href="dashboard.php">Дашборд</a>
        <a href="team.php">Команда</a>
        <a href="calls.php">📞 Я звоню</a>
        <a href="rop_control.php" class="active">🛡️ Контроль</a>
        <span class="user">👤 <?= htmlspecialchars($user_name) ?></span>
        <a href="logout.php" class="logout">Выйти</a>
    </div>

    <h2 style="margin-bottom:16px;">🛡️ Контроль качества звонков</h2>

    <div class="card">
        <form class="filters" method="GET">
            <div>
                <label>Период с</label>
                <input type="date" name="date_from" value="<?= $filter_date_from ?>">
            </div>
            <div>
                <label>по</label>
                <input type="date" name="date_to" value="<?= $filter_date_to ?>">
            </div>
            <div>
                <label>&nbsp;</label>
                <button type="submit" class="btn-primary">🔍 Показать</button>
            </div>
            <div>
                <label>&nbsp;</label>
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn-outline">📥 Статистика CSV</a>
            </div>
            <div>
                <label>&nbsp;</label>
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv_tasks'])) ?>" class="btn-outline">📋 Задачи CSV</a>
            </div>
        </form>
    </div>

    <?php if ($is_manager): ?>
    <!-- Сотрудник: своя статистика -->
    <div class="stats-bar">
        <?php $s = $employees[0]['stats']; ?>
        <div class="stat-card plan">
            <div class="value"><?= $s['daily_plan'] ?></div>
            <div class="label">📊 План/день</div>
        </div>
        <div class="stat-card done">
            <div class="value"><?= $s['total_tasks'] ?></div>
            <div class="label">📋 Задач</div>
        </div>
        <div class="stat-card done">
            <div class="value"><?= $s['calls_done'] ?></div>
            <div class="label">📞 Звонков</div>
        </div>
        <div class="stat-card control">
            <div class="value"><?= $s['on_control'] ?></div>
            <div class="label">🚨 На контроле</div>
        </div>
        <div class="stat-card pending">
            <div class="value"><?= $s['confirmed'] ?></div>
            <div class="label">✅ Подтверждено</div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($is_head): ?>
    <!-- Руководитель: команда -->
    <div class="card">
        <h3>📊 Статистика команды</h3>
        <table class="tb-table">
            <thead>
                <tr>
                    <th>Сотрудник</th>
                    <th>План/день</th>
                    <th>Задач</th>
                    <th>Звонков</th>
                    <th>На контроле</th>
                    <th>Подтверждено</th>
                    <th>Отклонено</th>
                    <th>Перепрозвон</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $totals = ['daily_plan'=>0, 'total_tasks'=>0, 'calls_done'=>0, 'on_control'=>0, 'confirmed'=>0, 'rejected'=>0, 'recall'=>0];
                foreach ($employees as $emp): 
                    $s = $emp['stats'];
                    // Явное суммирование (исправлено)
                    $totals['daily_plan'] += $s['daily_plan'];
                    $totals['total_tasks'] += $s['total_tasks'];
                    $totals['calls_done'] += $s['calls_done'];
                    $totals['on_control'] += $s['on_control'];
                    $totals['confirmed'] += $s['confirmed'];
                    $totals['rejected'] += $s['rejected'];
                    $totals['recall'] += $s['recall'];
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($emp['name']) ?></strong></td>
                    <td><?= $s['daily_plan'] ?></td>
                    <td><?= $s['total_tasks'] ?></td>
                    <td><?= $s['calls_done'] ?></td>
                    <td style="color:<?= $s['on_control'] > 0 ? '#dc3545' : '#28a745' ?>"><?= $s['on_control'] ?></td>
                    <td style="color:#28a745;"><?= $s['confirmed'] ?></td>
                    <td style="color:#dc3545;"><?= $s['rejected'] ?></td>
                    <td style="color:#1a73e8;"><?= $s['recall'] ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td>ИТОГО</td>
                    <td><?= $totals['daily_plan'] ?></td>
                    <td><?= $totals['total_tasks'] ?></td>
                    <td><?= $totals['calls_done'] ?></td>
                    <td><?= $totals['on_control'] ?></td>
                    <td><?= $totals['confirmed'] ?></td>
                    <td><?= $totals['rejected'] ?></td>
                    <td><?= $totals['recall'] ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($is_terman): ?>
    <!-- Термен: по территориям -->
    <?php foreach ($territories_data as $territory): ?>
    <div class="card">
        <div class="territory-title">
            <span>🌍 <?= htmlspecialchars($territory['name']) ?></span>
            <button class="toggle-btn" onclick="toggleHeads(<?= $territory['id'] ?>)">👥 Показать руководителей</button>
        </div>

        <!-- Итоги по территории -->
        <div class="stats-bar" style="margin-bottom:16px;">
            <div class="stat-card plan"><div class="value"><?= $territory['totals']['daily_plan'] ?></div><div class="label">План/день</div></div>
            <div class="stat-card done"><div class="value"><?= $territory['totals']['total_tasks'] ?></div><div class="label">Задач</div></div>
            <div class="stat-card done"><div class="value"><?= $territory['totals']['calls_done'] ?></div><div class="label">Звонков</div></div>
            <div class="stat-card control"><div class="value"><?= $territory['totals']['on_control'] ?></div><div class="label">На контроле</div></div>
            <div class="stat-card pending"><div class="value"><?= $territory['totals']['confirmed'] ?></div><div class="label">Подтверждено</div></div>
            <div class="stat-card control"><div class="value"><?= $territory['totals']['rejected'] ?></div><div class="label">Отклонено</div></div>
            <div class="stat-card pending"><div class="value"><?= $territory['totals']['recall'] ?></div><div class="label">Перепрозвон</div></div>
        </div>

        <!-- Руководители территории -->
        <table class="tb-table heads-table" id="heads_<?= $territory['id'] ?>">
            <thead>
                <tr>
                    <th>Руководитель</th>
                    <th>План/день</th>
                    <th>Задач</th>
                    <th>Звонков</th>
                    <th>На контроле</th>
                    <th>Подтверждено</th>
                    <th>Отклонено</th>
                    <th>Перепрозвон</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($territory['heads'] as $head): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($head['name']) ?></strong></td>
                    <td><?= $head['stats']['daily_plan'] ?></td>
                    <td><?= $head['stats']['total_tasks'] + $head['team_totals']['total_tasks'] ?></td>
                    <td><?= $head['stats']['calls_done'] + $head['team_totals']['calls_done'] ?></td>
                    <td><?= $head['stats']['on_control'] + $head['team_totals']['on_control'] ?></td>
                    <td><?= $head['stats']['confirmed'] + $head['team_totals']['confirmed'] ?></td>
                    <td><?= $head['stats']['rejected'] + $head['team_totals']['rejected'] ?></td>
                    <td><?= $head['stats']['recall'] + $head['team_totals']['recall'] ?></td>
                    <td><button class="toggle-btn" onclick="toggleTeam(<?= $head['id'] ?>)">👥 Команда</button></td>
                </tr>
                <tr>
                    <td colspan="9" style="padding:0;">
                        <table class="tb-table team-table" id="team_<?= $head['id'] ?>">
                            <thead>
                                <tr>
                                    <th>Сотрудник</th>
                                    <th>План/день</th>
                                    <th>Задач</th>
                                    <th>Звонков</th>
                                    <th>На контроле</th>
                                    <th>Подтверждено</th>
                                    <th>Отклонено</th>
                                    <th>Перепрозвон</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($head['team'] as $emp): 
                                    $s = $emp['stats'];
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($emp['name']) ?></td>
                                    <td><?= $s['daily_plan'] ?></td>
                                    <td><?= $s['total_tasks'] ?></td>
                                    <td><?= $s['calls_done'] ?></td>
                                    <td style="color:<?= $s['on_control'] > 0 ? '#dc3545' : '#28a745' ?>"><?= $s['on_control'] ?></td>
                                    <td style="color:#28a745;"><?= $s['confirmed'] ?></td>
                                    <td style="color:#dc3545;"><?= $s['rejected'] ?></td>
                                    <td style="color:#1a73e8;"><?= $s['recall'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="total-row">
                                    <td>ИТОГО КОМАНДЫ</td>
                                    <td><?= $head['team_totals']['daily_plan'] ?></td>
                                    <td><?= $head['team_totals']['total_tasks'] ?></td>
                                    <td><?= $head['team_totals']['calls_done'] ?></td>
                                    <td><?= $head['team_totals']['on_control'] ?></td>
                                    <td><?= $head['team_totals']['confirmed'] ?></td>
                                    <td><?= $head['team_totals']['rejected'] ?></td>
                                    <td><?= $head['team_totals']['recall'] ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
function toggleTeam(headId) {
    const table = document.getElementById('team_' + headId);
    const btn = event.target;
    if (table.classList.contains('show')) {
        table.classList.remove('show');
        btn.textContent = '👥 Команда';
    } else {
        table.classList.add('show');
        btn.textContent = '👥 Скрыть';
    }
}
function toggleHeads(territoryId) {
    const table = document.getElementById('heads_' + territoryId);
    const btn = event.target;
    if (table.classList.contains('show')) {
        table.classList.remove('show');
        btn.textContent = '👥 Показать руководителей';
    } else {
        table.classList.add('show');
        btn.textContent = '👥 Скрыть руководителей';
    }
}
</script>
</body>
</html>