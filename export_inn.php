<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once 'db.php';

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$user_tabel = $_SESSION['tabel'];

// Правильная логика доступа: начальники (имеют подчинённых) могут редактировать, менеджеры – только просмотр
$is_manager = ($user_role === 'manager' || $user_role === 'ubr_middle' || $user_role === 'mmb_manager');
$is_head = in_array($user_role, ['head', 'territory_head', 'mmb_tp_head', 'admin', 'terman']);
$can_edit = $is_head;

// ------------------------------------------------------------------
// МИГРАЦИЯ: добавляем новые поля и таблицу истории
// ------------------------------------------------------------------
function ensureColumns($pdo) {
    $existing = $pdo->query("PRAGMA table_info(inn_records)")->fetchAll(PDO::FETCH_COLUMN, 1);
    $newColumns = [
        'is_installed'        => "ALTER TABLE inn_records ADD COLUMN is_installed INTEGER DEFAULT 0",
        'checked_performance' => "ALTER TABLE inn_records ADD COLUMN checked_performance INTEGER DEFAULT 0",
        'checked_turnover'    => "ALTER TABLE inn_records ADD COLUMN checked_turnover INTEGER DEFAULT 0",
        'expected_turnover'   => "ALTER TABLE inn_records ADD COLUMN expected_turnover DECIMAL(12,2) DEFAULT 0",
        'client_type'         => "ALTER TABLE inn_records ADD COLUMN client_type TEXT DEFAULT 'new'",
    ];
    foreach ($newColumns as $col => $sql) {
        if (!in_array($col, $existing)) {
            $pdo->exec($sql);
        }
    }
    
    // Создаём таблицу истории проверок, если её нет
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS check_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            check_type TEXT NOT NULL,
            performed_by TEXT NOT NULL,
            performed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            count INTEGER DEFAULT 0
        )
    ");
}
ensureColumns($pdo);

