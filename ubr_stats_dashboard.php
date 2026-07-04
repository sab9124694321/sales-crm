<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'head') {
    header('Location: dashboard.php');
    exit;
}
require_once 'db.php';

// Параметры фильтрации
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$type_id   = isset($_GET['type_id']) && $_GET['type_id'] !== '' ? intval($_GET['type_id']) : null;

$types = $pdo->query("SELECT id, name FROM support_request_types ORDER BY name")->fetchAll();

// --- Статистика по типам ---
$type_stats = [];
$sql = "SELECT 
            rt.name as type_name,
            COUNT(sr.id) as total,
            SUM(CASE WHEN sr.status = 'closed' AND sr.resolution_deadline >= COALESCE(sr.resolved_at, sr.closed_at) THEN 1 ELSE 0 END) as on_time,
            SUM(CASE WHEN sr.status = 'closed' AND sr.resolution_deadline < COALESCE(sr.resolved_at, sr.closed_at) THEN 1 ELSE 0 END) as overdue_closed,
            SUM(CASE WHEN sr.status != 'closed' AND sr.resolution_deadline < datetime('now') THEN 1 ELSE 0 END) as overdue_open
        FROM support_requests sr
        LEFT JOIN support_request_types rt ON sr.request_type_id = rt.id
        WHERE sr.created_at BETWEEN ? AND ?
        " . ($type_id ? " AND sr.request_type_id = ?" : "") . "
        GROUP BY rt.id
        ORDER BY total DESC";
$stmt = $pdo->prepare($sql);
$params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];
if ($type_id) $params[] = $type_id;
$stmt->execute($params);
$type_stats = $stmt->fetchAll();

// --- Динамика по дням ---
$start = new DateTime($date_from);
$end   = new DateTime($date_to);
$end->modify('+1 day');
$interval = new DateInterval('P1D');
$period = new DatePeriod($start, $interval, $end);

$daily_stats = [];
foreach ($period as $dt) {
    $day = $dt->format('Y-m-d');
    $daily_stats[$day] = ['created' => 0, 'closed_on_time' => 0, 'overdue' => 0];
}

$sql_created = "SELECT DATE(created_at) as day, COUNT(*) as cnt 
                FROM support_requests 
                WHERE created_at BETWEEN ? AND ?
                " . ($type_id ? " AND request_type_id = ?" : "") . "
                GROUP BY DATE(created_at)";
$stmt = $pdo->prepare($sql_created);
$stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59'] + ($type_id ? [$type_id] : []));
while ($row = $stmt->fetch()) {
    if (isset($daily_stats[$row['day']])) $daily_stats[$row['day']]['created'] = $row['cnt'];
}

$sql_on_time = "SELECT DATE(COALESCE(resolved_at, closed_at)) as day, COUNT(*) as cnt 
                FROM support_requests 
                WHERE status = 'closed' 
                AND COALESCE(resolved_at, closed_at) BETWEEN ? AND ?
                AND resolution_deadline >= COALESCE(resolved_at, closed_at)
                " . ($type_id ? " AND request_type_id = ?" : "") . "
                GROUP BY DATE(COALESCE(resolved_at, closed_at))";
$stmt = $pdo->prepare($sql_on_time);
$stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59'] + ($type_id ? [$type_id] : []));
while ($row = $stmt->fetch()) {
    if (isset($daily_stats[$row['day']])) $daily_stats[$row['day']]['closed_on_time'] = $row['cnt'];
}

$sql_overdue_closed = "SELECT DATE(COALESCE(resolved_at, closed_at)) as day, COUNT(*) as cnt 
                       FROM support_requests 
                       WHERE status = 'closed' 
                       AND COALESCE(resolved_at, closed_at) BETWEEN ? AND ?
                       AND resolution_deadline < COALESCE(resolved_at, closed_at)
                       " . ($type_id ? " AND request_type_id = ?" : "") . "
                       GROUP BY DATE(COALESCE(resolved_at, closed_at))";
