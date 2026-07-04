<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once 'db.php';

$role = $_SESSION['role'];
$allowed_roles = ['head', 'territory_head', 'admin', 'ubr_middle'];
if (!in_array($role, $allowed_roles)) { die('Доступ только для руководителей'); }

$user_id = $_SESSION['user_id'];
$tabel = $_SESSION['tabel'];

// --- Фильтры ---
$filter_territory = $_GET['territory'] ?? '';
$filter_status = $_GET['status'] ?? 'На проверке';
$filter_date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$filter_date_to = $_GET['date_to'] ?? date('Y-m-d');

// --- Статистика по территориям ---
$stats_sql = "
    SELECT 
        COALESCE(t.name, 'Не указана') as territory_name,
        COUNT(DISTINCT r.task_id) as total_control,
        COUNT(DISTINCT CASE WHEN r.status = 'На проверке' THEN r.task_id END) as pending,
        COUNT(DISTINCT CASE WHEN r.status = 'Подтверждено' THEN r.task_id END) as confirmed,
        COUNT(DISTINCT CASE WHEN r.status = 'Отклонено' THEN r.task_id END) as rejected,
        COUNT(DISTINCT CASE WHEN r.status = 'Перепрозвон' THEN r.task_id END) as recall,
        ROUND(AVG(r.fraud_score), 1) as avg_score,
        COUNT(DISTINCT r.user_id) as managers_count
    FROM rop_control_queue r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN territories t ON u.territory_id = t.id
    WHERE date(r.created_at) BETWEEN ? AND ?
";
$stats_params = [$filter_date_from, $filter_date_to];
if ($filter_territory) {
    $stats_sql .= " AND t.name = ?";
    $stats_params[] = $filter_territory;
}
$stats_sql .= " GROUP BY t.name ORDER BY total_control DESC";

$stmt = $pdo->prepare($stats_sql);
$stmt->execute($stats_params);
$tb_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Список задач на контроле ---
$tasks_sql = "
    SELECT 
        r.id as control_id,
        r.task_id,
        r.fraud_score,
        r.comment_text,
        r.status as control_status,
        r.rop_comment,
        r.rop_action,
        r.created_at,
        r.checked_at,
        u.full_name as manager_name,
        u.tabel_number as manager_tabel,
        COALESCE(t.name, '—') as territory_name,
        u.role as manager_role,
        (SELECT COUNT(*) FROM call_comments WHERE task_id = r.task_id) as call_count,
        (SELECT MAX(created_at) FROM call_comments WHERE task_id = r.task_id) as last_call_date
    FROM rop_control_queue r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN territories t ON u.territory_id = t.id
    WHERE date(r.created_at) BETWEEN ? AND ?
";
$tasks_params = [$filter_date_from, $filter_date_to];
if ($filter_territory) {
    $tasks_sql .= " AND t.name = ?";
    $tasks_params[] = $filter_territory;
}
if ($filter_status) {
    $tasks_sql .= " AND r.status = ?";
    $tasks_params[] = $filter_status;
}
$tasks_sql .= " ORDER BY r.created_at DESC LIMIT 200";

$stmt = $pdo->prepare($tasks_sql);
$stmt->execute($tasks_params);
$control_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Список территорий для фильтра ---
$stmt = $pdo->query("SELECT DISTINCT name FROM territories WHERE name IS NOT NULL AND name != '' ORDER BY name");
$territories = $stmt->fetchAll(PDO::FETCH_COLUMN);

// --- Выгрузка CSV ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rop_control_' . date('Ymd') . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, ['Дата', 'Территория', 'Табель', 'Менеджер', 'Задача', 'Фрод-скор', 'Статус', 'Комментарий', 'Действие РОП', 'Дата проверки']);

    foreach ($control_tasks as $t) {
        fputcsv($output, [
            $t['created_at'],
            $t['territory_name'],
            $t['manager_tabel'],
            $t['manager_name'],
            $t['task_id'],
            $t['fraud_score'],
            $t['control_status'],
            mb_substr($t['comment_text'], 0, 200),
            $t['rop_action'] ?? '',
            $t['checked_at'] ?? ''
        ]);
    }
    fclose($output);
    exit;
}

