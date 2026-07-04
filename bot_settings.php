<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit;
}
require_once 'db.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    $pdo->prepare("UPDATE bot_config SET api_url=?, bot_token=?, chat_id=?, extra_headers=?, updated_at=datetime('now') WHERE id=1")
        ->execute([trim($_POST['api_url'] ?? ''), trim($_POST['bot_token'] ?? ''), trim($_POST['chat_id'] ?? ''), trim($_POST['extra_headers'] ?? '')]);
    $message = '<div class="success">✅ Настройки сохранены</div>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sprint'])) {
    $metrics = json_encode($_POST['metrics'] ?? []);
    if ($_POST['sprint_id'] ?? false) {
        $pdo->prepare("UPDATE bot_sprints SET title=?, cron_schedule=?, time_of_day=?, message_template=?, metrics=?, period=?, is_active=?, updated_at=datetime('now') WHERE id=?")
            ->execute([$_POST['title'], $_POST['cron_schedule'], $_POST['time_of_day'], $_POST['message_template'] ?? '', $metrics, $_POST['period'], isset($_POST['is_active']) ? 1 : 0, $_POST['sprint_id']]);
    } else {
        $pdo->prepare("INSERT INTO bot_sprints (title, cron_schedule, time_of_day, message_template, metrics, period, is_active) VALUES (?,?,?,?,?,?,?)")
            ->execute([$_POST['title'], $_POST['cron_schedule'], $_POST['time_of_day'], $_POST['message_template'] ?? '', $metrics, $_POST['period'], isset($_POST['is_active']) ? 1 : 0]);
    }
    $message = '<div class="success">✅ Спринт сохранён</div>';
}

if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM bot_sprints WHERE id = ?")->execute([$_GET['delete']]);
    header('Location: bot_settings.php');
    exit;
}

$config = $pdo->query("SELECT * FROM bot_config WHERE id = 1")->fetch();
$sprints = $pdo->query("SELECT * FROM bot_sprints ORDER BY id")->fetchAll();

