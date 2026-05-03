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
$message = '';

// ОБРАБОТКА POST ЗАПРОСА (НАЗНАЧЕНИЕ)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_territory'])) {
    $territory_id = (int)($_POST['territory_id'] ?? 0);
    $manager_id = $_POST['manager_id'] ?? null;
    
    if ($territory_id > 0) {
        if ($manager_id && $manager_id > 0) {
            $stmt = $pdo->prepare("UPDATE territories SET manager_id = ? WHERE id = ?");
            $stmt->execute([$manager_id, $territory_id]);
            $message = "✅ Руководитель назначен на территорию!";
        } elseif ($manager_id === '') {
            $stmt = $pdo->prepare("UPDATE territories SET manager_id = NULL WHERE id = ?");
            $stmt->execute([$territory_id]);
            $message = "✅ Руководитель снят с территории!";
        } else {
            $message = "⚠️ Выберите руководителя из списка";
        }
    }
}

// Текущий месяц
$year = date('Y');
$month = date('m');
$month_start = "$year-$month-01";
$month_end = date('Y-m-t');
$days_passed = max(1, (int)date('j'));
$days_left = max(1, (int)date('t') - (int)date('j') + 1);

// Получаем все территории
$territories = $pdo->query("SELECT * FROM territories ORDER BY name")->fetchAll();

// Получаем всех менеджеров
$managers = $pdo->query("SELECT id, full_name FROM users WHERE role IN ('manager', 'admin') ORDER BY full_name")->fetchAll();

// План по умолчанию
$default_plan = 682;

$territory_data = [];