$stmt = $pdo->prepare($sql_overdue_closed);
$stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59'] + ($type_id ? [$type_id] : []));
while ($row = $stmt->fetch()) {
    if (isset($daily_stats[$row['day']])) $daily_stats[$row['day']]['overdue'] = $row['cnt'];
}

$labels = array_keys($daily_stats);
$created_data = array_column($daily_stats, 'created');
$on_time_data = array_column($daily_stats, 'closed_on_time');
$overdue_data = array_column($daily_stats, 'overdue');

$total_created = array_sum($created_data);
$total_on_time = array_sum($on_time_data);
$total_overdue_closed = array_sum($overdue_data);
$sql_open_overdue = "SELECT COUNT(*) FROM support_requests 
                     WHERE status != 'closed' 
                     AND resolution_deadline < datetime('now')
                     " . ($type_id ? " AND request_type_id = ?" : "");
$stmt = $pdo->prepare($sql_open_overdue);
$stmt->execute($type_id ? [$type_id] : []);
$open_overdue = $stmt->fetchColumn();

$total_tickets = $total_created;
$completed_on_time = $total_on_time;
$completed_overdue = $total_overdue_closed;
$open_not_overdue = $total_tickets - ($completed_on_time + $completed_overdue + $open_overdue);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Отчёты УБР</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .nav { display: flex; gap: 15px; margin-bottom: 20px; background: #1a1a2e; padding: 12px 20px; border-radius: 16px; color: #fff; }
        .nav a { color: #ccc; text-decoration: none; }
        .nav a.active, .nav a:hover { color: #fff; }
        .nav .user-info { margin-left: auto; }
        .filters { background: #fff; border-radius: 16px; padding: 20px; margin-bottom: 20px; display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 5px; }
        .filter-group label { font-weight: 600; font-size: 0.8rem; }
        .btn { background: #1a73e8; color: #fff; border: none; padding: 8px 16px; border-radius: 8px; cursor: pointer; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .stat-card { background: #fff; border-radius: 16px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .stat-number { font-size: 2rem; font-weight: 800; }
        .stat-label { font-size: 0.8rem; color: #666; margin-top: 8px; }
        .charts { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .chart-card { background: #fff; border-radius: 16px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .chart-card h3 { margin-bottom: 15px; }
        .full-width { grid-column: span 2; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; }
        .type-stats { margin-top: 20px; }
        @media (max-width: 768px) { .charts { grid-template-columns: 1fr; } .full-width { grid-column: span 1; } }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="dashboard.php">📊 Дашборд</a>
        <a href="ubr_stats_dashboard.php" class="active">📊 Отчёты УБР</a>
        <a href="mmb_head_dashboard.php">📋 Отчёты ММБ</a>
        <span class="user-info">👤 <?= htmlspecialchars($_SESSION['name']) ?> (<?= htmlspecialchars($_SESSION['role']) ?>)</span>
        <a href="logout.php">🚪 Выйти</a>
    </div>

    <h2>📊 Аналитика обращений УБР</h2>

    <form method="GET" class="filters">
        <div class="filter-group">
            <label>Дата от</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
        </div>
        <div class="filter-group">
            <label>Дата до</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
        </div>
        <div class="filter-group">
            <label>Тип обращения</label>
            <select name="type_id">
                <option value="">Все типы</option>
                <?php foreach ($types as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= $type_id == $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <button type="submit" class="btn">📅 Применить</button>
        </div>
    </form>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?= $total_tickets ?></div>
            <div class="stat-label">Всего обращений за период</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" style="color:#2e7d32;"><?= $completed_on_time ?></div>
            <div class="stat-label">✅ Закрыто в срок</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" style="color:#d32f2f;"><?= $completed_overdue ?></div>
            <div class="stat-label">⚠️ Закрыто с просрочкой</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" style="color:#ed6c02;"><?= $open_overdue ?></div>
            <div class="stat-label">⌛ Открытые просроченные</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $open_not_overdue ?></div>
            <div class="stat-label">🟢 Открытые в сроке</div>
        </div>
    </div>

    <div class="charts">
        <div class="chart-card">
            <h3>📈 Динамика обращений по дням</h3>
            <canvas id="dailyChart" width="400" height="300"></canvas>
        </div>
        <div class="chart-card">
            <h3>🥧 Состояние обращений (за период)</h3>
            <canvas id="statusPieChart" width="400" height="300"></canvas>
        </div>
    </div>

    <div class="chart-card full-width">
        <h3>📋 Статистика по типам обращений</h3>
        <table>
            <thead>
                <tr><th>Тип</th><th>Всего</th><th>✅ Выполнено в срок</th><th>⚠️ Закрыто с просрочкой</th><th>⌛ Открытые просроченные</th><th>% выполнения в срок</th></tr>
            </thead>
            <tbody>
                <?php foreach ($type_stats as $stat): 
                    $total = $stat['total'];
                    $on_time = $stat['on_time'];
                    $overdue_closed = $stat['overdue_closed'];
                    $overdue_open = $stat['overdue_open'];
                    $percent = $total > 0 ? round(($on_time / $total) * 100) : 0;
                ?>
                <tr>
                    <td><?= htmlspecialchars($stat['type_name']) ?></td>
                    <td><?= $total ?></td>
                    <td style="color:#2e7d32;"><?= $on_time ?></td>
                    <td style="color:#d32f2f;"><?= $overdue_closed ?></td>
                    <td style="color:#ed6c02;"><?= $overdue_open ?></td>
                    <td><?= $percent ?>%</td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($type_stats)): ?>
                <tr><td colspan="6">Нет данных за выбранный период</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="chart-card full-width">
        <h3>📅 Детальная динамика по дням</h3>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr><th>Дата</th><th>Создано</th><th>Закрыто вовремя</th><th>Закрыто с просрочкой</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($daily_stats as $day => $data): ?>
                        <tr>
                            <td><?= $day ?></td>
                            <td><?= $data['created'] ?></td>
                            <td style="color:#2e7d32;"><?= $data['closed_on_time'] ?></td>
                            <td style="color:#d32f2f;"><?= $data['overdue'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    const ctx = document.getElementById('dailyChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($labels) ?>,
            datasets: [
                { label: 'Создано', data: <?= json_encode($created_data) ?>, borderColor: '#1a73e8', backgroundColor: 'rgba(26,115,232,0.1)', tension: 0.2, fill: false },
                { label: 'Закрыто в срок', data: <?= json_encode($on_time_data) ?>, borderColor: '#2e7d32', backgroundColor: 'rgba(46,125,50,0.1)', tension: 0.2, fill: false },
                { label: 'Закрыто с просрочкой', data: <?= json_encode($overdue_data) ?>, borderColor: '#d32f2f', backgroundColor: 'rgba(211,47,47,0.1)', tension: 0.2, fill: false }
            ]
        },
        options: { responsive: true, maintainAspectRatio: true, plugins: { tooltip: { mode: 'index', intersect: false }, legend: { position: 'top' } }, scales: { y: { beginAtZero: true, title: { display: true, text: 'Количество обращений' } }, x: { title: { display: true, text: 'Дата' } } } }
    });

    const pieCtx = document.getElementById('statusPieChart').getContext('2d');
    new Chart(pieCtx, {
        type: 'pie',
        data: {
            labels: ['✅ Закрыто в срок', '⚠️ Закрыто с просрочкой', '⌛ Открытые просроченные', '🟢 Открытые в сроке'],
            datasets: [{ data: [<?= $completed_on_time ?>, <?= $completed_overdue ?>, <?= $open_overdue ?>, <?= $open_not_overdue ?>], backgroundColor: ['#2e7d32', '#d32f2f', '#ed6c02', '#17a2b8'], borderWidth: 0 }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: (tooltipItem) => `${tooltipItem.label}: ${tooltipItem.raw} (${Math.round(tooltipItem.raw / <?= max(1, $total_tickets) ?> * 100)}%)` } } } }
    });
</script>
</body>
</html>