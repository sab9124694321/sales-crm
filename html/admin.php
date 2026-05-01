<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit;
}
require_once 'db.php';

$name = $_SESSION['name'];
$role = $_SESSION['role'];
$message = '';
$error = '';

// Добавление сотрудника
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_employee'])) {
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (tabel_number, full_name, phone, role, manager_id, password) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$_POST['tabel'], $_POST['full_name'], $_POST['phone'], $_POST['role'], $_POST['manager_id'] ?: null, $password]);
    $message = "Сотрудник добавлен";
}

// Применить план ко всем сотрудникам
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_to_all'])) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'employee'");
    $stmt->execute();
    $employees = $stmt->fetchAll();
    
    $success = 0;
    foreach ($employees as $emp) {
        $stmt = $pdo->prepare("DELETE FROM monthly_plans WHERE user_id = ? AND year = ? AND month = ?");
        $stmt->execute([$emp['id'], date('Y'), date('m')]);
        
        $stmt = $pdo->prepare("INSERT INTO monthly_plans (user_id, year, month, plan_calls, plan_answered, plan_meetings, plan_contracts, plan_registrations, plan_smart_cash, plan_pos_systems, plan_inn_leads, plan_teams, plan_turnover) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $emp['id'], date('Y'), date('m'),
            $_POST['plan_calls'], $_POST['plan_answered'], $_POST['plan_meetings'],
            $_POST['plan_contracts'], $_POST['plan_registrations'], $_POST['plan_smart_cash'],
            $_POST['plan_pos_systems'], $_POST['plan_inn_leads'], $_POST['plan_teams'],
            $_POST['plan_turnover']
        ]);
        $success++;
    }
    $message = "План применён к $success сотрудникам";
}

// Добавление/обновление плана для конкретного сотрудника
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

// Удаление сотрудника
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
    $stmt->execute([$_POST['user_id']]);
    $message = "Сотрудник удалён";
}

$employees = $pdo->query("SELECT * FROM users ORDER BY role, full_name")->fetchAll();
$managers = $pdo->query("SELECT id, tabel_number, full_name FROM users WHERE role IN ('admin', 'manager')")->fetchAll();

