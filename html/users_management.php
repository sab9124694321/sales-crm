<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: dashboard.php');
    exit;
}
require_once 'db.php';

$name = $_SESSION['name'];
$message = '';
$error = '';

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);
    
    // ДОБАВЛЕНИЕ НОВОГО СОТРУДНИКА
    if ($action === 'add_user') {
        $tabel_number = trim($_POST['tabel_number'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $role = $_POST['role'] ?? 'employee';
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '123456';
        $manager_id = $_POST['manager_id'] ?? null;
        
        if (empty($tabel_number) || empty($full_name)) {
            $error = "❌ Заполните обязательные поля: табельный номер и ФИО";
        } else {
            // Проверяем, не существует ли уже такой табельный номер
            $check = $pdo->prepare("SELECT id FROM users WHERE tabel_number = ?");
            $check->execute([$tabel_number]);
            if ($check->fetch()) {
                $error = "❌ Сотрудник с табельным номером '$tabel_number' уже существует";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO users (tabel_number, full_name, role, email, password, manager_id)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$tabel_number, $full_name, $role, $email, $hashed_password, $manager_id ?: null]);
                
                // Создаём планы для нового сотрудника
                $stmt = $pdo->prepare("
                    INSERT INTO plans (tabel_number, calls_plan, calls_answered_plan, meetings_plan, 
                        contracts_plan, registrations_plan, smart_cash_plan, pos_systems_plan, 
                        inn_leads_plan, teams_plan, turnover_plan)
                    VALUES (?, 350, 245, 35, 21, 15, 10, 5, 5, 3, 1500000)
                ");
                $stmt->execute([$tabel_number]);
                
                $message = "✅ Сотрудник '$full_name' добавлен! Пароль: $password";
            }
        }
    }
    // Назначение руководителя
    elseif ($action === 'update_manager' && $user_id > 0) {
        $manager_id = $_POST['manager_id'] ?? null;
        if ($manager_id && $manager_id > 0) {
            $stmt = $pdo->prepare("UPDATE users SET manager_id = ? WHERE id = ?");
            $stmt->execute([$manager_id, $user_id]);
            $message = "✅ Руководитель назначен";
        } else {
            $stmt = $pdo->prepare("UPDATE users SET manager_id = NULL WHERE id = ?");
            $stmt->execute([$user_id]);
            $message = "✅ Руководитель снят";
        }
    }
    // Удаление сотрудника
    elseif ($action === 'delete_user' && $user_id > 0) {
        $check = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $check->execute([$user_id]);
        $user = $check->fetch();
        if ($user && $user['role'] != 'admin') {
            // Получаем табельный номер
            $stmt = $pdo->prepare("SELECT tabel_number FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $tabel = $stmt->fetchColumn();
            if ($tabel) {
                // Удаляем планы сотрудника
                $stmt = $pdo->prepare("DELETE FROM plans WHERE tabel_number = ?");
                $stmt->execute([$tabel]);
            }
            // Удаляем сотрудника
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $message = "✅ Сотрудник удалён";
        } else {
            $error = "❌ Нельзя удалить администратора";
        }
    }
    // Сброс пароля
    elseif ($action === 'reset_password' && $user_id > 0) {
        $new_password = password_hash('123456', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$new_password, $user_id]);
        $message = "✅ Пароль сброшен на 123456";
    }
}

// Получаем всех сотрудников (кроме админа)
$users = $pdo->query("SELECT * FROM users WHERE role != 'admin' ORDER BY full_name")->fetchAll();

// Получаем всех возможных руководителей
$managers = $pdo->query("SELECT id, full_name FROM users WHERE role IN ('admin', 'manager') ORDER BY full_name")->fetchAll();

