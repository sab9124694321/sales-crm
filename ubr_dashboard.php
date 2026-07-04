<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ubr_middle') {
    header('Location: dashboard.php');
    exit;
}
require_once 'db.php';

$user_tabel = $_SESSION['tabel'];
$user_name = $_SESSION['name'];

// --- Определяем начальника УБР для текущего сотрудника ---
$ubr_head_tabel = null;
$stmt = $pdo->prepare("SELECT manager_id FROM users WHERE tabel_number = ?");
$stmt->execute([$user_tabel]);
$manager_id = $stmt->fetchColumn();
if ($manager_id) {
    $stmt2 = $pdo->prepare("SELECT tabel_number FROM users WHERE id = ?");
    $stmt2->execute([$manager_id]);
    $ubr_head_tabel = $stmt2->fetchColumn();
}

// Взять в работу
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_to_me'])) {
    $req_id = intval($_POST['request_id']);
    $pdo->prepare("UPDATE support_requests SET assigned_to_tabel = ?, status = 'in_progress', updated_at = datetime('now') WHERE id = ?")->execute([$user_tabel, $req_id]);
    header('Location: ubr_dashboard.php');
    exit;
}

// Отправка ответа (текст ответа не сохраняется)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reply'])) {
    $req_id = intval($_POST['request_id']);
    $message = trim($_POST['message']);

    $ticket_info = $pdo->prepare("SELECT created_by_tabel, ticket_number FROM support_requests WHERE id = ?");
    $ticket_info->execute([$req_id]);
    $ticket = $ticket_info->fetch();
    if (!$ticket) {
        header('Location: ubr_dashboard.php');
        exit;
    }

    // Обновляем статус и время первого ответа
    $pdo->prepare("UPDATE support_requests SET first_response_at = datetime('now'), status = 'waiting_for_mmb', updated_at = datetime('now') WHERE id = ? AND first_response_at IS NULL")->execute([$req_id]);
    $pdo->prepare("UPDATE support_requests SET status = 'waiting_for_mmb', updated_at = datetime('now') WHERE id = ?")->execute([$req_id]);

    // Отправляем письмо менеджеру ММБ
    $author = $pdo->prepare("SELECT email FROM users WHERE tabel_number = ?");
    $author->execute([$ticket['created_by_tabel']]);
    $author_email = $author->fetchColumn();

    if ($author_email) {
        $subject = "Ответ по тикету " . $ticket['ticket_number'] . " (ожидает подтверждения)";
        $body = "Здравствуйте!\n\nПо вашему обращению получен ответ. Пожалуйста, подтвердите, что ответ вас устраивает, или запросите доработку.\n\nОтвет:\n" . $message . "\n\n--\nС уважением, УБР Middle";
        $mailto_link = "mailto:" . rawurlencode($author_email) . "?subject=" . rawurlencode($subject) . "&body=" . rawurlencode($body);
        echo "<script>window.location.href='" . addslashes($mailto_link) . "'; setTimeout(function(){ window.location.href='ubr_dashboard.php?view=" . $req_id . "'; }, 2000);</script>";
        exit;
    }

    header('Location: ubr_dashboard.php?view=' . $req_id);
    exit;
}

// --- Получение списка обращений ---
$status_filter = $_GET['status'] ?? 'new';

// Показываем тикеты, которые:
// - либо адресованы группе (ubr_head_tabel = начальник текущего сотрудника, assigned_to_tabel IS NULL)
// - либо уже назначены на текущего сотрудника (assigned_to_tabel = $user_tabel)
$sql = "SELECT sr.*, rt.name as type_name 
    FROM support_requests sr 
    LEFT JOIN support_request_types rt ON sr.request_type_id = rt.id 
    WHERE ((sr.ubr_head_tabel = ? AND sr.assigned_to_tabel IS NULL) OR sr.assigned_to_tabel = ?)
    AND sr.status = ? 
    ORDER BY sr.first_response_deadline ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$ubr_head_tabel, $user_tabel, $status_filter]);
$requests = $stmt->fetchAll();