// Получаем текущие планы для отображения
$plans = [];
$stmt = $pdo->prepare("SELECT user_id, plan_calls, plan_answered, plan_meetings, plan_contracts, plan_registrations, plan_smart_cash, plan_pos_systems, plan_inn_leads, plan_teams, plan_turnover FROM monthly_plans WHERE year = ? AND month = ?");
$stmt->execute([date('Y'), date('m')]);
foreach ($stmt->fetchAll() as $p) {
    $plans[$p['user_id']] = $p;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Администрирование</title>
    <meta charset="utf-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; margin: 0; }
        .header { background: #1a2c3e; color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .header h1 { color: #00a36c; }
        .nav { display: flex; gap: 15px; flex-wrap: wrap; }
        .nav a, .logout { color: white; text-decoration: none; padding: 8px 16px; border-radius: 8px; background: #00a36c; }
        .container { max-width: 1400px; margin: 0 auto; padding: 30px 20px; }
        .card { background: white; border-radius: 12px; padding: 25px; margin-bottom: 30px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #555; }
        input, select { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; box-sizing: border-box; }
        button { background: #00a36c; color: white; border: none; padding: 12px 20px; border-radius: 8px; cursor: pointer; font-size: 14px; }
        button:hover { background: #008a5a; }
        button.danger { background: #dc3545; }
        button.danger:hover { background: #c82333; }
        button.secondary { background: #6c757d; }
        button.secondary:hover { background: #5a6268; }
        .export-import { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; }
        .export-import a { background: #00a36c; color: white; text-decoration: none; padding: 12px 24px; border-radius: 8px; display: inline-block; font-weight: bold; }
        .export-import a:hover { background: #008a5a; }
        .plan-form-all { background: #e8f5e9; padding: 20px; border-radius: 12px; margin-bottom: 30px; border: 1px solid #00a36c; }
        .plan-form-all h3 { margin-bottom: 15px; color: #1a2c3e; }
        .plan-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .plan-grid input { padding: 8px; font-size: 14px; }
        .plan-grid label { font-size: 12px; margin-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; font-size: 13px; }
        th { background: #f8f9fa; position: sticky; top: 0; }
        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 8px; margin-bottom: 20px; }
        .badge { padding: 4px 8px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .badge.admin { background: #d4edda; color: #155724; }
        .badge.manager { background: #fff3cd; color: #856404; }
        .badge.employee { background: #d1ecf1; color: #0c5460; }
        input.small-input { width: 70px; padding: 4px; text-align: center; }
        .table-actions { display: flex; gap: 5px; flex-wrap: wrap; }
        @media (max-width: 768px) { .header { flex-direction: column; text-align: center; gap: 15px; } .nav { justify-content: center; } }
    </style>
</head>
<body>
    <div class="header">
        <h1>Sales CRM</h1>
        <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
            <span>👋 <?= htmlspecialchars($name) ?> (<?= htmlspecialchars($role) ?>)</span>
            <div class="nav">
                <a href="dashboard.php">📊 Дашборд</a>
                <a href="team.php">👥 Команда</a>
                <a href="admin.php">⚙️ Админ</a>
                <a href="region_manager.php">🗺️ Тер. менеджер</a>
            </div>
            <a href="logout.php" class="logout">Выйти</a>
        </div>
    </div>
    <div class="container">
        <?php if ($message): ?>
            <div class="success">✅ <?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <div class="export-import">
            <a href="export_csv.php">📥 Экспорт отчётов (CSV)</a>
            <a href="import_employees.php">📤 Импорт сотрудников (CSV)</a>
        </div>
        
        <div class="plan-form-all">
            <h3>🎯 Применить план ко ВСЕМ сотрудникам</h3>
            <form method="post">
                <div class="plan-grid">
                    <div><label>📞 Звонки (мес)</label><input type="number" name="plan_calls" placeholder="Звонки" value="900"></div>
                    <div><label>✅ Дозвоны (мес)</label><input type="number" name="plan_answered" placeholder="Дозвоны" value="450"></div>
                    <div><label>📅 Встречи (мес)</label><input type="number" name="plan_meetings" placeholder="Встречи" value="90"></div>
                    <div><label>📄 Договоры (мес)</label><input type="number" name="plan_contracts" placeholder="Договоры" value="60"></div>
                    <div><label>📝 Регистрации (мес)</label><input type="number" name="plan_registrations" placeholder="Регистрации" value="90"></div>
                    <div><label>💳 Смарт-кассы (мес)</label><input type="number" name="plan_smart_cash" placeholder="Смарт-кассы" value="10"></div>
                    <div><label>🖥️ ПОС (мес)</label><input type="number" name="plan_pos_systems" placeholder="ПОС" value="1"></div>
                    <div><label>🔗 ИНН чаевые (мес)</label><input type="number" name="plan_inn_leads" placeholder="ИНН" value="60"></div>
                    <div><label>👥 Команды чаевые (мес)</label><input type="number" name="plan_teams" placeholder="Команды" value="5"></div>
                    <div><label>💰 Оборот чаевых (мес)</label><input type="number" name="plan_turnover" placeholder="Оборот" value="220000" step="1000"></div>
                </div>
                <button type="submit" name="apply_to_all" class="secondary">📌 Применить ко всем сотрудникам</button>
            </form>
        </div>
        
        <div class="card">
            <h3>➕ Добавить сотрудника</h3>
            <form method="post" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                <div class="form-group"><label>Табельный номер</label><input type="text" name="tabel" required></div>
                <div class="form-group"><label>ФИО</label><input type="text" name="full_name" required></div>
                <div class="form-group"><label>Телефон</label><input type="text" name="phone" required></div>
                <div class="form-group"><label>Пароль</label><input type="text" name="password" value="123456" required></div>
                <div class="form-group">
                    <label>Роль</label>
                    <select name="role">
                        <option value="employee">Сотрудник</option>
                        <option value="manager">Руководитель</option>
                        <option value="admin">Администратор</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Руководитель</label>
                    <select name="manager_id">
                        <option value="">— Нет —</option>
                        <?php foreach ($managers as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['full_name']) ?> (<?= $m['tabel_number'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: flex; align-items: flex-end;">
                    <button type="submit" name="add_employee">➕ Добавить</button>
                </div>
            </form>
        </div>
        
        <div class="card">
            <h3>📋 Список сотрудников</h3>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Табельный</th>
                            <th>ФИО</th>
                            <th>Роль</th>
                            <th>Руководитель</th>
                            <th colspan="10">Планы на месяц</th>
                            <th>Действия</th>
                         </tr>
                        <tr style="background: #f0f2f5;">
                            <th colspan="4"></th>
                            <th>📞</th><th>✅</th><th>📅</th><th>📄</th><th>📝</th><th>💳</th><th>🖥️</th><th>🔗</th><th>👥</th><th>💰</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($employees as $e): ?>
                        <form method="post">
                            <input type="hidden" name="user_id" value="<?= $e['id'] ?>">
                            <tr>
                                <td><?= htmlspecialchars($e['tabel_number']) ?></td>
                                <td><?= htmlspecialchars($e['full_name']) ?></td>
                                <td>
                                    <span class="badge <?= $e['role'] ?>">
                                        <?= $e['role'] == 'admin' ? 'Администратор' : ($e['role'] == 'manager' ? 'Руководитель' : 'Сотрудник') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    if ($e['manager_id']) {
                                        $mgr = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
                                        $mgr->execute([$e['manager_id']]);
                                        echo htmlspecialchars($mgr->fetchColumn());
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                                <td><input type="number" name="plan_calls" value="<?= $plans[$e['id']]['plan_calls'] ?? 0 ?>" class="small-input"></td>
                                <td><input type="number" name="plan_answered" value="<?= $plans[$e['id']]['plan_answered'] ?? 0 ?>" class="small-input"></td>
                                <td><input type="number" name="plan_meetings" value="<?= $plans[$e['id']]['plan_meetings'] ?? 0 ?>" class="small-input"></td>
                                <td><input type="number" name="plan_contracts" value="<?= $plans[$e['id']]['plan_contracts'] ?? 0 ?>" class="small-input" style="font-weight:bold;"></td>
                                <td><input type="number" name="plan_registrations" value="<?= $plans[$e['id']]['plan_registrations'] ?? 0 ?>" class="small-input"></td>
                                <td><input type="number" name="plan_smart_cash" value="<?= $plans[$e['id']]['plan_smart_cash'] ?? 0 ?>" class="small-input"></td>
                                <td><input type="number" name="plan_pos_systems" value="<?= $plans[$e['id']]['plan_pos_systems'] ?? 0 ?>" class="small-input"></td>
                                <td><input type="number" name="plan_inn_leads" value="<?= $plans[$e['id']]['plan_inn_leads'] ?? 0 ?>" class="small-input"></td>
                                <td><input type="number" name="plan_teams" value="<?= $plans[$e['id']]['plan_teams'] ?? 0 ?>" class="small-input"></td>
                                <td><input type="number" name="plan_turnover" value="<?= $plans[$e['id']]['plan_turnover'] ?? 0 ?>" class="small-input" step="1000"></td>
                                <td> class="table-actions">
                                    <button type="submit" name="set_plan" class="secondary" style="padding: 4px 8px; font-size: 11px;">💾</button>
                                    <?php if ($e['role'] != 'admin'): ?>
                                    <button type="submit" name="delete_user" class="danger" style="padding: 4px 8px; font-size: 11px;" onclick="return confirm('Удалить?')">🗑️</button>
                                    <?php else: ?>
                                    —
                                    <?php endif; ?>
                                 </div>
                              </div>
                           </tr>
                        </form>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
