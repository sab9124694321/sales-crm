<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$allowed_roles = ['manager', 'head', 'admin', 'ubr_middle', 'mmb_tp_head'];
if (!in_array($role, $allowed_roles)) {
    header('Location: dashboard.php');
    exit;
}

require_once 'db.php';

$manager_id = $_SESSION['user_id'];

// HEAD и ADMIN видят ВСЕ лиды, менеджеры — только свои или свободные
$is_head = in_array($role, ['head', 'admin', 'mmb_tp_head']);

// Обработка POST-запросов (редактирование/удаление)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_head) {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_lead') {
        $lead_id = $_POST['lead_id'] ?? 0;
        $stmt = $pdo->prepare("DELETE FROM hunter_leads WHERE id = ?");
        $stmt->execute([$lead_id]);
        header('Location: leads.php');
        exit;
    }

    if ($action === 'update_lead') {
        $lead_id = $_POST['lead_id'] ?? 0;
        $client_name = $_POST['client_name'] ?? '';
        $inn = $_POST['inn'] ?? '';
        $client_phone = $_POST['client_phone'] ?? '';
        $contact_name = $_POST['contact_name'] ?? '';
        $contact_phone = $_POST['contact_phone'] ?? '';
        $client_email = $_POST['client_email'] ?? '';
        $address = $_POST['address'] ?? '';
        $status = $_POST['status'] ?? '';

        $stmt = $pdo->prepare("UPDATE hunter_leads SET client_name = ?, inn = ?, client_phone = ?, contact_name = ?, contact_phone = ?, client_email = ?, address = ?, status = ?, updated_at = datetime('now') WHERE id = ?");
        $stmt->execute([$client_name, $inn, $client_phone, $contact_name, $contact_phone, $client_email, $address, $status, $lead_id]);
        header('Location: leads.php');
        exit;
    }

    if ($action === 'delete_hunter') {
        $hunter_id = $_POST['hunter_id'] ?? 0;
        // Удаляем связанные записи
        $pdo->prepare("DELETE FROM hunter_notifications WHERE hunter_id = ?")->execute([$hunter_id]);
        $pdo->prepare("DELETE FROM hunter_leads WHERE hunter_id = ?")->execute([$hunter_id]);
        $pdo->prepare("DELETE FROM hunters WHERE id = ?")->execute([$hunter_id]);
        header('Location: leads.php');
        exit;
    }
}

// Фильтр по статусу
$filter_status = $_GET['status'] ?? 'all';

// Все лиды (для head/admin)
if ($is_head) {
    $sql = "SELECT hl.*, h.full_name as hunter_name, h.login as hunter_login, h.phone as hunter_phone,
                   m.full_name as manager_name, m.tabel_number as manager_tabel
            FROM hunter_leads hl 
            LEFT JOIN hunters h ON hl.hunter_id = h.id
            LEFT JOIN users m ON hl.manager_id = m.id
            WHERE 1=1";
    $params = [];
    if ($filter_status !== 'all') {
        $sql .= " AND hl.status = ?";
        $params[] = $filter_status;
    }
    $sql .= " ORDER BY hl.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $all_leads = $stmt->fetchAll();
} else {
    $all_leads = [];
}