// --- Статистика по статусам ---
$status_counts = [
    'На проверке' => 0,
    'Подтверждено' => 0,
    'Отклонено' => 0,
    'Перепрозвон' => 0
];
foreach ($control_tasks as $t) {
    if (isset($status_counts[$t['control_status']])) {
        $status_counts[$t['control_status']]++;
    }
}
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
        .nav a.logout { color:#e03131; }

        .stats-bar { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px,1fr)); gap:12px; margin-bottom:20px; }
        .stat-card { background:#fff; border-radius:16px; padding:16px; box-shadow:0 1px 3px rgba(0,0,0,0.05); text-align:center; }
        .stat-card .value { font-size:2rem; font-weight:800; }
        .stat-card .label { font-size:0.8rem; color:#666; }
        .stat-card.pending { border-left:4px solid #ffc107; }
        .stat-card.confirmed { border-left:4px solid #28a745; }
        .stat-card.rejected { border-left:4px solid #dc3545; }
        .stat-card.recall { border-left:4px solid #1a73e8; }

        .card { background:#fff; border-radius:16px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:16px; }
        .card h3 { margin-bottom:16px; font-size:1.1rem; display:flex; align-items:center; gap:8px; }

        .filters { display:flex; gap:12px; margin-bottom:16px; flex-wrap:wrap; align-items:end; }
        .filters label { font-size:0.8rem; font-weight:600; color:#555; display:block; margin-bottom:4px; }
        .filters select, .filters input { padding:8px 12px; border:1px solid #ddd; border-radius:8px; font-size:0.85rem; }
        .filters button { padding:8px 16px; border:none; border-radius:8px; font-size:0.85rem; cursor:pointer; font-weight:600; }
        .btn-primary { background:#1a73e8; color:#fff; }
        .btn-success { background:#28a745; color:#fff; }
        .btn-outline { background:#fff; color:#1a73e8; border:1px solid #1a73e8; }

        .tb-table { width:100%; border-collapse:collapse; font-size:0.85rem; }
        .tb-table th { background:#f8f9fa; padding:10px; text-align:left; font-weight:600; color:#555; border-bottom:2px solid #e0e0e0; }
        .tb-table td { padding:10px; border-bottom:1px solid #eee; }
        .tb-table tr:hover { background:#f8f9fa; }

        .task-item { border:1px solid #e8ecf1; border-radius:12px; padding:16px; margin-bottom:12px; transition:all 0.2s; }
        .task-item:hover { box-shadow:0 2px 8px rgba(0,0,0,0.08); }
        .task-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px; flex-wrap:wrap; gap:8px; }
        .task-meta { display:flex; gap:12px; flex-wrap:wrap; font-size:0.8rem; color:#666; }
        .task-meta span { display:flex; align-items:center; gap:4px; }
        .score-badge { padding:4px 12px; border-radius:20px; font-weight:700; font-size:0.85rem; color:#fff; }
        .score-high { background:#28a745; }
        .score-mid { background:#ffc107; color:#333; }
        .score-low { background:#dc3545; }
        .status-badge { padding:4px 10px; border-radius:8px; font-size:0.75rem; font-weight:600; }
        .status-pending { background:#fff3e0; color:#856404; }
        .status-confirmed { background:#e8f5e9; color:#2e7d32; }
        .status-rejected { background:#ffebee; color:#c62828; }
        .status-recall { background:#e3f2fd; color:#1565c0; }

        .comment-preview { background:#f8f9fa; border-radius:8px; padding:12px; font-size:0.85rem; line-height:1.5; margin:10px 0; max-height:120px; overflow-y:auto; }
        .actions { display:flex; gap:8px; margin-top:10px; flex-wrap:wrap; }
        .actions button { padding:6px 14px; border:none; border-radius:8px; font-size:0.8rem; cursor:pointer; font-weight:600; }
        .btn-confirm { background:#28a745; color:#fff; }
        .btn-reject { background:#dc3545; color:#fff; }
        .btn-recall { background:#1a73e8; color:#fff; }

        .rop-comment { width:100%; padding:8px; border:1px solid #ddd; border-radius:8px; font-size:0.85rem; margin-top:8px; }

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
        <span class="user">👤 <?= htmlspecialchars($_SESSION['name']) ?></span>
        <a href="logout.php" class="logout">Выйти</a>
    </div>

    <h2 style="margin-bottom:16px;">🛡️ Контроль качества звонков</h2>

    <div class="stats-bar">
        <div class="stat-card pending">
            <div class="value"><?= $status_counts['На проверке'] ?></div>
            <div class="label">⏳ На проверке</div>
        </div>
        <div class="stat-card confirmed">
            <div class="value"><?= $status_counts['Подтверждено'] ?></div>
            <div class="label">✅ Подтверждено</div>
        </div>
        <div class="stat-card rejected">
            <div class="value"><?= $status_counts['Отклонено'] ?></div>
            <div class="label">❌ Отклонено</div>
        </div>
        <div class="stat-card recall">
            <div class="value"><?= $status_counts['Перепрозвон'] ?></div>
            <div class="label">📞 Перепрозвон</div>
        </div>
    </div>

    <div class="card">
        <form class="filters" method="GET">
            <div>
                <label>Территория</label>
                <select name="territory">
                    <option value="">Все</option>
                    <?php foreach ($territories as $ter): ?>
                        <option value="<?= htmlspecialchars($ter) ?>" <?= $filter_territory === $ter ? 'selected' : '' ?>><?= htmlspecialchars($ter) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Статус</label>
                <select name="status">
                    <option value="">Все</option>
                    <option value="На проверке" <?= $filter_status === 'На проверке' ? 'selected' : '' ?>>На проверке</option>
                    <option value="Подтверждено" <?= $filter_status === 'Подтверждено' ? 'selected' : '' ?>>Подтверждено</option>
                    <option value="Отклонено" <?= $filter_status === 'Отклонено' ? 'selected' : '' ?>>Отклонено</option>
                    <option value="Перепрозвон" <?= $filter_status === 'Перепрозвон' ? 'selected' : '' ?>>Перепрозвон</option>
                </select>
            </div>
            <div>
                <label>С</label>
                <input type="date" name="date_from" value="<?= $filter_date_from ?>">
            </div>
            <div>
                <label>По</label>
                <input type="date" name="date_to" value="<?= $filter_date_to ?>">
            </div>
            <div>
                <label>&nbsp;</label>
                <button type="submit" class="btn-primary">🔍 Показать</button>
            </div>
            <div>
                <label>&nbsp;</label>
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn-outline" style="text-decoration:none; display:inline-block; padding:8px 16px;">📥 CSV</a>
            </div>
        </form>
    </div>

    <?php if (!empty($tb_stats)): ?>
    <div class="card">
        <h3>📊 Сводка по территориям</h3>
        <table class="tb-table">
            <thead>
                <tr>
                    <th>Территория</th>
                    <th>Всего на контроле</th>
                    <th>На проверке</th>
                    <th>Подтверждено</th>
                    <th>Отклонено</th>
                    <th>Перепрозвон</th>
                    <th>Средний скор</th>
                    <th>Менеджеров</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tb_stats as $s): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($s['territory_name']) ?></strong></td>
                    <td><?= $s['total_control'] ?></td>
                    <td><?= $s['pending'] ?></td>
                    <td style="color:#28a745;"><?= $s['confirmed'] ?></td>
                    <td style="color:#dc3545;"><?= $s['rejected'] ?></td>
                    <td style="color:#1a73e8;"><?= $s['recall'] ?></td>
                    <td>
                        <span class="score-badge <?= $s['avg_score'] >= 70 ? 'score-high' : ($s['avg_score'] >= 40 ? 'score-mid' : 'score-low') ?>">
                            <?= $s['avg_score'] ?>
                        </span>
                    </td>
                    <td><?= $s['managers_count'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="card">
        <h3>📋 Задачи на контроле (<?= count($control_tasks) ?>)</h3>

        <?php if (empty($control_tasks)): ?>
            <div class="empty-state">📭 Нет задач по выбранным фильтрам</div>
        <?php else: ?>
            <?php foreach ($control_tasks as $task): ?>
            <div class="task-item" data-control-id="<?= $task['control_id'] ?>">
                <div class="task-header">
                    <div>
                        <div style="font-weight:600; font-size:0.95rem;">
                            <?= htmlspecialchars($task['manager_name']) ?> 
                            <span style="font-weight:400; color:#888;">(таб. <?= htmlspecialchars($task['manager_tabel']) ?>)</span>
                        </div>
                        <div class="task-meta">
                            <span>🏢 <?= htmlspecialchars($task['territory_name']) ?></span>
                            <span>📅 <?= date('d.m.Y H:i', strtotime($task['created_at'])) ?></span>
                            <span>📞 Звонков: <?= $task['call_count'] ?></span>
                        </div>
                    </div>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <span class="score-badge <?= $task['fraud_score'] >= 70 ? 'score-high' : ($task['fraud_score'] >= 40 ? 'score-mid' : 'score-low') ?>">
                            <?= $task['fraud_score'] ?>
                        </span>
                        <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $task['control_status'])) ?>">
                            <?= $task['control_status'] ?>
                        </span>
                    </div>
                </div>

                <div class="comment-preview"><?= nl2br(htmlspecialchars(mb_substr($task['comment_text'], 0, 500))) ?></div>

                <?php if ($task['rop_comment']): ?>
                <div style="background:#fff3e0; border-radius:8px; padding:10px; margin:8px 0; font-size:0.85rem;">
                    <strong>💬 РОП:</strong> <?= htmlspecialchars($task['rop_comment']) ?>
                </div>
                <?php endif; ?>

                <?php if ($task['control_status'] === 'На проверке'): ?>
                <div class="actions">
                    <button class="btn-confirm" onclick="ropAction(<?= $task['control_id'] ?>, 'confirm')">✅ Подтвердить</button>
                    <button class="btn-reject" onclick="ropAction(<?= $task['control_id'] ?>, 'reject')">❌ Отклонить</button>
                    <button class="btn-recall" onclick="ropAction(<?= $task['control_id'] ?>, 'recall')">📞 Перепрозвон</button>
                </div>
                <textarea class="rop-comment" id="comment_<?= $task['control_id'] ?>" placeholder="Комментарий РОПа (обязателен при отклонении/перепрозвоне)..."></textarea>
                <?php endif; ?>

                <div style="margin-top:8px; font-size:0.75rem; color:#888;">
                    Задача: ...<?= htmlspecialchars(substr($task['task_id'], -8)) ?> | 
                    <a href="https://new-tortuga.sigma.sbrf.ru/tort/tasks/sales/<?= htmlspecialchars($task['task_id']) ?>" target="_blank" style="color:#1a73e8;">Открыть в Ритм</a>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
function ropAction(controlId, action) {
    const comment = document.getElementById('comment_' + controlId)?.value.trim() || '';

    if ((action === 'reject' || action === 'recall') && !comment) {
        alert('Укажите причину отклонения или перепрозвона');
        return;
    }

    if (!confirm('Подтвердить действие?')) return;

    fetch('api_rop_action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            control_id: controlId,
            action: action,
            comment: comment
        })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            location.reload();
        } else {
            alert('Ошибка: ' + d.error);
        }
    })
    .catch(() => alert('Ошибка соединения'));
}
</script>
</body>
</html>