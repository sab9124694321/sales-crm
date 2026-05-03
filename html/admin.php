<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: dashboard.php');
    exit;
}
require_once 'db.php';

$name = $_SESSION['name'];
$message = '';

// Обработка массового обновления планов
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mass_update'])) {
    // Создаём таблицу если её нет
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS plans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tabel_number TEXT UNIQUE,
            calls_plan INTEGER DEFAULT 350,
            calls_answered_plan INTEGER DEFAULT 245,
            meetings_plan INTEGER DEFAULT 35,
            contracts_plan INTEGER DEFAULT 21,
            registrations_plan INTEGER DEFAULT 15,
            smart_cash_plan INTEGER DEFAULT 10,
            pos_systems_plan INTEGER DEFAULT 5,
            inn_leads_plan INTEGER DEFAULT 5,
            teams_plan INTEGER DEFAULT 3,
            turnover_plan INTEGER DEFAULT 1500000
        )
    ");
    
    $stmt = $pdo->prepare("UPDATE plans SET 
        calls_plan = ?, calls_answered_plan = ?, meetings_plan = ?, 
        contracts_plan = ?, registrations_plan = ?, smart_cash_plan = ?,
        pos_systems_plan = ?, inn_leads_plan = ?, teams_plan = ?, turnover_plan = ?
    ");
    $stmt->execute([
        (int)$_POST['calls_plan'], (int)$_POST['calls_answered_plan'], (int)$_POST['meetings_plan'],
        (int)$_POST['contracts_plan'], (int)$_POST['registrations_plan'], (int)$_POST['smart_cash_plan'],
        (int)$_POST['pos_systems_plan'], (int)$_POST['inn_leads_plan'], (int)$_POST['teams_plan'],
        (int)$_POST['turnover_plan']
    ]);
    $message = "✅ Массовое обновление планов выполнено";
}

// Обработка сброса пароля
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $user_id = (int)$_POST['user_id'];
    if ($user_id > 0) {
        $new_password = password_hash('123456', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$new_password, $user_id]);
        $message = "✅ Пароль сброшен на 123456";
    }
}

// Получаем статистику
$total_users = $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'admin'")->fetchColumn();
$users_without_manager = $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'admin' AND (manager_id IS NULL OR manager_id = 0)")->fetchColumn();

