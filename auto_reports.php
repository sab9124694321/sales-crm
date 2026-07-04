<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit;
}
require_once 'db.php';

$message = '';
$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$bot_config = $pdo->query("SELECT bot_token, chat_id FROM bot_config WHERE id = 1")->fetch();
$default_token = $bot_config['bot_token'] ?? '';
$default_chat = $bot_config['chat_id'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_report'])) {
    $title = trim($_POST['title']);
    $chat_id = trim($_POST['chat_id']);
    $bot_token = trim($_POST['bot_token']);
    $period_type = $_POST['period_type'];
    $date_from = $_POST['date_from'] ?? null;
    $date_to = $_POST['date_to'] ?? null;
    $schedule = $_POST['schedule'];
    $time_of_day = $_POST['time_of_day'];
    $template_text = trim($_POST['template_text']);
    $user_tabel = $_POST['user_tabel'] ?: null;
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($title && $chat_id && $bot_token) {
        if ($id) {
            $stmt = $pdo->prepare("UPDATE auto_reports SET title=?, chat_id=?, bot_token=?, period_type=?, date_from=?, date_to=?, schedule=?, time_of_day=?, template_text=?, user_tabel=?, is_active=?, updated_at=datetime('now') WHERE id=?");
            $stmt->execute([$title, $chat_id, $bot_token, $period_type, $date_from, $date_to, $schedule, $time_of_day, $template_text, $user_tabel, $is_active, $id]);
            $message = '<div class="success">✅ Отчёт обновлён</div>';
        } else {
            $stmt = $pdo->prepare("INSERT INTO auto_reports (title, chat_id, bot_token, period_type, date_from, date_to, schedule, time_of_day, template_text, user_tabel, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$title, $chat_id, $bot_token, $period_type, $date_from, $date_to, $schedule, $time_of_day, $template_text, $user_tabel, $is_active]);
            $message = '<div class="success">✅ Отчёт создан</div>';
        }
        header('Location: auto_reports.php');
        exit;
    } else {
        $message = '<div class="error">❌ Заполните обязательные поля (название, чат, токен)</div>';
    }
}

if ($action === 'delete' && $id) {
    $pdo->prepare("DELETE FROM auto_reports WHERE id = ?")->execute([$id]);
    header('Location: auto_reports.php');
    exit;
}

$reports = $pdo->query("SELECT * FROM auto_reports ORDER BY created_at DESC")->fetchAll();

$edit_report = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM auto_reports WHERE id = ?");
    $stmt->execute([$id]);
    $edit_report = $stmt->fetch();
}

