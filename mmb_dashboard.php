<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['mmb_manager', 'mmb_tp_head'])) {
    header('Location: dashboard.php');
    exit;
}
require_once 'db.php';

$user_tabel = $_SESSION['tabel'];
$user_name = $_SESSION['name'];
$role = $_SESSION['role'];

// --- Отзыв (удаление) тикета ---
if (isset($_GET['cancel_ticket'])) {
    $ticket_id = intval($_GET['cancel_ticket']);
    // Проверяем, что тикет принадлежит текущему пользователю и имеет статус 'new'
    $check = $pdo->prepare("SELECT id FROM support_requests WHERE id = ? AND created_by_tabel = ? AND status = 'new'");
    $check->execute([$ticket_id, $user_tabel]);
    if ($check->fetch()) {
        $pdo->prepare("DELETE FROM support_requests WHERE id = ?")->execute([$ticket_id]);
    }
    header('Location: mmb_dashboard.php');
    exit;
}

// --- Обновление статуса любого тикета точки ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $ticket_id = intval($_POST['ticket_id'] ?? 0);
    $new_status = $_POST['new_status'] ?? '';

    // Определяем mmb_head_tabel текущего пользователя
    $stmt = $pdo->prepare("SELECT manager_id, head_tabel FROM users WHERE tabel_number = ?");
    $stmt->execute([$user_tabel]);
    $user_data = $stmt->fetch();
    $current_mmb_head = null;
    if ($user_data) {
        if (!empty($user_data['manager_id'])) {
            $stmt2 = $pdo->prepare("SELECT tabel_number FROM users WHERE id = ?");
            $stmt2->execute([$user_data['manager_id']]);
            $current_mmb_head = $stmt2->fetchColumn();
        } elseif (!empty($user_data['head_tabel'])) {
            $current_mmb_head = $user_data['head_tabel'];
        }
    }
    if ($role == 'mmb_tp_head') {
        $current_mmb_head = $user_tabel;
    }

    // Проверяем, что тикет принадлежит нашей точке продаж
    $check = $pdo->prepare("SELECT id FROM support_requests WHERE id = ? AND mmb_head_tabel = ?");
    $check->execute([$ticket_id, $current_mmb_head]);
    if ($check->fetch() && in_array($new_status, ['new','in_progress','waiting_for_mmb','resolved','closed'])) {
        $update_fields = "status = ?, updated_at = datetime('now')";
        if ($new_status === 'resolved') {
            $update_fields .= ", resolved_at = datetime('now')";
        } elseif ($new_status === 'closed') {
            $update_fields .= ", closed_at = datetime('now')";
        }
        $pdo->prepare("UPDATE support_requests SET $update_fields WHERE id = ?")->execute([$new_status, $ticket_id]);
    }
    header('Location: mmb_dashboard.php');
    exit;
}

// Определяем начальника текущего пользователя
$stmt = $pdo->prepare("SELECT manager_id, head_tabel FROM users WHERE tabel_number = ?");
$stmt->execute([$user_tabel]);
$user_data = $stmt->fetch();
$mmb_head_tabel = null;
if ($user_data) {
    if (!empty($user_data['manager_id'])) {
        $stmt2 = $pdo->prepare("SELECT tabel_number FROM users WHERE id = ?");
        $stmt2->execute([$user_data['manager_id']]);
        $mmb_head_tabel = $stmt2->fetchColumn();
    } elseif (!empty($user_data['head_tabel'])) {
        $mmb_head_tabel = $user_data['head_tabel'];
    }
}
if ($role == 'mmb_tp_head') {
    $mmb_head_tabel = $user_tabel;
}

// По маршруту находим начальника УБР
$ubr_head_tabel = null;
if ($mmb_head_tabel) {
    $stmt = $pdo->prepare("SELECT ubr_head_tabel FROM mmb_head_to_ubr_head WHERE mmb_head_tabel = ?");
    $stmt->execute([$mmb_head_tabel]);
    $ubr_head_tabel = $stmt->fetchColumn();
}

// Выбираем почтовый ящик
$mailbox = null;
if ($ubr_head_tabel) {
    $stmt = $pdo->prepare("SELECT email_address FROM support_mailboxes WHERE ubr_head_tabel = ? LIMIT 1");
    $stmt->execute([$ubr_head_tabel]);
    $mailbox = $stmt->fetchColumn();
}
if (!$mailbox) {
    $stmt = $pdo->query("SELECT email_address FROM support_mailboxes WHERE ubr_head_tabel IS NULL LIMIT 1");
    $mailbox = $stmt->fetchColumn();
}
if (!$mailbox) {
    $mailbox = 'group@example.com';
}

