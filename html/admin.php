<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: dashboard.php');
    exit;
}
require_once 'db.php';

$name = $_SESSION['name'];

// Создаём таблицы если их нет
$pdo->exec("
    CREATE TABLE IF NOT EXISTS plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tabel_number TEXT UNIQUE,
        calls_plan INTEGER DEFAULT 30,
        calls_answered_plan INTEGER DEFAULT 15,
        meetings_plan INTEGER DEFAULT 5,
        contracts_plan INTEGER DEFAULT 2,
        registrations_plan INTEGER DEFAULT 3,
        smart_cash_plan INTEGER DEFAULT 2,
        pos_systems_plan INTEGER DEFAULT 1,
        inn_leads_plan INTEGER DEFAULT 5,
        teams_plan INTEGER DEFAULT 1,
        turnover_plan INTEGER DEFAULT 500000
    )
");

// Добавляем планы для пользователей, у которых их нет
$pdo->exec("
    INSERT OR IGNORE INTO plans (tabel_number)
    SELECT tabel_number FROM users WHERE tabel_number IS NOT NULL AND tabel_number != ''
");

// Получение всех пользователей
$users = $pdo->query("SELECT * FROM users WHERE role != 'admin' ORDER BY id")->fetchAll();

// Получение планов
$plans_by_tabel = [];
$stmt = $pdo->query("SELECT * FROM plans");
while ($plan = $stmt->fetch()) {
    $plans_by_tabel[$plan['tabel_number']] = $plan;
}

// Обработка обновления плана для конкретного пользователя
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_plan'])) {
    $tabel_number = $_POST['tabel_number'];
    $calls_plan = intval($_POST['calls_plan'] ?? 30);
    $calls_answered_plan = intval($_POST['calls_answered_plan'] ?? 15);
    $meetings_plan = intval($_POST['meetings_plan'] ?? 5);
    $contracts_plan = intval($_POST['contracts_plan'] ?? 2);
    $registrations_plan = intval($_POST['registrations_plan'] ?? 3);
    $smart_cash_plan = intval($_POST['smart_cash_plan'] ?? 2);
    $pos_systems_plan = intval($_POST['pos_systems_plan'] ?? 1);
    $inn_leads_plan = intval($_POST['inn_leads_plan'] ?? 5);
    $teams_plan = intval($_POST['teams_plan'] ?? 1);
    $turnover_plan = intval($_POST['turnover_plan'] ?? 500000);
    
    $stmt = $pdo->prepare("
        INSERT INTO plans (tabel_number, calls_plan, calls_answered_plan, meetings_plan, contracts_plan,
        registrations_plan, smart_cash_plan, pos_systems_plan, inn_leads_plan, teams_plan, turnover_plan)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(tabel_number) DO UPDATE SET
        calls_plan = excluded.calls_plan,
        calls_answered_plan = excluded.calls_answered_plan,
        meetings_plan = excluded.meetings_plan,
        contracts_plan = excluded.contracts_plan,
        registrations_plan = excluded.registrations_plan,
        smart_cash_plan = excluded.smart_cash_plan,
        pos_systems_plan = excluded.pos_systems_plan,
        inn_leads_plan = excluded.inn_leads_plan,
        teams_plan = excluded.teams_plan,
        turnover_plan = excluded.turnover_plan
    ");
    $stmt->execute([
        $tabel_number, $calls_plan, $calls_answered_plan, $meetings_plan, $contracts_plan,
        $registrations_plan, $smart_cash_plan, $pos_systems_plan, $inn_leads_plan, $teams_plan, $turnover_plan
    ]);
    header('Location: admin.php?updated=1');
    exit;
}

// Обработка сброса пароля
if (isset($_GET['reset_password'])) {
    $user_id = intval($_GET['reset_password']);
    $new_password = password_hash('123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->execute([$new_password, $user_id]);
    header('Location: admin.php?reset=1');
    exit;
}

// Обработка удаления пользователя
if (isset($_GET['delete_user'])) {
    $user_id = intval($_GET['delete_user']);
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    header('Location: admin.php?deleted=1');
    exit;
}

// Импорт сотрудников из CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file']) && $_FILES['import_file']['error'] == 0) {
    $file = fopen($_FILES['import_file']['tmp_name'], 'r');
    $headers = fgetcsv($file, 1000, ';');
    
    while (($data = fgetcsv($file, 1000, ';')) !== false) {
        if (count($data) >= 3) {
            $tabel_number = trim($data[0]);
            $full_name = trim($data[1]);
            $role = trim($data[2]) == 'manager' ? 'manager' : 'employee';
            $manager_id = null;
            
            if (isset($data[3]) && !empty(trim($data[3]))) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE full_name = ? AND role = 'manager'");
                $stmt->execute([trim($data[3])]);
                $manager = $stmt->fetch();
                if ($manager) $manager_id = $manager['id'];
            }
            
            $password = password_hash('123', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO users (tabel_number, full_name, role, manager_id, password)
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT(tabel_number) DO UPDATE SET
                full_name = excluded.full_name,
                role = excluded.role,
                manager_id = excluded.manager_id
            ");
            $stmt->execute([$tabel_number, $full_name, $role, $manager_id, $password]);
        }
    }
    fclose($file);
    header('Location: admin.php?imported=1');
    exit;
}

// Экспорт отчётов в CSV
if (isset($_GET['export_reports'])) {
    $stmt = $pdo->query("
        SELECT dr.*, u.full_name, u.tabel_number 
        FROM daily_reports dr
        JOIN users u ON dr.user_id = u.id
        ORDER BY dr.report_date DESC
    ");
    $reports = $stmt->fetchAll();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reports_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Дата', 'Табель', 'Сотрудник', 'Звонки', 'Дозвоны', 'Встречи', 'Договоры', 'Регистрации', 'Оборот'], ';');
    
    foreach ($reports as $report) {
        fputcsv($output, [
            $report['report_date'],
            $report['tabel_number'],
            $report['full_name'],
            $report['calls'],
            $report['calls_answered'],
            $report['meetings'],
            $report['contracts'],
            $report['registrations'],
            $report['turnover']
        ], ';');
    }
    fclose($output);
    exit;
}

// Экспорт пользователей в CSV
if (isset($_GET['export_users'])) {
    $users_list = $pdo->query("SELECT * FROM users ORDER BY id")->fetchAll();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Табель', 'ФИО', 'Роль', 'Руководитель'], ';');
    
    foreach ($users_list as $user) {
        $manager_name = '';
        if ($user['manager_id']) {
            $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
            $stmt->execute([$user['manager_id']]);
            $manager = $stmt->fetch();
            $manager_name = $manager ? $manager['full_name'] : '';
        }
        fputcsv($output, [
            $user['tabel_number'],
            $user['full_name'],
            $user['role'],
            $manager_name
        ], ';');
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin - Sales CRM</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; background: #f5f7fb; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .card { background: white; border-radius: 16px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .card h2 { margin-bottom: 15px; font-size: 18px; color: #333; border-left: 4px solid #00a36c; padding-left: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; font-weight: 600; }
        input, select { padding: 8px; border: 1px solid #ddd; border-radius: 6px; width: 100%; }
        button { background: #00a36c; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; }
        button:hover { background: #008a5c; }
        .btn-danger { background: #ef4444; }
        .btn-danger:hover { background: #dc2626; }
        .btn-warning { background: #f59e0b; color: white; padding: 4px 8px; border-radius: 4px; text-decoration: none; font-size: 12px; display: inline-block; }
        .btn-warning:hover { background: #d97706; }
        .btn-delete { background: #ef4444; color: white; padding: 4px 8px; border-radius: 4px; text-decoration: none; font-size: 12px; display: inline-block; }
        .btn-delete:hover { background: #dc2626; }
        .btn-export { background: #3b82f6; }
        .btn-export:hover { background: #2563eb; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 15px; }
        .success-msg { background: #d1fae5; color: #065f46; padding: 10px; border-radius: 8px; margin-bottom: 15px; }
        .info-text { font-size: 12px; color: #666; margin-top: 5px; }
        .plan-form { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; margin-top: 10px; }
        .plan-form input { width: 100%; font-size: 12px; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }
    </style>
</head>
<body>
<div class="container">
    <?php require_once 'navbar.php'; ?>
    
    <?php if (isset($_GET['updated'])): ?>
        <div class="success-msg">✅ План обновлён!</div>
    <?php endif; ?>
    <?php if (isset($_GET['reset'])): ?>
        <div class="success-msg">✅ Пароль сброшен на "123"!</div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
        <div class="success-msg">✅ Пользователь удалён!</div>
    <?php endif; ?>
    <?php if (isset($_GET['imported'])): ?>
        <div class="success-msg">✅ Импорт выполнен!</div>
    <?php endif; ?>
    
    <!-- Импорт и экспорт -->
    <div class="card">
        <h2>📁 Импорт / Экспорт данных</h2>
        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
            <div style="flex: 1;">
                <h3 style="font-size: 14px; margin-bottom: 10px;">📥 Импорт сотрудников (CSV)</h3>
                <form method="post" enctype="multipart/form-data">
                    <input type="file" name="import_file" accept=".csv" required style="margin-bottom: 10px;">
                    <button type="submit">Загрузить</button>
                    <div class="info-text">Формат: табель;ФИО;роль(employee/manager);руководитель(ФИО)</div>
                </form>
            </div>
            <div style="flex: 1;">
                <h3 style="font-size: 14px; margin-bottom: 10px;">📤 Экспорт данных</h3>
                <div style="display: flex; gap: 10px;">
                    <a href="?export_reports=1" class="btn-export" style="background: #3b82f6; color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none;">📊 Экспорт отчётов</a>
                    <a href="?export_users=1" class="btn-export" style="background: #3b82f6; color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none;">👥 Экспорт сотрудников</a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Пользователи и планы -->
    <div class="card">
        <h2>👥 Сотрудники и индивидуальные планы</h2>
        <table>
            <thead>
                <tr><th>Табель</th><th>ФИО</th><th>Роль</th><th>Руководитель</th><th>Планы (зв/доз/встр/дог/рег/обор)</th><th>Действия</th></tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): 
                    $plan = $plans_by_tabel[$user['tabel_number']] ?? null;
                ?>
                <tr>
                    <td><?= htmlspecialchars($user['tabel_number']) ?></td>
                    <td><?= htmlspecialchars($user['full_name']) ?></td>
                    <td><?= $user['role'] == 'manager' ? 'Руководитель' : 'Сотрудник' ?></td>
                    <td>
                        <?php
                        if ($user['manager_id']) {
                            $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
                            $stmt->execute([$user['manager_id']]);
                            $manager = $stmt->fetch();
                            echo htmlspecialchars($manager['full_name'] ?? '-');
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                    <td>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="tabel_number" value="<?= $user['tabel_number'] ?>">
                            <div class="plan-form" style="display: flex; gap: 5px; flex-wrap: wrap;">
                                <input type="number" name="calls_plan" value="<?= $plan['calls_plan'] ?? 30 ?>" style="width: 60px;" placeholder="зв">
                                <input type="number" name="calls_answered_plan" value="<?= $plan['calls_answered_plan'] ?? 15 ?>" style="width: 60px;" placeholder="доз">
                                <input type="number" name="meetings_plan" value="<?= $plan['meetings_plan'] ?? 5 ?>" style="width: 60px;" placeholder="встр">
                                <input type="number" name="contracts_plan" value="<?= $plan['contracts_plan'] ?? 2 ?>" style="width: 60px;" placeholder="дог">
                                <input type="number" name="registrations_plan" value="<?= $plan['registrations_plan'] ?? 3 ?>" style="width: 60px;" placeholder="рег">
                                <input type="number" name="turnover_plan" value="<?= $plan['turnover_plan'] ?? 500000 ?>" style="width: 100px;" placeholder="обор">
                            </div>
                            <button type="submit" name="update_plan" class="btn-sm" style="margin-top: 5px;">💾 Сохранить план</button>
                        </form>
                    </td>
                    <td>
                        <a href="?reset_password=<?= $user['id'] ?>" class="btn-warning" onclick="return confirm('Сбросить пароль?')">🔄 Сброс пароля</a>
                        <a href="?delete_user=<?= $user['id'] ?>" class="btn-delete" onclick="return confirm('Удалить?')">🗑️ Удалить</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>

<!-- Массовое обновление планов для всех сотрудников -->
<div class="card">
    <h2>⚙️ Массовое обновление планов для всех сотрудников</h2>
    <form method="post" action="?mass_update=1">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 15px;">
            <div>
                <label style="font-size: 12px;">📞 Звонки</label>
                <input type="number" name="calls_plan" value="30" style="width: 100%;">
            </div>
            <div>
                <label style="font-size: 12px;">✅ Дозвоны</label>
                <input type="number" name="calls_answered_plan" value="15" style="width: 100%;">
            </div>
            <div>
                <label style="font-size: 12px;">🤝 Встречи</label>
                <input type="number" name="meetings_plan" value="5" style="width: 100%;">
            </div>
            <div>
                <label style="font-size: 12px;">📄 Договоры</label>
                <input type="number" name="contracts_plan" value="2" style="width: 100%;">
            </div>
            <div>
                <label style="font-size: 12px;">📝 Регистрации</label>
                <input type="number" name="registrations_plan" value="3" style="width: 100%;">
            </div>
            <div>
                <label style="font-size: 12px;">🏪 smart-кассы</label>
                <input type="number" name="smart_cash_plan" value="2" style="width: 100%;">
            </div>
            <div>
                <label style="font-size: 12px;">💡 инн по чаевым</label>
                <input type="number" name="inn_leads_plan" value="5" style="width: 100%;">
            </div>
            <div>
                <label style="font-size: 12px;">👥 новые команды по чаевым</label>
                <input type="number" name="teams_plan" value="1" style="width: 100%;">
            </div>
            <div>
                <label style="font-size: 12px;">💰 новый оборот по чаевым</label>
                <input type="number" name="turnover_plan" value="500000" style="width: 100%;">
            </div>
        </div>
        <button type="submit" name="mass_update" style="background: #f59e0b; width: 100%;">📊 Применить ко всем сотрудникам</button>
    </form>
</div>
