<?php
session_start();
if(!isset($_SESSION['user_id'])){header('Location:login.php');exit;}
$role=$_SESSION['role'];
if(!in_array($role,['admin','terman','territory_head','head'])){header('Location:dashboard.php');exit;}
require_once 'db.php';

// --- Выбор периода и навигация ---
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_month = date('Y-m', strtotime($selected_date));
$level = $_GET['level'] ?? 'root';
$selected_id = $_GET['id'] ?? 0;

// Параметры для расчётов (как в dashboard)
$work_days_total = 22;
$current_day = date('j', strtotime($selected_date));
$days_passed = min($work_days_total, max(1, $current_day));
$days_left = max(1, $work_days_total - $days_passed + 1);

// --- Функции агрегации и расчётов (как в team.php) ---
function calcMetrics($plan, $fact, $days_passed, $days_left, $work_days = 22) {
    if ($plan <= 0) return ['forecast' => 0, 'daily_target' => 0, 'status' => 'none', 'percent' => 0];
    $ideal = ceil($plan / $work_days);
    $should_be = $ideal * ($days_passed - 1);
    $deviation = $should_be - $fact;
    $daily_target = ($deviation > 0) ? ceil(($plan - $fact) / $days_left) : $ideal;
    $forecast = ($days_passed > 1 && $fact > 0) ? round($fact + ($fact / ($days_passed - 1)) * $days_left) : $plan;
    if ($fact >= $plan) $status = 'success';
    elseif ($forecast >= $plan) $status = 'warning';
    else $status = 'danger';
    $percent = ($plan > 0) ? round(($fact / $plan) * 100) : 0;
    return ['forecast' => $forecast, 'daily_target' => $daily_target, 'status' => $status, 'percent' => $percent];
}

function getAggregatedMetricsForUsers($user_ids, $pdo, $selected_month, $selected_date, $days_passed, $days_left) {
    $totals = [
        'plan' => ['calls'=>0,'calls_answered'=>0,'meetings'=>0,'contracts'=>0,'registrations'=>0,'smart_cash'=>0,'pos_systems'=>0,'inn_leads'=>0,'teams'=>0,'turnover'=>0],
        'fact_month' => ['calls'=>0,'calls_answered'=>0,'meetings'=>0,'contracts'=>0,'registrations'=>0,'smart_cash'=>0,'pos_systems'=>0,'inn_leads'=>0,'teams'=>0,'turnover'=>0],
        'fact_today' => ['calls'=>0,'calls_answered'=>0,'meetings'=>0,'contracts'=>0,'registrations'=>0,'smart_cash'=>0,'pos_systems'=>0,'inn_leads'=>0,'teams'=>0,'turnover'=>0]
    ];
    foreach ($user_ids as $uid) {
        $stmt = $pdo->prepare("SELECT tabel_number FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        $tabel = $stmt->fetchColumn();
        if (!$tabel) continue;
        // Планы
        $stmt = $pdo->prepare("SELECT * FROM plans WHERE tabel_number = ? AND period = ?");
        $stmt->execute([$tabel, $selected_month]);
        $planRow = $stmt->fetch();
        if ($planRow) {
            foreach (['calls','calls_answered','meetings','contracts','registrations','smart_cash','pos_systems','inn_leads','teams','turnover'] as $f) {
                $totals['plan'][$f] += $planRow[$f.'_plan'] ?? 0;
            }
        }
        // Месячный факт
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(calls),0) as calls, COALESCE(SUM(calls_answered),0) as calls_answered,
            COALESCE(SUM(meetings),0) as meetings, COALESCE(SUM(contracts),0) as contracts,
            COALESCE(SUM(registrations),0) as registrations, COALESCE(SUM(smart_cash),0) as smart_cash,
            COALESCE(SUM(pos_systems),0) as pos_systems, COALESCE(SUM(inn_leads),0) as inn_leads,
            COALESCE(SUM(teams),0) as teams, COALESCE(SUM(turnover),0) as turnover
            FROM daily_reports WHERE user_id = ? AND strftime('%Y-%m', report_date) = ?");
        $stmt->execute([$uid, $selected_month]);
        $factM = $stmt->fetch();
        if ($factM) {
            foreach (['calls','calls_answered','meetings','contracts','registrations','smart_cash','pos_systems','inn_leads','teams','turnover'] as $f) {
                $totals['fact_month'][$f] += $factM[$f];
            }
        }
        // Дневной факт
        $stmt = $pdo->prepare("SELECT calls, calls_answered, meetings, contracts, registrations, smart_cash, pos_systems, inn_leads, teams, turnover FROM daily_reports WHERE user_id = ? AND report_date = ?");
        $stmt->execute([$uid, $selected_date]);
        $factD = $stmt->fetch();
        if ($factD) {
            foreach (['calls','calls_answered','meetings','contracts','registrations','smart_cash','pos_systems','inn_leads','teams','turnover'] as $f) {
                $totals['fact_today'][$f] += $factD[$f];
            }
        }
    }
    $result = [];
    $labels = ['calls'=>'Звонки','calls_answered'=>'Дозвоны','meetings'=>'Встречи','contracts'=>'Договоры','registrations'=>'ТЭ','smart_cash'=>'Смарт','pos_systems'=>'ПОС','inn_leads'=>'ИНН чаевые','teams'=>'Команды','turnover'=>'Оборот чаевых'];
    $icons = ['calls'=>'📞','calls_answered'=>'✅','meetings'=>'🤝','contracts'=>'📄','registrations'=>'📝','smart_cash'=>'💳','pos_systems'=>'🖥️','inn_leads'=>'🍵','teams'=>'👥','turnover'=>'💰'];
    $units = ['turnover'=>'₽'];
    foreach (array_keys($totals['plan']) as $key) {
        $plan = $totals['plan'][$key];
        $fact = $totals['fact_month'][$key];
        $today = $totals['fact_today'][$key];
        $calc = calcMetrics($plan, $fact, $days_passed, $days_left);
        $result[$key] = [
            'plan' => $plan,
            'fact' => $fact,
            'today' => $today,
            'percent' => $calc['percent'],
            'forecast' => $calc['forecast'],
            'daily_target' => $calc['daily_target'],
            'status' => $calc['status'],
            'label' => $labels[$key],
            'icon' => $icons[$key],
            'unit' => $units[$key] ?? ''
        ];
    }
    return $result;
}

