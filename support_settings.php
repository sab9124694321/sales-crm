<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'head') {
    header('Location: dashboard.php');
    exit;
}
require_once 'db.php';

$message = '';

// --- Типы обращений ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_type'])) {
    $name = trim($_POST['name']);
    $desc = trim($_POST['description']);
    $pdo->prepare("INSERT INTO support_request_types (name, description) VALUES (?, ?)")->execute([$name, $desc]);
    $message = '<div class="success">✅ Тип добавлен</div>';
}
if (isset($_GET['del_type'])) {
    $pdo->prepare("DELETE FROM support_request_types WHERE id = ?")->execute([$_GET['del_type']]);
    header('Location: support_settings.php');
    exit;
}

// --- SLA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sla'])) {
    $type_id = intval($_POST['request_type_id']);
    $resp = intval($_POST['response_hours']);
    $resol = intval($_POST['resolution_hours']);
    $pdo->prepare("INSERT OR REPLACE INTO sla_policies (request_type_id, response_hours, resolution_hours) VALUES (?, ?, ?)")->execute([$type_id, $resp, $resol]);
    $message = '<div class="success">✅ SLA сохранены</div>';
}

// --- Загрузка клиентов ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_clients'])) {
    $csv = trim($_POST['csv_data']);
    $lines = explode("\n", $csv);
    $added = 0;
    foreach ($lines as $line) {
        $parts = str_getcsv($line);
        if (count($parts) >= 2) {
            $inn = trim($parts[0]);
            $name = trim($parts[1]);
            if ($inn && $name) {
                $stmt = $pdo->prepare("INSERT OR IGNORE INTO clients (inn, name) VALUES (?, ?)");
                $stmt->execute([$inn, $name]);
                if ($stmt->rowCount()) $added++;
            }
        }
    }
    $message = "<div class='success'>✅ Загружено $added клиентов</div>";
}

// --- Почтовые ящики УБР (привязка к начальнику УБР) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_mailbox'])) {
    $email = trim($_POST['email']);
    $ubr_head = trim($_POST['ubr_head_tabel'] ?? '');
    if ($email) {
        // Проверяем, существует ли уже такой ящик
        $stmt = $pdo->prepare("SELECT id FROM support_mailboxes WHERE email_address = ?");
        $stmt->execute([$email]);
        $existing = $stmt->fetch();
        if ($existing) {
            // Обновляем привязку
            $stmt = $pdo->prepare("UPDATE support_mailboxes SET ubr_head_tabel = ? WHERE email_address = ?");
            $stmt->execute([$ubr_head ?: null, $email]);
            $message = '<div class="success">✅ Ящик обновлён</div>';
        } else {
            $stmt = $pdo->prepare("INSERT INTO support_mailboxes (email_address, ubr_head_tabel) VALUES (?, ?)");
            $stmt->execute([$email, $ubr_head ?: null]);
            $message = '<div class="success">✅ Ящик добавлен</div>';
        }
    }
}
if (isset($_GET['del_mailbox'])) {
    $pdo->prepare("DELETE FROM support_mailboxes WHERE id = ?")->execute([$_GET['del_mailbox']]);
    header('Location: support_settings.php#mailboxes');
    exit;
}

// --- Маршрутизация: начальник ММБ → начальник УБР ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_route'])) {
    $mmb_head = trim($_POST['mmb_head_tabel']);
    $ubr_head = trim($_POST['ubr_head_tabel']);
    if ($mmb_head && $ubr_head) {
        $pdo->prepare("INSERT OR REPLACE INTO mmb_head_to_ubr_head (mmb_head_tabel, ubr_head_tabel) VALUES (?, ?)")->execute([$mmb_head, $ubr_head]);
        $message = '<div class="success">✅ Маршрут назначен</div>';
    }
}
if (isset($_GET['del_route'])) {
    $pdo->prepare("DELETE FROM mmb_head_to_ubr_head WHERE id = ?")->execute([$_GET['del_route']]);
    header('Location: support_settings.php#routing');
    exit;
}

