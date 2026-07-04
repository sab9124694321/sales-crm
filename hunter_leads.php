<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'head', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}
require_once 'db.php';

$leads = $pdo->query("
    SELECT hl.*, h.full_name as hunter_name, h.phone as hunter_phone 
    FROM hunter_leads hl 
    JOIN hunters h ON hl.hunter_id = h.id 
    ORDER BY hl.created_at DESC
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_lead'])) {
    $lead_id = intval($_POST['lead_id']);
    $status = $_POST['status'];
    $bonus = 0;
    if ($status === 'converted') $bonus = 50;
    $pdo->prepare("UPDATE hunter_leads SET status = ?, updated_at = CURRENT_TIMESTAMP, converted_bonus = ? WHERE id = ?")->execute([$status, $bonus, $lead_id]);
    if ($bonus > 0) {
        $stmt = $pdo->prepare("SELECT hunter_id FROM hunter_leads WHERE id = ?");
        $stmt->execute([$lead_id]);
        $hunter_id = $stmt->fetchColumn();
        if ($hunter_id) {
            $pdo->prepare("UPDATE hunters SET points = points + ?, total_bonus = total_bonus + ? WHERE id = ?")->execute([$bonus, $bonus, $hunter_id]);
        }
    }
    header('Location: hunter_leads.php');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Лид-менеджер — Терминальная охота</title>
    <style>
        body { font-family: system-ui; background: #f0f2f5; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: #fff; border-radius: 16px; padding: 20px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; }
        .status-select { padding: 4px 8px; border-radius: 8px; border: 1px solid #ccc; }
        .btn { background: #1a73e8; color: #fff; border: none; padding: 6px 12px; border-radius: 8px; cursor: pointer; }
        .photo-link { color: #1a73e8; text-decoration: none; }
        .nav { display: flex; gap: 20px; margin-bottom: 20px; }
        .nav a { text-decoration: none; color: #1a73e8; font-weight: 600; }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="dashboard.php">📊 Дашборд</a>
        <a href="hunter_leads.php">📋 Лиды охотников</a>
        <a href="logout.php">🚪 Выйти</a>
    </div>
    <h2>📋 Лиды от охотников</h2>
    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Охотник</th>
                    <th>Клиент</th>
                    <th>Телефон</th>
                    <th>ИНН</th>
                    <th>Адрес</th>
                    <th>Фото</th>
                    <th>Статус</th>
                    <th>Дата</th>
                    <th>Действие</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leads as $lead): ?>
                <tr>
                    <td><?= $lead['id'] ?></td>
                    <td><?= htmlspecialchars($lead['hunter_name']) ?></td>
                    <td><?= htmlspecialchars($lead['client_name']) ?></td>
                    <td><?= htmlspecialchars($lead['client_phone']) ?></td>
                    <td><?= htmlspecialchars($lead['inn']) ?></td>
                    <td><?= htmlspecialchars($lead['address']) ?></td>
                    <td>
                        <?php if ($lead['photo_path']): ?>
                            <a href="/<?= $lead['photo_path'] ?>" target="_blank" class="photo-link">📷</a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
                            <select name="status" class="status-select" onchange="this.form.submit()">
                                <option value="new" <?= $lead['status'] === 'new' ? 'selected' : '' ?>>Новый</option>
                                <option value="assigned" <?= $lead['status'] === 'assigned' ? 'selected' : '' ?>>В работе</option>
                                <option value="converted" <?= $lead['status'] === 'converted' ? 'selected' : '' ?>>Подключён</option>
                                <option value="rejected" <?= $lead['status'] === 'rejected' ? 'selected' : '' ?>>Отклонён</option>
                            </select>
                            <input type="hidden" name="update_lead" value="1">
                        </form>
                    </td>
                    <td><?= date('d.m.Y', strtotime($lead['created_at'])) ?></td>
                    <td>
                        <?php if ($lead['status'] === 'converted'): ?>
                            <span style="color: #28a745;">+50 бонусов</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