// Свободные лиды (status = 'new')
$stmt = $pdo->prepare("SELECT hl.*, h.full_name as hunter_name, h.login as hunter_login 
                       FROM hunter_leads hl 
                       LEFT JOIN hunters h ON hl.hunter_id = h.id
                       WHERE hl.status = 'new' ORDER BY hl.created_at ASC");
$stmt->execute();
$free_leads = $stmt->fetchAll();

// Мои лиды
$my_leads = [];
if ($role === 'manager') {
    $stmt = $pdo->prepare("SELECT hl.*, h.full_name as hunter_name, h.login as hunter_login,
                                  m.full_name as manager_name
                           FROM hunter_leads hl 
                           LEFT JOIN hunters h ON hl.hunter_id = h.id
                           LEFT JOIN users m ON hl.manager_id = m.id
                           WHERE hl.manager_id = ? ORDER BY hl.created_at DESC");
    $stmt->execute([$manager_id]);
    $my_leads = $stmt->fetchAll();
}

// Все охотники (для head/admin)
$all_hunters = [];
if ($is_head) {
    $stmt = $pdo->query("SELECT * FROM hunters ORDER BY created_at DESC");
    $all_hunters = $stmt->fetchAll();
}

$free_count = count($free_leads);
$is_manager = ($role === 'manager');

// Выгрузка в CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $is_head) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=leads_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['ID', 'Название', 'ИНН', 'Телефон заведения', 'Контактное лицо', 'Телефон контакта', 'Email', 'Адрес', 'Статус', 'Охотник', 'Менеджер', 'Бонусы', 'Дата создания', 'Комментарий']);
    foreach ($all_leads as $lead) {
        $status_text = $lead['status'] === 'new' ? 'Новый' : ($lead['status'] === 'assigned' ? 'В работе' : ($lead['status'] === 'converted' ? 'Подключен' : 'Отклонен'));
        fputcsv($output, [
            $lead['id'],
            $lead['client_name'],
            $lead['inn'],
            $lead['client_phone'],
            $lead['contact_name'],
            $lead['contact_phone'],
            $lead['client_email'],
            $lead['address'],
            $status_text,
            $lead['hunter_name'] ?? ('ID:' . $lead['hunter_id']),
            $lead['manager_name'] ?? '-',
            ($lead['bonus_points'] ?? 0) + ($lead['converted_bonus'] ?? 0),
            $lead['created_at'],
            $lead['comment'] ?? ''
        ]);
    }
    fclose($output);
    exit;
}

// Редактирование лида
$edit_lead = null;
if ($is_head && isset($_GET['edit_lead'])) {
    $stmt = $pdo->prepare("SELECT * FROM hunter_leads WHERE id = ?");
    $stmt->execute([$_GET['edit_lead']]);
    $edit_lead = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Лиды</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .lead-card { background: #fff; border-radius: 16px; padding: 16px; margin-bottom: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .lead-card .actions { margin-top: 10px; display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-sm { padding: 6px 14px; border-radius: 20px; border: none; font-weight: 600; cursor: pointer; font-size: 14px; }
        .btn-primary { background: #1a73e8; color: #fff; }
        .btn-success { background: #28a745; color: #fff; }
        .btn-danger { background: #dc3545; color: #fff; }
        .btn-secondary { background: #6c757d; color: #fff; }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-export { background: #17a2b8; color: #fff; text-decoration: none; display: inline-block; }
        .btn-sm:disabled { opacity: 0.5; cursor: not-allowed; }
        .comment-input { width: 100%; padding: 8px; border-radius: 8px; border: 1px solid #ddd; margin-top: 8px; }
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 30px; font-size: 12px; font-weight: 600; }
        .status-new { background: #fff3cd; color: #856404; }
        .status-assigned { background: #cce5ff; color: #004085; }
        .status-converted { background: #d4edda; color: #155724; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .tab { padding: 10px 20px; background: #f1f3f5; border-radius: 30px; cursor: pointer; }
        .tab.active { background: #1a73e8; color: #fff; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .hunter-info { font-size: 12px; color: #888; margin-top: 4px; }
        .lead-detail { font-size: 13px; color: #555; margin: 4px 0; }
        .lead-detail strong { color: #333; }
        .navbar{background:linear-gradient(135deg,#1a1a2e,#16213e);color:#fff;padding:12px 16px;border-radius:16px;margin-bottom:20px;display:flex;justify-content:space-between;flex-wrap:wrap;align-items:center}
        .logo{font-size:1.3rem;font-weight:bold}
        .nav-links{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
        .nav-links a{color:#ccc;text-decoration:none;font-size:0.85rem}
        .nav-links .active{color:#fff;font-weight:bold}
        .user-info{color:#fff;font-weight:bold;margin-left:auto;font-size:0.9rem}
        .container { max-width: 1200px; margin: 0 auto; padding: 0 16px; }
        .filter-bar { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; align-items: center; }
        .filter-bar select { padding: 8px 12px; border-radius: 8px; border: 1px solid #ddd; }
        .stats-bar { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; margin-bottom: 20px; }
        .stat-box { background: #fff; padding: 12px; border-radius: 12px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .stat-box .num { font-size: 24px; font-weight: 700; color: #1a73e8; }
        .stat-box .lbl { font-size: 12px; color: #888; }
        .edit-form { background: #f8f9fa; padding: 16px; border-radius: 12px; margin-top: 10px; }
        .edit-form input, .edit-form select { padding: 8px 12px; border-radius: 8px; border: 1px solid #ddd; margin: 4px 0; width: 100%; }
        .edit-form .form-row { display: flex; gap: 10px; flex-wrap: wrap; }
        .edit-form .form-group { flex: 1; min-width: 200px; }
        .hunter-card { background: #fff; border-radius: 16px; padding: 16px; margin-bottom: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    </style>
</head>
<body>
<div class="container">
    <div class="navbar">
        <div class="logo">🚀 SZB</div>
        <div class="nav-links">
            <a href="dashboard.php">Дашборд</a>
            <a href="team.php">Команда</a>
            <a href="leads.php" class="active">Лиды</a>
            <a href="quests.php">Квесты</a>
            <?php if($role=='admin'): ?><a href="admin.php">Админ</a><?php endif; ?>
            <span class="user-info">👤 <?= htmlspecialchars($_SESSION['name']) ?></span>
            <a href="logout.php">Выйти</a>
        </div>
    </div>

    <h2>📋 Лиды</h2>

    <?php if ($is_head): ?>
    <!-- Статистика для руководителя -->
    <div class="stats-bar">
        <div class="stat-box">
            <div class="num"><?= count(array_filter($all_leads, fn($l) => $l['status'] === 'new')) ?></div>
            <div class="lbl">Новые</div>
        </div>
        <div class="stat-box">
            <div class="num"><?= count(array_filter($all_leads, fn($l) => $l['status'] === 'assigned')) ?></div>
            <div class="lbl">В работе</div>
        </div>
        <div class="stat-box">
            <div class="num"><?= count(array_filter($all_leads, fn($l) => $l['status'] === 'converted')) ?></div>
            <div class="lbl">Подключено</div>
        </div>
        <div class="stat-box">
            <div class="num"><?= count(array_filter($all_leads, fn($l) => $l['status'] === 'rejected')) ?></div>
            <div class="lbl">Отказов</div>
        </div>
        <div class="stat-box">
            <div class="num"><?= count($all_leads) ?></div>
            <div class="lbl">Всего</div>
        </div>
    </div>

    <div class="filter-bar">
        <a href="?export=csv" class="btn-sm btn-export">📥 Выгрузить CSV</a>
        <form method="GET" style="display: flex; gap: 10px;">
            <select name="status" onchange="this.form.submit()">
                <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>Все статусы</option>
                <option value="new" <?= $filter_status === 'new' ? 'selected' : '' ?>>Новые</option>
                <option value="assigned" <?= $filter_status === 'assigned' ? 'selected' : '' ?>>В работе</option>
                <option value="converted" <?= $filter_status === 'converted' ? 'selected' : '' ?>>Подключены</option>
                <option value="rejected" <?= $filter_status === 'rejected' ? 'selected' : '' ?>>Отклонены</option>
            </select>
        </form>
    </div>
    <?php else: ?>
    <div style="background: #f8f9fa; border-radius: 16px; padding: 12px 20px; margin-bottom: 20px;">
        <strong>Свободных лидов:</strong> <?= $free_count ?>
        <span style="margin-left: 20px; font-size: 14px; color: #666;">(обновлено: <?= date('H:i:s') ?>)</span>
        <a href="leads.php" style="margin-left: 20px; font-size: 14px;">⟳</a>
    </div>
    <?php endif; ?>

    <div class="tabs">
        <?php if ($is_head): ?>
        <div class="tab active" data-tab="all">Все (<?= count($all_leads) ?>)</div>
        <div class="tab" data-tab="hunters">Охотники (<?= count($all_hunters) ?>)</div>
        <?php endif; ?>
        <div class="tab <?= !$is_head ? 'active' : '' ?>" data-tab="free">Свободные (<?= $free_count ?>)</div>
        <?php if ($is_manager): ?>
        <div class="tab" data-tab="my">Мои (<?= count($my_leads) ?>)</div>
        <?php endif; ?>
    </div>

    <?php if ($is_head): ?>
    <!-- Вкладка ВСЕ лиды -->
    <div id="tab-all" class="tab-content active">
        <?php if ($all_leads): ?>
            <?php foreach ($all_leads as $lead): ?>
                <div class="lead-card">
                    <div style="display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 10px;">
                        <div style="flex: 1;">
                            <div><strong><?= htmlspecialchars($lead['client_name']) ?></strong> — ИНН: <?= htmlspecialchars($lead['inn']) ?></div>
                            <div class="lead-detail">📞 Телефон заведения: <strong><?= htmlspecialchars($lead['client_phone']) ?></strong></div>
                            <div class="lead-detail">👤 Контакт: <strong><?= htmlspecialchars($lead['contact_name']) ?></strong> | 📱 <?= htmlspecialchars($lead['contact_phone']) ?></div>
                            <?php if ($lead['client_email']): ?>
                            <div class="lead-detail">✉️ Email: <?= htmlspecialchars($lead['client_email']) ?></div>
                            <?php endif; ?>
                            <?php if ($lead['address']): ?>
                            <div class="lead-detail">📍 Адрес: <?= htmlspecialchars($lead['address']) ?></div>
                            <?php endif; ?>
                            <?php if ($lead['comment']): ?>
                            <div class="lead-detail" style="color: #666; font-style: italic;">💬 <?= htmlspecialchars($lead['comment']) ?></div>
                            <?php endif; ?>
                            <div class="hunter-info">
                                🎯 Охотник: <?= htmlspecialchars($lead['hunter_name'] ?? 'ID:' . $lead['hunter_id']) ?> (<?= htmlspecialchars($lead['hunter_phone'] ?? '') ?>) | 
                                👤 Менеджер: <?= htmlspecialchars($lead['manager_name'] ?? '—') ?> |
                                <span class="status-badge status-<?= $lead['status'] ?>">
                                    <?= $lead['status'] === 'new' ? 'Новый' : ($lead['status'] === 'assigned' ? 'В работе' : ($lead['status'] === 'converted' ? 'Подключён' : 'Отклонён')) ?>
                                </span> |
                                <?= date('d.m.Y H:i', strtotime($lead['created_at'])) ?>
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 18px; font-weight: 700; color: #1a73e8;">+<?= ($lead['bonus_points'] ?? 0) + ($lead['converted_bonus'] ?? 0) ?> XP</div>
                            <div class="actions" style="margin-top: 8px;">
                                <a href="?edit_lead=<?= $lead['id'] ?>" class="btn-sm btn-warning">✏️ Редактировать</a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Удалить лид?');">
                                    <input type="hidden" name="action" value="delete_lead">
                                    <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
                                    <button type="submit" class="btn-sm btn-danger">🗑️ Удалить</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <?php if ($edit_lead && $edit_lead['id'] == $lead['id']): ?>
                    <div class="edit-form">
                        <h4>✏️ Редактирование лида #<?= $lead['id'] ?></h4>
                        <form method="POST">
                            <input type="hidden" name="action" value="update_lead">
                            <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Название</label>
                                    <input type="text" name="client_name" value="<?= htmlspecialchars($lead['client_name']) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>ИНН</label>
                                    <input type="text" name="inn" value="<?= htmlspecialchars($lead['inn']) ?>" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Телефон заведения</label>
                                    <input type="text" name="client_phone" value="<?= htmlspecialchars($lead['client_phone']) ?>">
                                </div>
                                <div class="form-group">
                                    <label>Контактное лицо</label>
                                    <input type="text" name="contact_name" value="<?= htmlspecialchars($lead['contact_name']) ?>">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Телефон контакта</label>
                                    <input type="text" name="contact_phone" value="<?= htmlspecialchars($lead['contact_phone']) ?>">
                                </div>
                                <div class="form-group">
                                    <label>Email</label>
                                    <input type="email" name="client_email" value="<?= htmlspecialchars($lead['client_email'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Адрес</label>
                                    <input type="text" name="address" value="<?= htmlspecialchars($lead['address'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label>Статус</label>
                                    <select name="status">
                                        <option value="new" <?= $lead['status'] === 'new' ? 'selected' : '' ?>>Новый</option>
                                        <option value="assigned" <?= $lead['status'] === 'assigned' ? 'selected' : '' ?>>В работе</option>
                                        <option value="converted" <?= $lead['status'] === 'converted' ? 'selected' : '' ?>>Подключён</option>
                                        <option value="rejected" <?= $lead['status'] === 'rejected' ? 'selected' : '' ?>>Отклонён</option>
                                    </select>
                                </div>
                            </div>
                            <div class="actions">
                                <button type="submit" class="btn-sm btn-success">💾 Сохранить</button>
                                <a href="leads.php" class="btn-sm btn-secondary">Отмена</a>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="color: #888; text-align: center; padding: 20px;">Нет лидов.</p>
        <?php endif; ?>
    </div>

    <!-- Вкладка ОХОТНИКИ -->
    <div id="tab-hunters" class="tab-content">
        <?php if ($all_hunters): ?>
            <?php foreach ($all_hunters as $hunter): ?>
                <div class="hunter-card">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <div><strong><?= htmlspecialchars($hunter['full_name']) ?></strong> (@<?= htmlspecialchars($hunter['login']) ?>)</div>
                            <div class="lead-detail">📱 <?= htmlspecialchars($hunter['phone']) ?></div>
                            <div class="lead-detail">✉️ <?= htmlspecialchars($hunter['email'] ?? '—') ?></div>
                            <div class="lead-detail">⭐ Уровень <?= $hunter['hunter_level'] ?? 1 ?> | <?= $hunter['points'] ?? 0 ?> XP</div>
                            <div class="lead-detail">🎯 Реферальный код: <?= htmlspecialchars($hunter['referral_code']) ?></div>
                            <div class="hunter-info">Зарегистрирован: <?= date('d.m.Y', strtotime($hunter['created_at'])) ?></div>
                        </div>
                        <form method="POST" onsubmit="return confirm('Удалить охотника и все его лиды?');">
                            <input type="hidden" name="action" value="delete_hunter">
                            <input type="hidden" name="hunter_id" value="<?= $hunter['id'] ?>">
                            <button type="submit" class="btn-sm btn-danger">🗑️ Удалить</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="color: #888; text-align: center; padding: 20px;">Нет охотников.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Вкладка СВОБОДНЫЕ -->
    <div id="tab-free" class="tab-content <?= !$is_head ? 'active' : '' ?>">
        <?php if ($free_leads): ?>
            <?php foreach ($free_leads as $lead): ?>
                <div class="lead-card">
                    <div><strong><?= htmlspecialchars($lead['client_name']) ?></strong> — ИНН: <?= htmlspecialchars($lead['inn']) ?></div>
                    <div class="lead-detail">📞 <?= htmlspecialchars($lead['client_phone']) ?></div>
                    <div class="lead-detail">👤 Контакт: <?= htmlspecialchars($lead['contact_name']) ?> | 📱 <?= htmlspecialchars($lead['contact_phone']) ?></div>
                    <?php if ($lead['client_email']): ?>
                    <div class="lead-detail">✉️ <?= htmlspecialchars($lead['client_email']) ?></div>
                    <?php endif; ?>
                    <?php if ($lead['address']): ?>
                    <div class="lead-detail">📍 <?= htmlspecialchars($lead['address']) ?></div>
                    <?php endif; ?>
                    <div class="hunter-info">🎯 Охотник: <?= htmlspecialchars($lead['hunter_name'] ?? 'ID:' . $lead['hunter_id']) ?> — <?= date('d.m.Y', strtotime($lead['created_at'])) ?></div>
                    <?php if ($is_manager): ?>
                        <div class="actions">
                            <button class="btn-sm btn-primary take-lead" data-id="<?= $lead['id'] ?>">📥 Взять в работу</button>
                        </div>
                    <?php else: ?>
                        <div class="actions"><span style="color: #888; font-size: 13px;">Только для менеджеров</span></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="color: #888; text-align: center; padding: 20px;">Нет свободных лидов.</p>
        <?php endif; ?>
    </div>

    <?php if ($is_manager): ?>
    <!-- Вкладка МОИ лиды -->
    <div id="tab-my" class="tab-content">
        <?php if ($my_leads): ?>
            <?php foreach ($my_leads as $lead): ?>
                <div class="lead-card">
                    <div><strong><?= htmlspecialchars($lead['client_name']) ?></strong> — ИНН: <?= htmlspecialchars($lead['inn']) ?></div>
                    <div class="lead-detail">📞 <?= htmlspecialchars($lead['client_phone']) ?></div>
                    <div class="lead-detail">👤 Контакт: <?= htmlspecialchars($lead['contact_name']) ?> | 📱 <?= htmlspecialchars($lead['contact_phone']) ?></div>
                    <?php if ($lead['client_email']): ?>
                    <div class="lead-detail">✉️ <?= htmlspecialchars($lead['client_email']) ?></div>
                    <?php endif; ?>
                    <?php if ($lead['address']): ?>
                    <div class="lead-detail">📍 <?= htmlspecialchars($lead['address']) ?></div>
                    <?php endif; ?>
                    <div>
                        <span class="status-badge status-<?= $lead['status'] ?>">
                            <?= $lead['status'] === 'new' ? 'Новый' : ($lead['status'] === 'assigned' ? 'В работе' : ($lead['status'] === 'converted' ? 'Подключён' : 'Отклонён')) ?>
                        </span>
                        <span style="font-size: 12px; color: #888; margin-left: 8px;"><?= date('d.m.Y H:i', strtotime($lead['created_at'])) ?></span>
                    </div>
                    <?php if ($lead['status'] === 'assigned'): ?>
                        <div class="actions">
                            <button class="btn-sm btn-success resolve-lead" data-id="<?= $lead['id'] ?>">✅ Успех</button>
                            <button class="btn-sm btn-danger reject-lead" data-id="<?= $lead['id'] ?>">❌ Неудача</button>
                        </div>
                        <div class="reject-comment" style="display: none; margin-top: 10px;">
                            <input type="text" class="comment-input" placeholder="Причина неудачи..." id="comment_<?= $lead['id'] ?>">
                            <button class="btn-sm btn-secondary confirm-reject" data-id="<?= $lead['id'] ?>">Отправить</button>
                        </div>
                    <?php elseif ($lead['status'] === 'rejected'): ?>
                        <div style="margin-top: 8px; color: #721c24; background: #f8d7da; padding: 8px; border-radius: 8px;">
                            <strong>Комментарий:</strong> <?= htmlspecialchars($lead['comment'] ?? '—') ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="color: #888; text-align: center; padding: 20px;">У вас нет взятых лидов.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Переключение вкладок
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            const target = this.dataset.tab;
            document.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
            document.getElementById('tab-' + target).classList.add('active');
        });
    });

    <?php if ($is_manager): ?>
    // Взять лид
    document.querySelectorAll('.take-lead').forEach(btn => {
        btn.addEventListener('click', function() {
            if (!confirm('Взять этот лид в работу?')) return;
            const id = this.dataset.id;
            fetch('api_take_lead.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ lead_id: id })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('Лид взят в работу!');
                    location.reload();
                } else {
                    alert('Ошибка: ' + (data.error || 'неизвестная'));
                }
            })
            .catch(e => alert('Ошибка соединения: ' + e.message));
        });
    });

    // Успех
    document.querySelectorAll('.resolve-lead').forEach(btn => {
        btn.addEventListener('click', function() {
            if (!confirm('Подтвердить успешное подключение?')) return;
            const id = this.dataset.id;
            fetch('api_lead_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ lead_id: id, status: 'converted' })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('✅ Лид отмечен как успешный! Охотник получил бонус.');
                    location.reload();
                } else {
                    alert('Ошибка: ' + (data.error || 'неизвестная'));
                }
            })
            .catch(e => alert('Ошибка соединения: ' + e.message));
        });
    });

    // Показать поле для комментария при неудаче
    document.querySelectorAll('.reject-lead').forEach(btn => {
        btn.addEventListener('click', function() {
            const card = this.closest('.lead-card');
            const commentDiv = card.querySelector('.reject-comment');
            if (commentDiv) {
                commentDiv.style.display = commentDiv.style.display === 'none' ? 'block' : 'none';
            }
        });
    });

    // Подтвердить неудачу с комментарием
    document.querySelectorAll('.confirm-reject').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const comment = document.getElementById('comment_' + id).value.trim();
            if (!comment) {
                alert('Пожалуйста, укажите причину неудачи.');
                return;
            }
            if (!confirm('Отметить лид как неудачный с комментарием?')) return;
            fetch('api_lead_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ lead_id: id, status: 'rejected', comment: comment })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('❌ Лид отмечен как неудачный. Комментарий сохранён.');
                    location.reload();
                } else {
                    alert('Ошибка: ' + (data.error || 'неизвестная'));
                }
            })
            .catch(e => alert('Ошибка соединения: ' + e.message));
        });
    });
    <?php endif; ?>
});
</script>
</body>
</html>