$types = $pdo->query("SELECT id, name FROM support_request_types WHERE is_active=1")->fetchAll();

$message = '';
$ticket_data = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_ticket'])) {
    $client_inn = trim($_POST['client_inn']);
    $request_type_id = intval($_POST['request_type_id']);
    $contact_name = trim($_POST['contact_name'] ?? '');
    $contact_phone = trim($_POST['contact_phone'] ?? '');
    $description = trim($_POST['description'] ?? '');

    $errors = [];
    if (!$client_inn) $errors[] = 'ИНН клиента';
    if (!$request_type_id) $errors[] = 'Тип обращения';
    if (!$contact_name) $errors[] = 'ФИО клиента';
    if (!$contact_phone) $errors[] = 'Телефон клиента';
    if (!$description) $errors[] = 'Описание проблемы';
    if ($errors) {
        $message = '<div class="error">❌ Заполните обязательные поля: ' . implode(', ', $errors) . '</div>';
    } else {
        $stmt = $pdo->prepare("SELECT name FROM clients WHERE inn = ?");
        $stmt->execute([$client_inn]);
        $client = $stmt->fetch();
        if (!$client) {
            $message = '<div class="error">❌ Клиент с таким ИНН не найден в системе.</div>';
        } else {
            $sla = $pdo->prepare("SELECT response_hours, resolution_hours FROM sla_policies WHERE request_type_id = ? AND is_active = 1");
            $sla->execute([$request_type_id]);
            $sla_row = $sla->fetch();
            $response_hours = $sla_row ? $sla_row['response_hours'] : 24;
            $resolution_hours = $sla_row ? $sla_row['resolution_hours'] : 72;

            $created_at = date('Y-m-d H:i:s');
            $first_response_deadline = date('Y-m-d H:i:s', strtotime("+{$response_hours} hours"));
            $resolution_deadline = date('Y-m-d H:i:s', strtotime("+{$resolution_hours} hours"));

            $ticket_number = 'REQ-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

            $stmt = $pdo->prepare("INSERT INTO support_requests 
                (ticket_number, client_inn, client_name, request_type_id, status, created_by_tabel, created_at, first_response_deadline, resolution_deadline, mmb_head_tabel, ubr_head_tabel)
                VALUES (?, ?, ?, ?, 'new', ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $ticket_number, $client_inn, $client['name'], $request_type_id,
                $user_tabel, $created_at, $first_response_deadline, $resolution_deadline,
                $mmb_head_tabel, $ubr_head_tabel
            ]);

            $type_name = '';
            foreach ($types as $t) if ($t['id'] == $request_type_id) $type_name = $t['name'];

            $mail_subject = "Тикет {$ticket_number}: Обращение от " . date('d.m.Y H:i');
            $mail_body = "Тип: {$type_name}\n";
            $mail_body .= "ИНН клиента: {$client_inn}\nКлиент: {$client['name']}\n";
            $mail_body .= "Контактное лицо: {$contact_name}\nТелефон: {$contact_phone}\n";
            $mail_body .= "Описание:\n{$description}\n";
            $mail_body .= "Срок первого ответа: до {$first_response_deadline}\nСрок решения: до {$resolution_deadline}\n";
            $mail_body .= "\n--\nПри ответе сохраняйте тему письма (с номером тикета).\n";
            $mail_body .= "Автор обращения: {$user_name} (таб. {$user_tabel})";

            $encoded_subject = rawurlencode($mail_subject);
            $encoded_body = rawurlencode($mail_body);
            $mailto_link = "mailto:{$mailbox}?subject={$encoded_subject}&body={$encoded_body}";

            $ticket_data = [
                'ticket_number' => $ticket_number,
                'mail_subject' => $mail_subject,
                'mail_body' => $mail_body,
                'mailto_link' => $mailto_link,
                'full_copy_text' => "Тема: {$mail_subject}\n\nТело письма:\n{$mail_body}"
            ];
        }
    }
}

// --- ФИЛЬТР ПО СОТРУДНИКУ ---
$filter_tabel = $_GET['filter_tabel'] ?? $user_tabel; // По умолчанию — свои

