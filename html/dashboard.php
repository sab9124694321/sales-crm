<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once 'db.php';

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$name = $_SESSION['name'];

// Получаем данные сотрудника
$stmt = $pdo->prepare("SELECT tabel_number, full_name, manager_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Планы по умолчанию
$default_plans = [
    'calls_plan' => 30,
    'calls_answered_plan' => 15,
    'meetings_plan' => 5,
    'contracts_plan' => 2,
    'registrations_plan' => 3,
    'smart_cash_plan' => 2,
    'pos_systems_plan' => 1,
    'inn_leads_plan' => 5,
    'teams_plan' => 1,
    'turnover_plan' => 500000
];

// Пытаемся получить планы из БД
try {
    $stmt = $pdo->prepare("SELECT * FROM plans WHERE tabel_number = ?");
    $stmt->execute([$user['tabel_number']]);
    $plans = $stmt->fetch();
    if (!$plans) {
        $plans = $default_plans;
    }
} catch (PDOException $e) {
    $plans = $default_plans;
}

// Обработка редактирования отчёта
$editReport = null;
$editDate = null;
if (isset($_GET['edit'])) {
    $editDate = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM daily_reports WHERE user_id = ? AND report_date = ?");
    $stmt->execute([$user_id, $editDate]);
    $editReport = $stmt->fetch();
}

// Обработка сохранения (INSERT или UPDATE)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportDate = $_POST['report_date'] ?? date('Y-m-d');
    
    $calls = !empty($_POST['calls']) ? intval($_POST['calls']) : 0;
    $calls_answered = !empty($_POST['calls_answered']) ? intval($_POST['calls_answered']) : 0;
    $meetings = !empty($_POST['meetings']) ? intval($_POST['meetings']) : 0;
    $contracts = !empty($_POST['contracts']) ? intval($_POST['contracts']) : 0;
    $registrations = !empty($_POST['registrations']) ? intval($_POST['registrations']) : 0;
    $smart_cash = !empty($_POST['smart_cash']) ? intval($_POST['smart_cash']) : 0;
    $pos_systems = !empty($_POST['pos_systems']) ? intval($_POST['pos_systems']) : 0;
    $inn_leads = !empty($_POST['inn_leads']) ? intval($_POST['inn_leads']) : 0;
    $teams = !empty($_POST['teams']) ? intval($_POST['teams']) : 0;
    $turnover = !empty($_POST['turnover']) ? floatval(str_replace(',', '.', $_POST['turnover'])) : 0;
    
    $stmt = $pdo->prepare("SELECT id FROM daily_reports WHERE user_id = ? AND report_date = ?");
    $stmt->execute([$user_id, $reportDate]);
    $exists = $stmt->fetch();
    
    if ($exists) {
        $stmt = $pdo->prepare("
            UPDATE daily_reports SET 
                calls = ?, calls_answered = ?, meetings = ?, contracts = ?, registrations = ?,
                smart_cash = ?, pos_systems = ?, inn_leads = ?, teams = ?, turnover = ?
            WHERE user_id = ? AND report_date = ?
        ");
        $stmt->execute([
            $calls, $calls_answered, $meetings, $contracts, $registrations,
            $smart_cash, $pos_systems, $inn_leads, $teams, $turnover,
            $user_id, $reportDate
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO daily_reports 
            (user_id, report_date, calls, calls_answered, meetings, contracts, registrations, 
             smart_cash, pos_systems, inn_leads, teams, turnover)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_id, $reportDate,
            $calls, $calls_answered, $meetings, $contracts, $registrations,
            $smart_cash, $pos_systems, $inn_leads, $teams, $turnover
        ]);
    }
    header('Location: dashboard.php?saved=1');
    exit;
}

// Удаление отчёта
if (isset($_GET['delete'])) {
    $deleteDate = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM daily_reports WHERE user_id = ? AND report_date = ?");
    $stmt->execute([$user_id, $deleteDate]);
    header('Location: dashboard.php?deleted=1');
    exit;
}

// Получаем отчёты за последние 30 дней
$stmt = $pdo->prepare("
    SELECT * FROM daily_reports 
    WHERE user_id = ? 
    ORDER BY report_date DESC 
    LIMIT 30
");
$stmt->execute([$user_id]);
$reports_list = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Дашборд - Sales CRM</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; background: #f5f7fb; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .card { background: white; border-radius: 16px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .card h3 { margin-bottom: 15px; color: #333; font-size: 18px; }
        .metric { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; }
        .metric-value { font-weight: bold; color: #00a36c; }
        .progress-bar { background: #e5e7eb; border-radius: 10px; height: 8px; margin-top: 8px; overflow: hidden; }
        .progress-fill { background: #00a36c; height: 100%; border-radius: 10px; transition: width 0.3s; }
        form { display: grid; gap: 15px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        input, select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; }
        button { background: #00a36c; color: white; border: none; padding: 12px; border-radius: 8px; cursor: pointer; font-size: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; }
        .edit-btn, .delete-btn { padding: 4px 12px; border-radius: 6px; text-decoration: none; font-size: 12px; }
        .edit-btn { background: #3b82f6; color: white; }
        .delete-btn { background: #ef4444; color: white; }
    </style>
</head>
<body>
<?php require_once "navbar.php"; ?>
<div class="container">
    
    <?php require_once 'gamification_widget.php'; ?>
    
    <div class="grid">
        <div class="card">
            <h3>📈 Текущие показатели (план/факт)</h3>
            <?php
            $metrics_list = [
                'calls' => ['name' => 'Звонки', 'plan' => $plans['calls_plan']],
                'calls_answered' => ['name' => 'Дозвоны', 'plan' => $plans['calls_answered_plan']],
                'meetings' => ['name' => 'Встречи', 'plan' => $plans['meetings_plan']],
                'contracts' => ['name' => 'Договоры', 'plan' => $plans['contracts_plan']],
                'registrations' => ['name' => 'Регистрации', 'plan' => $plans['registrations_plan']],
                'turnover' => ['name' => 'новый оборот по чаевым', 'plan' => $plans['turnover_plan'], 'is_money' => true]
            ];
            
            // Суммируем показатели за последние 30 дней
            $totals = array_fill_keys(array_keys($metrics_list), 0);
            foreach ($reports_list as $report) {
                foreach ($metrics_list as $key => $info) {
                    $totals[$key] += $report[$key] ?? 0;
                }
            }
            
            foreach ($metrics_list as $key => $info):
                $total = $totals[$key];
                $plan = $info['plan'];
                $percent = $plan > 0 ? min(100, round(($total / $plan) * 100)) : 0;
            ?>
            <div class="metric">
                <span><?= $info['name'] ?></span>
                <span class="metric-value">
                    <?= isset($info['is_money']) ? number_format($total) . ' ₽' : $total ?>
                    / <?= isset($info['is_money']) ? number_format($plan) . ' ₽' : $plan ?>
                </span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?= $percent ?>%"></div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="card">
            <h3>📝 Ежедневный отчёт</h3>
            <form method="post">
                <input type="date" name="report_date" value="<?= $editDate ?? date('Y-m-d') ?>">
                <div class="form-row">
                    <input type="number" name="calls" placeholder="Звонки" value="<?= $editReport['calls'] ?? '' ?>">
                    <input type="number" name="calls_answered" placeholder="Дозвоны" value="<?= $editReport['calls_answered'] ?? '' ?>">
                </div>
                <div class="form-row">
                    <input type="number" name="meetings" placeholder="Встречи" value="<?= $editReport['meetings'] ?? '' ?>">
                    <input type="number" name="contracts" placeholder="Договоры" value="<?= $editReport['contracts'] ?? '' ?>">
                </div>
                <div class="form-row">
                    <input type="number" name="registrations" placeholder="Регистрации" value="<?= $editReport['registrations'] ?? '' ?>">
                    <input type="number" name="smart_cash" placeholder="smart-кассы" value="<?= $editReport['smart_cash'] ?? '' ?>">
                </div>
                <div class="form-row">
                    <input type="number" name="pos_systems" placeholder="POS-системы" value="<?= $editReport['pos_systems'] ?? '' ?>">
                    <input type="number" name="inn_leads" placeholder="инн по чаевым" value="<?= $editReport['inn_leads'] ?? '' ?>">
                </div>
                <div class="form-row">
                    <input type="number" name="teams" placeholder="новые команды по чаевым" value="<?= $editReport['teams'] ?? '' ?>">
                    <input type="number" name="turnover" placeholder="новый оборот по чаевым (руб)" value="<?= $editReport['turnover'] ?? '' ?>">
                </div>
                <button type="submit">Сохранить отчёт</button>
            </form>
        </div>
    </div>
    
    <div class="card">
        <h3>📋 История отчётов</h3>
        <table>
            <thead>
                <tr><th>Дата</th><th>Звонки</th><th>Дозвоны</th><th>Встречи</th><th>Договоры</th><th>Регистрации</th><th>новый оборот по чаевым</th><th>Действия</th></tr>
            </thead>
            <tbody>
                <?php foreach ($reports_list as $report): ?>
                <tr>
                    <td><?= $report['report_date'] ?></td>
                    <td><?= $report['calls'] ?></td>
                    <td><?= $report['calls_answered'] ?></td>
                    <td><?= $report['meetings'] ?></td>
                    <td><?= $report['contracts'] ?></td>
                    <td><?= $report['registrations'] ?></td>
                    <td><?= number_format($report['turnover']) ?> ₽</td>
                    <td>
                        <a href="?edit=<?= $report['report_date'] ?>" class="edit-btn">✏️</a>
                        <a href="?delete=<?= $report['report_date'] ?>" class="delete-btn" onclick="return confirm('Удалить?')">🗑️</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