$metrics_list = [
    'calls_fact' => '📞 Звонки (факт)',
    'calls_plan' => '📋 План звонков',
    'turnover_total' => '💰 Оборот чаевых',
    'pos_count' => '🖥️ POS',
    'registrations' => '📝 ТЭ',
    'smart_cash' => '💳 Смарт-кассы',
    'inn_leads' => '🍵 ИНН чаевые',
    'total_team' => '🏆 Итого (ТЭ+Смарт+POS)',
    'period_text' => '📅 Период'
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>🤖 Настройки бота</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}body{background:#f0f2f5;font-family:system-ui;padding:12px}
.container{max-width:1200px;margin:0 auto}
.nav{display:flex;align-items:center;padding:12px 20px;background:linear-gradient(135deg,#1a1a2e,#16213e);color:#fff;border-radius:16px;margin-bottom:20px;gap:12px;flex-wrap:wrap}
.nav a{color:#ccc;text-decoration:none;padding:8px 14px;border-radius:8px;font-size:13px}
.nav a:hover,.nav a.active{background:rgba(255,255,255,0.1);color:#fff}
.nav .logo{font-size:20px;font-weight:700;color:#fff;margin-right:auto}
.card{background:#fff;border-radius:16px;padding:20px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,0.04)}
.grid2{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:15px}
.form-group{display:flex;flex-direction:column}
.form-group label{font-size:12px;color:#666;margin-bottom:4px;font-weight:600}
.form-group input,.form-group select,.form-group textarea{padding:8px 12px;border:1px solid #dee2e6;border-radius:10px;font-size:14px}
.btn{padding:10px 20px;background:#1a73e8;color:#fff;border:none;border-radius:10px;cursor:pointer;font-weight:500}
.btn-sm{padding:6px 12px;font-size:12px;background:#6c757d}
.btn-danger{background:#e03131}
.badge{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600}
.badge-success{background:#d3f9d8;color:#0ca678}
.badge-warning{background:#fff3bf;color:#f08c00}
.success{background:#d4edda;padding:10px;border-radius:8px;margin-bottom:15px;color:#155724}
.error{background:#f8d7da;padding:10px;border-radius:8px;margin-bottom:15px;color:#721c24}
table{width:100%;border-collapse:collapse}
th,td{padding:10px;text-align:left;border-bottom:1px solid #eee}
th{background:#f8f9fa}
</style>
</head>
<body>
<div class="container">
<div class="nav">
<a href="dashboard.php" class="logo">🚀 SZB</a>
<a href="dashboard.php">📊 Дашборд</a>
<a href="admin.php">⚙️ Админ</a>
<a href="bot_settings.php" class="active">🤖 Бот</a>
<a href="auto_reports.php">📋 Автоотчёты</a>
<span style="margin-left:auto">👤 <?= htmlspecialchars($_SESSION['name']) ?></span>
<a href="logout.php" style="color:#e03131">Выйти</a>
</div>

<h2>🤖 Настройки бота (Макс)</h2>
<?= $message ?>

<div class="card">
<h3>🔑 API‑подключение</h3>
<form method="post">
<input type="hidden" name="save_config" value="1">
<div class="grid2">
<div class="form-group"><label>API URL</label><input type="text" name="api_url" value="<?= htmlspecialchars($config['api_url'] ?? 'https://platform-api.max.ru/messages') ?>"></div>
<div class="form-group"><label>Bot Token</label><input type="text" name="bot_token" value="<?= htmlspecialchars($config['bot_token'] ?? '') ?>"></div>
<div class="form-group"><label>Chat ID</label><input type="text" name="chat_id" value="<?= htmlspecialchars($config['chat_id'] ?? '') ?>"></div>
<div class="form-group"><label>Доп. заголовки (JSON)</label><input type="text" name="extra_headers" value="<?= htmlspecialchars($config['extra_headers'] ?? '') ?>"></div>
</div>
<button type="submit" class="btn" style="margin-top:12px">💾 Сохранить</button>
</form>
</div>

<div class="card">
<h3>📋 Спринты (периодические отчёты)</h3>
<table>
<thead><tr><th>Название</th><th>Расписание</th><th>Время</th><th>Активен</th><th></th></tr></thead>
<tbody>
<?php foreach ($sprints as $s): ?>
<tr>
<td><?= htmlspecialchars($s['title']) ?></td>
<td><?= $s['cron_schedule'] ?></td>
<td><?= $s['time_of_day'] ?></td>
<td><span class="badge <?= $s['is_active'] ? 'badge-success' : 'badge-warning' ?>"><?= $s['is_active'] ? 'Да' : 'Нет' ?></span></td>
<td>
<a href="?edit=<?= $s['id'] ?>" class="btn btn-sm">✏️</a>
<a href="?delete=<?= $s['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Удалить?')">✕</a>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<a href="?edit=new" class="btn btn-sm" style="margin-top:12px">➕ Новый спринт</a>
</div>

<?php
$edit = null;
if (isset($_GET['edit'])) {
    if ($_GET['edit'] === 'new') $edit = true;
    else {
        $stmt = $pdo->prepare("SELECT * FROM bot_sprints WHERE id = ?");
        $stmt->execute([$_GET['edit']]);
        $edit = $stmt->fetch();
    }
}
if ($edit):
$sel = is_array($edit) ? json_decode($edit['metrics'] ?? '[]', true) : [];
?>
<div class="card">
<h3><?= is_array($edit) ? '✏️ Редактировать' : '➕ Новый' ?> спринт</h3>
<form method="post">
<input type="hidden" name="save_sprint" value="1">
<?php if (is_array($edit)): ?><input type="hidden" name="sprint_id" value="<?= $edit['id'] ?>"><?php endif; ?>
<div class="grid2">
<div class="form-group"><label>Название</label><input type="text" name="title" value="<?= is_array($edit) ? htmlspecialchars($edit['title']) : '' ?>" required></div>
<div class="form-group"><label>Расписание</label>
<select name="cron_schedule">
<option value="daily" <?= (is_array($edit) && $edit['cron_schedule'] === 'daily') ? 'selected' : '' ?>>Ежедневно</option>
<option value="weekly" <?= (is_array($edit) && $edit['cron_schedule'] === 'weekly') ? 'selected' : '' ?>>Пн-Пт</option>
<option value="monthly" <?= (is_array($edit) && $edit['cron_schedule'] === 'monthly') ? 'selected' : '' ?>>1 число</option>
</select>
</div>
<div class="form-group"><label>Время</label><input type="time" name="time_of_day" value="<?= is_array($edit) ? $edit['time_of_day'] : '09:00' ?>"></div>
<div class="form-group"><label>Период</label>
<select name="period">
<option value="yesterday" <?= (is_array($edit) && $edit['period'] === 'yesterday') ? 'selected' : '' ?>>Вчера</option>
<option value="this_week" <?= (is_array($edit) && $edit['period'] === 'this_week') ? 'selected' : '' ?>>Текущая неделя</option>
<option value="last_week" <?= (is_array($edit) && $edit['period'] === 'last_week') ? 'selected' : '' ?>>Прошлая неделя</option>
<option value="this_month" <?= (is_array($edit) && $edit['period'] === 'this_month') ? 'selected' : '' ?>>Текущий месяц</option>
</select>
</div>
</div>
<div class="form-group"><label>Шаблон сообщения (необязательно)</label>
<textarea name="message_template" rows="3"><?= is_array($edit) ? htmlspecialchars($edit['message_template']) : '' ?></textarea>
</div>
<div class="form-group"><label>Метрики:</label>
<div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:6px">
<?php foreach ($metrics_list as $k => $v): ?>
<label><input type="checkbox" name="metrics[]" value="<?= $k ?>" <?= in_array($k, $sel) ? 'checked' : '' ?>> <?= $v ?></label>
<?php endforeach; ?>
</div>
</div>
<div class="form-group"><label><input type="checkbox" name="is_active" value="1" <?= (!is_array($edit) || $edit['is_active']) ? 'checked' : '' ?>> Активен</label></div>
<button type="submit" class="btn" style="margin-top:12px">💾 Сохранить спринт</button>
</form>
</div>
<?php endif; ?>
</div>
</body>
</html>