// ------------------------------------------------------------------
// AJAX-обработчик для быстрого обновления чекбоксов
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update'])) {
    if (!$can_edit) {
        echo json_encode(['success' => false, 'error' => 'Нет прав']);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    $field = $_POST['field'] ?? '';
    $value = (int)($_POST['value'] ?? 0);
    $allowed_fields = ['is_installed', 'checked_performance', 'checked_turnover'];
    
    // Обработка отдельного поля "Целевой список" (обновляем station_type)
    if ($field === 'is_target') {
        $new_station = $value ? 'target' : 'newreg';
        $stmt = $pdo->prepare("UPDATE inn_records SET station_type = ? WHERE id = ?");
        $stmt->execute([$new_station, $id]);
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($id > 0 && in_array($field, $allowed_fields)) {
        $stmt = $pdo->prepare("UPDATE inn_records SET $field = ? WHERE id = ?");
        $stmt->execute([$value, $id]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
    }
    exit;
}

// ------------------------------------------------------------------
// Функция получения команды (всех подчинённых, включая самого руководителя)
// ------------------------------------------------------------------
function getTeamEmployees($pdo, $user_id, $user_tabel, $user_role) {
    if ($user_role === 'admin') {
        $stmt = $pdo->prepare("SELECT tabel_number, full_name FROM users WHERE is_active = 1 ORDER BY full_name");
        $stmt->execute();
        return $stmt->fetchAll();
    } elseif ($user_role === 'terman') {
        $stmt = $pdo->prepare("
            SELECT u.tabel_number, u.full_name 
            FROM users u
            JOIN territory_managers tm ON u.territory_id = tm.territory_id
            WHERE tm.manager_id = ? AND u.is_active = 1
            ORDER BY u.full_name
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("
            SELECT tabel_number, full_name FROM users 
            WHERE is_active = 1 AND (manager_id = ? OR id = ?)
            ORDER BY full_name
        ");
        $stmt->execute([$user_id, $user_id]);
        return $stmt->fetchAll();
    }
}

$accessible_employees = getTeamEmployees($pdo, $user_id, $user_tabel, $user_role);
$employee_options = [];
foreach ($accessible_employees as $emp) {
    $employee_options[$emp['tabel_number']] = $emp['full_name'];
}

// ------------------------------------------------------------------
// Обработка удаления и редактирования (только для начальников)
// ------------------------------------------------------------------
if (isset($_GET['delete']) && $can_edit) {
    $pdo->prepare("DELETE FROM inn_records WHERE id = ?")->execute([$_GET['delete']]);
    header("Location: export_inn.php?" . http_build_query(array_diff_key($_GET, ['delete' => ''])));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_id']) && $can_edit) {
    $stmt = $pdo->prepare("UPDATE inn_records SET inn = ?, product = ?, sale_date = ?, is_installed = ?, checked_performance = ?, checked_turnover = ?, expected_turnover = ?, client_type = ?, station_type = ? WHERE id = ?");
    $stmt->execute([
        trim($_POST['inn']),
        $_POST['product'],
        $_POST['sale_date'],
        isset($_POST['is_installed']) ? 1 : 0,
        isset($_POST['checked_performance']) ? 1 : 0,
        isset($_POST['checked_turnover']) ? 1 : 0,
        (float)($_POST['expected_turnover'] ?? 0),
        $_POST['client_type'] ?? 'new',
        isset($_POST['is_target']) && $_POST['is_target'] ? 'target' : 'newreg',
        $_POST['edit_id']
    ]);
    header("Location: export_inn.php?" . http_build_query(array_diff_key($_GET, ['edit_id' => ''])));
    exit;
}

// ------------------------------------------------------------------
// Обработка массовой проверки по списку ИНН (только для начальников)
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_list']) && $can_edit) {
    $check_type = $_POST['check_type'] ?? '';
    $inn_list_text = trim($_POST['inn_list'] ?? '');
    if (!empty($inn_list_text) && in_array($check_type, ['performance', 'turnover', 'target'])) {
        $inn_list = preg_split('/[\s,;]+/u', $inn_list_text);
        $inn_list = array_filter(array_map('trim', $inn_list), fn($v) => $v !== '');
        if (!empty($inn_list)) {
            $placeholders = implode(',', array_fill(0, count($inn_list), '?'));
            if ($check_type === 'target') {
                $sql = "UPDATE inn_records SET station_type = 'target' WHERE inn IN ($placeholders)";
            } else {
                $col = $check_type === 'performance' ? 'checked_performance' : 'checked_turnover';
                $sql = "UPDATE inn_records SET $col = 1 WHERE inn IN ($placeholders) AND $col = 0";
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($inn_list);
            $count = $stmt->rowCount();
            
            // Записываем в историю
            $stmt = $pdo->prepare("INSERT INTO check_history (check_type, performed_by, count) VALUES (?, ?, ?)");
            $stmt->execute([$check_type, $user_tabel, $count]);
        }
    }
    $back_url = 'export_inn.php?' . http_build_query(array_diff_key($_GET, ['check_list' => '']));
    header("Location: " . $back_url);
    exit;
}

// ------------------------------------------------------------------
// Получаем даты последних проверок
// ------------------------------------------------------------------
$last_checks = [];
foreach (['performance', 'turnover', 'target'] as $type) {
    $stmt = $pdo->prepare("SELECT performed_at, count FROM check_history WHERE check_type = ? ORDER BY performed_at DESC LIMIT 1");
    $stmt->execute([$type]);
    $row = $stmt->fetch();
    if ($row) {
        $last_checks[$type] = [
            'date' => date('d.m.Y H:i', strtotime($row['performed_at'])),
            'count' => $row['count']
        ];
    } else {
        $last_checks[$type] = null;
    }
}

// ------------------------------------------------------------------
// Параметры фильтрации
// ------------------------------------------------------------------
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to'] ?? '';
$products_param = $_GET['products'] ?? '';
$products_selected = $products_param !== '' ? explode(',', $products_param) : [];
$filter_by_products = !empty($products_selected);
$employee_tabel = $_GET['employee'] ?? '';
if ($employee_tabel !== '' && !isset($employee_options[$employee_tabel])) {
    $employee_tabel = '';
}

$is_key_filter = $_GET['is_key'] ?? '';
$station_type_filter = $_GET['station_type'] ?? '';
$client_type_filter = $_GET['client_type'] ?? '';
$is_installed_filter = $_GET['is_installed'] ?? '';
$checked_performance_filter = $_GET['checked_performance'] ?? '';
$checked_turnover_filter = $_GET['checked_turnover'] ?? '';

$where = [];
$params = [];

if (!empty($date_from)) {
    $where[] = "sale_date >= ?";
    $params[] = $date_from;
}
if (!empty($date_to)) {
    $where[] = "sale_date <= ?";
    $params[] = $date_to;
}
if ($filter_by_products) {
    $placeholders = implode(',', array_fill(0, count($products_selected), '?'));
    $where[] = "product IN ($placeholders)";
    $params = array_merge($params, $products_selected);
}

$cols = $pdo->query("PRAGMA table_info(inn_records)")->fetchAll(PDO::FETCH_COLUMN, 1);
$hasKey = in_array('is_key', $cols);
$hasStation = in_array('station_type', $cols);
$hasClient = in_array('client_type', $cols);

if ($hasKey && $is_key_filter !== '') {
    $where[] = "is_key = ?";
    $params[] = $is_key_filter === 'key' ? 1 : 0;
}
if ($hasStation && $station_type_filter !== '') {
    $where[] = "station_type = ?";
    $params[] = $station_type_filter;
}
if ($hasClient && $client_type_filter !== '') {
    $where[] = "client_type = ?";
    $params[] = $client_type_filter;
}
if ($is_installed_filter !== '') {
    $where[] = "is_installed = ?";
    $params[] = (int)$is_installed_filter;
}
if ($checked_performance_filter !== '') {
    $where[] = "checked_performance = ?";
    $params[] = (int)$checked_performance_filter;
}
if ($checked_turnover_filter !== '') {
    $where[] = "checked_turnover = ?";
    $params[] = (int)$checked_turnover_filter;
}

// Логика фильтра по сотруднику
if ($user_role !== 'admin' && $user_role !== 'terman') {
    $allowed_tabels = array_keys($employee_options);
    if ($employee_tabel !== '' && in_array($employee_tabel, $allowed_tabels)) {
        $where[] = "employee_tabel = ?";
        $params[] = $employee_tabel;
    } else {
        $placeholders = implode(',', array_fill(0, count($allowed_tabels), '?'));
        $where[] = "employee_tabel IN ($placeholders)";
        $params = array_merge($params, $allowed_tabels);
    }
} else {
    if ($employee_tabel !== '') {
        $where[] = "employee_tabel = ?";
        $params[] = $employee_tabel;
    }
}

$sql = "SELECT * FROM inn_records";
if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY sale_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ------------------------------------------------------------------
// ПОДСЧЁТ ИНН и ДУБЛЕЙ
// ------------------------------------------------------------------
$total_inns = count($rows);
$inn_counts = [];
foreach ($rows as $r) {
    $inn = $r['inn'];
    if (isset($inn_counts[$inn])) {
        $inn_counts[$inn]++;
    } else {
        $inn_counts[$inn] = 1;
    }
}
$duplicate_inns = 0;
foreach ($inn_counts as $count) {
    if ($count > 1) {
        $duplicate_inns += ($count - 1);
    }
}

// ------------------------------------------------------------------
// ЧИПСЫ: список продуктов
// ------------------------------------------------------------------
$products_from_db = $pdo->query("SELECT DISTINCT product FROM inn_records WHERE product IS NOT NULL AND product != '' ORDER BY product")->fetchAll(PDO::FETCH_COLUMN);
if (empty($products_from_db)) {
    $products_list = ['ТЭ', 'Смарт', 'ПОС', 'Чаевые'];
} else {
    $products_list = $products_from_db;
}

$all_products_json = json_encode($products_list);

if ($products_param === '') {
    $selectedProducts = $products_list;
} else {
    $selectedProducts = $products_selected;
}
$initial_selected_json = json_encode($selectedProducts);

// ------------------------------------------------------------------
// Скачивание CSV (с новыми полями)
// ------------------------------------------------------------------
if (isset($_GET['download'])) {
    error_reporting(0);
    ini_set('display_errors', 0);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=inn_' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ИНН', 'Продукт', 'Сотрудник', 'Руководитель', 'Дата', 'Ключевая', 'Тип станции', 'Тип клиента', 'Ожидаемый оборот', 'Установлен', 'Зашёл в произв.', 'Оборот 10т'], ';', '"', "\\");
    foreach ($rows as $r) {
        $is_key_label = isset($r['is_key']) ? ($r['is_key'] ? 'Ключевая' : 'Неключевая') : 'Н/Д';
        $station_label = isset($r['station_type']) ? $r['station_type'] : '';
        $client_label = isset($r['client_type']) ? ($r['client_type'] === 'new' ? 'Новый' : 'Расширение') : '';
        fputcsv($out, [
            $r['inn'],
            $r['product'],
            $r['employee_name'],
            $r['head_name'] ?? '',
            $r['sale_date'],
            $is_key_label,
            $station_label,
            $client_label,
            number_format($r['expected_turnover'] ?? 0, 2, '.', ''),
            isset($r['is_installed']) && $r['is_installed'] ? 'Да' : 'Нет',
            isset($r['checked_performance']) && $r['checked_performance'] ? 'Да' : 'Нет',
            isset($r['checked_turnover']) && $r['checked_turnover'] ? 'Да' : 'Нет'
        ], ';', '"', "\\");
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>ИНН — SZB CRM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css">
    <style>
        .nav { display:flex; align-items:center; padding:12px 20px; background:linear-gradient(135deg,#1a1a2e,#16213e); color:#fff; border-radius:16px; margin-bottom:20px; gap:12px; flex-wrap:wrap; }
        .nav a { color:#ccc; text-decoration:none; padding:8px 14px; border-radius:8px; font-size:13px; font-weight:500; }
        .nav a:hover, .nav a.active { background:rgba(255,255,255,0.1); color:#fff; }
        .nav .logo { font-size:20px; font-weight:700; color:#fff; margin-right:auto; }
        .nav .user { margin-left:auto; color:#aaa; font-size:13px; }
        .nav a.logout { color:#e03131; }
        .container { max-width:1400px; margin:0 auto; padding:24px; }
        .card { background:#fff; border-radius:16px; padding:20px; margin-bottom:16px; box-shadow:0 2px 12px rgba(0,0,0,0.04); border:1px solid #e8ecf1; }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        th, td { padding:10px 12px; text-align:left; border-bottom:1px solid #e8ecf1; }
        th { background:#f8f9fa; color:#666; font-size:11px; font-weight:600; text-transform:uppercase; }
        .btn { display:inline-flex; align-items:center; justify-content:center; padding:8px 16px; background:#1a73e8; color:#fff; border:none; border-radius:10px; font-size:13px; font-weight:500; cursor:pointer; text-decoration:none; }
        .btn:hover { background:#1557b0; }
        .btn-sm { padding:6px 12px; font-size:12px; background:#6c757d; }
        .btn-danger { background:#e03131; }
        .btn-edit { background:#1a73e8; padding:6px 12px; border-radius:8px; color:#fff; border:none; cursor:pointer; font-size:12px; margin-right:4px; }
        .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; }
        .modal-content { background:#fff; border-radius:16px; padding:24px; max-width:500px; margin:100px auto; }
        .filter-form { background:#fff; border-radius:16px; padding:20px; margin-bottom:20px; box-shadow:0 2px 12px rgba(0,0,0,0.04); border:1px solid #e8ecf1; }
        .filter-row { display:flex; flex-wrap:wrap; gap:20px; align-items:flex-end; margin-bottom:15px; }
        .filter-group { flex:1; min-width:150px; }
        .filter-group label { display:block; font-size:0.8rem; font-weight:600; margin-bottom:4px; color:#444; }
        .filter-group input, .filter-group select { width:100%; padding:8px 12px; border:1px solid #ccc; border-radius:12px; font-size:0.9rem; }
        .chips-wrapper { display: flex; flex-wrap: wrap; gap: 6px; padding: 8px; border: 1px solid #ccc; border-radius: 12px; background: #fff; min-height: 42px; align-items: center; }
        .chip { background: #e8ecf1; border-radius: 20px; padding: 4px 10px; display: flex; align-items: center; gap: 6px; font-size: 0.85rem; color: #1a1a2e; font-weight: 500; }
        .chip .remove { cursor: pointer; font-weight: bold; color: #666; line-height: 1; }
        .chip .remove:hover { color: #e03131; }
        .chips-placeholder { color: #999; font-size: 0.9rem; }
        .action-buttons { display:flex; gap:10px; margin-top:10px; flex-wrap:wrap; }
        .check-list-box { background:#f8f9fa; border-radius:12px; padding:15px; margin-top:15px; border:1px dashed #ccc; }
        .check-list-box textarea { width:100%; height:80px; margin-bottom:10px; padding:8px; border:1px solid #ccc; border-radius:8px; font-family:monospace; }
        .checkbox-cell input { transform: scale(1.3); cursor:pointer; }
        .update-checkbox { cursor: pointer; }
        .stats-bar { display: flex; gap: 20px; margin-bottom: 15px; padding: 10px 15px; background: #f0f4ff; border-radius: 12px; align-items: center; flex-wrap:wrap; }
        .stats-item { font-size: 14px; font-weight: 600; color: #333; }
        .stats-item span { color: #1a73e8; }
        .duplicate-row { background-color: #fff0f0; }
        .duplicate-badge { background: #e03131; color: #fff; font-size: 10px; padding: 2px 6px; border-radius: 10px; margin-left: 5px; }
        .access-warning { background: #fff3cd; border-radius: 12px; padding: 12px 16px; margin-bottom: 16px; border: 1px solid #ffc107; font-size: 14px; }
        .last-check-info { display: flex; gap: 20px; padding: 8px 15px; background: #e8f5e9; border-radius: 12px; margin-bottom: 16px; flex-wrap:wrap; font-size: 13px; }
        .last-check-info .label { font-weight: 600; }
        .last-check-info .date { color: #2e7d32; }
        .last-check-info .count { color: #1a73e8; }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="dashboard.php" class="logo">🚀 SZB</a>
        <a href="dashboard.php">Дашборд</a>
        <a href="team.php">Команда</a>
        <a href="territories.php">Территории</a>
        <a href="export_inn.php" class="active">ИНН</a>
        <a href="quests.php">Квесты</a>
        <a href="ai.php">AI</a>
        <?php if ($user_role == 'admin'): ?><a href="admin.php">Админ</a><?php endif; ?>
        <span class="user"><?= htmlspecialchars($_SESSION['name']) ?></span>
        <a href="logout.php" class="logout">Выйти</a>
    </div>

    <h2>📋 Выгрузка ИНН</h2>

    <?php if ($is_manager): ?>
    <div class="access-warning">
        ⚠️ У вас нет прав на редактирование записей. Вы можете только просматривать данные.
    </div>
    <?php endif; ?>

    <!-- Информация о последних проверках -->
    <div class="last-check-info">
        <span><span class="label">📌 Последняя проверка "Зашёл в производительность":</span> 
            <?php if ($last_checks['performance']): ?>
                <span class="date"><?= $last_checks['performance']['date'] ?></span> 
                (обработано <span class="count"><?= $last_checks['performance']['count'] ?></span> ИНН)
            <?php else: ?>
                <span style="color:#999;">ещё не проводилась</span>
            <?php endif; ?>
        </span>
        <span><span class="label">📌 Последняя проверка "Оборот 10т":</span> 
            <?php if ($last_checks['turnover']): ?>
                <span class="date"><?= $last_checks['turnover']['date'] ?></span> 
                (обработано <span class="count"><?= $last_checks['turnover']['count'] ?></span> ИНН)
            <?php else: ?>
                <span style="color:#999;">ещё не проводилась</span>
            <?php endif; ?>
        </span>
        <span><span class="label">📌 Последняя проверка "Целевой список":</span> 
            <?php if ($last_checks['target']): ?>
                <span class="date"><?= $last_checks['target']['date'] ?></span> 
                (обработано <span class="count"><?= $last_checks['target']['count'] ?></span> ИНН)
            <?php else: ?>
                <span style="color:#999;">ещё не проводилась</span>
            <?php endif; ?>
        </span>
    </div>

    <form class="filter-form" method="GET" action="export_inn.php" id="filterForm">
        <div class="filter-row">
            <div class="filter-group">
                <label>Дата с</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
            </div>
            <div class="filter-group">
                <label>Дата по</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
            </div>
            <div class="filter-group">
                <label>Сотрудник</label>
                <select name="employee">
                    <option value="">Все сотрудники</option>
                    <?php foreach ($employee_options as $tabel => $name): ?>
                        <option value="<?= htmlspecialchars($tabel) ?>" <?= $employee_tabel == $tabel ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($hasKey): ?>
            <div class="filter-group">
                <label>Ключевая</label>
                <select name="is_key">
                    <option value="">Все</option>
                    <option value="key" <?= $is_key_filter === 'key' ? 'selected' : '' ?>>Ключевая</option>
                    <option value="nonkey" <?= $is_key_filter === 'nonkey' ? 'selected' : '' ?>>Неключевая</option>
                </select>
            </div>
            <?php endif; ?>
            <?php if ($hasStation): ?>
            <div class="filter-group">
                <label>Тип станции</label>
                <select name="station_type">
                    <option value="">Все</option>
                    <option value="pirate" <?= $station_type_filter === 'pirate' ? 'selected' : '' ?>>Пиратская</option>
                    <option value="target" <?= $station_type_filter === 'target' ? 'selected' : '' ?>>Целевой список</option>
                    <option value="newreg" <?= $station_type_filter === 'newreg' ? 'selected' : '' ?>>Новорег</option>
                </select>
            </div>
            <?php endif; ?>
            <?php if ($hasClient): ?>
            <div class="filter-group">
                <label>Тип клиента</label>
                <select name="client_type">
                    <option value="">Все</option>
                    <option value="new" <?= $client_type_filter === 'new' ? 'selected' : '' ?>>Новый</option>
                    <option value="expansion" <?= $client_type_filter === 'expansion' ? 'selected' : '' ?>>Расширение</option>
                </select>
            </div>
            <?php endif; ?>
            <div class="filter-group">
                <label>Установлен</label>
                <select name="is_installed">
                    <option value="">Все</option>
                    <option value="1" <?= $is_installed_filter === '1' ? 'selected' : '' ?>>Да</option>
                    <option value="0" <?= $is_installed_filter === '0' ? 'selected' : '' ?>>Нет</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Зашёл в произв.</label>
                <select name="checked_performance">
                    <option value="">Все</option>
                    <option value="1" <?= $checked_performance_filter === '1' ? 'selected' : '' ?>>Да</option>
                    <option value="0" <?= $checked_performance_filter === '0' ? 'selected' : '' ?>>Нет</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Оборот 10т</label>
                <select name="checked_turnover">
                    <option value="">Все</option>
                    <option value="1" <?= $checked_turnover_filter === '1' ? 'selected' : '' ?>>Да</option>
                    <option value="0" <?= $checked_turnover_filter === '0' ? 'selected' : '' ?>>Нет</option>
                </select>
            </div>
            <div class="filter-group" style="flex:2;">
                <label>Продукты</label>
                <div class="chips-wrapper" id="chipsContainer"></div>
                <input type="hidden" name="products" id="productsInput" value="<?= htmlspecialchars($products_param) ?>">
            </div>
        </div>
        <div class="action-buttons">
            <button type="submit" class="btn">🔍 Фильтровать</button>
            <a href="export_inn.php" class="btn btn-sm">🔄 Сбросить</a>
            <a href="?<?= http_build_query(array_merge(array_diff_key($_GET, ['download' => '']), ['download' => 1])) ?>" class="btn btn-sm" style="background:#28a745;">📥 Скачать CSV</a>
        </div>
    </form>

    <?php if ($can_edit): ?>
    <!-- Форма массовой проверки ИНН (только для начальников) -->
    <div class="card">
        <h3>📌 Массовая проверка ИНН</h3>
        <p>Вставьте список ИНН (каждый с новой строки или через запятую). Система отметит те записи, которые есть в базе.</p>
        <form method="POST" action="export_inn.php">
            <div class="filter-row">
                <div class="filter-group" style="flex:3;">
                    <label>Список ИНН</label>
                    <textarea name="inn_list" placeholder="7701234567, 7801234567" style="width:100%; height:100px;"></textarea>
                </div>
                <div class="filter-group">
                    <label>Что сделать?</label>
                    <select name="check_type">
                        <option value="performance">Отметить "Зашёл в производительность"</option>
                        <option value="turnover">Отметить "Оборот 10т"</option>
                        <option value="target">Отметить как "Целевой список"</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn" name="check_list" value="1">✅ Применить</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- СЧЁТЧИКИ -->
    <div class="stats-bar">
        <div class="stats-item">📊 Всего ИНН после фильтрации: <span><?= $total_inns ?></span></div>
        <div class="stats-item">⚠️ Количество дублей: <span><?= $duplicate_inns ?></span></div>
    </div>

    <div class="card" style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>ИНН</th>
                    <th>Продукт</th>
                    <th>Сотрудник</th>
                    <th>Руководитель</th>
                    <th>Дата</th>
                    <?php if ($hasKey): ?><th>Ключевая</th><?php endif; ?>
                    <?php if ($hasStation): ?><th>Тип станции</th><?php endif; ?>
                    <?php if ($hasClient): ?><th>Тип клиента</th><?php endif; ?>
                    <th>Ожидаемый оборот</th>
                    <th>Установлен</th>
                    <th>Зашёл в произв.</th>
                    <th>Оборот 10т</th>
                    <?php if ($hasStation): ?><th>Целевой список</th><?php endif; ?>
                    <?php if ($can_edit): ?><th>Действия</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="<?= 7 + ($hasKey?1:0) + ($hasStation?2:0) + ($hasClient?1:0) + 3 + ($can_edit?1:0) ?>" style="text-align:center;color:#999;padding:24px;">Записей не найдено</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r): 
                    $is_duplicate = isset($inn_counts[$r['inn']]) && $inn_counts[$r['inn']] > 1;
                    $is_target = isset($r['station_type']) && $r['station_type'] === 'target';
                ?>
                <tr class="<?= $is_duplicate ? 'duplicate-row' : '' ?>">
                    <td>
                        <?= htmlspecialchars($r['inn']) ?>
                        <?php if ($is_duplicate): ?>
                            <span class="duplicate-badge" title="Этот ИНН встречается несколько раз">дубль</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($r['product']) ?></td>
                    <td><?= htmlspecialchars($r['employee_name']) ?></td>
                    <td><?= htmlspecialchars($r['head_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($r['sale_date']) ?></td>
                    <?php if ($hasKey): ?><td><?= isset($r['is_key']) ? ($r['is_key'] ? 'Ключевая' : 'Неключевая') : 'Н/Д' ?></td><?php endif; ?>
                    <?php if ($hasStation): ?><td><?= isset($r['station_type']) ? htmlspecialchars($r['station_type']) : '' ?></td><?php endif; ?>
                    <?php if ($hasClient): ?><td><?= isset($r['client_type']) ? ($r['client_type'] === 'new' ? 'Новый' : 'Расширение') : '' ?></td><?php endif; ?>
                    <td><?= number_format($r['expected_turnover'] ?? 0, 0, '.', ' ') ?> ₽</td>
                    <td class="checkbox-cell">
                        <input type="checkbox" class="update-checkbox" data-id="<?= $r['id'] ?>" data-field="is_installed" <?= !empty($r['is_installed']) ? 'checked' : '' ?> <?= $can_edit ? '' : 'disabled' ?>>
                    </td>
                    <td class="checkbox-cell">
                        <input type="checkbox" class="update-checkbox" data-id="<?= $r['id'] ?>" data-field="checked_performance" <?= !empty($r['checked_performance']) ? 'checked' : '' ?> <?= $can_edit ? '' : 'disabled' ?>>
                    </td>
                    <td class="checkbox-cell">
                        <input type="checkbox" class="update-checkbox" data-id="<?= $r['id'] ?>" data-field="checked_turnover" <?= !empty($r['checked_turnover']) ? 'checked' : '' ?> <?= $can_edit ? '' : 'disabled' ?>>
                    </td>
                    <?php if ($hasStation): ?>
                    <td class="checkbox-cell">
                        <input type="checkbox" class="update-checkbox" data-id="<?= $r['id'] ?>" data-field="is_target" <?= $is_target ? 'checked' : '' ?> <?= $can_edit ? '' : 'disabled' ?>>
                    </td>
                    <?php endif; ?>
                    <?php if ($can_edit): ?>
                    <td>
                        <button class="btn-edit" onclick="openEditModal(<?= $r['id'] ?>, '<?= htmlspecialchars($r['inn'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['product'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['sale_date'], ENT_QUOTES) ?>', <?= !empty($r['is_installed']) ? 1 : 0 ?>, <?= !empty($r['checked_performance']) ? 1 : 0 ?>, <?= !empty($r['checked_turnover']) ? 1 : 0 ?>, <?= $r['expected_turnover'] ?? 0 ?>, '<?= $r['client_type'] ?? 'new' ?>', <?= $is_target ? 1 : 0 ?>)">✏️</button>
                        <a href="?delete=<?= $r['id'] ?>&<?= http_build_query(array_diff_key($_GET, ['delete' => ''])) ?>" class="btn btn-sm btn-danger" onclick="return confirm('Удалить запись ИНН <?= htmlspecialchars($r['inn']) ?>?')">✕</a>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($can_edit): ?>
<!-- Модальное окно редактирования -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <h3>✏️ Редактировать запись</h3>
        <form method="POST">
            <input type="hidden" name="edit_id" id="edit_id">
            <div class="form-group"><label>ИНН</label><input type="text" name="inn" id="edit_inn" required pattern="\d{10,12}" maxlength="12"></div>
            <div class="form-group"><label>Продукт</label><select name="product" id="edit_product" required>
                <?php foreach ($products_list as $prod): ?>
                    <option value="<?= htmlspecialchars($prod) ?>"><?= htmlspecialchars($prod) ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="form-group"><label>Дата</label><input type="date" name="sale_date" id="edit_date" required></div>
            <div class="form-group"><label>Ожидаемый оборот</label><input type="number" name="expected_turnover" id="edit_turnover_val" step="0.01" value="0"></div>
            <div class="form-group"><label>Тип клиента</label>
                <select name="client_type" id="edit_client_type">
                    <option value="new">Новый</option>
                    <option value="expansion">Расширение</option>
                </select>
            </div>
            <div class="form-group">
                <label><input type="checkbox" name="is_installed" id="edit_installed"> Установлен</label>
            </div>
            <div class="form-group">
                <label><input type="checkbox" name="checked_performance" id="edit_performance"> Зашёл в производительность</label>
            </div>
            <div class="form-group">
                <label><input type="checkbox" name="checked_turnover" id="edit_turnover"> Оборот 10т</label>
            </div>
            <div class="form-group">
                <label><input type="checkbox" name="is_target" id="edit_target"> Целевой список</label>
            </div>
            <div style="display:flex; gap:10px; margin-top:15px;">
                <button type="submit" class="btn">💾 Сохранить</button>
                <button type="button" class="btn btn-sm" onclick="closeEditModal()">Отмена</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(id, inn, product, date, is_installed, checked_performance, checked_turnover, expected_turnover, client_type, is_target) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_inn').value = inn;
    document.getElementById('edit_product').value = product;
    document.getElementById('edit_date').value = date;
    document.getElementById('edit_installed').checked = is_installed == 1;
    document.getElementById('edit_performance').checked = checked_performance == 1;
    document.getElementById('edit_turnover').checked = checked_turnover == 1;
    document.getElementById('edit_turnover_val').value = expected_turnover || 0;
    document.getElementById('edit_client_type').value = client_type || 'new';
    document.getElementById('edit_target').checked = is_target == 1;
    document.getElementById('editModal').style.display = 'block';
}
function closeEditModal() { document.getElementById('editModal').style.display = 'none'; }
window.onclick = function(e) { if (e.target === document.getElementById('editModal')) closeEditModal(); }

// AJAX для чекбоксов
<?php if ($can_edit): ?>
document.querySelectorAll('.update-checkbox:not([disabled])').forEach(function(checkbox) {
    checkbox.addEventListener('change', function() {
        const id = this.dataset.id;
        const field = this.dataset.field;
        const value = this.checked ? 1 : 0;
        fetch('export_inn.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'ajax_update=1&id=' + id + '&field=' + field + '&value=' + value
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                alert('Ошибка обновления');
                this.checked = !this.checked;
            }
        })
        .catch(() => {
            alert('Ошибка сети');
            this.checked = !this.checked;
        });
    });
});
<?php endif; ?>
</script>
<?php endif; ?>

<!-- ========== СКРИПТ ДЛЯ ЧИПСОВ ========== -->
<script>
const ALL_PRODUCTS = <?= $all_products_json ?>;
let selectedProducts = <?= $initial_selected_json ?>;

const chipsContainer = document.getElementById('chipsContainer');
const productsInput = document.getElementById('productsInput');

function renderChips() {
    if (!chipsContainer) return;
    chipsContainer.innerHTML = '';
    if (!selectedProducts || selectedProducts.length === 0) {
        chipsContainer.innerHTML = '<span class="chips-placeholder">Ничего не выбрано</span>';
        return;
    }
    selectedProducts.forEach(prod => {
        const chip = document.createElement('div');
        chip.className = 'chip';
        chip.innerHTML = `${prod} <span class="remove" data-value="${prod}">×</span>`;
        chipsContainer.appendChild(chip);
    });
}

function updateInput() {
    if (!productsInput) return;
    if (selectedProducts.length === ALL_PRODUCTS.length && ALL_PRODUCTS.every(p => selectedProducts.includes(p))) {
        productsInput.value = '';
    } else {
        productsInput.value = selectedProducts.join(',');
    }
}

chipsContainer.addEventListener('click', function(e) {
    const removeBtn = e.target.closest('.remove');
    if (!removeBtn) return;
    const value = removeBtn.dataset.value;
    selectedProducts = selectedProducts.filter(p => p !== value);
    renderChips();
    updateInput();
});

document.addEventListener('DOMContentLoaded', function() {
    renderChips();
    updateInput();
});
</script>
</body>
</html>