// Просмотр одного тикета
$view_id = isset($_GET['view']) ? intval($_GET['view']) : null;
$ticket = null;
if ($view_id) {
    $stmt = $pdo->prepare("SELECT sr.*, rt.name as type_name FROM support_requests sr LEFT JOIN support_request_types rt ON sr.request_type_id = rt.id WHERE sr.id = ?");
    $stmt->execute([$view_id]);
    $ticket = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>УБР Middle - Обращения</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .card { background:#fff; border-radius:16px; padding:20px; margin-bottom:20px; box-shadow:0 2px 12px rgba(0,0,0,0.04); }
        .btn { background:#1a73e8; color:#fff; border:none; padding:8px 16px; border-radius:8px; cursor:pointer; }
        .btn-sm { background:#6c757d; font-size:12px; padding:4px 8px; border-radius:12px; text-decoration:none; color:white; }
        .status { display:inline-block; padding:4px 12px; border-radius:20px; font-size:12px; }
        .status-new { background:#ffc107; color:#000; }
        .status-progress { background:#17a2b8; color:#fff; }
        .status-waiting { background:#fd7e14; color:#fff; }
        .status-closed { background:#28a745; color:#fff; }
        .nav { display:flex; gap:10px; margin-bottom:20px; }
        .nav a { padding:8px 16px; background:#f0f2f5; border-radius:20px; text-decoration:none; }
        .form-group { margin-bottom:15px; }
        .form-group textarea { width:100%; padding:8px 12px; border:1px solid #ccc; border-radius:8px; }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="dashboard.php">📊 Дашборд</a>
        <a href="ubr_dashboard.php" class="active">🛠️ Обращения УБР</a>
        <span style="margin-left:auto;">👤 <?= htmlspecialchars($user_name) ?> | <a href="logout.php">Выйти</a></span>
    </div>

    <?php if ($view_id && $ticket): ?>
        <h2>Тикет <?= htmlspecialchars($ticket['ticket_number']) ?></h2>
        <div class="card">
            <p><strong>Клиент:</strong> <?= htmlspecialchars($ticket['client_name']) ?> (ИНН <?= htmlspecialchars($ticket['client_inn']) ?>)</p>
            <p><strong>Тип:</strong> <?= htmlspecialchars($ticket['type_name']) ?></p>
            <p><strong>Статус:</strong> <span class="status status-<?= $ticket['status'] == 'new' ? 'new' : ($ticket['status'] == 'in_progress' ? 'progress' : ($ticket['status'] == 'waiting_for_mmb' ? 'waiting' : 'closed')) ?>"><?= $ticket['status'] ?></span></p>
            <p><strong>Срок первого ответа:</strong> <?= $ticket['first_response_deadline'] ?></p>
            <p><strong>Срок решения:</strong> <?= $ticket['resolution_deadline'] ?></p>
            <?php if (!$ticket['assigned_to_tabel'] && $ticket['status'] != 'closed' && $ticket['status'] != 'waiting_for_mmb'): ?>
                <form method="post"><input type="hidden" name="request_id" value="<?= $ticket['id'] ?>"><button type="submit" name="assign_to_me" class="btn">📌 Взять в работу</button></form>
            <?php elseif ($ticket['assigned_to_tabel'] == $user_tabel && $ticket['status'] == 'in_progress'): ?>
                <!-- Здесь форма ответа -->
                <form method="post" style="margin-top:20px;">
                    <input type="hidden" name="request_id" value="<?= $ticket['id'] ?>">
                    <div class="form-group">
                        <textarea name="message" rows="5" placeholder="Введите ваш ответ..." required></textarea>
                    </div>
                    <button type="submit" name="send_reply" class="btn">✉️ Отправить ответ</button>
                </form>
            <?php elseif ($ticket['assigned_to_tabel'] == $user_tabel && $ticket['status'] == 'waiting_for_mmb'): ?>
                <p>Тикет ожидает подтверждения от ММБ.</p>
            <?php elseif ($ticket['assigned_to_tabel'] && $ticket['assigned_to_tabel'] != $user_tabel): ?>
                <p>Тикет уже взят в работу другим сотрудником.</p>
            <?php endif; ?>
        </div>
        <a href="ubr_dashboard.php" class="btn">← Назад к списку</a>
    <?php else: ?>
        <h2>📋 Обращения в поддержку</h2>
        <div class="card">
            <form method="get">
                <label>Фильтр по статусу:</label>
                <select name="status" onchange="this.form.submit()">
                    <option value="new" <?= $status_filter=='new'?'selected':'' ?>>Новые</option>
                    <option value="in_progress" <?= $status_filter=='in_progress'?'selected':'' ?>>В работе</option>
                    <option value="waiting_for_mmb" <?= $status_filter=='waiting_for_mmb'?'selected':'' ?>>Ожидают ММБ</option>
                    <option value="closed" <?= $status_filter=='closed'?'selected':'' ?>>Закрытые</option>
                </select>
            </form>
            <table style="width:100%; margin-top:15px;">
                <thead><tr><th>№</th><th>Клиент</th><th>Тип</th><th>Статус</th><th>Создан</th><th>Срок ответа</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($requests as $req): ?>
                <tr>
                    <td><?= htmlspecialchars($req['ticket_number']) ?></td>
                    <td><?= htmlspecialchars($req['client_name']) ?></td>
                    <td><?= htmlspecialchars($req['type_name']) ?></td>
                    <td><span class="status status-<?= $req['status']=='new'?'new':($req['status']=='in_progress'?'progress':($req['status']=='waiting_for_mmb'?'waiting':'closed')) ?>"><?= $req['status'] ?></span></td>
                    <td><?= $req['created_at'] ?></td>
                    <td><?= $req['first_response_deadline'] ?></td>
                    <td><a href="?view=<?= $req['id'] ?>" class="btn-sm">Открыть</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>