// --- Сбор данных в зависимости от роли и уровня ---
$show_ai_form = false;
$ai_subordinates = []; // менеджеры, которые будут переданы в AI
$territories = [];
$managers = [];
$employees = [];

if ($role == 'admin') {
    // Админ видит всё, но для простоты покажем список всех менеджеров сразу
    $stmt = $pdo->query("SELECT u.*, h.full_name as head_name, t.name as territory_name 
        FROM users u 
        LEFT JOIN users h ON u.head_tabel = h.tabel_number 
        LEFT JOIN territories t ON u.territory_id = t.id 
        WHERE u.role = 'manager' AND u.is_active = 1 ORDER BY u.full_name");
    $employees = $stmt->fetchAll();
    $show_ai_form = true;
    $ai_subordinates = $employees;
    $show_table = true;
} elseif ($role == 'terman') {
    // Термен: список территорий
    $stmt = $pdo->prepare("SELECT t.id, t.name FROM territories t JOIN territory_managers tm ON t.id = tm.territory_id WHERE tm.manager_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $territories = $stmt->fetchAll();

    if ($level == 'root') {
        $show_territories = true;
        // Рассчитываем агрегированные метрики для каждой территории
        $territory_stats = [];
        foreach ($territories as $t) {
            $stmt = $pdo->prepare("SELECT u.id FROM users u WHERE u.role IN ('head','territory_head','manager') AND u.territory_id = ? AND u.is_active = 1");
            $stmt->execute([$t['id']]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($ids) {
                $metrics = getAggregatedMetricsForUsers($ids, $pdo, $selected_month, $selected_date, $days_passed, $days_left);
                $territory_stats[] = ['territory' => $t, 'metrics' => $metrics];
            }
        }
    } elseif ($level == 'territory') {
        // Показываем менеджеров выбранной территории (включая начальников? только менеджеров)
        $stmt = $pdo->prepare("SELECT u.*, h.full_name as head_name, t.name as territory_name 
            FROM users u 
            LEFT JOIN users h ON u.head_tabel = h.tabel_number 
            LEFT JOIN territories t ON u.territory_id = t.id 
            WHERE u.role = 'manager' AND u.territory_id = ? AND u.is_active = 1 ORDER BY u.full_name");
        $stmt->execute([$selected_id]);
        $employees = $stmt->fetchAll();
        $show_ai_form = true;
        $ai_subordinates = $employees;
        $show_table = true;
        $current_territory_name = $territories[array_search($selected_id, array_column($territories, 'id'))]['name'] ?? '';
    }
} elseif (in_array($role, ['head', 'territory_head'])) {
    // Начальник: его прямые подчинённые менеджеры
    $stmt = $pdo->prepare("SELECT u.*, h.full_name as head_name, t.name as territory_name 
        FROM users u 
        LEFT JOIN users h ON u.head_tabel = h.tabel_number 
        LEFT JOIN territories t ON u.territory_id = t.id 
        WHERE u.manager_id = ? AND u.role = 'manager' AND u.is_active = 1 ORDER BY u.full_name");
    $stmt->execute([$_SESSION['user_id']]);
    $employees = $stmt->fetchAll();
    $show_ai_form = true;
    $ai_subordinates = $employees;
    $show_table = true;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>AI-аналитика — SZB CRM</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .ai-result { background: #f8f9fa; border-radius: 12px; padding: 16px; margin-top: 16px; white-space: pre-wrap; }
        #ai_question { flex:1; padding:12px; border:1px solid #dee2e6; border-radius:10px; font-size:14px; }
        .ask-btn { background: #1a73e8; color: #fff; border: none; padding: 12px 20px; border-radius: 10px; cursor: pointer; }
        .territory-card { background: #fff; border-radius: 16px; padding: 16px; margin-bottom: 16px; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,0.05); transition: 0.2s; }
        .territory-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .metric-row { display: flex; gap: 20px; flex-wrap: wrap; margin-top: 8px; }
        .metric-badge { background: #f1f3f5; border-radius: 8px; padding: 4px 8px; font-size: 0.8rem; }
        .progress-bg { background: #e0e0e0; border-radius: 10px; height: 6px; margin-top: 4px; }
        .progress-fill { height: 100%; border-radius: 10px; }
        .progress-fill.success { background: #2e7d32; }
        .progress-fill.warning { background: #ed6c02; }
        .progress-fill.danger { background: #d32f2f; }
        /* Единая навигация как на дашборде */
        .navbar{background:linear-gradient(135deg,#1a1a2e,#16213e);color:#fff;padding:12px 16px;border-radius:16px;margin-bottom:20px;display:flex;justify-content:space-between;flex-wrap:wrap;align-items:center}.logo{font-size:1.3rem;font-weight:bold}.nav-links{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.nav-links a{color:#ccc;text-decoration:none;font-size:0.85rem}.nav-links .active{color:#fff;font-weight:bold}.user-info{color:#fff;font-weight:bold;margin-left:auto;font-size:0.9rem}.date-form{display:flex;gap:8px;align-items:center;margin-left:12px}.date-form input[type="date"]{padding:5px 8px;border-radius:8px;border:none;font-size:0.85rem}.date-form button{background:#fff;color:#1a1a2e;border:none;padding:5px 12px;border-radius:8px;font-weight:bold;cursor:pointer;font-size:0.85rem}
    </style>
</head>
<body>
<div class="container">
<div class="navbar">
    <div class="logo">🚀 SZB</div>
    <div class="nav-links">
        <a href="dashboard.php">Дашборд</a>
        <a href="team.php">Команда</a>
        <a href="territories.php">Территории</a>
        <a href="export_inn.php">ИНН</a>
        <a href="ai.php" class="active">AI</a>
        <?php if($role=='admin'): ?><a href="admin.php">Админ</a><?php endif; ?>
        <span class="user-info">👤 <?= htmlspecialchars($_SESSION['name']) ?></span>
        <form class="date-form" method="GET" action="ai.php">
            <?php if (!empty($_GET['level'])) echo '<input type="hidden" name="level" value="'.htmlspecialchars($_GET['level']).'">'; ?>
            <?php if (!empty($_GET['id'])) echo '<input type="hidden" name="id" value="'.htmlspecialchars($_GET['id']).'">'; ?>
            <input type="date" name="date" value="<?= $selected_date ?>">
            <button type="submit">📅</button>
        </form>
        <a href="logout.php">Выйти</a>
    </div>
</div>
    <h2>🤖 AI-аналитика</h2>

    <?php if (isset($show_territories) && $territory_stats): ?>
        <h3>🌍 Территории (кликните для детализации)</h3>
        <?php foreach ($territory_stats as $ts): $t = $ts['territory']; $m = $ts['metrics']; ?>
            <div class="territory-card" onclick="location.href='?level=territory&id=<?= $t['id'] ?>&date=<?= $selected_date ?>'">
                <strong><?= htmlspecialchars($t['name']) ?></strong>
                <div class="metric-row">
                    <?php foreach (['calls','contracts','turnover'] as $key): $met = $m[$key]; ?>
                        <div>
                            <span><?= $met['icon'] ?> <?= $met['label'] ?>: <strong><?= $met['today'] ?></strong> / план <?= $met['plan'] ?></span>
                            <div class="progress-bg"><div class="progress-fill <?= $met['status'] ?>" style="width:<?= min(100, $met['percent']) ?>%"></div></div>
                            <small>🎯 цель: <?= $met['daily_target'] ?> | 📅 прогноз: <?= $met['forecast'] ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($show_ai_form): ?>
    <div class="card" style="margin-bottom:20px">
        <h3>💬 Задайте вопрос AI-аналитику</h3>
        <div style="display:flex; gap:10px">
            <input type="text" id="ai_question" placeholder="Например: кто не выполнит план по договорам? или на сколько процентов выполним план по обороту?">
            <button onclick="askAI()" class="ask-btn">🚀 Спросить</button>
        </div>
        <div id="ai_answer" class="ai-result" style="display:none"></div>
    </div>
    <?php endif; ?>

    <?php if (isset($show_table) && $employees): ?>
        <?php if ($level == 'territory'): ?>
            <a href="ai.php?date=<?= $selected_date ?>" class="btn btn-sm" style="margin-bottom:15px;">← Назад к территориям</a>
            <h3>👥 Менеджеры территории "<?= htmlspecialchars($current_territory_name) ?>"</h3>
        <?php else: ?>
            <h3>👥 Данные менеджеров (текущий месяц)</h3>
        <?php endif; ?>
        <div class="card" style="overflow-x:auto">
            <table class="manager-table">
                <thead>
                    <tr>
                        <th>Менеджер</th>
                        <th>Начальник</th>
                        <th>Территория</th>
                        <th>📞 Звонки</th>
                        <th>✅ Дозвоны</th>
                        <th>🤝 Встречи</th>
                        <th>📄 Договоры</th>
                        <th>📝 ТЭ</th>
                        <th>💳 Смарт</th>
                        <th>🖥️ ПОС</th>
                        <th>🍵 Чаевые</th>
                        <th>👥 Команды</th>
                        <th>💰 Оборот</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $sub): 
                        // Получаем данные для каждого сотрудника (аналогично ai.php ранее)
                        $stmt = $pdo->prepare("SELECT COALESCE(SUM(calls),0) calls, COALESCE(SUM(calls_answered),0) calls_answered,
                            COALESCE(SUM(meetings),0) meetings, COALESCE(SUM(contracts),0) contracts,
                            COALESCE(SUM(registrations),0) registrations, COALESCE(SUM(smart_cash),0) smart_cash,
                            COALESCE(SUM(pos_systems),0) pos_systems, COALESCE(SUM(inn_leads),0) inn_leads,
                            COALESCE(SUM(teams),0) teams, COALESCE(SUM(turnover),0) turnover
                            FROM daily_reports WHERE user_id = ? AND strftime('%Y-%m', report_date) = strftime('%Y-%m', 'now')");
                        $stmt->execute([$sub['id']]);
                        $m = $stmt->fetch();
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($sub['full_name']) ?></td>
                        <td><?= htmlspecialchars($sub['head_name'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($sub['territory_name'] ?? '—') ?></td>
                        <td><?= $m['calls'] ?></td>
                        <td><?= $m['calls_answered'] ?></td>
                        <td><?= $m['meetings'] ?></td>
                        <td><?= $m['contracts'] ?></td>
                        <td><?= $m['registrations'] ?></td>
                        <td><?= $m['smart_cash'] ?></td>
                        <td><?= $m['pos_systems'] ?></td>
                        <td><?= $m['inn_leads'] ?></td>
                        <td><?= $m['teams'] ?></td>
                        <td><?= number_format($m['turnover'], 0, '.', ' ') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
// AI запрос с фильтрацией по уровню
function askAI(){
    let q = document.getElementById('ai_question').value.trim();
    if(!q) return;
    let ans = document.getElementById('ai_answer');
    ans.style.display = 'block';
    ans.innerHTML = '⏳ AI анализирует данные...';
    
    fetch('api_ai_analytics.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            question: q,
            level: '<?= $level ?>',
            id: <?= (int)$selected_id ?>,
            date: '<?= $selected_date ?>'
        })
    })
    .then(r => r.json())
    .then(d => {
        ans.innerHTML = '<strong>🤖 Ответ:</strong><br>' + (d.response || d.error || 'Нет ответа');
    })
    .catch(e => ans.innerHTML = 'Ошибка соединения');
}
</script>
</body>
</html>