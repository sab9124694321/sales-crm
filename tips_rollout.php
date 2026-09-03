<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once 'db.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['name'];
$role = $_SESSION['role'];
$tabel_number = $_SESSION['tabel'];

// ── Права на экспорт: все, кроме менеджеров ──
$can_export = ($role !== 'manager');

// ── Обработка экспорта ──
if (isset($_GET['export']) && $can_export) {
    $export_type = $_GET['export']; // 'command' или 'personal'
    $filter_tb = $_GET['filter_tb'] ?? '';
    $filter_uuid = $_GET['filter_uuid'] ?? '';
    $filter_tid = $_GET['filter_tid'] ?? '';
    $filter_imei = $_GET['filter_imei'] ?? '';
    $filter_sn = $_GET['filter_sn'] ?? '';
    $filter_user = $_GET['filter_user'] ?? '';
    $filter_date_from = $_GET['filter_date_from'] ?? '';
    $filter_date_to = $_GET['filter_date_to'] ?? '';

    $sql = "SELECT * FROM tips_rollout WHERE type = ?";
    $params = [$export_type];

    if ($filter_tb !== '') {
        $sql .= " AND tb LIKE ?";
        $params[] = '%' . $filter_tb . '%';
    }
    if ($filter_uuid !== '') {
        $sql .= " AND (public_uuid LIKE ? OR uuid LIKE ?)";
        $params[] = '%' . $filter_uuid . '%';
        $params[] = '%' . $filter_uuid . '%';
    }
    if ($filter_tid !== '') {
        $sql .= " AND tid LIKE ?";
        $params[] = '%' . $filter_tid . '%';
    }
    if ($filter_imei !== '') {
        $sql .= " AND imei1 LIKE ?";
        $params[] = '%' . $filter_imei . '%';
    }
    if ($filter_sn !== '') {
        $sql .= " AND sn_kkt LIKE ?";
        $params[] = '%' . $filter_sn . '%';
    }
    if ($filter_user !== '') {
        $sql .= " AND user_name LIKE ?";
        $params[] = '%' . $filter_user . '%';
    }
    if ($filter_date_from !== '') {
        $sql .= " AND date(created_at) >= ?";
        $params[] = $filter_date_from;
    }
    if ($filter_date_to !== '') {
        $sql .= " AND date(created_at) <= ?";
        $params[] = $filter_date_to;
    }
    $sql .= " ORDER BY created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($export_type === 'command') {
        $filename = 'СЗБ_Командные_Реестр_Чаевые_ПОС_' . date('Y-m-d') . '.csv';
        $headers = ['ТБ', 'Публичный идентификатор команды (UUID)', 'TID (Терминала)'];
        $rows = [];
        foreach ($records as $r) {
            $rows[] = [$r['tb'], $r['public_uuid'], $r['tid']];
        }
    } else {
        $filename = 'СЗБ_Персональные_Чаевые_Реестр_' . date('Y-m-d') . '.csv';
        $headers = ['TID (головной)', 'UUID команды чаевых', 'IMEI1', 'СН ККТ (стикер)', 'Подключены ранее', 'Ссылка'];
        $rows = [];
        foreach ($records as $r) {
            $rows[] = [
                $r['tid'],
                $r['uuid'],
                $r['imei1'],
                $r['sn_kkt'],
                $r['previously_connected'] ? 'Да' : 'Нет',
                $r['link']
            ];
        }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, $headers, ';', '"', '\\');
    foreach ($rows as $row) fputcsv($output, $row, ';', '"', '\\');
    fclose($output);
    exit;
}

// ── Получаем следующую пятницу ──
$next_friday = new DateTime('next friday');
$send_date = $next_friday->format('d.m.Y');
$appearance_date = (clone $next_friday)->modify('+2 weeks')->format('d.m.Y');

$msg = '';
$saved = isset($_GET['saved']) && $_GET['saved'] == 1;