// Получаем список сотрудников для сброса пароля (только ФИО и ID)
$users = $pdo->query("SELECT id, full_name FROM users WHERE role != 'admin' ORDER BY full_name")->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Админ-панель - Sales CRM</title>
    <meta charset="utf-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; background: #f5f7fb; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { color: #00a36c; margin-bottom: 20px; }
        .card { background: white; border-radius: 16px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .card h2 { margin-bottom: 15px; font-size: 18px; border-left: 3px solid #00a36c; padding-left: 12px; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        button, .btn { background: #00a36c; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; }
        .btn-secondary { background: #6c757d; }
        .btn-warning { background: #f59e0b; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 15px; }
        .form-group label { font-size: 12px; color: #666; display: block; margin-bottom: 4px; }
        .form-group input { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 6px; }
        .stats-bar { display: flex; gap: 20px; margin-bottom: 30px; flex-wrap: wrap; }
        .stat-card { background: white; border-radius: 16px; padding: 20px; flex: 1; min-width: 150px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center; }
        .stat-card .number { font-size: 32px; font-weight: bold; color: #00a36c; }
        .stat-card .label { font-size: 14px; color: #666; margin-top: 8px; }
        select { padding: 8px; border: 1px solid #ddd; border-radius: 6px; min-width: 200px; }
        hr { margin: 20px 0; border-color: #eee; }
        .inline-form { display: inline-flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    </style>
</head>
<body>
<div class="container">
    <?php require_once 'navbar.php'; ?>
    
    <h1>⚙️ Админ-панель</h1>
    
    <?php if ($message): ?>
        <div class="success"><?= htmlspecialchars($message) ?></div>
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
    </div>
    
    <!-- КНОПКА УПРАВЛЕНИЯ СОТРУДНИКАМИ -->
    <div class="card" style="background: linear-gradient(135deg, #00a36c 0%, #008a5c 100%); text-align: center;">
        <h2 style="color: white; border-left-color: white;">👥 Управление сотрудниками</h2>
        <p style="color: rgba(255,255,255,0.9); margin-bottom: 15px;">Назначение руководителей, удаление сотрудников</p>
        <a href="users_management.php" style="background: white; color: #00a36c; padding: 12px 30px; text-decoration: none; border-radius: 10px; font-weight: bold; display: inline-block;">👔 Перейти к управлению →</a>
    </div>
    
    <!-- Карточка: Сброс пароля (оставляем только эту кнопку) -->
    <div class="card">
        <h2>🔑 Сброс пароля сотрудника</h2>
        <form method="post" class="inline-form">
            <select name="user_id" required>
                <option value="">— выберите сотрудника —</option>
                <?php foreach ($users as $user): ?>
                <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="reset_password" class="btn-warning" style="background: #f59e0b;">🔑 Сбросить пароль на 123456</button>
        </form>
        <p style="font-size: 12px; color: #666; margin-top: 10px;">Новый пароль: <strong>123456</strong>. Сотрудник сможет сменить его после первого входа.</p>
    </div>
    
    <!-- Карточка: Импорт сотрудников (CSV) -->
    <div class="card">
        <h2>📥 Импорт сотрудников (CSV)</h2>
        <form method="post" action="import_employees.php" enctype="multipart/form-data">
            <input type="file" name="csv_file" accept=".csv" required>
            <button type="submit">📤 Загрузить и импортировать</button>
        </form>
        <p style="font-size: 12px; color: #666; margin-top: 10px;">Формат: tabel_number,full_name,role,email,password,manager_id</p>
        <p style="font-size: 12px; color: #999; margin-top: 5px;">role: employee или manager | manager_id: ID руководителя (можно оставить пустым)</p>
    </div>
    
    <!-- Карточка: Управление ИИ-наставником (чисто, без лишнего) -->
    <div class="card" style="background: #fef3c7;">
        <h2>🎮 ИИ-наставник</h2>
        <a href="refresh_all_advices.php" class="btn-warning" style="background: #f59e0b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 8px; display: inline-block;">🔄 Обновить советы для всех</a>
        <a href="all_advices.php" style="background: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 8px; display: inline-block; margin-left: 10px;">📋 Все советы ИИ</a>
        <p style="font-size: 12px; color: #666; margin-top: 10px;">Очистка кеша и обновление пула советов. При следующем входе каждый получит новый персональный совет.</p>
    </div>
    
    <!-- Карточка: Экспорт данных -->
    <div class="card">
        <h2>📤 Экспорт данных</h2>
        <a href="export_csv.php"><button type="button">📊 Экспорт отчётов (CSV)</button></a>
        <a href="export_credentials.php"><button type="button">🔐 Экспорт сотрудников с паролями</button></a>
    </div>
    
    <!-- Карточка: Массовое обновление планов -->
    <div class="card">
        <h2>⚙️ Массовое обновление планов</h2>
        <form method="post">
            <div class="form-grid">
                <div class="form-group"><label>📞 Звонки</label><input type="number" name="calls_plan" value="350"></div>
                <div class="form-group"><label>✅ Дозвоны</label><input type="number" name="calls_answered_plan" value="245"></div>
                <div class="form-group"><label>🤝 Встречи</label><input type="number" name="meetings_plan" value="35"></div>
                <div class="form-group"><label>📄 Договоры</label><input type="number" name="contracts_plan" value="21"></div>
                <div class="form-group"><label>📝 Регистрации</label><input type="number" name="registrations_plan" value="15"></div>
                <div class="form-group"><label>💳 smart-кассы</label><input type="number" name="smart_cash_plan" value="10"></div>
                <div class="form-group"><label>🖥️ POS-системы</label><input type="number" name="pos_systems_plan" value="5"></div>
                <div class="form-group"><label>📊 инн по чаевым</label><input type="number" name="inn_leads_plan" value="5"></div>
                <div class="form-group"><label>👥 новые команды по чаевым</label><input type="number" name="teams_plan" value="3"></div>
                <div class="form-group"><label>💰 новый оборот по чаевым</label><input type="number" name="turnover_plan" value="1500000" step="1000"></div>
            </div>
            <button type="submit" name="mass_update" value="1">📊 Применить ко всем сотрудникам</button>
        </form>
        <p style="font-size: 12px; color: #666; margin-top: 10px;">Устанавливает указанные планы для ВСЕХ сотрудников в таблице plans</p>
    </div>
</div>
</body>
</html>