// Получаем статистику
$total_users = count($users);
$users_without_manager = $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'admin' AND (manager_id IS NULL OR manager_id = 0)")->fetchColumn();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Управление сотрудниками - Sales CRM</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; background: #f5f7fb; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        
        h1 { color: #00a36c; margin-bottom: 10px; }
        .subtitle { color: #666; margin-bottom: 20px; }
        
        .stats-bar { display: flex; gap: 20px; margin-bottom: 30px; flex-wrap: wrap; }
        .stat-card { background: white; border-radius: 16px; padding: 20px; flex: 1; min-width: 180px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center; }
        .stat-card .number { font-size: 32px; font-weight: bold; color: #00a36c; }
        .stat-card .label { font-size: 14px; color: #666; margin-top: 8px; }
        
        .message { background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        
        .card { background: white; border-radius: 16px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .card h2 { margin-bottom: 15px; font-size: 18px; border-left: 3px solid #00a36c; padding-left: 12px; }
        
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .form-group label { display: block; font-size: 12px; color: #666; margin-bottom: 5px; }
        .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #00a36c; }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 10px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; font-weight: 600; }
        
        select { padding: 8px; border: 1px solid #ddd; border-radius: 6px; min-width: 150px; }
        button { border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-size: 14px; }
        .btn-success { background: #00a36c; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        
        .actions { white-space: nowrap; }
        .actions form { display: inline-block; margin-right: 5px; }
        
        .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; }
        .badge-manager { background: #d4edda; color: #155724; }
        .badge-employee { background: #d1d5db; color: #374151; }
        
        .back-link { display: inline-block; margin-top: 20px; color: #00a36c; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        
        hr { margin: 20px 0; border-color: #eee; }
        
        @media (max-width: 768px) { th, td { font-size: 12px; padding: 8px; } .btn-sm { padding: 4px 8px; } }
    </style>
</head>
<body>
<div class="container">
    <?php require_once 'navbar.php'; ?>
    
    <h1>👥 Управление сотрудниками</h1>
    <div class="subtitle">Добавление, назначение руководителей, удаление и сброс паролей</div>
    
    <?php if ($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <!-- Статистика -->
    <div class="stats-bar">
        <div class="stat-card">
            <div class="number"><?= $total_users ?></div>
            <div class="label">👥 Всего сотрудников</div>
        </div>
        <div class="stat-card">
            <div class="number"><?= $users_without_manager ?></div>
            <div class="label">👤 Без руководителя</div>
        </div>
        <div class="stat-card">
            <div class="number"><?= count($managers) ?></div>
            <div class="label">👔 Руководителей</div>
        </div>
    </div>
    
    <!-- ФОРМА ДОБАВЛЕНИЯ НОВОГО СОТРУДНИКА -->
    <div class="card" style="background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);">
        <h2>➕ Добавить нового сотрудника</h2>
        <form method="post">
            <input type="hidden" name="action" value="add_user">
            <div class="form-grid">
                <div class="form-group">
                    <label>📛 Табельный номер *</label>
                    <input type="text" name="tabel_number" required placeholder="например: 21001">
                </div>
                <div class="form-group">
                    <label>👤 ФИО *</label>
                    <input type="text" name="full_name" required placeholder="Иванов Иван Иванович">
                </div>
                <div class="form-group">
                    <label>📧 Email</label>
                    <input type="email" name="email" placeholder="ivanov@example.com">
                </div>
                <div class="form-group">
                    <label>💼 Роль</label>
                    <select name="role">
                        <option value="employee">👤 Сотрудник</option>
                        <option value="manager">👔 Менеджер</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>🔑 Пароль</label>
                    <input type="text" name="password" value="123456" placeholder="оставьте 123456">
                    <small style="font-size: 10px; color: #666;">По умолчанию: 123456</small>
                </div>
                <div class="form-group">
                    <label>👔 Руководитель</label>
                    <select name="manager_id">
                        <option value="">— без руководителя —</option>
                        <?php foreach ($managers as $m): ?>
                        <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn-success">➕ Добавить сотрудника</button>
        </form>
    </div>
    
    <!-- Таблица сотрудников -->
    <div class="card">
        <h2>📋 Список сотрудников</h2>
        <?php if (empty($users)): ?>
            <p style="color: #666;">Нет сотрудников. Добавьте через форму выше или импорт CSV.</p>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Табельный номер</th>
                            <th>ФИО</th>
                            <th>Роль</th>
                            <th>Текущий руководитель</th>
                            <th>Сменить руководителя</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): 
                            $current_manager_name = '';
                            foreach ($managers as $m) {
                                if ($m['id'] == $user['manager_id']) {
                                    $current_manager_name = $m['full_name'];
                                    break;
                                }
                            }
                        ?>
                        <tr>
                            <td><?= $user['id'] ?></td>
                            <td><?= htmlspecialchars($user['tabel_number']) ?></td>
                            <td><strong><?= htmlspecialchars($user['full_name']) ?></strong></td>
                            <td>
                                <span class="badge <?= $user['role'] == 'manager' ? 'badge-manager' : 'badge-employee' ?>">
                                    <?= $user['role'] == 'manager' ? '👔 Менеджер' : '👤 Сотрудник' ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($current_manager_name): ?>
                                    <span style="color: #00a36c;"><?= htmlspecialchars($current_manager_name) ?></span>
                                <?php else: ?>
                                    <span style="color: #999;">— не назначен —</span>
                                <?php endif; ?>
                            </td>
                            <form method="post">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <input type="hidden" name="action" value="update_manager">
                                <td>
                                    <select name="manager_id" style="min-width: 160px;">
                                        <option value="">— без руководителя —</option>
                                        <?php foreach ($managers as $m): ?>
                                        <option value="<?= $m['id'] ?>" <?= $user['manager_id'] == $m['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($m['full_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="actions">
                                    <button type="submit" class="btn-success btn-sm">👔 Назначить</button>
                                    <button type="submit" formaction="?action=delete_user" name="action" value="delete_user" class="btn-danger btn-sm" onclick="return confirm('Удалить сотрудника <?= addslashes($user['full_name']) ?>?')">🗑️</button>
                                    <button type="submit" formaction="?action=reset_password" name="action" value="reset_password" class="btn-warning btn-sm" onclick="return confirm('Сбросить пароль для <?= addslashes($user['full_name']) ?> на 123456?')">🔑 Сброс</button>
                                </td>
                            </form>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    
    <a href="admin.php" class="back-link">← Вернуться в админ-панель</a>
</div>
</body>
</html>