// --- Получение данных для отображения ---
$types = $pdo->query("SELECT * FROM support_request_types ORDER BY name")->fetchAll();
$clients = $pdo->query("SELECT inn, name FROM clients LIMIT 100")->fetchAll();
$mailboxes = $pdo->query("SELECT * FROM support_mailboxes ORDER BY id")->fetchAll();

$mmb_heads = $pdo->query("SELECT tabel_number, full_name FROM users WHERE role = 'mmb_tp_head' AND is_active = 1 ORDER BY full_name")->fetchAll();
$ubr_heads = $pdo->query("SELECT tabel_number, full_name FROM users WHERE role = 'head' AND is_active = 1 ORDER BY full_name")->fetchAll();
$routes = $pdo->query("SELECT r.*, 
    (SELECT full_name FROM users WHERE tabel_number = r.mmb_head_tabel) as mmb_head_name,
    (SELECT full_name FROM users WHERE tabel_number = r.ubr_head_tabel) as ubr_head_name
    FROM mmb_head_to_ubr_head r ORDER BY r.id")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Настройки поддержки</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .card { background:#fff; border-radius:16px; padding:20px; margin-bottom:20px; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
        .form-group { margin-bottom:15px; }
        .btn { background:#1a73e8; color:#fff; padding:8px 16px; border:none; border-radius:8px; cursor:pointer; }
        .success { background:#d4edda; padding:10px; border-radius:8px; margin-bottom:15px; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:8px; text-align:left; border-bottom:1px solid #eee; }
        .nav { display:flex; gap:10px; margin-bottom:20px; }
        .tab-buttons { margin-bottom:20px; display:flex; gap:10px; flex-wrap:wrap; }
        .tab-buttons a { padding:8px 20px; background:#f0f2f5; border-radius:20px; text-decoration:none; color:#1a1a2e; }
        .tab-buttons a.active { background:#1a73e8; color:#fff; }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="dashboard.php">📊 Дашборд</a>
        <a href="support_settings.php" class="active">⚙️ Настройки поддержки</a>
        <span style="margin-left:auto;">👤 <?= htmlspecialchars($_SESSION['name']) ?> | <a href="logout.php">Выйти</a></span>
    </div>
    <div class="tab-buttons">
        <a href="#types">📂 Типы</a>
        <a href="#sla">⏱️ SLA</a>
        <a href="#clients">🏢 Клиенты</a>
        <a href="#mailboxes" class="active">📧 Ящики УБР</a>
        <a href="#routing">🔄 Маршрутизация</a>
    </div>

    <?= $message ?>

    <!-- Почтовые ящики УБР -->
    <div id="mailboxes" class="card">
        <h3>📧 Почтовые ящики УБР</h3>
        <p>Привяжите ящик к начальнику УБР. Если ящик не привязан – он считается глобальным.</p>
        <form method="post" style="margin-bottom:20px;">
            <input type="email" name="email" placeholder="group@example.com" required style="width:250px;">
            <select name="ubr_head_tabel">
                <option value="">— Глобальный ящик (без привязки) —</option>
                <?php foreach ($ubr_heads as $h): ?>
                    <option value="<?= $h['tabel_number'] ?>"><?= htmlspecialchars($h['full_name']) ?> (<?= $h['tabel_number'] ?>)</option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="add_mailbox" class="btn">➕ Добавить / Обновить</button>
        </form>
        <h4>Список ящиков</h4>
        <table>
            <thead><tr><th>Email</th><th>Привязан к начальнику УБР</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($mailboxes as $mb): 
                $headName = '';
                if ($mb['ubr_head_tabel']) {
                    $stmt = $pdo->prepare("SELECT full_name FROM users WHERE tabel_number = ?");
                    $stmt->execute([$mb['ubr_head_tabel']]);
                    $headName = $stmt->fetchColumn();
                }
            ?>
                <tr>
                    <td><?= htmlspecialchars($mb['email_address']) ?></td>
                    <td><?= $headName ? htmlspecialchars($headName) : '— глобальный —' ?></td>
                    <td><a href="?del_mailbox=<?= $mb['id'] ?>" onclick="return confirm('Удалить ящик?')">🗑️</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Маршрутизация -->
    <div id="routing" class="card" style="display:none;">
        <h3>🔄 Маршрутизация: начальник ММБ → начальник УБР</h3>
        <form method="post" style="margin-bottom:20px;">
            <select name="mmb_head_tabel" required>
                <option value="">Выберите начальника ММБ</option>
                <?php foreach ($mmb_heads as $h): ?>
                    <option value="<?= $h['tabel_number'] ?>"><?= htmlspecialchars($h['full_name']) ?> (<?= $h['tabel_number'] ?>)</option>
                <?php endforeach; ?>
            </select>
            <select name="ubr_head_tabel" required>
                <option value="">Выберите начальника УБР</option>
                <?php foreach ($ubr_heads as $h): ?>
                    <option value="<?= $h['tabel_number'] ?>"><?= htmlspecialchars($h['full_name']) ?> (<?= $h['tabel_number'] ?>)</option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="set_route" class="btn">➕ Назначить маршрут</button>
        </form>
        <h4>Текущие маршруты</h4>
        <table>
            <thead><tr><th>Начальник ММБ</th><th>Начальник УБР</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($routes as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['mmb_head_name'] ?? $r['mmb_head_tabel']) ?></td>
                    <td><?= htmlspecialchars($r['ubr_head_name'] ?? $r['ubr_head_tabel']) ?></td>
                    <td><a href="?del_route=<?= $r['id'] ?>" onclick="return confirm('Удалить маршрут?')">🗑️</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Типы обращений -->
    <div id="types" class="card" style="display:none;">
        <h3>📂 Типы обращений</h3>
        <form method="post">
            <input type="text" name="name" placeholder="Название типа" required>
            <input type="text" name="description" placeholder="Описание" style="margin-top:5px; width:100%;">
            <button type="submit" name="add_type" class="btn" style="margin-top:10px;">➕ Добавить</button>
        </form>
        <table style="margin-top:15px;">
            <thead><tr><th>ID</th><th>Название</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($types as $t): ?>
                <tr>
                    <td><?= $t['id'] ?></td>
                    <td><?= htmlspecialchars($t['name']) ?></td>
                    <td><a href="?del_type=<?= $t['id'] ?>" onclick="return confirm('Удалить?')">🗑️</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- SLA -->
    <div id="sla" class="card" style="display:none;">
        <h3>⏱️ SLA (часы)</h3>
        <form method="post">
            <select name="request_type_id" required>
                <?php foreach ($types as $t): ?>
                    <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="response_hours" placeholder="Реагирование (часы)" required>
            <input type="number" name="resolution_hours" placeholder="Решение (часы)" required>
            <button type="submit" name="save_sla" class="btn">💾 Сохранить SLA</button>
        </form>
    </div>

    <!-- Клиенты -->
    <div id="clients" class="card" style="display:none;">
        <h3>🏢 Клиенты (ИНН)</h3>
        <form method="post">
            <textarea name="csv_data" rows="6" placeholder="Формат: ИНН,Название&#10;7701234567,ООО Ромашка&#10;7707654321,ЗАО Лютик" style="width:100%;"></textarea>
            <button type="submit" name="upload_clients" class="btn">📤 Загрузить список</button>
        </form>
        <h4>Загруженные клиенты (пример)</h4>
        <table>
            <thead><tr><th>ИНН</th><th>Название</th></tr></thead>
            <tbody><?php foreach (array_slice($clients, 0, 10) as $c): ?><tr><td><?= htmlspecialchars($c['inn']) ?></td><td><?= htmlspecialchars($c['name']) ?></td></tr><?php endforeach; ?></tbody>
        </table>
    </div>
</div>

<script>
    function showTab(tabId) {
        document.querySelectorAll('.card').forEach(card => card.style.display = 'none');
        document.getElementById(tabId).style.display = 'block';
        document.querySelectorAll('.tab-buttons a').forEach(btn => btn.classList.remove('active'));
        document.querySelector(`.tab-buttons a[href="#${tabId}"]`).classList.add('active');
    }
    let hash = window.location.hash.substring(1);
    if (hash && document.getElementById(hash)) showTab(hash);
    else showTab('mailboxes');
    document.querySelectorAll('.tab-buttons a').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            let id = btn.getAttribute('href').substring(1);
            if (id) showTab(id);
        });
    });
</script>
</body>
</html>