// ── Обработка сохранения ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_one'])) {
    $type = $_POST['type'] ?? 'command';
    $tb = trim($_POST['tb'] ?? 'СЗБ');
    $public_uuid = trim($_POST['public_uuid'] ?? '');
    $tid = trim($_POST['tid'] ?? '');
    $uuid = trim($_POST['uuid'] ?? '');
    $imei1 = trim($_POST['imei1'] ?? '');
    $sn_kkt = trim($_POST['sn_kkt'] ?? '');
    $previously_connected = ($_POST['previously_connected'] ?? '1') == '1' ? 1 : 0;

    $errors = [];
    if ($type === 'command') {
        if (empty($tid)) $errors[] = 'TID обязателен';
        if (empty($public_uuid)) $errors[] = 'UUID команды обязателен';
    } elseif ($type === 'personal') {
        if (empty($tid)) $errors[] = 'TID обязателен';
        if (empty($uuid)) $errors[] = 'UUID команды чаевых обязателен';
        if (empty($imei1)) $errors[] = 'IMEI1 обязателен';
        if (empty($sn_kkt)) $errors[] = 'СН ККТ (стикер) обязателен';
    } else {
        $errors[] = 'Выберите тип';
    }

    if (empty($errors)) {
        $link = $type === 'personal' ? 'https://pay.sbtips.ru/t/' . $uuid : '';
        $stmt = $pdo->prepare("
            INSERT INTO tips_rollout 
            (type, tb, public_uuid, tid, uuid, imei1, sn_kkt, link, previously_connected, user_id, user_name)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $type, $tb, $public_uuid, $tid, $uuid, $imei1, $sn_kkt, $link, $previously_connected, $user_id, $user_name
        ]);
        header('Location: tips_rollout.php?saved=1');
        exit;
    } else {
        $msg = '<div class="alert alert-danger">❌ ' . implode('<br>', $errors) . '</div>';
    }
}

if ($saved && empty($msg)) {
    $msg = '<div class="alert alert-success">
        ✅ Запись сохранена!<br>
        📅 Отправка в ЦА: <strong>' . $send_date . '</strong><br>
        📅 Ожидаемая дата появления на терминале: <strong>' . $appearance_date . '</strong>
    </div>';
}

// ── Фильтры ──
$filter_type = $_GET['filter_type'] ?? '';
$filter_tb = $_GET['filter_tb'] ?? '';
$filter_uuid = $_GET['filter_uuid'] ?? '';
$filter_tid = $_GET['filter_tid'] ?? '';
$filter_imei = $_GET['filter_imei'] ?? '';
$filter_sn = $_GET['filter_sn'] ?? '';
$filter_user = $_GET['filter_user'] ?? '';
$filter_date_from = $_GET['filter_date_from'] ?? '';
$filter_date_to = $_GET['filter_date_to'] ?? '';
$filter_previously = $_GET['filter_previously'] ?? '';

$sql = "SELECT * FROM tips_rollout WHERE 1=1";
$params = [];

if ($filter_type !== '') {
    $sql .= " AND type = ?";
    $params[] = $filter_type;
}
if ($filter_tb !== '') {
    $sql .= " AND tb LIKE ?";
    $params[] = '%' . $filter_tb . '%';
}
if ($filter_uuid !== '') {
    $sql .= " AND (public_uuid LIKE ? OR uuid LIKE ?)";
    $params[] = '%' . $filter_uuid . '%';
    $params[] = '%' . $filter_uuid . '%';
}
if ($filter_tid !== '') {
    $sql .= " AND tid LIKE ?";
    $params[] = '%' . $filter_tid . '%';
}
if ($filter_imei !== '') {
    $sql .= " AND imei1 LIKE ?";
    $params[] = '%' . $filter_imei . '%';
}
if ($filter_sn !== '') {
    $sql .= " AND sn_kkt LIKE ?";
    $params[] = '%' . $filter_sn . '%';
}
if ($filter_user !== '') {
    $sql .= " AND user_name LIKE ?";
    $params[] = '%' . $filter_user . '%';
}
if ($filter_date_from !== '') {
    $sql .= " AND date(created_at) >= ?";
    $params[] = $filter_date_from;
}
if ($filter_date_to !== '') {
    $sql .= " AND date(created_at) <= ?";
    $params[] = $filter_date_to;
}
if ($filter_previously !== '') {
    $sql .= " AND previously_connected = ?";
    $params[] = (int)$filter_previously;
}

