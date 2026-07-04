<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['mmb_tp_head', 'head'])) {
    header('Location: dashboard.php');
    exit;
}
require_once 'db.php';

$requests = $pdo->query("SELECT sr.*, rt.name as type_name, u.full_name as creator_name 
    FROM support_requests sr 
    LEFT JOIN support_request_types rt ON sr.request_type_id = rt.id 
    LEFT JOIN users u ON sr.created_by_tabel = u.tabel_number
    ORDER BY sr.created_at DESC")->fetchAll();

// Агрегация по статусам
$stats = ['new'=>0, 'in_progress'=>0, 'waiting_for_mmb'=>0, 'closed'=>0];
foreach ($requests as $r) {
    if (isset($stats[$r['status']])) $stats[$r['status']]++;
    else $stats[$r['status']] = 1;
}

// Просрочки
$now = date('Y-m-d H:i:s');
$overdue_resp = 0;
$overdue_resol = 0;
foreach ($requests as $r) {
    if ($r['first_response_deadline'] < $now && is_null($r['first_response_at'])) $overdue_resp++;
    if ($r['resolution_deadline'] < $now && is_null($r['resolved_at']) && $r['status'] !== 'closed') $overdue_resol++;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Отчёт руководителя ММБ</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .card { background:#fff; border-radius:16px; padding:20px; margin-bottom:20px; }
        .stats { display:flex; gap:20px; flex-wrap:wrap; }
        .stat-item { background:#f0f2f5; border-radius:12px; padding:15px; text-align:center; flex:1; }
        .stat-number { font-size:28px; font-weight:bold; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:8px; text-align:left; border-bottom:1px solid #eee; }
        .nav { display:flex; gap:10px; margin-bottom:20px; }
        .btn-sm { background:#6c757d; padding:4px 8px; border-radius:6px; color:#fff; text-decoration:none; font-size:12px; }
        tr.overdue { background-color: #ffe3e3; }
        tr.warning { background-color: #fff3cd; }
        tr.success { background-color: #d4edda; }
        tr:hover { filter: brightness(0.97); }
        .filter-row { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:15px; background:#f8f9fa; padding:12px; border-radius:12px; }
        .filter-input { flex:1; min-width:120px; padding:6px 10px; border:1px solid #ccc; border-radius:8px; font-size:0.8rem; }
        .clear-filters { background:#6c757d; color:white; border:none; padding:6px 12px; border-radius:8px; cursor:pointer; }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="dashboard.php">📊 Дашборд</a>
        <a href="mmb_dashboard.php">🆘 Поддержка ММБ</a>
        <a href="mmb_head_dashboard.php" class="active">📈 Отчёт руководителя</a>
        <span style="margin-left:auto;">👤 <?= htmlspecialchars($_SESSION['name']) ?> | <a href="logout.php">Выйти</a></span>
    </div>
    <h2>Аналитика по обращениям ММБ</h2>
    <div class="stats">
        <div class="stat-item"><div class="stat-number"><?= count($requests) ?></div>Всего обращений</div>
        <div class="stat-item"><div class="stat-number"><?= $stats['new'] ?></div>Новые</div>
        <div class="stat-item"><div class="stat-number"><?= $stats['in_progress'] ?></div>В работе</div>
        <div class="stat-item"><div class="stat-number"><?= $stats['waiting_for_mmb'] ?></div>Ожидают ММБ</div>
        <div class="stat-item"><div class="stat-number"><?= $stats['closed'] ?></div>Закрытые</div>
        <div class="stat-item"><div class="stat-number" style="color:#dc3545;"><?= $overdue_resp ?></div>Просрочка первого ответа</div>
        <div class="stat-item"><div class="stat-number" style="color:#dc3545;"><?= $overdue_resol ?></div>Просрочка решения</div>
    </div>
    <div class="card">
        <h3>Список всех обращений</h3>
        <div class="filter-row">
            <input type="text" class="filter-input" data-column="0" placeholder="Фильтр по тикету">
            <input type="text" class="filter-input" data-column="1" placeholder="Фильтр по клиенту">
            <input type="text" class="filter-input" data-column="2" placeholder="Фильтр по типу">
            <input type="text" class="filter-input" data-column="3" placeholder="Фильтр по создателю">
            <input type="text" class="filter-input" data-column="4" placeholder="Фильтр по статусу">
            <input type="text" class="filter-input" data-column="5" placeholder="Фильтр по сроку ответа">
            <input type="text" class="filter-input" data-column="6" placeholder="Фильтр по сроку решения">
            <button class="clear-filters" id="clearFilters">Сбросить</button>
        </div>
        <div style="overflow-x:auto;">
            <table id="ticketsTable">
                <thead>
                    <tr><th>Тикет</th><th>Клиент</th><th>Тип</th><th>Создал</th><th>Статус</th><th>Срок ответа</th><th>Срок решения</th><th></th></tr>
                </thead>
                <tbody>
                <?php 
                $now_ts = time();
                $warning_hours = 24;
                foreach ($requests as $r):
                    $row_class = '';
                    $deadline_resp = $r['first_response_deadline'] ? strtotime($r['first_response_deadline']) : null;
                    $deadline_resol = $r['resolution_deadline'] ? strtotime($r['resolution_deadline']) : null;
                    $closed_ts = ($r['status'] == 'closed') ? strtotime($r['closed_at'] ?? $r['updated_at']) : null;
                    
                    if ($r['status'] == 'closed' && $deadline_resol && $closed_ts) {
                        if ($closed_ts <= $deadline_resol) $row_class = 'success';
                        else $row_class = 'overdue';
                    } else {
                        $closest_deadline = null;
                        if ($deadline_resp && !$r['first_response_at']) $closest_deadline = $deadline_resp;
                        if ($deadline_resol && !$r['resolved_at']) {
                            if ($closest_deadline === null || $deadline_resol < $closest_deadline) $closest_deadline = $deadline_resol;
                        }
                        if ($closest_deadline) {
                            if ($closest_deadline < $now_ts) $row_class = 'overdue';
                            elseif ($closest_deadline - $now_ts < $warning_hours * 3600) $row_class = 'warning';
                        }
                    }
                ?>
                    <tr class="<?= $row_class ?>" data-ticket="<?= htmlspecialchars($r['ticket_number']) ?>"
                        data-client="<?= htmlspecialchars($r['client_name']) ?>"
                        data-type="<?= htmlspecialchars($r['type_name']) ?>"
                        data-creator="<?= htmlspecialchars($r['creator_name']) ?>"
                        data-status="<?= htmlspecialchars($r['status']) ?>"
                        data-resp-deadline="<?= htmlspecialchars($r['first_response_deadline']) ?>"
                        data-resol-deadline="<?= htmlspecialchars($r['resolution_deadline']) ?>">
                        <td><a href="ticket_view.php?id=<?= $r['id'] ?>"><?= htmlspecialchars($r['ticket_number']) ?></a></td>
                        <td><?= htmlspecialchars($r['client_name']) ?></td>
                        <td><?= htmlspecialchars($r['type_name']) ?></td>
                        <td><?= htmlspecialchars($r['creator_name']) ?></td>
                        <td><?= htmlspecialchars($r['status']) ?></td>
                        <td><?= htmlspecialchars($r['first_response_deadline']) ?></td>
                        <td><?= htmlspecialchars($r['resolution_deadline']) ?></td>
                        <td><a href="ticket_view.php?id=<?= $r['id'] ?>" class="btn-sm">Открыть</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
    const filters = document.querySelectorAll('.filter-input');
    const tableRows = document.querySelectorAll('#ticketsTable tbody tr');
    const clearBtn = document.getElementById('clearFilters');
    function filterTable() {
        const filterValues = Array.from(filters).map(f => f.value.trim().toLowerCase());
        tableRows.forEach(row => {
            let show = true;
            const rowData = [
                row.getAttribute('data-ticket')?.toLowerCase() || '',
                row.getAttribute('data-client')?.toLowerCase() || '',
                row.getAttribute('data-type')?.toLowerCase() || '',
                row.getAttribute('data-creator')?.toLowerCase() || '',
                row.getAttribute('data-status')?.toLowerCase() || '',
                row.getAttribute('data-resp-deadline')?.toLowerCase() || '',
                row.getAttribute('data-resol-deadline')?.toLowerCase() || ''
            ];
            for (let i = 0; i < filterValues.length; i++) {
                if (filterValues[i] !== '' && !rowData[i].includes(filterValues[i])) {
                    show = false;
                    break;
                }
            }
            row.style.display = show ? '' : 'none';
        });
    }
    filters.forEach(filter => filter.addEventListener('input', filterTable));
    clearBtn.addEventListener('click', () => {
        filters.forEach(f => f.value = '');
        filterTable();
    });
</script>
</body>
</html>