foreach ($territories as $terr) {
    $manager_id = $terr['manager_id'];
    
    // Имя менеджера
    $manager_name = '— не назначен —';
    foreach ($managers as $m) {
        if ($m['id'] == $manager_id) {
            $manager_name = $m['full_name'];
            break;
        }
    }
    
    // Инициализация статистики
    $total_calls = 0;
    $total_meetings = 0;
    $total_contracts = 0;
    $total_turnover = 0;
    $plan_contracts = $default_plan;
    
    // Если есть руководитель - считаем статистику
    if ($manager_id) {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(dr.calls), 0) as calls_sum,
                COALESCE(SUM(dr.meetings), 0) as meetings_sum,
                COALESCE(SUM(dr.contracts), 0) as contracts_sum,
                COALESCE(SUM(dr.turnover), 0) as turnover_sum
            FROM daily_reports dr
            JOIN users u ON dr.user_id = u.id
            WHERE u.manager_id = ? AND dr.report_date BETWEEN ? AND ?
        ");
        $stmt->execute([$manager_id, $month_start, $month_end]);
        $stats = $stmt->fetch();
        
        $total_calls = (int)($stats['calls_sum'] ?? 0);
        $total_meetings = (int)($stats['meetings_sum'] ?? 0);
        $total_contracts = (int)($stats['contracts_sum'] ?? 0);
        $total_turnover = (int)($stats['turnover_sum'] ?? 0);
        
        // Получаем план
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(plans.contracts_plan), 0) as plan_sum
            FROM plans
            JOIN users u ON plans.tabel_number = u.tabel_number
            WHERE u.manager_id = ? OR u.id = ?
        ");
        $stmt->execute([$manager_id, $manager_id]);
        $plan_data = $stmt->fetch();
        $plan_contracts = (int)($plan_data['plan_sum'] ?? $default_plan);
        if ($plan_contracts <= 0) $plan_contracts = $default_plan;
    }
    
    // Прогноз
    $daily_avg = $total_contracts / $days_passed;
    $forecast = (int)round($total_contracts + ($daily_avg * $days_left));
    if ($forecast < 0) $forecast = 0;
    
    // Прогресс
    $progress = $plan_contracts > 0 ? min(100, (int)round(($total_contracts / $plan_contracts) * 100)) : 0;
    
    // Статус
    $status_color = '#ef4444';
    $status_text = '🔴 Провал';
    if ($forecast >= $plan_contracts) {
        $status_color = '#00a36c';
        $status_text = '🚀 Будет выполнено';
    } elseif ($forecast >= $plan_contracts * 0.8) {
        $status_color = '#f59e0b';
        $status_text = '⚠️ Под угрозой';
    }
    
    $territory_data[] = [
        'id' => $terr['id'],
        'name' => $terr['name'],
        'manager_id' => $manager_id,
        'manager_name' => $manager_name,
        'calls' => $total_calls,
        'meetings' => $total_meetings,
        'contracts' => $total_contracts,
        'turnover' => $total_turnover,
        'plan' => $plan_contracts,
        'forecast' => $forecast,
        'progress' => $progress,
        'status_color' => $status_color,
        'status_text' => $status_text
    ];
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Территориальный менеджер - Sales CRM</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; background: #f5f7fb; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { color: #00a36c; margin-bottom: 10px; }
        .subtitle { color: #666; margin-bottom: 20px; }
        .message { background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .region-card { background: white; border-radius: 16px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-left: 4px solid; }
        .region-card h3 { font-size: 18px; margin-bottom: 15px; }
        .region-card .manager { font-size: 13px; color: #666; margin-bottom: 10px; }
        .region-card .value { font-size: 24px; font-weight: bold; }
        .region-card .forecast { font-size: 12px; margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; }
        .progress-bar { background: #e5e7eb; border-radius: 10px; height: 8px; margin: 10px 0; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 10px; }
        .card { background: white; border-radius: 16px; padding: 20px; margin-bottom: 30px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; font-weight: 600; }
        .badge { padding: 4px 8px; border-radius: 20px; font-size: 11px; display: inline-block; }
        select, button { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; }
        .btn-primary { background: #00a36c; color: white; border: none; cursor: pointer; }
        .assign-cell { white-space: nowrap; }
        .assign-cell form { display: inline-flex; gap: 8px; align-items: center; }
        .note { font-size: 11px; color: #888; margin-top: 10px; text-align: center; }
    </style>
</head>
<body>
<div class="container">
    <?php require_once 'navbar.php'; ?>
    
    <h1>🗺️ Территориальный менеджмент</h1>
    <div class="subtitle">Статистика по территориям на <?= date('F Y') ?></div>
    
    <?php if ($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    
    <!-- Карточки -->
    <div class="stats-grid">
        <?php foreach ($territory_data as $t): ?>
        <div class="region-card" style="border-left-color: <?= $t['status_color'] ?>">
            <h3>🗺️ <?= htmlspecialchars($t['name']) ?></h3>
            <div class="manager">👔 <?= htmlspecialchars($t['manager_name']) ?></div>
            <div class="value"><?= number_format($t['contracts']) ?> / <?= number_format($t['plan']) ?></div>
            <div>Договоров / План</div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?= $t['progress'] ?>%; background: <?= $t['status_color'] ?>"></div>
            </div>
            <div class="forecast">📈 Прогноз: <?= number_format($t['forecast']) ?> <span class="badge" style="background: <?= $t['status_color'] ?>20; color: <?= $t['status_color'] ?>"><?= $t['status_text'] ?></span></div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Таблица -->
    <div class="card">
        <h2>📊 Детальная статистика по территориям</h2>
        <div style="overflow-x: auto;">
            <form method="post" id="assignForm">
                <table>
                    <thead>
                        <tr>
                            <th>Территория</th>
                            <th>Руководитель</th>
                            <th>📞 Звонки</th>
                            <th>📅 Встречи</th>
                            <th>📄 Договоры</th>
                            <th>💰 Оборот</th>
                            <th>Прогноз</th>
                            <th>Выполнение</th>
                            <?php if ($role == 'admin'): ?>
                            <th>Назначить</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($territory_data as $t): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($t['name']) ?></strong></td>
                            <td><?= htmlspecialchars($t['manager_name']) ?></td>
                            <td><?= number_format($t['calls']) ?></td>
                            <td><?= number_format($t['meetings']) ?></td>
                            <td><?= number_format($t['contracts']) ?> / <?= number_format($t['plan']) ?></td>
                            <td><?= number_format($t['turnover'], 0, ',', ' ') ?> ₽</td>
                            <td><?= number_format($t['forecast']) ?></td>
                            <td style="width: 100px;">
                                <div class="progress-bar" style="display: inline-block; width: 60px; margin-right: 5px;">
                                    <div class="progress-fill" style="width: <?= $t['progress'] ?>%; background: <?= $t['status_color'] ?>"></div>
                                </div>
                                <?= $t['progress'] ?>%
                             </td>
                            <?php if ($role == 'admin'): ?>
                            <td class="assign-cell">
                                <select name="manager_<?= $t['id'] ?>" id="manager_<?= $t['id'] ?>">
                                    <option value="">— без руководителя —</option>
                                    <?php foreach ($managers as $m): ?>
                                    <option value="<?= $m['id'] ?>" <?= $t['manager_id'] == $m['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($m['full_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn-primary" onclick="assignTerritory(<?= $t['id'] ?>)">👔 Назначить</button>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        </div>
    </div>
    
    <div class="note">💡 Для корректной работы территории должен быть назначен руководитель. Планы синхронизируются с сотрудниками территории.</div>
</div>

<?php if ($role == 'admin'): ?>
<script>
function assignTerritory(territoryId) {
    var select = document.getElementById('manager_' + territoryId);
    var managerId = select.value;
    
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '';
    
    var inputTerritory = document.createElement('input');
    inputTerritory.type = 'hidden';
    inputTerritory.name = 'territory_id';
    inputTerritory.value = territoryId;
    form.appendChild(inputTerritory);
    
    var inputManager = document.createElement('input');
    inputManager.type = 'hidden';
    inputManager.name = 'manager_id';
    inputManager.value = managerId;
    form.appendChild(inputManager);
    
    var inputAction = document.createElement('input');
    inputAction.type = 'hidden';
    inputAction.name = 'assign_territory';
    inputAction.value = '1';
    form.appendChild(inputAction);
    
    document.body.appendChild(form);
    form.submit();
}
</script>
<?php endif; ?>
</body>
</html>
