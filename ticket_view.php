<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['mmb_manager', 'mmb_tp_head', 'ubr_middle', 'head', 'admin'])) {
    header('Location: dashboard.php');
    exit;
}
require_once 'db.php';

$id = intval($_GET['id']);
$stmt = $pdo->prepare("SELECT sr.*, rt.name as type_name FROM support_requests sr LEFT JOIN support_request_types rt ON sr.request_type_id = rt.id WHERE sr.id = ?");
$stmt->execute([$id]);
$ticket = $stmt->fetch();
if (!$ticket) die('Тикет не найден');

$user_role = $_SESSION['role'];
$is_mmb = in_array($user_role, ['mmb_manager', 'mmb_tp_head']);

// Подтверждение (закрытие) тикета
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_ticket'])) {
    $ticket_id = intval($_POST['ticket_id']);
    $pdo->prepare("UPDATE support_requests SET status = 'closed', closed_at = datetime('now'), updated_at = datetime('now') WHERE id = ?")->execute([$ticket_id]);
    header('Location: mmb_dashboard.php');
    exit;
}

// Отправка на доработку
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rework_ticket'])) {
    $ticket_id = intval($_POST['ticket_id']);
    $comment = trim($_POST['comment']); // не сохраняется, только в письмо
    $pdo->prepare("UPDATE support_requests SET status = 'in_progress', assigned_to_tabel = NULL, updated_at = datetime('now') WHERE id = ?")->execute([$ticket_id]);

    $mailbox = $pdo->query("SELECT email_address FROM support_mailboxes WHERE is_active=1 LIMIT 1")->fetchColumn();
    if (!$mailbox) $mailbox = 'group@example.com';
    $subject = "Доработка по тикету " . $ticket['ticket_number'];
    $body = "Здравствуйте!\n\nТикет {$ticket['ticket_number']} отправлен на доработку.\n\nКомментарий: {$comment}\n\n--\nС уважением, ММБ";
    $mailto_link = "mailto:" . rawurlencode($mailbox) . "?subject=" . rawurlencode($subject) . "&body=" . rawurlencode($body);
    echo "<script>window.location.href='" . addslashes($mailto_link) . "'; setTimeout(function(){ window.location.href='mmb_dashboard.php'; }, 2000);</script>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Тикет <?= htmlspecialchars($ticket['ticket_number']) ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .card { background:#fff; border-radius:16px; padding:20px; margin-bottom:20px; }
        .btn { background:#1a73e8; color:#fff; border:none; padding:8px 16px; border-radius:8px; cursor:pointer; text-decoration:none; display:inline-block; margin-right:10px; }
        .btn-success { background:#28a745; }
        .btn-danger { background:#dc3545; }
        .status { display:inline-block; padding:4px 12px; border-radius:20px; font-size:12px; }
        .status-new { background:#ffc107; color:#000; }
        .status-progress { background:#17a2b8; color:#fff; }
        .status-waiting { background:#fd7e14; color:#fff; }
        .status-closed { background:#28a745; color:#fff; }
        .form-group { margin-bottom:15px; }
        .form-group label { display:block; margin-bottom:5px; font-weight:500; }
        .form-group textarea { width:100%; padding:8px 12px; border:1px solid #ccc; border-radius:8px; }
    </style>
</head>
<body>
<div class="container">
    <h2>Тикет <?= htmlspecialchars($ticket['ticket_number']) ?></h2>
    <div class="card">
        <p><strong>Клиент:</strong> <?= htmlspecialchars($ticket['client_name']) ?> (ИНН <?= htmlspecialchars($ticket['client_inn']) ?>)</p>
        <p><strong>Тип:</strong> <?= htmlspecialchars($ticket['type_name']) ?></p>
        <p><strong>Статус:</strong> <span class="status status-<?= $ticket['status'] == 'new' ? 'new' : ($ticket['status'] == 'in_progress' ? 'progress' : ($ticket['status'] == 'waiting_for_mmb' ? 'waiting' : 'closed')) ?>"><?= $ticket['status'] ?></span></p>
        <p><strong>Срок первого ответа:</strong> <?= $ticket['first_response_deadline'] ?></p>
        <p><strong>Срок решения:</strong> <?= $ticket['resolution_deadline'] ?></p>
        <?php if ($is_mmb && $ticket['status'] == 'waiting_for_mmb'): ?>
            <hr>
            <h3>Действия</h3>
            <form method="post" style="display:inline-block;">
                <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
                <button type="submit" name="confirm_ticket" class="btn btn-success" onclick="return confirm('Подтвердить решение и закрыть тикет?')">✅ Подтвердить (закрыть)</button>
            </form>
            <form method="post" style="margin-top:10px;">
                <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
                <div class="form-group">
                    <label>Причина доработки *</label>
                    <textarea name="comment" rows="2" required placeholder="Укажите, что нужно исправить или дополнить..."></textarea>
                </div>
                <button type="submit" name="rework_ticket" class="btn btn-danger">🔄 Отправить на доработку</button>
            </form>
        <?php elseif ($ticket['status'] == 'closed'): ?>
            <p><strong>Тикет закрыт.</strong></p>
        <?php elseif ($is_mmb && $ticket['status'] != 'waiting_for_mmb'): ?>
            <p><strong>Тикет в работе у УБР. Ожидайте ответа.</strong></p>
        <?php endif; ?>
    </div>
    <a href="<?= $is_mmb ? 'mmb_dashboard.php' : 'ubr_dashboard.php' ?>" class="btn">← Назад</a>
</div>
</body>
</html>