$sql .= " ORDER BY created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($records as &$r) {
    $created = new DateTime($r['created_at']);
    $friday = clone $created;
    $friday->modify('next friday');
    $r['send_date'] = $friday->format('d.m.Y');
    $r['appearance_date'] = (clone $friday)->modify('+2 weeks')->format('d.m.Y');
}
unset($r);
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>📱 Раскатка чаевых</title><meta name="viewport" content="width=device-width, initial-scale=1"><style>
*{margin:0;padding:0;box-sizing:border-box}body{background:#f0f2f5;font-family:system-ui;padding:12px}.container{max-width:1400px;margin:0 auto}.navbar{background:linear-gradient(135deg,#1a1a2e,#16213e);color:#fff;padding:12px 16px;border-radius:16px;margin-bottom:20px;display:flex;justify-content:space-between;flex-wrap:wrap;align-items:center}.logo{font-size:1.3rem;font-weight:bold}.nav-links{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.nav-links a{color:#ccc;text-decoration:none;font-size:0.85rem}.nav-links .user-info{color:#fff;font-weight:bold;margin-left:auto;font-size:0.9rem}.panel{background:#fff;border-radius:16px;padding:16px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.05)}.panel h3{margin:0 0 12px;font-size:1rem;color:#202124}.filter-bar{background:#f8f9fa;border-radius:16px;padding:12px 16px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}.filter-bar .form-group{flex:1 1 100px;min-width:80px}.filter-bar label{display:block;font-size:0.7rem;font-weight:600;color:#444;margin-bottom:2px}.filter-bar input,.filter-bar select{width:100%;padding:5px 8px;border:1px solid #ccc;border-radius:8px;font-size:0.8rem}.filter-bar .actions{display:flex;gap:6px;align-items:center;margin-left:auto;flex-wrap:wrap}.btn{padding:5px 12px;border:none;border-radius:8px;cursor:pointer;font-size:0.8rem;font-weight:500;transition:0.2s}.btn-primary{background:#1a73e8;color:#fff}.btn-primary:hover{background:#1557b0}.btn-success{background:#e6f4ea;color:#188038}.btn-success:hover{background:#ceead6}.btn-secondary{background:#f1f3f4;color:#3c4043;text-decoration:none}.btn-secondary:hover{background:#e8eaed}.form-row{display:flex;flex-wrap:wrap;gap:16px;margin-bottom:16px;align-items:flex-end}.form-group{flex:1;min-width:140px}.form-group label{display:block;font-size:0.7rem;font-weight:600;color:#444;margin-bottom:4px}.form-group input,.form-group select{width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:12px;font-size:0.9rem}.radio-group{display:flex;gap:20px;margin-bottom:16px;font-weight:500}.field-group{display:none}.field-group.active{display:block}.table-wrap{overflow-x:auto}.table-wrap table{width:100%;border-collapse:collapse;font-size:0.85rem}.table-wrap th,.table-wrap td{padding:8px 10px;text-align:left;border-bottom:1px solid #e8eaed}.table-wrap th{background:#f8f9fa;font-weight:600;color:#5f6368}.badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:500}.badge-personal{background:#e8f0fe;color:#1a73e8}.badge-command{background:#e6f4ea;color:#188038}.hint{font-size:0.75rem;color:#666;margin-top:4px}.alert{padding:12px;border-radius:8px;margin-bottom:15px}.alert-success{background:#d4edda;color:#155724}.alert-danger{background:#f8d7da;color:#721c24}.flex{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
</style></head>
<body><div class="container">
<div class="navbar">
<div class="logo">🚀 SZB</div>
<div class="nav-links">
    <a href="dashboard.php">Дашборд</a>
    <a href="team.php">Команда</a>
    <a href="territories.php">Территории</a>
    <a href="export_inn.php">ИНН</a>
    <a href="quests.php">Квесты</a>
    <a href="calls.php">📞 Я звоню</a>
    <a href="rop_control.php">🛡️ Контроль</a>
    <a href="ai_dashboard.php">AI</a>
    <?php if ($role == 'admin'): ?>
        <a href="admin.php">Админ</a>
    <?php endif; ?>
    <a href="tips_rollout.php" style="font-weight:600;">📱 Чаевые</a>
    <span class="user-info">👤 <?= htmlspecialchars($user_name) ?></span>
</div>
</div>

<?= $msg ?>

<div class="panel">
    <h3>➕ Добавить запись</h3>
    <form method="POST" id="addForm">
        <div class="radio-group">
            <label><input type="radio" name="type" value="command" checked onchange="toggleFields()"> Командные чаевые</label>
            <label><input type="radio" name="type" value="personal" onchange="toggleFields()"> Персональные чаевые</label>
        </div>

        <div id="command-fields" class="field-group active">
            <div class="form-row">
                <div class="form-group"><label>ТБ</label><input type="text" name="tb" value="СЗБ" placeholder="СЗБ"></div>
                <div class="form-group"><label>UUID команды</label><input type="text" name="public_uuid" placeholder="1000173053" required></div>
                <div class="form-group"><label>TID терминала</label><input type="text" name="tid" placeholder="44626876" required></div>
            </div>
        </div>

        <div id="personal-fields" class="field-group">
            <div class="form-row">
                <div class="form-group"><label>TID (головной)</label><input type="text" name="tid" placeholder="12340567" required></div>
                <div class="form-group"><label>UUID команды чаевых</label><input type="text" name="uuid" placeholder="1001000877" required></div>
                <div class="form-group"><label>IMEI1</label><input type="text" name="imei1" placeholder="866805064750552" required></div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>СН ККТ (стикер) *</label>
                    <input type="text" name="sn_kkt" placeholder="Номер на стикере" required>
                    <div class="hint">📌 Посмотреть на стикере на кассе</div>
                </div>
                <div class="form-group">
                    <label>Подключены ли командные чаевые ранее?</label>
                    <select name="previously_connected">
                        <option value="1" selected>Да</option>
                        <option value="0">Нет</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Ссылка на команду чаевых (генерируется)</label>
                <input type="text" id="generatedLink" readonly style="background:#f5f5f5; color:#1a73e8;">
            </div>
        </div>

        <button type="submit" name="save_one" class="btn btn-primary" id="saveBtn" onclick="this.textContent='⏳ Сохранение...'; this.disabled=true; document.getElementById('addForm').submit();">💾 Сохранить</button>
    </form>
</div>

<div class="panel">
    <h3>📋 Список записей</h3>

    <form id="filterForm" method="GET" class="filter-bar">
        <div class="form-group"><label>Тип</label><select name="filter_type"><option value="">Все</option><option value="command" <?= $filter_type=='command'?'selected':'' ?>>Командные</option><option value="personal" <?= $filter_type=='personal'?'selected':'' ?>>Персональные</option></select></div>
        <div class="form-group"><label>ТБ</label><input type="text" name="filter_tb" value="<?= htmlspecialchars($filter_tb) ?>" placeholder="СЗБ"></div>
        <div class="form-group"><label>UUID</label><input type="text" name="filter_uuid" value="<?= htmlspecialchars($filter_uuid) ?>" placeholder="1000173053"></div>
        <div class="form-group"><label>TID</label><input type="text" name="filter_tid" value="<?= htmlspecialchars($filter_tid) ?>" placeholder="44626876"></div>
        <div class="form-group"><label>IMEI1</label><input type="text" name="filter_imei" value="<?= htmlspecialchars($filter_imei) ?>" placeholder="866805064750552"></div>
        <div class="form-group"><label>СН ККТ</label><input type="text" name="filter_sn" value="<?= htmlspecialchars($filter_sn) ?>" placeholder="Номер стикера"></div>
        <div class="form-group"><label>Подключены</label><select name="filter_previously"><option value="">Все</option><option value="1" <?= $filter_previously=='1'?'selected':'' ?>>Да</option><option value="0" <?= $filter_previously=='0'?'selected':'' ?>>Нет</option></select></div>
        <div class="form-group"><label>Кто добавил</label><input type="text" name="filter_user" value="<?= htmlspecialchars($filter_user) ?>" placeholder="ФИО"></div>
        <div class="form-group"><label>Дата с</label><input type="date" name="filter_date_from" value="<?= htmlspecialchars($filter_date_from) ?>"></div>
        <div class="form-group"><label>Дата по</label><input type="date" name="filter_date_to" value="<?= htmlspecialchars($filter_date_to) ?>"></div>
        <div class="actions">
            <button type="submit" class="btn btn-primary">🔍</button>
            <a href="?filter_type=&filter_tb=&filter_uuid=&filter_tid=&filter_imei=&filter_sn=&filter_previously=&filter_user=&filter_date_from=&filter_date_to=" class="btn btn-secondary">✖</a>
            <?php if ($can_export): ?>
            <a href="?export=command&<?= http_build_query(array_intersect_key($_GET, ['filter_tb'=>'','filter_uuid'=>'','filter_tid'=>'','filter_imei'=>'','filter_sn'=>'','filter_user'=>'','filter_date_from'=>'','filter_date_to'=>'','filter_previously'=>''])) ?>" class="btn btn-success">📥 Командные</a>
            <a href="?export=personal&<?= http_build_query(array_intersect_key($_GET, ['filter_tb'=>'','filter_uuid'=>'','filter_tid'=>'','filter_imei'=>'','filter_sn'=>'','filter_user'=>'','filter_date_from'=>'','filter_date_to'=>'','filter_previously'=>''])) ?>" class="btn btn-success">📥 Персональные</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="flex" style="margin-bottom:8px;"><span style="font-size:0.85rem;color:#5f6368;">Найдено: <?= count($records) ?></span></div>

    <div class="table-wrap">
        <table>
            <tr><th>Тип</th><th>ТБ</th><th>UUID</th><th>TID</th><th>IMEI1</th><th>СН ККТ</th><th>Подключены</th><th>Ссылка</th><th>Отправка</th><th>Ожидаемая дата</th><th>Кто добавил</th></tr>
            <?php foreach ($records as $r): ?>
            <tr>
                <td><span class="badge <?= $r['type']==='personal'?'badge-personal':'badge-command' ?>"><?= $r['type']==='personal'?'Персональные':'Командные' ?></span></td>
                <td><?= htmlspecialchars($r['tb']) ?></td>
                <td><?= htmlspecialchars($r['public_uuid'] ?: $r['uuid']) ?></td>
                <td><?= htmlspecialchars($r['tid']) ?></td>
                <td><?= htmlspecialchars($r['imei1']) ?></td>
                <td><?= htmlspecialchars($r['sn_kkt']) ?></td>
                <td><?= $r['previously_connected'] ? 'Да' : 'Нет' ?></td>
                <td><?= $r['link'] ? '<a href="'.$r['link'].'" target="_blank">ссылка</a>' : '' ?></td>
                <td><?= $r['send_date'] ?></td>
                <td><?= $r['appearance_date'] ?></td>
                <td><?= htmlspecialchars($r['user_name']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($records)): ?><tr><td colspan="11" style="text-align:center;color:#999;padding:20px;">Нет записей</td></tr><?php endif; ?>
        </table>
    </div>
</div>

<script>
function toggleFields() {
    const type = document.querySelector('input[name="type"]:checked').value;
    document.getElementById('command-fields').classList.toggle('active', type === 'command');
    document.getElementById('personal-fields').classList.toggle('active', type === 'personal');
}
document.addEventListener('DOMContentLoaded', function() {
    toggleFields();
    document.querySelector('input[name="uuid"]')?.addEventListener('input', function() {
        const val = this.value.trim();
        document.getElementById('generatedLink').value = val ? 'https://pay.sbtips.ru/t/' + val : '';
    });
});
</script>
</div></body></html>