// Получаем список коллег (менеджеров ММБ с тем же mmb_head_tabel)
$colleagues = [];
if ($mmb_head_tabel) {
    $stmt = $pdo->prepare("SELECT tabel_number, full_name FROM users WHERE (head_tabel = ? OR (role = 'mmb_tp_head' AND tabel_number = ?)) AND role IN ('mmb_manager', 'mmb_tp_head') AND tabel_number != ? ORDER BY full_name");
    $stmt->execute([$mmb_head_tabel, $mmb_head_tabel, $user_tabel]);
    $colleagues = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Получение списка обращений
if ($filter_tabel === 'all') {
    // Все тикеты точки продаж
    $requests = $pdo->prepare("SELECT sr.*, rt.name as type_name, creator.full_name as creator_name
        FROM support_requests sr 
        LEFT JOIN support_request_types rt ON sr.request_type_id = rt.id 
        LEFT JOIN users creator ON sr.created_by_tabel = creator.tabel_number
        WHERE sr.mmb_head_tabel = ? 
        ORDER BY sr.created_at DESC");
    $requests->execute([$mmb_head_tabel]);
} elseif ($filter_tabel == $user_tabel) {
    // Только свои
    $requests = $pdo->prepare("SELECT sr.*, rt.name as type_name, creator.full_name as creator_name
        FROM support_requests sr 
        LEFT JOIN support_request_types rt ON sr.request_type_id = rt.id 
        LEFT JOIN users creator ON sr.created_by_tabel = creator.tabel_number
        WHERE sr.created_by_tabel = ? 
        ORDER BY sr.created_at DESC");
    $requests->execute([$user_tabel]);
} else {
    // Конкретный коллега
    $requests = $pdo->prepare("SELECT sr.*, rt.name as type_name, creator.full_name as creator_name
        FROM support_requests sr 
        LEFT JOIN support_request_types rt ON sr.request_type_id = rt.id 
        LEFT JOIN users creator ON sr.created_by_tabel = creator.tabel_number
        WHERE sr.created_by_tabel = ? AND sr.mmb_head_tabel = ?
        ORDER BY sr.created_at DESC");
    $requests->execute([$filter_tabel, $mmb_head_tabel]);
}
$my_requests = $requests->fetchAll();

// Статусы для отображения
$status_labels = [
    'new' => 'Новый',
    'in_progress' => 'В работе',
    'waiting_for_mmb' => 'Ожидает ММБ',
    'resolved' => 'Решён',
    'closed' => 'Закрыт'
];
$status_classes = [
    'new' => 'status-new',
    'in_progress' => 'status-progress',
    'waiting_for_mmb' => 'status-waiting',
    'resolved' => 'status-resolved',
    'closed' => 'status-closed'
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Поддержка ММБ</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .card { background:#fff; border-radius:16px; padding:20px; margin-bottom:20px; box-shadow:0 2px 12px rgba(0,0,0,0.04); }
        .form-group { margin-bottom:15px; }
        .form-group label { display:block; margin-bottom:5px; font-weight:500; }
        .form-group input, .form-group select, .form-group textarea { width:100%; padding:8px 12px; border:1px solid #ccc; border-radius:8px; }
        .btn { background:#1a73e8; color:#fff; border:none; padding:10px 20px; border-radius:8px; cursor:pointer; }
        .error { background:#f8d7da; color:#721c24; padding:10px; border-radius:8px; margin-bottom:15px; }
        .success { background:#d4edda; color:#155724; padding:10px; border-radius:8px; margin-bottom:15px; }
        table { width:100%; border-collapse:collapse; table-layout: fixed; }
        th, td { padding:8px; text-align:left; border-bottom:1px solid #eee; word-break: break-word; white-space: normal; }
        th { background:#f8f9fa; }
        .nav { display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap; align-items:center; }
        .nav a { padding:8px 16px; background:#f0f2f5; border-radius:20px; text-decoration:none; color:#1a1a2e; }
        .nav a.active { background:#1a73e8; color:#fff; }
        .nav .logout { background:#e03131; color:#fff; }
        .status { display:inline-block; padding:4px 12px; border-radius:20px; font-size:12px; }
        .status-new { background:#ffc107; color:#000; }
        .status-progress { background:#17a2b8; color:#fff; }
        .status-waiting { background:#fd7e14; color:#fff; }
        .status-resolved { background:#6c757d; color:#fff; }
        .status-closed { background:#28a745; color:#fff; }
        .btn-sm { background:#6c757d; font-size:12px; padding:4px 8px; border-radius:12px; text-decoration:none; color:white; }
        .btn-danger-small { background:#e03131; font-size:12px; padding:4px 8px; border-radius:12px; text-decoration:none; color:white; margin-left:5px; }
        .btn-success-small { background:#28a745; font-size:12px; padding:4px 8px; border-radius:12px; border:none; color:white; cursor:pointer; margin-left:5px; }
        .btn-primary-small { background:#1a73e8; font-size:12px; padding:4px 8px; border-radius:12px; border:none; color:white; cursor:pointer; margin-left:5px; }
        .copy-btn { background:#28a745; margin-left:10px; padding:4px 12px; border-radius:8px; border:none; color:#fff; cursor:pointer; }
        .mail-content { background:#f8f9fa; padding:15px; border-radius:8px; margin:10px 0; font-family:monospace; white-space:pre-wrap; }
        .instruction { background:#fff3cd; border-left:4px solid #ffc107; padding:10px; margin:10px 0; }
        .filter-bar { display:flex; gap:10px; margin-bottom:15px; align-items:center; flex-wrap:wrap; }
        .filter-bar select { padding:6px 12px; border:1px solid #ccc; border-radius:8px; font-size:14px; }
        .filter-bar label { font-weight:500; font-size:14px; }
        .hint { background:#e7f3ff; border-left:4px solid #1a73e8; padding:10px; margin-bottom:15px; font-size:13px; color:#1a1a2e; }
        /* Ограничение ширины для колонок таблицы */
        td:nth-child(2), th:nth-child(2) { max-width: 180px; overflow: hidden; text-overflow: ellipsis; }
        td:nth-child(3), th:nth-child(3) { max-width: 130px; overflow: hidden; text-overflow: ellipsis; }
        td:nth-child(4), th:nth-child(4) { max-width: 110px; }
        td:nth-child(5), th:nth-child(5) { max-width: 140px; }
        td:nth-child(6), th:nth-child(6) { max-width: 140px; }
        td:last-child { width: 160px; }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="mmb_dashboard.php" class="active">🆘 Поддержка ММБ</a>
        <?php if ($role === 'mmb_tp_head'): ?>
            <a href="mmb_head_dashboard.php">📈 Отчёт руководителя</a>
        <?php endif; ?>
        <span style="margin-left:auto;">👤 <?= htmlspecialchars($user_name) ?></span>
        <a href="logout.php" class="logout">Выйти</a>
    </div>

    <?php if ($ticket_data): ?>
        <div class="success">
            ✅ Тикет <strong><?= htmlspecialchars($ticket_data['ticket_number']) ?></strong> успешно создан.
        </div>
        <div class="card">
            <h3>📧 Отправьте письмо в УБР</h3>
            <p><strong>Тема письма:</strong><br><?= htmlspecialchars($ticket_data['mail_subject']) ?></p>
            <p><strong>Тело письма:</strong></p>
            <div class="mail-content" id="mailBodyText"><?= nl2br(htmlspecialchars($ticket_data['mail_body'])) ?></div>
            <div id="fullCopyText" style="display:none;"><?= htmlspecialchars($ticket_data['full_copy_text']) ?></div>

            <div class="instruction">
                📌 <strong>Для корпоративного iPad:</strong> если после нажатия на кнопку ниже не открылось письмо, скопируйте текст выше и вставьте его в пустое письмо вашей почты (вручную укажите получателя <?= htmlspecialchars($mailbox) ?> и тему).
            </div>

            <p>
                <a href="<?= htmlspecialchars($ticket_data['mailto_link']) ?>" class="btn">📧 Открыть почтовую программу</a>
                <button class="copy-btn" onclick="copyText()">📋 Скопировать текст письма</button>
                <button class="copy-btn" onclick="copyLink()">🔗 Скопировать ссылку</button>
            </p>
            <p><a href="mmb_dashboard.php" class="btn-sm">← Вернуться к созданию нового обращения</a></p>
        </div>
    <?php else: ?>
        <h2>📬 Создать обращение в УБР</h2>
        <?= $message ?>
        <div class="card">
            <form method="post">
                <div class="form-group">
                    <label>ИНН клиента *</label>
                    <input type="text" name="client_inn" required placeholder="Введите ИНН (должен быть в справочнике)">
                </div>
                <div class="form-group">
                    <label>Тип обращения *</label>
                    <select name="request_type_id" required>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>ФИО клиента (контактное лицо) *</label>
                    <input type="text" name="contact_name" required>
                </div>
                <div class="form-group">
                    <label>Телефон клиента *</label>
                    <input type="text" name="contact_phone" required>
                </div>
                <div class="form-group">
                    <label>Описание проблемы *</label>
                    <textarea name="description" rows="4" required></textarea>
                </div>
                <button type="submit" name="create_ticket" class="btn">📤 Отправить обращение</button>
            </form>
        </div>
    <?php endif; ?>

    <!-- Фильтр -->
    <h3>📋 Обращения</h3>
    <div class="hint">
        💡 Вы можете просматривать и менять статус тикетов любых коллег своей точки продаж. Отозвать (удалить) можно только свои тикеты со статусом «Новый».
    </div>
    <div class="card">
        <div class="filter-bar">
            <label>👤 Сотрудник:</label>
            <form method="GET" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <select name="filter_tabel" onchange="this.form.submit()">
                    <option value="<?= $user_tabel ?>" <?= $filter_tabel == $user_tabel ? 'selected' : '' ?>>👤 Мои тикеты</option>
                    <option value="all" <?= $filter_tabel === 'all' ? 'selected' : '' ?>>👥 Все тикеты точки</option>
                    <?php foreach ($colleagues as $col): ?>
                    <option value="<?= $col['tabel_number'] ?>" <?= $filter_tabel == $col['tabel_number'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(explode(' ', $col['full_name'])[0]) ?> (таб. <?= $col['tabel_number'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <span style="margin-left:auto; font-size:13px; color:#6c757d;">Найдено: <?= count($my_requests) ?></span>
        </div>

        <table>
            <thead>
                <tr><th>№ тикета</th><th>Клиент</th><th>Тип</th><th>Статус</th><th>Создал</th><th>Создан</th><th>Срок ответа</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($my_requests as $req): ?>
                <tr>
                    <td><a href="ticket_view.php?id=<?= $req['id'] ?>"><?= htmlspecialchars($req['ticket_number']) ?></a></td>
                    <td><?= htmlspecialchars($req['client_name']) ?></td>
                    <td><?= htmlspecialchars($req['type_name']) ?></td>
                    <td><span class="status <?= $status_classes[$req['status']] ?? 'status-new' ?>"><?= $status_labels[$req['status']] ?? $req['status'] ?></span></td>
                    <td><?= htmlspecialchars($req['creator_name'] ?: $req['created_by_tabel']) ?></td>
                    <td><?= date('d.m H:i', strtotime($req['created_at'])) ?></td>
                    <td><?= $req['first_response_deadline'] ? date('d.m H:i', strtotime($req['first_response_deadline'])) : '—' ?></td>
                    <td style="white-space:nowrap;">
                        <a href="ticket_view.php?id=<?= $req['id'] ?>" class="btn-sm">Открыть</a>

                        <?php if ($req['status'] === 'new'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="ticket_id" value="<?= $req['id'] ?>">
                                <input type="hidden" name="new_status" value="in_progress">
                                <button type="submit" name="update_status" class="btn-primary-small" title="Взять в работу">▶️</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($req['status'] === 'in_progress'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="ticket_id" value="<?= $req['id'] ?>">
                                <input type="hidden" name="new_status" value="waiting_for_mmb">
                                <button type="submit" name="update_status" class="btn-primary-small" title="Ожидает ММБ">⏸️</button>
                            </form>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="ticket_id" value="<?= $req['id'] ?>">
                                <input type="hidden" name="new_status" value="resolved">
                                <button type="submit" name="update_status" class="btn-success-small" title="Решён">✅</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($req['status'] === 'waiting_for_mmb'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="ticket_id" value="<?= $req['id'] ?>">
                                <input type="hidden" name="new_status" value="in_progress">
                                <button type="submit" name="update_status" class="btn-primary-small" title="Вернуть в работу">▶️</button>
                            </form>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="ticket_id" value="<?= $req['id'] ?>">
                                <input type="hidden" name="new_status" value="resolved">
                                <button type="submit" name="update_status" class="btn-success-small" title="Решён">✅</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($req['status'] === 'resolved'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="ticket_id" value="<?= $req['id'] ?>">
                                <input type="hidden" name="new_status" value="closed">
                                <button type="submit" name="update_status" class="btn-primary-small" title="Закрыть">🔒</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($req['status'] == 'new' && $req['created_by_tabel'] == $user_tabel): ?>
                            <a href="?cancel_ticket=<?= $req['id'] ?>" class="btn-danger-small" onclick="return confirm('Отозвать обращение? Тикет будет удалён без возможности восстановления.')">Отозвать</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (empty($my_requests)): ?>
            <div style="text-align:center; padding:30px; color:#9ca3af;">
                📭 Тикетов не найдено
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function copyText() {
    let text = document.getElementById('fullCopyText').innerText;
    navigator.clipboard.writeText(text).then(() => alert('✅ Тема и текст письма скопированы')).catch(() => alert('❌ Ошибка копирования'));
}
function copyLink() {
    let link = '<?= addslashes($ticket_data['mailto_link'] ?? '') ?>';
    navigator.clipboard.writeText(link).then(() => alert('✅ Ссылка скопирована')).catch(() => alert('❌ Ошибка копирования'));
}
</script>
</body>
</html>
