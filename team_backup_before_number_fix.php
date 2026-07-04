<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once 'db.php';

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$user_name = $_SESSION['name'];

function calculateDailyPlan($plan, $fact, $days_passed, $days_left, $work_days = 22) {
    if ($plan <= 0) return 0;
    $ideal = ceil($plan / $work_days);
    $should_be = $ideal * ($days_passed - 1);
    $deviation = $should_be - $fact;
    if ($deviation > 0) return ceil(($plan - $fact) / $days_left);
    return $ideal;
}

$work_days_total = 22;
$current_day = date('j');
$days_passed = min($work_days_total, max(1, $current_day));
$days_left = max(1, $work_days_total - $days_passed + 1);

$level = $_GET['level'] ?? 'root';
$selected_id = $_GET['id'] ?? 0;

$territories = [];
$managers = [];
$employees = [];

if ($user_role == 'admin') {
    $stmt = $pdo->query("SELECT id, tabel_number, full_name, role FROM users WHERE role != 'admin' ORDER BY full_name");
    $employees = $stmt->fetchAll();
    $show_employees = true;
} 
elseif ($user_role == 'terman') {
    // Получаем территории, закреплённые за менеджером
    $stmt = $pdo->prepare("
        SELECT t.id, t.name 
        FROM territories t
        JOIN territory_managers tm ON t.id = tm.territory_id
        WHERE tm.manager_id = ?
    ");
    $stmt->execute([$user_id]);
    $territories = $stmt->fetchAll();
    
    if ($level == 'root') {
        $show_territories = true;
    } 
    elseif ($level == 'territory') {
        // Получаем начальников (head) на этой территории
        $stmt = $pdo->prepare("
            SELECT id, tabel_number, full_name, role 
            FROM users 
            WHERE role = 'head'
            ORDER BY full_name
        ");
        $stmt->execute();
        $managers = $stmt->fetchAll();
        $show_managers = true;
        $current_territory = $selected_id;
    } 
    elseif ($level == 'manager') {
        // Получаем сотрудников этого начальника
        $stmt = $pdo->prepare("
            SELECT id, tabel_number, full_name, role 
            FROM users 
            WHERE manager_id = ? AND role IN ('manager', 'employee')
            ORDER BY full_name
        ");
        $stmt->execute([$selected_id]);
        $employees = $stmt->fetchAll();
        $show_employees = true;
    }
}
else {
    // Обычный менеджер видит свою команду
    $stmt = $pdo->prepare("
        SELECT id, tabel_number, full_name, role 
        FROM users 
        WHERE manager_id = ? AND role IN ('manager', 'employee')
        ORDER BY full_name
    ");
    $stmt->execute([$user_id]);
    $employees = $stmt->fetchAll();
    $show_employees = true;
}

function getEmployeesData($employees, $pdo, $days_passed, $days_left) {
    $data = [];
    foreach ($employees as $emp) {
        $stmt = $pdo->prepare("SELECT * FROM plans WHERE tabel_number = ?");
        $stmt->execute([$emp['tabel_number']]);
        $plans = $stmt->fetch();
        if (!$plans) {
            $plans = ['calls_plan'=>350,'calls_answered_plan'=>245,'meetings_plan'=>35,'contracts_plan'=>21,'registrations_plan'=>15,'smart_cash_plan'=>10,'pos_systems_plan'=>5,'inn_leads_plan'=>5,'teams_plan'=>3,'turnover_plan'=>1500000];
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                SUM(calls) as calls, SUM(calls_answered) as calls_answered,
                SUM(meetings) as meetings, SUM(contracts) as contracts,
                SUM(registrations) as registrations, SUM(smart_cash) as smart_cash,
                SUM(pos_systems) as pos_systems, SUM(inn_leads) as inn_leads,
                SUM(teams) as teams, SUM(turnover) as turnover
            FROM daily_reports 
            WHERE user_id = ? AND strftime('%Y-%m', report_date) = strftime('%Y-%m', 'now')
        ");
        $stmt->execute([$emp['id']]);
        $fact = $stmt->fetch();
        if (!$fact) $fact = ['calls'=>0,'calls_answered'=>0,'meetings'=>0,'contracts'=>0,'registrations'=>0,'smart_cash'=>0,'pos_systems'=>0,'inn_leads'=>0,'teams'=>0,'turnover'=>0];
        
        $forecast = []; $daily_plans = [];
        foreach (['calls','calls_answered','meetings','contracts','registrations','smart_cash','pos_systems','inn_leads','teams','turnover'] as $metric) {
            $plan_val = $plans[$metric.'_plan'] ?? 0;
            $fact_val = $fact[$metric] ?? 0;
            if ($days_passed > 1 && $fact_val > 0) {
                $avg = $fact_val / ($days_passed - 1);
                $forecast[$metric] = round($fact_val + ($avg * $days_left));
            } else { $forecast[$metric] = $plan_val; }
            $daily_plans[$metric] = calculateDailyPlan($plan_val, $fact_val, $days_passed, $days_left);
        }
        $data[] = [
            'id'=>$emp['id'],
            'tabel_number'=>$emp['tabel_number'],
            'full_name'=>$emp['full_name'],
            'plans'=>$plans,
            'fact'=>$fact,
            'forecast'=>$forecast,
            'daily_plan'=>$daily_plans
        ];
    }
    return $data;
}

function getConsolidatedForTerritory($territory_id, $pdo, $days_passed, $days_left) {
    // Получаем всех начальников на территории
    $stmt = $pdo->prepare("
        SELECT u.id 
        FROM users u
        WHERE u.role = 'head'
    ");
    $stmt->execute();
    $heads = $stmt->fetchAll();
    
    $total_plans = ['calls_plan'=>0,'contracts_plan'=>0];
    $total_fact = ['calls'=>0,'contracts'=>0];
    
    foreach ($heads as $head) {
        $stmt = $pdo->prepare("SELECT * FROM plans WHERE tabel_number = (SELECT tabel_number FROM users WHERE id = ?)");
        $stmt->execute([$head['id']]);
        $plans = $stmt->fetch();
        if ($plans) {
            $total_plans['calls_plan'] += $plans['calls_plan'] ?? 0;
            $total_plans['contracts_plan'] += $plans['contracts_plan'] ?? 0;
        }
        
        $stmt = $pdo->prepare("
            SELECT SUM(calls) as calls, SUM(contracts) as contracts
            FROM daily_reports 
            WHERE user_id = ? AND strftime('%Y-%m', report_date) = strftime('%Y-%m', 'now')
        ");
        $stmt->execute([$head['id']]);
        $fact = $stmt->fetch();
        if ($fact) {
            $total_fact['calls'] += $fact['calls'] ?? 0;
            $total_fact['contracts'] += $fact['contracts'] ?? 0;
        }
    }
    
    $forecast_calls = ($days_passed > 1 && $total_fact['calls'] > 0) ? round($total_fact['calls'] + (($total_fact['calls'] / ($days_passed - 1)) * $days_left)) : $total_plans['calls_plan'];
    $forecast_contracts = ($days_passed > 1 && $total_fact['contracts'] > 0) ? round($total_fact['contracts'] + (($total_fact['contracts'] / ($days_passed - 1)) * $days_left)) : $total_plans['contracts_plan'];
    
    return [
        'calls_fact' => $total_fact['calls'],
        'calls_plan' => $total_plans['calls_plan'],
        'contracts_fact' => $total_fact['contracts'],
        'contracts_plan' => $total_plans['contracts_plan'],
        'forecast_calls' => $forecast_calls,
        'forecast_contracts' => $forecast_contracts
    ];
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>👥 Команда</title><style>
*{margin:0;padding:0;box-sizing:border-box}body{background:#f5f7fb;padding:20px;font-family:system-ui}.container{max-width:1400px;margin:0 auto}.header{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:20px;border-radius:15px;margin-bottom:25px;display:flex;justify-content:space-between}.nav-links a{color:#fff;padding:8px 16px;background:rgba(255,255,255,0.2);border-radius:8px;margin-left:10px;text-decoration:none}.card-list{display:flex;flex-wrap:wrap;gap:15px}.card{background:#fff;border-radius:15px;padding:20px;width:280px;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,0.1);transition:0.2s}.card:hover{transform:translateY(-3px);background:#f0f0f0}table{width:100%;background:#fff;border-radius:15px;border-collapse:collapse}th,td{padding:12px;text-align:left;border-bottom:1px solid #eee;font-size:13px}th{background:#f8f9fa}.progress-bar{background:#e0e0e0;border-radius:10px;height:6px;width:100px;overflow:hidden}.progress-fill{background:#4caf50;height:100%}small{font-size:10px;color:#666}.back-link{display:inline-block;margin-bottom:20px;color:#667eea;text-decoration:none}
</style></head>
<body><div class="container"><div class="header"><div><h1>👥 Команда</h1><p><?= htmlspecialchars($user_name) ?> (<?= htmlspecialchars($user_role) ?>)</p></div><div class="nav-links"><a href="dashboard.php">📊 Дашборд</a><a href="logout.php">🚪 Выйти</a></div></div>

<?php if (isset($show_territories)): ?>
    <div class="card-list">
        <?php foreach ($territories as $t): 
            $cons = getConsolidatedForTerritory($t['id'], $pdo, $days_passed, $days_left);
        ?>
            <div class="card" onclick="location.href='?level=territory&id=<?=$t['id']?>'">
                <h3>🌍 <?= htmlspecialchars($t['name']) ?></h3>
                <div style="font-size:12px; margin-top:10px">
                    <div>📞 Звонки: <?=$cons['calls_fact']?>/<?=$cons['calls_plan']?></div>
                    <div>📄 Договоры: <?=$cons['contracts_fact']?>/<?=$cons['contracts_plan']?></div>
                    <div>🎯 Прогноз договоров: <?=$cons['forecast_contracts']?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
<?php elseif (isset($show_managers)): ?>
    <a href="team.php" class="back-link">← Назад к территориям</a>
    <div class="card-list">
        <?php foreach ($managers as $m): ?>
            <div class="card" onclick="location.href='?level=manager&id=<?=$m['id']?>'">
                <h3>👔 <?= htmlspecialchars($m['full_name']) ?></h3>
                <p>Таб. <?= htmlspecialchars($m['tabel_number']) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
    
<?php else: ?>
    <?php $display_data = getEmployeesData($employees, $pdo, $days_passed, $days_left); ?>
    <?php if (empty($display_data)): ?>
        <div style="background:#fff;border-radius:15px;padding:40px;text-align:center"><p>😕 Нет сотрудников для отображения</p></div>
    <?php else: ?>
        <div style="overflow-x:auto">
            <table><thead><tr><th>Сотрудник</th><th>Таб.</th><th>📞 Звонки</th><th>✅ Дозвоны</th><th>🤝 Встречи</th><th>📄 Договоры</th><th>📝 Регистрации</th><th>💳 Smart</th><th>🖥️ POS</th><th>🍵 ИНН</th><th>👥 Команды</th><th>💰 Оборот</th></tr></thead>
            <tbody>
            <?php foreach ($display_data as $emp): ?>
            <tr>
                <td><strong><?= htmlspecialchars($emp['full_name']) ?></strong></td>
                <td><?= $emp['tabel_number'] ?></td>
                <td><div><?=$emp['fact']['calls']?>/<?=$emp['plans']['calls_plan']?></div><div class="progress-bar"><div class="progress-fill" style="width:<?=min(100,($emp['fact']['calls']/$emp['plans']['calls_plan'])*100)?>%"></div></div><small>📅 <?=$emp['forecast']['calls']?> | 🎯 <?=$emp['daily_plan']['calls']?></small></td>
                <td><div><?=$emp['fact']['calls_answered']?>/<?=$emp['plans']['calls_answered_plan']?></div><div class="progress-bar"><div class="progress-fill" style="width:<?=min(100,($emp['fact']['calls_answered']/$emp['plans']['calls_answered_plan'])*100)?>%"></div></div><small>📅 <?=$emp['forecast']['calls_answered']?> | 🎯 <?=$emp['daily_plan']['calls_answered']?></small></td>
                <td><div><?=$emp['fact']['meetings']?>/<?=$emp['plans']['meetings_plan']?></div><div class="progress-bar"><div class="progress-fill" style="width:<?=min(100,($emp['fact']['meetings']/$emp['plans']['meetings_plan'])*100)?>%"></div></div><small>📅 <?=$emp['forecast']['meetings']?> | 🎯 <?=$emp['daily_plan']['meetings']?></small></td>
                <td><div><?=$emp['fact']['contracts']?>/<?=$emp['plans']['contracts_plan']?></div><div class="progress-bar"><div class="progress-fill" style="width:<?=min(100,($emp['fact']['contracts']/$emp['plans']['contracts_plan'])*100)?>%"></div></div><small>📅 <?=$emp['forecast']['contracts']?> | 🎯 <?=$emp['daily_plan']['contracts']?></small></td>
                <td><div><?=$emp['fact']['registrations']?>/<?=$emp['plans']['registrations_plan']?></div><div class="progress-bar"><div class="progress-fill" style="width:<?=min(100,($emp['fact']['registrations']/$emp['plans']['registrations_plan'])*100)?>%"></div></div><small>📅 <?=$emp['forecast']['registrations']?> | 🎯 <?=$emp['daily_plan']['registrations']?></small></td>
                <td><div><?=$emp['fact']['smart_cash']?>/<?=$emp['plans']['smart_cash_plan']?></div><div class="progress-bar"><div class="progress-fill" style="width:<?=min(100,($emp['fact']['smart_cash']/$emp['plans']['smart_cash_plan'])*100)?>%"></div></div><small>📅 <?=$emp['forecast']['smart_cash']?> | 🎯 <?=$emp['daily_plan']['smart_cash']?></small></td>
                <td><div><?=$emp['fact']['pos_systems']?>/<?=$emp['plans']['pos_systems_plan']?></div><div class="progress-bar"><div class="progress-fill" style="width:<?=min(100,($emp['fact']['pos_systems']/$emp['plans']['pos_systems_plan'])*100)?>%"></div></div><small>📅 <?=$emp['forecast']['pos_systems']?> | 🎯 <?=$emp['daily_plan']['pos_systems']?></small></td>
                <td><div><?=$emp['fact']['inn_leads']?>/<?=$emp['plans']['inn_leads_plan']?></div><div class="progress-bar"><div class="progress-fill" style="width:<?=min(100,($emp['fact']['inn_leads']/$emp['plans']['inn_leads_plan'])*100)?>%"></div></div><small>📅 <?=$emp['forecast']['inn_leads']?> | 🎯 <?=$emp['daily_plan']['inn_leads']?></small></td>
                <td><div><?=$emp['fact']['teams']?>/<?=$emp['plans']['teams_plan']?></div><div class="progress-bar"><div class="progress-fill" style="width:<?=min(100,($emp['fact']['teams']/$emp['plans']['teams_plan'])*100)?>%"></div></div><small>📅 <?=$emp['forecast']['teams']?> | 🎯 <?=$emp['daily_plan']['teams']?></small></td>
                <td><div><?=number_format($emp['fact']['turnover'],0,'.',' ')?>/<?=number_format($emp['plans']['turnover_plan'],0,'.',' ')?> ₽</div><div class="progress-bar"><div class="progress-fill" style="width:<?=min(100,($emp['fact']['turnover']/$emp['plans']['turnover_plan'])*100)?>%"></div></div><small>📅 <?=number_format($emp['forecast']['turnover'],0,'.',' ')?> | 🎯 <?=number_format($emp['daily_plan']['turnover'],0,'.',' ')?> ₽</small></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>
</div></body></html>
