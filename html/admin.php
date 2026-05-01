<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit;
}
require_once 'db.php';

$message = '';
$error = '';

// Добавление сотрудника
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_employee'])) {
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (tabel_number, full_name, phone, role, manager_id, password) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$_POST['tabel'], $_POST['full_name'], $_POST['phone'], $_POST['role'], $_POST['manager_id'] ?: null, $password]);
    $message = "Сотрудник добавлен";
}

// Добавление/обновление плана
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_plan'])) {
    $stmt = $pdo->prepare("DELETE FROM monthly_plans WHERE user_id = ? AND year = ? AND month = ?");
    $stmt->execute([$_POST['user_id'], date('Y'), date('m')]);
    $stmt = $pdo->prepare("INSERT INTO monthly_plans (user_id, year, month, plan_calls, plan_answered, plan_meetings, plan_contracts, plan_registrations, plan_smart_cash, plan_pos_systems, plan_inn_leads, plan_teams, plan_turnover) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_POST['user_id'], date('Y'), date('m'),
        $_POST['plan_calls'], $_POST['plan_answered'], $_POST['plan_meetings'],
        $_POST['plan_contracts'], $_POST['plan_registrations'], $_POST['plan_smart_cash'],
        $_POST['plan_pos_systems'], $_POST['plan_inn_leads'], $_POST['plan_teams'],
        $_POST['plan_turnover']
    ]);
    $message = "План сохранён";
}

$employees = $pdo->query("SELECT * FROM users")->fetchAll();
$managers = $pdo->query("SELECT id, tabel_number, full_name FROM users WHERE role IN ('admin', 'manager')")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Администрирование</title>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; background: #f0f2f5; margin: 0; }
        .header { background: #1a2c3e; color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .header h1 { color: #00a36c; }
        .nav a, .logout { color: white; text-decoration: none; padding: 8px 16px; border-radius: 8px; background: #00a36c; margin: 5px; display: inline-block; }
        .container { max-width: 1200px; margin: 0 auto; padding: 30px 20px; }
        .card { background: white; border-radius: 12px; padding: 25px; margin-bottom: 30px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; box-sizing: border-box; }
        button { background: #00a36c; color: white; border: none; padding: 12px 20px; border-radius: 8px; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; }
        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 8px; margin-bottom: 20px; }
        .plan-form { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin-top: 15px; }
        .plan-form input { width: 100%; padding: 6px; font-size: 12px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Sales CRM</h1>
        <div>
            <a href="dashboard.php" class="nav">📊 Дашборд</a>
            <a href="team.php" class="nav">👥 Команда</a>
            <a href="admin.php" class="nav">⚙️ Админ</a>
            <a href="import_employees.php" class="nav">📥 Импорт</a>
            <a href="logout.php" class="logout">Выйти</a>
        </div>
    </div>
    <div class="container">
        <?php if ($message): ?>
            <div class="success">✅ <?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <div class="card">
            <h3>📋 Сотрудники и планы</h3>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Табельный</th><th>ФИО</th><th>Роль</th><th>Руководитель</th>
                            <th>📞 Звонки</th><th>✅ Дозвоны</th><th>📅 Встречи</th>
                            <th>📄 Договоры</th><th>📝 Регистрации</th><th>💳 Смарт-кассы</th>
                            <th>🖥️ ПОС</th><th>🔗 ИНН</th><th>👥 Команды</th><th>💰 Оборот</th><th>Действие</th>
                         </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($employees as $e): 
                        $stmt = $pdo->prepare("SELECT * FROM monthly_plans WHERE user_id = ? AND year = ? AND month = ?");
                        $stmt->execute([$e['id'], date('Y'), date('m')]);
                        $plan = $stmt->fetch();
                    ?>
                        <tr>
                            <form method="post">
                                <input type="hidden" name="user_id" value="<?= $e['id'] ?>">
                                <td><?= htmlspecialchars($e['tabel_number']) ?></td>
                                <td><?= htmlspecialchars($e['full_name']) ?></td>
                                <td><?= htmlspecialchars($e['role']) ?></td>
                                <td>
                                    <?php 
                                    if ($e['manager_id']) {
                                        $mgr = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
                                        $mgr->execute([$e['manager_id']]);
                                        echo htmlspecialchars($mgr->fetchColumn());
                                    } else { echo '—'; }
                                    ?>
                                </td>
                                <td><input type="number" name="plan_calls" value="<?= $plan['plan_calls'] ?? 0 ?>" style="width:70px"></td>
                                <td><input type="number" name="plan_answered" value="<?= $plan['plan_answered'] ?? 0 ?>" style="width:70px"></td>
                                <td><input type="number" name="plan_meetings" value="<?= $plan['plan_meetings'] ?? 0 ?>" style="width:70px"></td>
                                <td><input type="number" name="plan_contracts" value="<?= $plan['plan_contracts'] ?? 0 ?>" style="width:70px"></td>
                                <td><input type="number" name="plan_registrations" value="<?= $plan['plan_registrations'] ?? 0 ?>" style="width:70px"></td>
                                <td><input type="number" name="plan_smart_cash" value="<?= $plan['plan_smart_cash'] ?? 0 ?>" style="width:70px"></td>
                                <td><input type="number" name="plan_pos_systems" value="<?= $plan['plan_pos_systems'] ?? 0 ?>" style="width:70px"></td>
                                <td><input type="number" name="plan_inn_leads" value="<?= $plan['plan_inn_leads'] ?? 0 ?>" style="width:70px"></td>
                                <td><input type="number" name="plan_teams" value="<?= $plan['plan_teams'] ?? 0 ?>" style="width:70px"></td>
                                <td><input type="number" name="plan_turnover" value="<?= $plan['plan_turnover'] ?? 0 ?>" style="width:90px" step="1000"></td>
                                <td><button type="submit" name="set_plan">Сохранить</button></td>
                            </form>
                         </tr>
                    <?php endforeach; ?>
                    </tbody>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