$leaders = $pdo->query("SELECT tabel_number, full_name FROM users WHERE role IN ('head','mmb_tp_head') AND is_active = 1 ORDER BY full_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Автоотчёты</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .nav { display:flex; align-items:center; padding:12px 20px; background:linear-gradient(135deg,#1a1a2e,#16213e); color:#fff; border-radius:16px; margin-bottom:20px; gap:12px; flex-wrap:wrap; }
        .nav a { color:#ccc; text-decoration:none; padding:8px 14px; border-radius:8px; font-size:13px; }
        .nav a.active, .nav a:hover { background:rgba(255,255,255,0.1); color:#fff; }
        .container { max-width:1200px; margin:0 auto; padding:20px; }
        .card { background:#fff; border-radius:16px; padding:20px; margin-bottom:20px; }
        .form-group { margin-bottom:15px; }
        .form-group label { display:block; font-weight:600; margin-bottom:4px; }
        input, select, textarea { width:100%; padding:8px 12px; border:1px solid #ccc; border-radius:8px; }
        .btn { background:#1a73e8; color:#fff; border:none; padding:8px 16px; border-radius:8px; cursor:pointer; }
        .success { background:#d4edda; color:#155724; padding:10px; border-radius:8px; margin-bottom:15px; }
        .error { background:#f8d7da; color:#721c24; padding:10px; border-radius:8px; margin-bottom:15px; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:8px; text-align:left; border-bottom:1px solid #eee; }
        .btn-sm { padding:4px 8px; font-size:12px; background:#6c757d; }
        .btn-danger { background:#e03131; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:15px; }
        .vars-list { background:#f8f9fa; border-radius:8px; padding:10px; font-size:13px; margin-top:8px; }
        .vars-list code { background:#fff; padding:2px 4px; border-radius:4px; }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="dashboard.php">📊 Дашборд</a>
        <a href="auto_reports.php" class="active">📋 Автоотчёты</a>
        <a href="admin.php">⚙️ Админ</a>
        <a href="bot_settings.php">🤖 Бот</a>
        <a href="logout.php" style="color:#e03131">Выйти</a>
    </div>
    <h2>📋 Автоматические отчёты (бот)</h2>
    <?= $message ?>

    <div class="card">
        <h3>➕ Новый отчёт</h3>
        <form method="post">
            <input type="hidden" name="save_report" value="1">
            <div class="grid2">
                <div class="form-group"><label>Название *</label><input type="text" name="title" required></div>
                <div class="form-group"><label>Chat ID *</label><input type="text" name="chat_id" value="<?= htmlspecialchars($default_chat) ?>" required></div>
                <div class="form-group"><label>Токен бота *</label><input type="text" name="bot_token" value="<?= htmlspecialchars($default_token) ?>" required></div>
                <div class="form-group"><label>Период</label>
                    <select name="period_type" id="period_type">
                        <option value="yesterday">Вчера</option>
                        <option value="this_week">Текущая неделя</option>
                        <option value="last_week">Прошлая неделя</option>
                        <option value="this_month">Текущий месяц</option>
                        <option value="custom">Произвольный</option>
                    </select>
                </div>
                <div class="form-group" id="custom_dates" style="display:none;">
                    <label>Дата с</label><input type="date" name="date_from">
                    <label>Дата по</label><input type="date" name="date_to">
                </div>
                <div class="form-group"><label>Расписание</label>
                    <select name="schedule">
                        <option value="daily">Ежедневно</option>
                        <option value="weekly">Пн-Пт</option>
                        <option value="monthly">1 число</option>
                    </select>
                </div>
                <div class="form-group"><label>Время отправки</label><input type="time" name="time_of_day" value="09:00"></div>
                <div class="form-group"><label>Привязать к руководителю (опционально)</label>
                    <select name="user_tabel">
                        <option value="">— Не привязывать —</option>
                        <?php foreach ($leaders as $l): ?>
                            <option value="<?= $l['tabel_number'] ?>"><?= htmlspecialchars($l['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label><input type="checkbox" name="is_active" value="1" checked> Активен</label></div>
            </div>
            <div class="form-group">
                <label>Шаблон текста сообщения</label>
                <textarea name="template_text" rows="6" placeholder="📊 Отчёт {TITLE}
Период: {PERIOD_TEXT}
Звонки: {TOTAL_CALLS}
Оборот: {TOTAL_TURNOVER} ₽"></textarea>
                <div class="vars-list">
                    <strong>📌 Доступные переменные (вставляйте их в шаблон):</strong><br>
                    <code>{TITLE}</code> – название отчёта<br>
                    <code>{PERIOD_TEXT}</code> – текстовое описание периода<br>
                    <code>{DATE_FROM}</code> – дата начала периода<br>
                    <code>{DATE_TO}</code> – дата конца периода<br>
                    <code>{DEPARTMENT_HEAD}</code> – ФИО начальника отдела<br>
                    <code>{TOTAL_CALLS}</code> – суммарное количество звонков<br>
                    <code>{TOTAL_CONTRACTS}</code> – суммарное количество договоров<br>
                    <code>{TOTAL_TURNOVER}</code> – суммарный оборот чаевых<br>
                    <code>{TOTAL_REGISTRATIONS}</code> – суммарное количество ТЭ<br>
                    <code>{TOTAL_SMART_CASH}</code> – суммарное количество смарт-касс<br>
                    <code>{TOTAL_POS}</code> – суммарное количество POS-систем<br>
                    <code>{TOTAL_INN_LEADS}</code> – суммарное количество ИНН чаевых<br>
                    <code>{TOTAL_TEAM}</code> – суммарное количество ТЭ+Смарт+POS
                </div>
            </div>
            <button type="submit" class="btn">💾 Сохранить</button>
        </form>
    </div>

    <h3>📋 Список отчётов</h3>
    <div class="card">
        <table>
            <thead><tr><th>Название</th><th>Чат</th><th>Расписание</th><th>Время</th><th>Привязка</th><th>Активен</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($reports as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['title']) ?></td>
                <td><?= htmlspecialchars($r['chat_id']) ?></td>
                <td><?= $r['schedule'] ?></td>
                <td><?= $r['time_of_day'] ?></td>
                <td><?= $r['user_tabel'] ?: '—' ?></td>
                <td><?= $r['is_active'] ? '✅' : '❌' ?></td>
                <td>
                    <a href="?action=edit&id=<?= $r['id'] ?>" class="btn btn-sm">✏️</a>
                    <a href="?action=delete&id=<?= $r['id'] ?>" onclick="return confirm('Удалить?')" class="btn btn-sm btn-danger">✕</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($edit_report): ?>
    <div class="card">
        <h3>✏️ Редактировать отчёт</h3>
        <form method="post">
            <input type="hidden" name="save_report" value="1">
            <input type="hidden" name="id" value="<?= $edit_report['id'] ?>">
            <div class="grid2">
                <div class="form-group"><label>Название *</label><input type="text" name="title" value="<?= htmlspecialchars($edit_report['title']) ?>" required></div>
                <div class="form-group"><label>Chat ID *</label><input type="text" name="chat_id" value="<?= htmlspecialchars($edit_report['chat_id']) ?>" required></div>
                <div class="form-group"><label>Токен бота *</label><input type="text" name="bot_token" value="<?= htmlspecialchars($edit_report['bot_token']) ?>" required></div>
                <div class="form-group"><label>Период</label>
                    <select name="period_type" id="period_type_edit">
                        <option value="yesterday" <?= $edit_report['period_type'] == 'yesterday' ? 'selected' : '' ?>>Вчера</option>
                        <option value="this_week" <?= $edit_report['period_type'] == 'this_week' ? 'selected' : '' ?>>Текущая неделя</option>
                        <option value="last_week" <?= $edit_report['period_type'] == 'last_week' ? 'selected' : '' ?>>Прошлая неделя</option>
                        <option value="this_month" <?= $edit_report['period_type'] == 'this_month' ? 'selected' : '' ?>>Текущий месяц</option>
                        <option value="custom" <?= $edit_report['period_type'] == 'custom' ? 'selected' : '' ?>>Произвольный</option>
                    </select>
                </div>
                <div class="form-group" id="custom_dates_edit" style="<?= $edit_report['period_type'] == 'custom' ? '' : 'display:none' ?>">
                    <label>Дата с</label><input type="date" name="date_from" value="<?= $edit_report['date_from'] ?>">
                    <label>Дата по</label><input type="date" name="date_to" value="<?= $edit_report['date_to'] ?>">
                </div>
                <div class="form-group"><label>Расписание</label>
                    <select name="schedule">
                        <option value="daily" <?= $edit_report['schedule'] == 'daily' ? 'selected' : '' ?>>Ежедневно</option>
                        <option value="weekly" <?= $edit_report['schedule'] == 'weekly' ? 'selected' : '' ?>>Пн-Пт</option>
                        <option value="monthly" <?= $edit_report['schedule'] == 'monthly' ? 'selected' : '' ?>>1 число</option>
                    </select>
                </div>
                <div class="form-group"><label>Время отправки</label><input type="time" name="time_of_day" value="<?= $edit_report['time_of_day'] ?>"></div>
                <div class="form-group"><label>Привязать к руководителю</label>
                    <select name="user_tabel">
                        <option value="">— Не привязывать —</option>
                        <?php foreach ($leaders as $l): ?>
                            <option value="<?= $l['tabel_number'] ?>" <?= $edit_report['user_tabel'] == $l['tabel_number'] ? 'selected' : '' ?>><?= htmlspecialchars($l['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label><input type="checkbox" name="is_active" value="1" <?= $edit_report['is_active'] ? 'checked' : '' ?>> Активен</label></div>
            </div>
            <div class="form-group">
                <label>Шаблон текста</label>
                <textarea name="template_text" rows="6"><?= htmlspecialchars($edit_report['template_text']) ?></textarea>
                <div class="vars-list">
                    <strong>📌 Доступные переменные (те же, что и при создании)</strong> – см. список выше.
                </div>
            </div>
            <button type="submit" class="btn">💾 Сохранить изменения</button>
        </form>
    </div>
    <?php endif; ?>
</div>
<script>
function toggleCustom(selectId, divId) {
    const select = document.getElementById(selectId);
    const div = document.getElementById(divId);
    if (select.value === 'custom') div.style.display = 'block';
    else div.style.display = 'none';
}
const periodNew = document.getElementById('period_type');
if (periodNew) periodNew.addEventListener('change', () => toggleCustom('period_type', 'custom_dates'));
const periodEdit = document.getElementById('period_type_edit');
if (periodEdit) periodEdit.addEventListener('change', () => toggleCustom('period_type_edit', 'custom_dates_edit'));
toggleCustom('period_type', 'custom_dates');
toggleCustom('period_type_edit', 'custom_dates_edit');
</script>
</body>
</html>
