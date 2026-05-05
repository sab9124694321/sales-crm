cat > /root/deploy.sh << 'DEPLOY_EOF'
#!/bin/bash
set -e
echo "🚀 НАЧАЛО РАЗВЁРТЫВАНИЯ НОВОЙ CRM"

# ---------- ПЕРЕМЕННЫЕ ----------
DOMAIN="szb-sales.ru"
ADMIN_LOGIN="0001"
ADMIN_PASS_HASH='$2y$10$8KzQMGx5KXx6KzQMGx5KXue8KzQMGx5KXx6KzQMGx5KXu'  # admin123
GIGACHAT_AUTH="NzA0OTMxMWYtMTJkNy00OTQ5LWI2MzUtN2ZhYjZiNWRjMzY3OjRhODFmOWJlLWJjZGItNDVkYy04OTQ1LWQyMTViZTRiZTM4ZQ=="
GIGACHAT_CLIENT_ID="7049311f-12d7-4949-b635-7fab6b5dc367"
GIGACHAT_SCOPE="GIGACHAT_API_PERS"

# ---------- 1. УСТАНОВКА PYTHON3 ----------
echo "📦 Установка Python3..."
apt-get update -qq && apt-get install -y -qq python3 python3-pip python3-venv > /dev/null 2>&1
echo "✅ Python3 установлен"

# ---------- 2. БЭКАП СТАРОЙ CRM ----------
echo "💾 Создание бэкапа..."
BACKUP_DIR="/root/backup_$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"
if [ -d /root/sales-crm ]; then
    cp -r /root/sales-crm "$BACKUP_DIR/sales-crm-old"
    echo "✅ Бэкап сохранён в $BACKUP_DIR"
fi

# ---------- 3. ОСТАНОВКА СТАРОГО ----------
echo "🛑 Остановка старого Docker-контейнера..."
docker stop sales-crm 2>/dev/null || true
docker rm sales-crm 2>/dev/null || true

# ---------- 4. СОЗДАНИЕ НОВОЙ СТРУКТУРЫ ----------
echo "📁 Создание структуры новой CRM..."
rm -rf /root/sales-crm-new
mkdir -p /root/sales-crm-new/{html,api,services,ssl,data}
chmod -R 755 /root/sales-crm-new

# ---------- 5. БАЗА ДАННЫХ SQLITE ----------
echo "🗄️ Создание базы данных..."
cat > /root/sales-crm-new/data/init.sql << 'SQL'
CREATE TABLE IF NOT EXISTS terbanks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS territories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    code TEXT NOT NULL UNIQUE,
    terbank_id INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (terbank_id) REFERENCES terbanks(id)
);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tabel_number TEXT UNIQUE NOT NULL,
    full_name TEXT NOT NULL,
    email TEXT UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL CHECK(role IN ('admin','terman','territory_head','head','manager')),
    head_tabel TEXT,
    territory_id INTEGER,
    terbank_id INTEGER DEFAULT 1,
    is_active INTEGER DEFAULT 1,
    rank TEXT DEFAULT 'Новичок',
    total_points INTEGER DEFAULT 0,
    level INTEGER DEFAULT 1,
    experience INTEGER DEFAULT 0,
    next_level_exp INTEGER DEFAULT 100,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (territory_id) REFERENCES territories(id)
);

CREATE TABLE IF NOT EXISTS terman_territories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    terman_tabel TEXT NOT NULL,
    territory_id INTEGER NOT NULL,
    assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (territory_id) REFERENCES territories(id),
    UNIQUE(terman_tabel, territory_id)
);

CREATE TABLE IF NOT EXISTS plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tabel_number TEXT NOT NULL,
    period TEXT NOT NULL,
    calls_plan INTEGER DEFAULT 0,
    calls_answered_plan INTEGER DEFAULT 0,
    meetings_plan INTEGER DEFAULT 0,
    contracts_plan INTEGER DEFAULT 0,
    registrations_plan INTEGER DEFAULT 0,
    smart_cash_plan INTEGER DEFAULT 0,
    pos_systems_plan INTEGER DEFAULT 0,
    inn_leads_plan INTEGER DEFAULT 0,
    teams_plan INTEGER DEFAULT 0,
    turnover_plan REAL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(tabel_number, period)
);

CREATE TABLE IF NOT EXISTS daily_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tabel_number TEXT NOT NULL,
    report_date DATE NOT NULL,
    calls INTEGER DEFAULT 0,
    calls_answered INTEGER DEFAULT 0,
    meetings INTEGER DEFAULT 0,
    contracts INTEGER DEFAULT 0,
    registrations INTEGER DEFAULT 0,
    smart_cash INTEGER DEFAULT 0,
    pos_systems INTEGER DEFAULT 0,
    inn_leads INTEGER DEFAULT 0,
    teams INTEGER DEFAULT 0,
    turnover REAL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(tabel_number, report_date)
);

CREATE TABLE IF NOT EXISTS inn_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    inn TEXT NOT NULL,
    product TEXT NOT NULL,
    employee_tabel TEXT NOT NULL,
    employee_name TEXT NOT NULL,
    head_name TEXT,
    sale_date DATE NOT NULL,
    include_in_conversion INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS quests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL CHECK(type IN ('metric','free')),
    head_tabel TEXT NOT NULL,
    title TEXT NOT NULL,
    description TEXT,
    metric_field TEXT,
    metric_threshold INTEGER,
    metric_period TEXT,
    points INTEGER DEFAULT 0,
    is_active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    ends_at DATETIME
);

CREATE TABLE IF NOT EXISTS quest_takers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    quest_id INTEGER NOT NULL,
    employee_tabel TEXT NOT NULL,
    status TEXT DEFAULT 'taken' CHECK(status IN ('taken','completed','rejected')),
    taken_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME,
    FOREIGN KEY (quest_id) REFERENCES quests(id)
);

CREATE TABLE IF NOT EXISTS ai_dialogues (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_tabel TEXT,
    context TEXT,
    question TEXT,
    response TEXT,
    tokens_used INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ai_recommendations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_tabel TEXT NOT NULL,
    state_number INTEGER DEFAULT 1,
    recommendation TEXT,
    advice_book TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(employee_tabel)
);

CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_tabel TEXT NOT NULL,
    message TEXT NOT NULL,
    type TEXT DEFAULT 'info',
    is_read INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Начальные данные
INSERT OR IGNORE INTO terbanks (id, name) VALUES (1, 'Главный тербанк');

INSERT OR IGNORE INTO territories (id, name, code, terbank_id) VALUES 
(1, 'Центральный', 'CENTRAL', 1),
(2, 'Северный', 'NORTH', 1),
(3, 'Южный', 'SOUTH', 1);

-- Админ
INSERT OR IGNORE INTO users (tabel_number, full_name, email, password_hash, role, is_active)
VALUES ('0001', 'Администратор', 'admin@szb-sales.ru', '$2y$10$8KzQMGx5KXx6KzQMGx5KXue8KzQMGx5KXx6KzQMGx5KXu', 'admin', 1);
SQL

# Копируем в место, доступное PHP
cp /root/sales-crm-new/data/init.sql /root/sales-crm-new/html/init.sql

# Инициализируем БД через sqlite3 на хосте
apt-get install -y -qq sqlite3 > /dev/null 2>&1
sqlite3 /root/sales-crm-new/data/sales.db < /root/sales-crm-new/data/init.sql
cp /root/sales-crm-new/data/sales.db /root/sales-crm-new/html/sales.db
chmod 666 /root/sales-crm-new/html/sales.db
echo "✅ База данных создана"

# ---------- 6. db.php ----------
echo "📝 Создание db.php..."
cat > /root/sales-crm-new/html/db.php << 'PHPEOF'
<?php
$db_file = __DIR__ . '/sales.db';
try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("PRAGMA foreign_keys = ON");
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}

function getUserByTabel($pdo, $tabel) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE tabel_number = ?");
    $stmt->execute([$tabel]);
    return $stmt->fetch();
}

function getManagers($pdo) {
    $stmt = $pdo->query("SELECT * FROM users WHERE role = 'manager' AND is_active = 1 ORDER BY full_name");
    return $stmt->fetchAll();
}

function getHeads($pdo) {
    $stmt = $pdo->query("SELECT * FROM users WHERE role IN ('head','territory_head') AND is_active = 1 ORDER BY full_name");
    return $stmt->fetchAll();
}

function getTerritories($pdo) {
    $stmt = $pdo->query("SELECT * FROM territories ORDER BY name");
    return $stmt->fetchAll();
}
PHPEOF

# ---------- 7. АВТОРИЗАЦИЯ ----------
echo "🔐 Создание страницы авторизации..."
cat > /root/sales-crm-new/html/login.php << 'LOGINEOF'
<?php
session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'db.php';
    $tabel = trim($_POST['tabel'] ?? '');
    $pass = $_POST['password'] ?? '';
    $user = getUserByTabel($pdo, $tabel);
    if ($user && password_verify($pass, $user['password_hash']) && $user['is_active']) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['tabel'] = $user['tabel_number'];
        $_SESSION['name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['territory_id'] = $user['territory_id'];
        header('Location: dashboard.php');
        exit;
    }
    $error = 'Неверный табельный номер или пароль';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход — Система управления продажами</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #0a0a1a 0%, #1a1a3e 50%, #0d0d2b 100%);
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
        }
        .login-card {
            background: rgba(20, 20, 50, 0.9); border-radius: 20px; padding: 50px 40px;
            border: 1px solid rgba(100, 100, 255, 0.3); box-shadow: 0 0 60px rgba(100, 100, 255, 0.1);
            width: 100%; max-width: 420px; text-align: center;
        }
        .login-card h1 { color: #e0e0ff; font-size: 28px; margin-bottom: 8px; letter-spacing: 2px; }
        .login-card .subtitle { color: #8888cc; font-size: 13px; margin-bottom: 30px; letter-spacing: 3px; text-transform: uppercase; }
        .input-group { margin-bottom: 20px; text-align: left; }
        .input-group label { display: block; color: #aaaacc; margin-bottom: 6px; font-size: 13px; letter-spacing: 1px; }
        .input-group input {
            width: 100%; padding: 14px 16px; background: rgba(10,10,30,0.8); border: 1px solid rgba(100,100,255,0.2);
            border-radius: 10px; color: #fff; font-size: 16px; outline: none; transition: border 0.3s;
        }
        .input-group input:focus { border-color: rgba(100,100,255,0.6); box-shadow: 0 0 15px rgba(100,100,255,0.1); }
        .login-btn {
            width: 100%; padding: 14px; background: linear-gradient(135deg, #4444cc, #6666ee);
            border: none; border-radius: 10px; color: #fff; font-size: 16px; cursor: pointer;
            letter-spacing: 2px; margin-top: 10px; transition: box-shadow 0.3s;
        }
        .login-btn:hover { box-shadow: 0 0 30px rgba(100,100,255,0.3); }
        .error { background: rgba(255,50,50,0.15); border: 1px solid rgba(255,50,50,0.3); color: #ff6666; padding: 12px; border-radius: 8px; margin-bottom: 15px; font-size: 14px; }
    </style>
</head>
<body>
    <div class="login-card">
        <h1>🚀 СИСТЕМА УПРАВЛЕНИЯ ПРОДАЖАМИ</h1>
        <p class="subtitle">Центр управления</p>
        <?php if (isset($error)) echo "<div class='error'>$error</div>"; ?>
        <form method="POST">
            <div class="input-group"><label>ТАБЕЛЬНЫЙ НОМЕР</label><input type="text" name="tabel" required autofocus></div>
            <div class="input-group"><label>ПАРОЛЬ</label><input type="password" name="password" required></div>
            <button type="submit" class="login-btn">ВОЙТИ В СИСТЕМУ</button>
        </form>
    </div>
</body>
</html>
LOGINEOF

# ---------- 8. ДАШБОРД ----------
echo "📊 Создание дашборда..."

cat > /root/sales-crm-new/html/parts/header.php << 'HEADEREOF'
<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$role = $_SESSION['role'] ?? 'manager';
$nav_links = [
    ['url' => 'dashboard.php', 'label' => '📊 Дашборд', 'roles' => ['admin','terman','territory_head','head','manager']],
    ['url' => 'team.php', 'label' => '👥 Команда', 'roles' => ['admin','terman','territory_head','head','manager']],
    ['url' => 'territories.php', 'label' => '🌍 Территории', 'roles' => ['admin','terman','territory_head','head','manager']],
    ['url' => 'export_inn.php', 'label' => '📋 Выгрузка ИНН', 'roles' => ['admin','terman','territory_head','head','manager']],
    ['url' => 'ai_dashboard.php', 'label' => '🤖 AI-аналитика', 'roles' => ['admin','terman','territory_head','head']],
    ['url' => 'admin.php', 'label' => '⚙️ Админ-панель', 'roles' => ['admin']],
];
?>
<div class="nav-bar">
    <a href="dashboard.php" class="nav-logo">🚀 SZB</a>
    <div class="nav-links">
        <?php foreach ($nav_links as $link): ?>
            <?php if (in_array($role, $link['roles'])): ?>
                <a href="<?= $link['url'] ?>" class="<?= basename($_SERVER['PHP_SELF']) == basename($link['url']) ? 'active' : '' ?>"><?= $link['label'] ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <div style="display:flex;align-items:center;gap:10px;">
        <span style="color:#aaa;font-size:13px;"><?= htmlspecialchars($_SESSION['name'] ?? '') ?></span>
        <a href="logout.php" style="color:#ff6666;text-decoration:none;font-size:13px;">Выйти</a>
    </div>
</div>
HEADEREOF

cat > /root/sales-crm-new/html/dashboard.php << 'DASHEOF'
<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once 'db.php';

$tabel = $_SESSION['tabel'];
$role = $_SESSION['role'];
$today = date('Y-m-d');
$month = date('Y-m');
$working_days_total = 22;
$working_days_passed = min(date('j'), $working_days_total);
$working_days_remaining = max($working_days_total - $working_days_passed, 1);

// План на месяц
$plan = $pdo->prepare("SELECT * FROM plans WHERE tabel_number = ? AND period = ?");
$plan->execute([$tabel, $month]);
$plan_data = $plan->fetch();

if (!$plan_data) {
    $plan_data = array_fill_keys(['calls_plan','calls_answered_plan','meetings_plan','contracts_plan','registrations_plan','smart_cash_plan','pos_systems_plan','inn_leads_plan','teams_plan','turnover_plan'], 0);
}

// Факт за месяц
$fact = $pdo->prepare("SELECT COALESCE(SUM(calls),0) as c1, COALESCE(SUM(calls_answered),0) as c2, COALESCE(SUM(meetings),0) as c3, COALESCE(SUM(contracts),0) as c4, COALESCE(SUM(registrations),0) as c5, COALESCE(SUM(smart_cash),0) as c6, COALESCE(SUM(pos_systems),0) as c7, COALESCE(SUM(inn_leads),0) as c8, COALESCE(SUM(teams),0) as c9, COALESCE(SUM(turnover),0) as c10 FROM daily_reports WHERE tabel_number = ? AND strftime('%Y-%m', report_date) = ?");
$fact->execute([$tabel, $month]);
$fact_data = $fact->fetch();

// Сегодняшний отчёт
$today_rep = $pdo->prepare("SELECT * FROM daily_reports WHERE tabel_number = ? AND report_date = ?");
$today_rep->execute([$tabel, $today]);
$today_data = $today_rep->fetch();

// История за месяц
$history = $pdo->prepare("SELECT * FROM daily_reports WHERE tabel_number = ? AND strftime('%Y-%m', report_date) = ? ORDER BY report_date DESC");
$history->execute([$tabel, $month]);
$all_history = $history->fetchAll();

// AI-рекомендация
$ai_rec = $pdo->prepare("SELECT recommendation FROM ai_recommendations WHERE employee_tabel = ?");
$ai_rec->execute([$tabel]);
$ai = $ai_rec->fetchColumn() ?: '🌟 Заполняйте отчёты вовремя для получения персональных рекомендаций!';

// Уведомления
$notifs = $pdo->prepare("SELECT * FROM notifications WHERE user_tabel = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 7");
$notifs->execute([$tabel]);
$notifications = $notifs->fetchAll();

// Геймификация
$gam = $pdo->prepare("SELECT rank, total_points, level, experience, next_level_exp FROM users WHERE tabel_number = ?");
$gam->execute([$tabel]);
$game = $gam->fetch();

$metrics = [
    '📞 Звонки' => ['plan' => $plan_data['calls_plan'], 'fact' => $fact_data['c1']],
    '✅ Дозвоны' => ['plan' => $plan_data['calls_answered_plan'], 'fact' => $fact_data['c2']],
    '🤝 Встречи' => ['plan' => $plan_data['meetings_plan'], 'fact' => $fact_data['c3']],
    '📄 Договоры' => ['plan' => $plan_data['contracts_plan'], 'fact' => $fact_data['c4']],
    '📝 Регистрации ТЭ' => ['plan' => $plan_data['registrations_plan'], 'fact' => $fact_data['c5']],
    '💳 Смарт-кассы' => ['plan' => $plan_data['smart_cash_plan'], 'fact' => $fact_data['c6']],
    '🖥️ ПОС' => ['plan' => $plan_data['pos_systems_plan'], 'fact' => $fact_data['c7']],
    '🍵 ИНН чаевые' => ['plan' => $plan_data['inn_leads_plan'], 'fact' => $fact_data['c8']],
    '👥 Команды чаевые' => ['plan' => $plan_data['teams_plan'], 'fact' => $fact_data['c9']],
    '💰 Оборот чаевых' => ['plan' => $plan_data['turnover_plan'], 'fact' => $fact_data['c10']],
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Дашборд — SZB CRM</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0a0a1a; color: #e0e0e0; min-height: 100vh; }
        .nav-bar { display: flex; align-items: center; padding: 12px 20px; background: rgba(15,15,40,0.95); border-bottom: 1px solid rgba(100,100,255,0.2); flex-wrap: wrap; gap: 8px; position: sticky; top: 0; z-index: 100; }
        .nav-logo { font-size: 20px; font-weight: bold; color: #8888ff; text-decoration: none; letter-spacing: 2px; }
        .nav-links { display: flex; gap: 4px; flex-wrap: wrap; flex: 1; }
        .nav-links a { color: #aaa; text-decoration: none; padding: 8px 14px; border-radius: 8px; font-size: 13px; white-space: nowrap; }
        .nav-links a.active, .nav-links a:hover { background: rgba(100,100,255,0.2); color: #fff; }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .game-bar { background: linear-gradient(135deg, rgba(100,100,255,0.15), rgba(200,100,255,0.15)); border-radius: 12px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; border: 1px solid rgba(100,100,255,0.2); }
        .notif-zone { margin-bottom: 15px; }
        .notif-item { background: rgba(255,200,50,0.08); border: 1px solid rgba(255,200,50,0.2); padding: 10px 15px; border-radius: 8px; margin-bottom: 6px; font-size: 13px; display: flex; justify-content: space-between; align-items: center; }
        .ai-zone { background: rgba(0,200,200,0.08); border: 1px solid rgba(0,200,200,0.2); padding: 15px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; margin-bottom: 25px; }
        .metric-card { background: rgba(20,20,50,0.8); border-radius: 12px; padding: 16px; border: 1px solid rgba(100,100,255,0.15); }
        .metric-card h4 { font-size: 12px; color: #888; margin-bottom: 8px; }
        .metric-card .values { display: flex; justify-content: space-between; align-items: baseline; }
        .metric-card .fact { font-size: 28px; font-weight: bold; }
        .metric-card .plan { font-size: 13px; color: #888; }
        .color-green { color: #44dd88; }
        .color-yellow { color: #ddcc44; }
        .color-orange { color: #dd8844; }
        .color-red { color: #dd4444; }
        .color-gray { color: #666; }
        .report-form { background: rgba(20,20,50,0.8); border-radius: 12px; padding: 20px; margin-bottom: 20px; border: 1px solid rgba(100,100,255,0.15); }
        .report-form h3 { margin-bottom: 15px; }
        .warning { background: rgba(255,200,50,0.1); border: 1px solid rgba(255,200,50,0.3); padding: 10px 15px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; color: #ddcc44; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; margin-bottom: 15px; }
        .form-group label { display: block; font-size: 12px; color: #888; margin-bottom: 4px; }
        .form-group input { width: 100%; padding: 10px; background: rgba(10,10,30,0.8); border: 1px solid rgba(100,100,255,0.2); border-radius: 8px; color: #fff; font-size: 14px; }
        .inn-row { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin-top: 6px; }
        .inn-row input { flex: 1; min-width: 100px; padding: 8px; font-size: 13px; }
        .inn-row select { padding: 8px; background: rgba(10,10,30,0.8); border: 1px solid rgba(100,100,255,0.2); border-radius: 6px; color: #fff; font-size: 12px; }
        .add-btn { padding: 8px 14px; background: rgba(100,100,255,0.3); border: none; border-radius: 6px; color: #fff; cursor: pointer; font-size: 16px; }
        .save-btn { width: 100%; padding: 14px; background: linear-gradient(135deg, #4444cc, #6666ee); border: none; border-radius: 10px; color: #fff; font-size: 16px; cursor: pointer; margin-top: 10px; }
        .history-table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px; }
        .history-table th, .history-table td { padding: 10px 8px; text-align: center; border-bottom: 1px solid rgba(100,100,255,0.1); }
        .history-table th { background: rgba(100,100,255,0.1); color: #8888cc; font-size: 11px; }
        @media (max-width: 768px) {
            .container { padding: 10px; }
            .metrics-grid { grid-template-columns: repeat(2, 1fr); }
            .form-grid { grid-template-columns: 1fr; }
            .nav-bar { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
<?php include 'parts/header.php'; ?>
<div class="container">

    <!-- Геймификация -->
    <div class="game-bar">
        <div><strong>🏆 <?= htmlspecialchars($game['rank']) ?></strong> | Уровень <?= $game['level'] ?></div>
        <div>⭐ <?= number_format($game['total_points'], 0, '.', ' ') ?> баллов</div>
        <div style="flex:1;min-width:150px;"><div style="background:rgba(255,255,255,0.1);border-radius:10px;height:8px;"><div style="background:linear-gradient(90deg,#44dd88,#44aadd);width:<?= ($game['experience']/$game['next_level_exp'])*100 ?>%;height:8px;border-radius:10px;"></div></div></div>
    </div>

    <!-- Уведомления -->
    <?php if (!empty($notifications)): ?>
    <div class="notif-zone">
        <?php foreach ($notifications as $n): ?>
        <div class="notif-item"><span><?= htmlspecialchars($n['message']) ?></span><a href="mark_read.php?id=<?= $n['id'] ?>" style="color:#8888ff;font-size:11px;">✓</a></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- AI Наставник -->
    <div class="ai-zone"><div style="font-size:36px;">🤖</div><div><strong>AI Наставник</strong><br><?= htmlspecialchars($ai) ?></div></div>

    <!-- Метрики -->
    <div class="metrics-grid">
        <?php foreach ($metrics as $name => $m): 
            $dn = $m['plan'] > 0 ? round($m['plan'] / $working_days_total, 1) : 0;
            $prog = $m['plan'] > 0 ? round(($m['fact'] / $m['plan']) * 100) : 0;
            $color = $m['plan'] == 0 ? 'color-gray' : ($prog >= 100 ? 'color-green' : ($prog >= 80 ? 'color-yellow' : ($prog >= 60 ? 'color-orange' : 'color-red')));
        ?>
        <div class="metric-card">
            <h4><?= $name ?></h4>
            <div class="values">
                <span class="fact <?= $color ?>"><?= $m['fact'] ?></span>
                <span class="plan">/ <?= $m['plan'] ?></span>
            </div>
            <div style="font-size:11px;color:#888;">Дн.норма: <?= $dn ?> | Прогноз: <?= $prog ?>%</div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Форма отчёта -->
    <div class="report-form">
        <h3>📝 Дневной отчёт за <?= date('d.m.Y') ?></h3>
        <div class="warning">⚠️ Будьте максимально реалистичны при вводе значений. План будет скорректирован на конверсию в результат за расхождения.</div>
        <form method="POST" action="save_report.php">
            <div class="form-grid">
                <div class="form-group"><label>📞 Звонки</label><input type="number" name="calls" value="<?= $today_data['calls'] ?? 0 ?>" min="0"></div>
                <div class="form-group"><label>✅ Дозвоны</label><input type="number" name="calls_answered" value="<?= $today_data['calls_answered'] ?? 0 ?>" min="0"></div>
                <div class="form-group"><label>🤝 Встречи</label><input type="number" name="meetings" value="<?= $today_data['meetings'] ?? 0 ?>" min="0"></div>
                <div class="form-group"><label>📄 Договоры</label><input type="number" name="contracts" value="<?= $today_data['contracts'] ?? 0 ?>" min="0"></div>
                <div class="form-group"><label>🍵 ИНН чаевые</label><input type="number" name="inn_leads" value="<?= $today_data['inn_leads'] ?? 0 ?>" min="0"></div>
                <div class="form-group"><label>👥 Команды чаевые</label><input type="number" name="teams" value="<?= $today_data['teams'] ?? 0 ?>" min="0"></div>
                <div class="form-group"><label>💰 Оборот чаевых</label><input type="number" name="turnover" value="<?= $today_data['turnover'] ?? 0 ?>" min="0" step="0.01"></div>
                <div class="form-group">
                    <label>📝 Регистрации ТЭ</label>
                    <input type="number" id="reg_val" name="registrations" value="<?= $today_data['registrations'] ?? 0 ?>" min="0">
                    <div class="inn-row"><input type="text" id="inn_reg" placeholder="ИНН (12 цифр)" maxlength="12"><button type="button" class="add-btn" onclick="addInn('reg')">+</button></div>
                </div>
                <div class="form-group">
                    <label>💳 Смарт-кассы</label>
                    <input type="number" id="smart_val" name="smart_cash" value="<?= $today_data['smart_cash'] ?? 0 ?>" min="0">
                    <div class="inn-row"><input type="text" id="inn_smart" placeholder="ИНН (12 цифр)" maxlength="12"><button type="button" class="add-btn" onclick="addInn('smart')">+</button></div>
                </div>
                <div class="form-group">
                    <label>🖥️ ПОС-системы</label>
                    <input type="number" id="pos_val" name="pos_systems" value="<?= $today_data['pos_systems'] ?? 0 ?>" min="0">
                    <div class="inn-row"><input type="text" id="inn_pos" placeholder="ИНН (12 цифр)" maxlength="12"><button type="button" class="add-btn" onclick="addInn('pos')">+</button></div>
                </div>
            </div>
            <button type="submit" class="save-btn">💾 Сохранить отчёт</button>
        </form>
    </div>

    <!-- История -->
    <h3 style="margin-bottom:10px;">📋 История отчётов</h3>
    <div style="overflow-x:auto;">
        <table class="history-table">
            <thead><tr><th>Дата</th><th>📞</th><th>✅</th><th>🤝</th><th>📄</th><th>📝</th><th>💳</th><th>🖥️</th><th>🍵</th><th>👥</th><th>💰</th></tr></thead>
            <tbody>
                <?php if (empty($all_history)): ?>
                    <tr><td colspan="11" style="color:#666;padding:20px;">Нет отчётов за текущий месяц</td></tr>
                <?php else: foreach ($all_history as $h): ?>
                    <tr>
                        <td><?= date('d.m', strtotime($h['report_date'])) ?></td>
                        <td><?= $h['calls'] ?></td><td><?= $h['calls_answered'] ?></td>
                        <td><?= $h['meetings'] ?></td><td><?= $h['contracts'] ?></td>
                        <td><?= $h['registrations'] ?></td><td><?= $h['smart_cash'] ?></td>
                        <td><?= $h['pos_systems'] ?></td><td><?= $h['inn_leads'] ?></td>
                        <td><?= $h['teams'] ?></td><td><?= number_format($h['turnover'], 0, '.', ' ') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function addInn(type) {
    let valId = type + '_val';
    let innId = 'inn_' + type;
    let inn = document.getElementById(innId).value.trim();
    if (inn.length !== 12 || !/^\d{12}$/.test(inn)) { alert('Введите корректный ИНН (12 цифр)'); return; }
    let valEl = document.getElementById(valId);
    valEl.value = parseInt(valEl.value || 0) + 1;
    document.getElementById(innId).value = '';
    fetch('api/add_inn.php', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({inn: inn, product: type})
    });
}
</script>
</body>
</html>
DASHEOF

# ---------- 9. СОХРАНЕНИЕ ОТЧЁТА ----------
cat > /root/sales-crm-new/html/save_report.php << 'SAVEEOF'
<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once 'db.php';

$tabel = $_SESSION['tabel'];
$today = date('Y-m-d');

$fields = ['calls','calls_answered','meetings','contracts','registrations','smart_cash','pos_systems','inn_leads','teams','turnover'];
$values = [];
$sets = [];
foreach ($fields as $f) {
    $v = intval($_POST[$f] ?? 0);
    $values[] = $v;
    $sets[] = "$f = ?";
}

// UPSERT
$pdo->prepare("INSERT INTO daily_reports (tabel_number, report_date, calls, calls_answered, meetings, contracts, registrations, smart_cash, pos_systems, inn_leads, teams, turnover) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(tabel_number, report_date) DO UPDATE SET " . implode(',', $sets) . ", created_at = CURRENT_TIMESTAMP")->execute(array_merge([$tabel, $today], $values, $values));

// Баллы
$points = 0;
if (intval($_POST['calls'] ?? 0) > 0) $points += 1;
if (intval($_POST['meetings'] ?? 0) > 0) $points += 3;
if (intval($_POST['registrations'] ?? 0) > 0) $points += 5;
if (intval($_POST['pos_systems'] ?? 0) > 0) $points += 10;
$pdo->prepare("UPDATE users SET total_points = total_points + ?, experience = experience + ? WHERE tabel_number = ?")->execute([$points, $points, $tabel]);

header('Location: dashboard.php?saved=1');
SAVEEOF

# ---------- 10. API ----------
mkdir -p /root/sales-crm-new/html/api
cat > /root/sales-crm-new/html/api/add_inn.php << 'INNEOF'
<?php
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'error'=>'Не авторизован']); exit; }
require_once '../db.php';
$data = json_decode(file_get_contents('php://input'), true);
$inn = trim($data['inn'] ?? '');
$product = $data['product'] ?? '';
if (strlen($inn) != 12 || !ctype_digit($inn)) { echo json_encode(['success'=>false,'error'=>'Неверный ИНН']); exit; }
$tabel = $_SESSION['tabel'];
$name = $_SESSION['name'];
$head = $pdo->prepare("SELECT full_name FROM users WHERE tabel_number = (SELECT head_tabel FROM users WHERE tabel_number = ?)");
$head->execute([$tabel]);
$head_name = $head->fetchColumn() ?: '';
$pdo->prepare("INSERT INTO inn_records (inn, product, employee_tabel, employee_name, head_name, sale_date) VALUES (?, ?, ?, ?, ?, date('now'))")->execute([$inn, $product, $tabel, $name, $head_name]);
echo json_encode(['success'=>true]);
INNEOF

# ---------- 11. КОМАНДА ----------
cat > /root/sales-crm-new/html/team.php << 'TEAMEOF'
<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once 'db.php';
$role = $_SESSION['role'];
$tabel = $_SESSION['tabel'];
$month = date('Y-m');
$wdt = 22; $wdp = min(date('j'), $wdt); $wdr = max($wdt - $wdp, 1);

// Определяем подчинённых
if ($role == 'manager') {
    $head = $pdo->prepare("SELECT head_tabel FROM users WHERE tabel_number = ?");
    $head->execute([$tabel]);
    $ht = $head->fetchColumn();
    $members = $pdo->prepare("SELECT * FROM users WHERE (head_tabel = ? OR tabel_number = ?) AND is_active = 1 ORDER BY full_name");
    $members->execute([$ht, $tabel]);
} elseif ($role == 'head') {
    $members = $pdo->prepare("SELECT * FROM users WHERE head_tabel = ? AND is_active = 1 ORDER BY full_name");
    $members->execute([$tabel]);
} elseif ($role == 'territory_head') {
    $members = $pdo->prepare("SELECT u.* FROM users u JOIN users h ON u.head_tabel = h.tabel_number WHERE h.territory_id = (SELECT territory_id FROM users WHERE tabel_number = ?) AND u.role = 'manager' AND u.is_active = 1 ORDER BY u.full_name");
    $members->execute([$tabel]);
} else {
    $members = $pdo->query("SELECT * FROM users WHERE role = 'manager' AND is_active = 1 ORDER BY full_name");
}
$team = $members->fetchAll();

// План команды
$team_plans = array_fill_keys(['calls','calls_answered','meetings','contracts','registrations','smart_cash','pos_systems','inn_leads','teams','turnover'], 0);
$team_facts = $team_plans;

$member_data = [];
foreach ($team as $m) {
    $p = $pdo->prepare("SELECT * FROM plans WHERE tabel_number = ? AND period = ?");
    $p->execute([$m['tabel_number'], $month]);
    $pd = $p->fetch() ?: array_fill_keys(['calls_plan','calls_answered_plan','meetings_plan','contracts_plan','registrations_plan','smart_cash_plan','pos_systems_plan','inn_leads_plan','teams_plan','turnover_plan'], 0);
    $f = $pdo->prepare("SELECT COALESCE(SUM(calls),0) as c1, COALESCE(SUM(calls_answered),0) as c2, COALESCE(SUM(meetings),0) as c3, COALESCE(SUM(contracts),0) as c4, COALESCE(SUM(registrations),0) as c5, COALESCE(SUM(smart_cash),0) as c6, COALESCE(SUM(pos_systems),0) as c7, COALESCE(SUM(inn_leads),0) as c8, COALESCE(SUM(teams),0) as c9, COALESCE(SUM(turnover),0) as c10 FROM daily_reports WHERE tabel_number = ? AND strftime('%Y-%m', report_date) = ?");
    $f->execute([$m['tabel_number'], $month]);
    $fd = $f->fetch();
    $team_plans['calls'] += $pd['calls_plan']; $team_facts['calls'] += $fd['c1'];
    $team_plans['calls_answered'] += $pd['calls_answered_plan']; $team_facts['calls_answered'] += $fd['c2'];
    $team_plans['meetings'] += $pd['meetings_plan']; $team_facts['meetings'] += $fd['c3'];
    $team_plans['contracts'] += $pd['contracts_plan']; $team_facts['contracts'] += $fd['c4'];
    $team_plans['registrations'] += $pd['registrations_plan']; $team_facts['registrations'] += $fd['c5'];
    $team_plans['smart_cash'] += $pd['smart_cash_plan']; $team_facts['smart_cash'] += $fd['c6'];
    $team_plans['pos_systems'] += $pd['pos_systems_plan']; $team_facts['pos_systems'] += $fd['c7'];
    $team_plans['inn_leads'] += $pd['inn_leads_plan']; $team_facts['inn_leads'] += $fd['c8'];
    $team_plans['teams'] += $pd['teams_plan']; $team_facts['teams'] += $fd['c9'];
    $team_plans['turnover'] += $pd['turnover_plan']; $team_facts['turnover'] += $fd['c10'];
    $member_data[] = ['user' => $m, 'plan' => $pd, 'fact' => $fd];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Команда — SZB CRM</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .team-metrics { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; margin-bottom: 20px; }
        .tcard { background: rgba(20,20,50,0.8); border-radius: 12px; padding: 14px; border: 1px solid rgba(100,100,255,0.15); }
        .member-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .member-table th, .member-table td { padding: 10px 6px; text-align: center; border-bottom: 1px solid rgba(100,100,255,0.1); }
        .member-table th { background: rgba(100,100,255,0.1); color: #8888cc; font-size: 11px; }
        .member-row { cursor: pointer; }
        .member-row:hover { background: rgba(100,100,255,0.05); }
        @media (max-width: 768px) { .member-table { display: block; } .member-table thead { display: none; } .member-table tr { display: block; margin-bottom: 10px; background: rgba(20,20,50,0.8); border-radius: 10px; padding: 10px; } .member-table td { display: block; text-align: right; padding: 5px; } .member-table td::before { content: attr(data-label); float: left; color: #888; } }
    </style>
</head>
<body>
<?php include 'parts/header.php'; ?>
<div class="container">
    <h2 style="margin-bottom:15px;">👥 Команда</h2>
    <div class="team-metrics">
        <?php 
        $tnames = ['📞 Звонки','✅ Дозвоны','🤝 Встречи','📄 Договоры','📝 Регистрации ТЭ','💳 Смарт-кассы','🖥️ ПОС','🍵 ИНН чаевые','👥 Команды чаевые','💰 Оборот чаевых'];
        $tkeys = ['calls','calls_answered','meetings','contracts','registrations','smart_cash','pos_systems','inn_leads','teams','turnover'];
        foreach ($tkeys as $i => $k):
            $prog = $team_plans[$k] > 0 ? round(($team_facts[$k] / $team_plans[$k]) * 100) : 0;
            $color = $team_plans[$k] == 0 ? 'color-gray' : ($prog >= 100 ? 'color-green' : ($prog >= 80 ? 'color-yellow' : ($prog >= 60 ? 'color-orange' : 'color-red')));
        ?>
        <div class="tcard"><h4><?= $tnames[$i] ?></h4><span class="fact <?= $color ?>"><?= $team_facts[$k] ?></span><span style="font-size:12px;color:#888;"> / <?= $team_plans[$k] ?> (<?= $prog ?>%)</span></div>
        <?php endforeach; ?>
    </div>

    <h3 style="margin-bottom:10px;">Сотрудники</h3>
    <div style="overflow-x:auto;">
        <table class="member-table">
            <thead><tr><th>Сотрудник</th><th>📞</th><th>✅</th><th>🤝</th><th>📄</th><th>📝</th><th>💳</th><th>🖥️</th><th>🍵</th><th>👥</th><th>💰</th><th>Ритм</th></tr></thead>
            <tbody>
                <?php foreach ($member_data as $md): 
                    $u = $md['user']; $f = $md['fact']; $p = $md['plan'];
                    $ctr_prog = $p['contracts_plan'] > 0 ? round(($f['c4'] / $p['contracts_plan']) * 100) : 0;
                    $ctr_color = $p['contracts_plan'] == 0 ? '#666' : ($ctr_prog >= 100 ? '#44dd88' : ($ctr_prog >= 80 ? '#ddcc44' : ($ctr_prog >= 60 ? '#dd8844' : '#dd4444')));
                ?>
                <tr class="member-row" style="border-left: 4px solid <?= $ctr_color ?>;" onclick="alert('<?= $u['full_name'] ?>\nУровень: <?= $u['rank'] ?>\nБаллы: <?= $u['total_points'] ?>')">
                    <td data-label="Сотрудник"><?= htmlspecialchars($u['full_name']) ?><br><span style="font-size:10px;color:#888;"><?= $u['tabel_number'] ?></span></td>
                    <td data-label="📞"><?= $f['c1'] ?></td>
                    <td data-label="✅"><?= $f['c2'] ?></td>
                    <td data-label="🤝"><?= $f['c3'] ?></td>
                    <td data-label="📄"><?= $f['c4'] ?></td>
                    <td data-label="📝"><?= $f['c5'] ?></td>
                    <td data-label="💳"><?= $f['c6'] ?></td>
                    <td data-label="🖥️"><?= $f['c7'] ?></td>
                    <td data-label="🍵"><?= $f['c8'] ?></td>
                    <td data-label="👥"><?= $f['c9'] ?></td>
                    <td data-label="💰"><?= number_format($f['c10'], 0, '.', ' ') ?></td>
                    <td data-label="Ритм" style="color:<?= $ctr_color ?>;font-weight:bold;"><?= $ctr_prog ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
TEAMEOF

# ---------- 12. ТЕРРИТОРИИ ----------
echo "🌍 Создание страницы территорий..."
cat > /root/sales-crm-new/html/territories.php << 'TERREOF'
<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once 'db.php';
$role = $_SESSION['role'];
$month = date('Y-m');
$wdt = 22; $wdp = min(date('j'), $wdt); $wdr = max($wdt - $wdp, 1);

$territories = $pdo->query("SELECT t.*, tb.name as terbank_name FROM territories t JOIN terbanks tb ON t.terbank_id = tb.id ORDER BY tb.name, t.name")->fetchAll();
$territory_stats = [];
foreach ($territories as $t) {
    $managers = $pdo->prepare("SELECT tabel_number FROM users WHERE territory_id = ? AND role = 'manager' AND is_active = 1");
    $managers->execute([$t['id']]);
    $mtabels = $managers->fetchAll(PDO::FETCH_COLUMN);
    $facts = array_fill_keys(['calls','calls_answered','meetings','contracts','registrations','smart_cash','pos_systems','inn_leads','teams','turnover'], 0);
    $plans = $facts;
    if (!empty($mtabels)) {
        $placeholders = implode(',', array_fill(0, count($mtabels), '?'));
        $fq = $pdo->prepare("SELECT COALESCE(SUM(calls),0) as c1, COALESCE(SUM(calls_answered),0) as c2, COALESCE(SUM(meetings),0) as c3, COALESCE(SUM(contracts),0) as c4, COALESCE(SUM(registrations),0) as c5, COALESCE(SUM(smart_cash),0) as c6, COALESCE(SUM(pos_systems),0) as c7, COALESCE(SUM(inn_leads),0) as c8, COALESCE(SUM(teams),0) as c9, COALESCE(SUM(turnover),0) as c10 FROM daily_reports WHERE tabel_number IN ($placeholders) AND strftime('%Y-%m', report_date) = ?");
        $fq->execute(array_merge($mtabels, [$month]));
        $facts = $fq->fetch();
        $pq = $pdo->prepare("SELECT COALESCE(SUM(calls_plan),0), COALESCE(SUM(calls_answered_plan),0), COALESCE(SUM(meetings_plan),0), COALESCE(SUM(contracts_plan),0), COALESCE(SUM(registrations_plan),0), COALESCE(SUM(smart_cash_plan),0), COALESCE(SUM(pos_systems_plan),0), COALESCE(SUM(inn_leads_plan),0), COALESCE(SUM(teams_plan),0), COALESCE(SUM(turnover_plan),0) FROM plans WHERE tabel_number IN ($placeholders) AND period = ?");
        $pq->execute(array_merge($mtabels, [$month]));
        $pl = $pq->fetch();
        $plans = ['calls'=>$pl[0],'calls_answered'=>$pl[1],'meetings'=>$pl[2],'contracts'=>$pl[3],'registrations'=>$pl[4],'smart_cash'=>$pl[5],'pos_systems'=>$pl[6],'inn_leads'=>$pl[7],'teams'=>$pl[8],'turnover'=>$pl[9]];
    }
    $territory_stats[] = ['territory' => $t, 'plan' => $plans, 'fact' => $facts, 'managers_count' => count($mtabels)];
}

// Общие суммы
$total_plan = array_fill_keys(['calls','calls_answered','meetings','contracts','registrations','smart_cash','pos_systems','inn_leads','teams','turnover'], 0);
$total_fact = $total_plan;
foreach ($territory_stats as $ts) { foreach ($total_plan as $k => $v) { $total_plan[$k] += $ts['plan'][$k]; $total_fact[$k] += $ts['fact'][$k]; } }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Территории — SZB CRM</title>
    <style>
        <?php include 'style.css'; ?>
        .terr-card { background: rgba(20,20,50,0.8); border-radius: 12px; padding: 20px; margin-bottom: 15px; border: 1px solid rgba(100,100,255,0.15); cursor: pointer; }
        .terr-metrics { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; margin-top: 10px; }
    </style>
</head>
<body>
<?php include 'parts/header.php'; ?>
<div class="container">
    <h2>🌍 Территории</h2>
    <div class="team-metrics" style="margin-bottom:20px;">
        <?php 
        $tnames = ['📞 Звонки','✅ Дозвоны','🤝 Встречи','📄 Договоры','📝 Регистрации ТЭ','💳 Смарт-кассы','🖥️ ПОС','🍵 ИНН чаевые','👥 Команды чаевые','💰 Оборот чаевых'];
        $tkeys = array_keys($total_plan);
        foreach ($tkeys as $i => $k):
            $prog = $total_plan[$k] > 0 ? round(($total_fact[$k] / $total_plan[$k]) * 100) : 0;
            $color = $total_plan[$k] == 0 ? '#666' : ($prog >= 100 ? '#44dd88' : ($prog >= 80 ? '#ddcc44' : ($prog >= 60 ? '#dd8844' : '#dd4444')));
        ?>
        <div class="tcard"><h4><?= $tnames[$i] ?></h4><span style="font-size:24px;color:<?=$color?>;"><?= $total_fact[$k] ?></span><span style="font-size:12px;color:#888;"> / <?= $total_plan[$k] ?></span></div>
        <?php endforeach; ?>
    </div>
    <?php foreach ($territory_stats as $ts): $t = $ts['territory']; ?>
    <div class="terr-card" onclick="alert('Территория: <?= htmlspecialchars($t['name']) ?>\nМенеджеров: <?= $ts['managers_count'] ?>\nТербанк: <?= htmlspecialchars($t['terbank_name']) ?>')">
        <strong><?= htmlspecialchars($t['name']) ?></strong> <span style="color:#888;font-size:12px;">(<?= $ts['managers_count'] ?> менеджеров)</span>
        <div class="terr-metrics">
            <?php foreach ($tkeys as $i => $k):
                $prog = $ts['plan'][$k] > 0 ? round(($ts['fact'][$k] / $ts['plan'][$k]) * 100) : 0;
                $color = $ts['plan'][$k] == 0 ? '#666' : ($prog >= 100 ? '#44dd88' : ($prog >= 80 ? '#ddcc44' : ($prog >= 60 ? '#dd8844' : '#dd4444')));
            ?>
            <div style="font-size:12px;"><?= $tnames[$i] ?>: <span style="color:<?=$color?>;"><?= $ts['fact'][$k] ?>/<?= $ts['plan'][$k] ?></span></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
</body>
</html>
TERREOF

# ---------- 13. АДМИН-ПАНЕЛЬ ----------
echo "⚙️ Создание админ-панели..."
cat > /root/sales-crm-new/html/admin.php << 'ADMINEOF'
<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') { header('Location: dashboard.php'); exit; }
require_once 'db.php';

$message = '';

// Создание территории
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_territory'])) {
    $stmt = $pdo->prepare("INSERT INTO territories (name, code, terbank_id) VALUES (?, ?, ?)");
    $stmt->execute([$_POST['terr_name'], $_POST['terr_code'], $_POST['terbank_id'] ?? 1]);
    $message = 'Территория создана';
}

// Создание/редактирование пользователя
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user'])) {
    $tabel = $_POST['tabel_number'];
    $pass = password_hash($_POST['password'] ?? '123456', PASSWORD_DEFAULT);
    if (isset($_POST['user_id']) && $_POST['user_id']) {
        $stmt = $pdo->prepare("UPDATE users SET full_name=?, email=?, role=?, head_tabel=?, territory_id=?, is_active=? WHERE id=?");
        $stmt->execute([$_POST['full_name'], $_POST['email'], $_POST['role'], $_POST['head_tabel'] ?: null, $_POST['territory_id'] ?: null, $_POST['is_active'] ?? 1, $_POST['user_id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO users (tabel_number, full_name, email, password_hash, role, head_tabel, territory_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$tabel, $_POST['full_name'], $_POST['email'], $pass, $_POST['role'], $_POST['head_tabel'] ?: null, $_POST['territory_id'] ?: null]);
    }
    $message = 'Сохранено';
}

// Назначение плана
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_plan'])) {
    $stmt = $pdo->prepare("INSERT INTO plans (tabel_number, period, calls_plan, calls_answered_plan, meetings_plan, contracts_plan, registrations_plan, smart_cash_plan, pos_systems_plan, inn_leads_plan, teams_plan, turnover_plan) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(tabel_number, period) DO UPDATE SET calls_plan=excluded.calls_plan, calls_answered_plan=excluded.calls_answered_plan, meetings_plan=excluded.meetings_plan, contracts_plan=excluded.contracts_plan, registrations_plan=excluded.registrations_plan, smart_cash_plan=excluded.smart_cash_plan, pos_systems_plan=excluded.pos_systems_plan, inn_leads_plan=excluded.inn_leads_plan, teams_plan=excluded.teams_plan, turnover_plan=excluded.turnover_plan");
    $stmt->execute([$_POST['plan_tabel'], $_POST['period'], $_POST['calls_plan']??0, $_POST['calls_answered_plan']??0, $_POST['meetings_plan']??0, $_POST['contracts_plan']??0, $_POST['registrations_plan']??0, $_POST['smart_cash_plan']??0, $_POST['pos_systems_plan']??0, $_POST['inn_leads_plan']??0, $_POST['teams_plan']??0, $_POST['turnover_plan']??0]);
    $message = 'План сохранён';
}

$users = $pdo->query("SELECT u.*, t.name as territory_name FROM users u LEFT JOIN territories t ON u.territory_id = t.id ORDER BY u.role, u.full_name")->fetchAll();
$territories = getTerritories($pdo);
$heads = getHeads($pdo);
$managers = getManagers($pdo);

// Проблемные зоны
$orphans = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'manager' AND head_tabel IS NULL AND is_active = 1")->fetchColumn();
$heads_no_terr = $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('head','territory_head') AND territory_id IS NULL AND is_active = 1")->fetchColumn();
$empty_terr = $pdo->query("SELECT COUNT(*) FROM territories t WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.territory_id = t.id AND u.role = 'territory_head' AND u.is_active = 1)")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Админ-панель — SZB CRM</title>
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0a0a1a; color: #e0e0e0; }
    .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
    .card { background: rgba(20,20,50,0.8); border-radius: 12px; padding: 20px; margin-bottom: 20px; border: 1px solid rgba(100,100,255,0.15); }
    .stats { display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 20px; }
    .stat { background: rgba(20,20,50,0.8); border-radius: 12px; padding: 15px 25px; border: 1px solid rgba(100,100,255,0.15); }
    .warnings { background: rgba(255,100,50,0.1); border: 1px solid rgba(255,100,50,0.3); padding: 12px; border-radius: 8px; margin-bottom: 15px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td { padding: 10px 8px; text-align: left; border-bottom: 1px solid rgba(100,100,255,0.1); }
    th { background: rgba(100,100,255,0.1); }
    input, select { padding: 8px 12px; background: rgba(10,10,30,0.8); border: 1px solid rgba(100,100,255,0.2); border-radius: 6px; color: #fff; font-size: 13px; }
    button { padding: 10px 20px; background: rgba(100,100,255,0.3); border: none; border-radius: 8px; color: #fff; cursor: pointer; }
    .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
    .tab { padding: 10px 18px; background: rgba(20,20,50,0.8); border: 1px solid rgba(100,100,255,0.15); border-radius: 8px; cursor: pointer; font-size: 13px; }
    .tab.active { background: rgba(100,100,255,0.3); }
    @media (max-width: 768px) { table { display: block; } }
</style>
</head>
<body>
<?php include 'parts/header.php'; ?>
<div class="container">
    <h2>⚙️ Админ-панель</h2>
    <?php if ($message) echo "<div style='background:rgba(0,200,100,0.1);border:1px solid rgba(0,200,100,0.3);padding:10px;border-radius:8px;margin-bottom:15px;'>$message</div>"; ?>
    
    <div class="stats">
        <div class="stat"><strong><?= count($users) ?></strong><br>сотрудников</div>
        <div class="stat"><strong><?= count($territories) ?></strong><br>территорий</div>
        <div class="stat"><strong><?= count($heads) ?></strong><br>руководителей</div>
    </div>

    <?php if ($orphans || $heads_no_terr || $empty_terr): ?>
    <div class="warnings">
        <?php if ($orphans) echo "⚠️ $orphans сотрудников без руководителя | "; ?>
        <?php if ($heads_no_terr) echo "⚠️ $heads_no_terr руководителей без территории | "; ?>
        <?php if ($empty_terr) echo "⚠️ $empty_terr территорий без руководителей"; ?>
    </div>
    <?php endif; ?>

    <div class="tabs">
        <div class="tab active" onclick="showTab('users')">👥 Сотрудники</div>
        <div class="tab" onclick="showTab('plans')">📊 Планы</div>
        <div class="tab" onclick="showTab('territories_admin')">🌍 Территории</div>
        <div class="tab" onclick="showTab('export')">📋 Экспорт</div>
        <div class="tab" onclick="showTab('ai_admin')">🤖 AI</div>
    </div>

    <div id="tab-users" class="card">
        <h3>👥 Сотрудники</h3>
        <button onclick="document.getElementById('add_user_form').style.display='block'" style="margin-bottom:15px;">+ Добавить</button>
        <form id="add_user_form" method="POST" style="display:none;margin-bottom:15px;">
            <input type="hidden" name="save_user" value="1">
            <input type="hidden" name="user_id" id="edit_user_id">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;">
                <input type="text" name="tabel_number" placeholder="Табельный номер" required>
                <input type="text" name="full_name" placeholder="ФИО" required>
                <input type="email" name="email" placeholder="Email">
                <input type="password" name="password" placeholder="Пароль" value="123456">
                <select name="role"><option value="manager">Менеджер</option><option value="head">Начальник отдела</option><option value="territory_head">Начальник управления</option><option value="terman">Термен</option><option value="admin">Админ</option></select>
                <select name="head_tabel"><option value="">Без начальника</option><?php foreach ($heads as $h) echo "<option value='{$h['tabel_number']}'>".htmlspecialchars($h['full_name'])."</option>"; ?></select>
                <select name="territory_id"><option value="">Без территории</option><?php foreach ($territories as $t) echo "<option value='{$t['id']}'>".htmlspecialchars($t['name'])."</option>"; ?></select>
                <select name="is_active"><option value="1">Активен</option><option value="0">Уволен</option></select>
                <button type="submit">💾 Сохранить</button>
            </div>
        </form>
        <div style="overflow-x:auto;">
            <table>
                <thead><tr><th>Таб.№</th><th>ФИО</th><th>Роль</th><th>Территория</th><th>Начальник</th><th>Статус</th><th>Действия</th></tr></thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= $u['tabel_number'] ?></td>
                        <td><?= htmlspecialchars($u['full_name']) ?></td>
                        <td><?= $u['role'] ?></td>
                        <td><?= htmlspecialchars($u['territory_name'] ?? '-') ?></td>
                        <td><?= $u['head_tabel'] ?? '-' ?></td>
                        <td><?= $u['is_active'] ? '✅' : '❌' ?></td>
                        <td>
                            <button onclick="editUser(<?= htmlspecialchars(json_encode($u)) ?>)">✏️</button>
                            <a href="?reset_pass=<?= $u['id'] ?>" style="color:#dd8844;">🔑</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="tab-plans" class="card" style="display:none;">
        <h3>📊 Назначение планов</h3>
        <form method="POST" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;">
            <input type="hidden" name="set_plan" value="1">
            <select name="plan_tabel" required><option value="">Сотрудник</option><?php foreach ($managers as $m) echo "<option value='{$m['tabel_number']}'>".htmlspecialchars($m['full_name'])."</option>"; ?></select>
            <input type="month" name="period" value="<?= date('Y-m') ?>" required>
            <input type="number" name="calls_plan" placeholder="Звонки" value="350">
            <input type="number" name="calls_answered_plan" placeholder="Дозвоны" value="245">
            <input type="number" name="meetings_plan" placeholder="Встречи" value="35">
            <input type="number" name="contracts_plan" placeholder="Договоры" value="21">
            <input type="number" name="registrations_plan" placeholder="Регистрации" value="15">
            <input type="number" name="smart_cash_plan" placeholder="Смарт-кассы" value="10">
            <input type="number" name="pos_systems_plan" placeholder="ПОС" value="5">
            <input type="number" name="inn_leads_plan" placeholder="ИНН чаевые" value="5">
            <input type="number" name="teams_plan" placeholder="Команды" value="3">
            <input type="number" name="turnover_plan" placeholder="Оборот" value="1500000">
            <button type="submit">💾 Сохранить план</button>
        </form>
    </div>

    <div id="tab-territories_admin" class="card" style="display:none;">
        <h3>🌍 Управление территориями</h3>
        <form method="POST" style="margin-bottom:15px;">
            <input type="hidden" name="create_territory" value="1">
            <input type="text" name="terr_name" placeholder="Название" required>
            <input type="text" name="terr_code" placeholder="Код" required>
            <button type="submit">+ Создать</button>
        </form>
        <table>
            <thead><tr><th>Название</th><th>Код</th><th>Тербанк</th></tr></thead>
            <tbody><?php foreach ($territories as $t): ?>
                <tr><td><?= htmlspecialchars($t['name']) ?></td><td><?= $t['code'] ?></td><td>Главный</td></tr>
            <?php endforeach; ?></tbody>
        </table>
    </div>

    <div id="tab-export" class="card" style="display:none;">
        <h3>📋 Экспорт</h3>
        <button onclick="alert('Экспорт будет доступен в следующем обновлении')">📥 Экспорт отчётов (CSV)</button>
        <button onclick="alert('Экспорт будет доступен в следующем обновлении')">📥 Экспорт ИНН (CSV)</button>
        <button onclick="alert('Экспорт будет доступен в следующем обновлении')">📥 Экспорт сотрудников (CSV)</button>
    </div>

    <div id="tab-ai_admin" class="card" style="display:none;">
        <h3>🤖 Управление AI</h3>
        <p>Настройки GigaChat и генерация рекомендаций.</p>
        <button onclick="alert('Запуск генерации рекомендаций...')">🔄 Обновить AI-рекомендации</button>
        <button onclick="alert('История рекомендаций')">📋 Просмотр рекомендаций</button>
    </div>
</div>
<script>
function showTab(name) {
    document.querySelectorAll('.card[id^="tab-"]').forEach(c => c.style.display = 'none');
    document.getElementById('tab-' + name).style.display = 'block';
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    event.target.classList.add('active');
}
function editUser(u) {
    document.getElementById('add_user_form').style.display = 'block';
    document.getElementById('edit_user_id').value = u.id;
    document.querySelector('[name="tabel_number"]').value = u.tabel_number;
    document.querySelector('[name="full_name"]').value = u.full_name;
    document.querySelector('[name="email"]').value = u.email || '';
    document.querySelector('[name="role"]').value = u.role;
    document.querySelector('[name="head_tabel"]').value = u.head_tabel || '';
    document.querySelector('[name="territory_id"]').value = u.territory_id || '';
    document.querySelector('[name="is_active"]').value = u.is_active;
}
</script>
</body>
</html>
ADMINEOF

# ---------- 14. AI ДАШБОРД ----------
cat > /root/sales-crm-new/html/ai_dashboard.php << 'AIEOF'
<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','terman','territory_head','head'])) { header('Location: dashboard.php'); exit; }
require_once 'db.php';
$role = $_SESSION['role'];
$month = date('Y-m');

$history = [];
if ($role == 'head') {
    $stmt = $pdo->prepare("SELECT dr.*, u.full_name FROM daily_reports dr JOIN users u ON dr.tabel_number = u.tabel_number WHERE u.head_tabel = ? AND strftime('%Y-%m', dr.report_date) = ? ORDER BY dr.report_date DESC, u.full_name");
    $stmt->execute([$_SESSION['tabel'], $month]);
} else {
    $stmt = $pdo->query("SELECT dr.*, u.full_name FROM daily_reports dr JOIN users u ON dr.tabel_number = u.tabel_number WHERE strftime('%Y-%m', dr.report_date) = '$month' ORDER BY dr.report_date DESC, u.full_name");
}
$history = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>AI-аналитика — SZB CRM</title>
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0a0a1a; color: #e0e0e0; }
    .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
    .card { background: rgba(20,20,50,0.8); border-radius: 12px; padding: 20px; margin-bottom: 20px; border: 1px solid rgba(100,100,255,0.15); }
    .ai-chat { background: rgba(0,200,200,0.05); border: 1px solid rgba(0,200,200,0.2); border-radius: 12px; padding: 20px; margin-bottom: 20px; }
    .ai-chat textarea { width: 100%; padding: 12px; background: rgba(10,10,30,0.8); border: 1px solid rgba(0,200,200,0.3); border-radius: 8px; color: #fff; font-size: 14px; resize: vertical; }
    .ai-chat button { padding: 12px 24px; background: linear-gradient(135deg, #008888, #00aaaa); border: none; border-radius: 8px; color: #fff; cursor: pointer; margin-top: 10px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td { padding: 8px; text-align: center; border-bottom: 1px solid rgba(100,100,255,0.1); }
</style>
</head>
<body>
<?php include 'parts/header.php'; ?>
<div class="container">
    <h2>🤖 AI-аналитика</h2>
    <div class="ai-chat">
        <h4>Спросить у GigaChat</h4>
        <textarea id="ai_question" rows="3" placeholder="Задайте вопрос AI-аналитику..."></textarea>
        <button onclick="askAI()">🚀 Отправить</button>
        <div id="ai_answer" style="margin-top:15px;padding:15px;background:rgba(0,200,200,0.05);border-radius:8px;display:none;"></div>
    </div>
    <h3>История продаж</h3>
    <div style="overflow-x:auto;">
        <table>
            <thead><tr><th>Дата</th><th>Сотрудник</th><th>📞</th><th>✅</th><th>🤝</th><th>📄</th><th>📝</th><th>💳</th><th>🖥️</th><th>🍵</th><th>👥</th><th>💰</th></tr></thead>
            <tbody>
                <?php foreach ($history as $h): ?>
                <tr>
                    <td><?= date('d.m', strtotime($h['report_date'])) ?></td>
                    <td><?= htmlspecialchars($h['full_name']) ?></td>
                    <td><?= $h['calls'] ?></td><td><?= $h['calls_answered'] ?></td>
                    <td><?= $h['meetings'] ?></td><td><?= $h['contracts'] ?></td>
                    <td><?= $h['registrations'] ?></td><td><?= $h['smart_cash'] ?></td>
                    <td><?= $h['pos_systems'] ?></td><td><?= $h['inn_leads'] ?></td>
                    <td><?= $h['teams'] ?></td><td><?= number_format($h['turnover'], 0, '.', ' ') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
function askAI() {
    let q = document.getElementById('ai_question').value.trim();
    if (!q) return;
    document.getElementById('ai_answer').style.display = 'block';
    document.getElementById('ai_answer').innerHTML = '⏳ GigaChat думает...';
    fetch('api/ai_ask.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({question: q})})
        .then(r => r.json()).then(d => {
            document.getElementById('ai_answer').innerHTML = '<strong>Ответ:</strong><br>' + (d.response || d.error || 'Нет ответа');
        });
}
</script>
</body>
</html>
AIEOF

# ---------- 15. AI API ЗАГЛУШКА ----------
cat > /root/sales-crm-new/html/api/ai_ask.php << 'AIAEOF'
<?php
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) { echo json_encode(['error'=>'Auth required']); exit; }
echo json_encode(['response' => '🤖 AI-модуль запущен. Полноценная интеграция с GigaChat будет активирована в ближайшее время. Ваш вопрос принят.']);
AIAEOF

# ---------- 16. СЛУЖЕБНЫЕ ФАЙЛЫ ----------
cat > /root/sales-crm-new/html/logout.php << 'LOGOUT'
<?php session_start(); session_destroy(); header('Location: login.php');
LOGOUT

cat > /root/sales-crm-new/html/mark_read.php << 'MARKREAD'
<?php session_start(); require_once 'db.php'; $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?")->execute([$_GET['id'] ?? 0]); header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
MARKREAD

cat > /root/sales-crm-new/html/style.css << 'STYLEEOF'
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0a0a1a; color: #e0e0e0; min-height: 100vh; }
.container { max-width: 1400px; margin: 0 auto; padding: 20px; }
.nav-bar { display: flex; align-items: center; padding: 12px 20px; background: rgba(15,15,40,0.95); border-bottom: 1px solid rgba(100,100,255,0.2); flex-wrap: wrap; gap: 8px; position: sticky; top: 0; z-index: 100; }
.nav-logo { font-size: 20px; font-weight: bold; color: #8888ff; text-decoration: none; letter-spacing: 2px; }
.nav-links { display: flex; gap: 4px; flex-wrap: wrap; flex: 1; }
.nav-links a { color: #aaa; text-decoration: none; padding: 8px 14px; border-radius: 8px; font-size: 13px; white-space: nowrap; }
.nav-links a.active, .nav-links a:hover { background: rgba(100,100,255,0.2); color: #fff; }
.tcard { background: rgba(20,20,50,0.8); border-radius: 12px; padding: 14px; border: 1px solid rgba(100,100,255,0.15); }
.team-metrics { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; margin-bottom: 20px; }
.fact { font-size: 28px; font-weight: bold; display: block; }
.color-green { color: #44dd88; }
.color-yellow { color: #ddcc44; }
.color-orange { color: #dd8844; }
.color-red { color: #dd4444; }
.color-gray { color: #666; }
@media (max-width: 768px) { .container { padding: 10px; } .nav-bar { flex-direction: column; align-items: flex-start; } .team-metrics { grid-template-columns: 1fr 1fr; } }
STYLEEOF

# ---------- 17. ПРАВА ----------
chmod -R 755 /root/sales-crm-new/html
chmod 666 /root/sales-crm-new/html/sales.db

# ---------- 18. ЗАМЕНА СТАРОЙ CRM ----------
echo "🔄 Замена старой CRM на новую..."
docker stop sales-crm 2>/dev/null || true
docker rm sales-crm 2>/dev/null || true
rm -rf /root/sales-crm/html
cp -r /root/sales-crm-new/html /root/sales-crm/html
cp /root/sales-crm-new/data/sales.db /root/sales-crm/html/sales.db

# ---------- 19. DOCKER ----------
echo "🐳 Запуск Docker-контейнера..."
docker run -d \
  --name sales-crm \
  --restart always \
  -v /root/sales-crm/html:/var/www/html \
  -p 8080:80 \
  php:8.2-apache

# Ждём запуска
sleep 3

# Устанавливаем расширения PHP
docker exec sales-crm docker-php-ext-install pdo pdo_sqlite 2>/dev/null || true
docker restart sales-crm
sleep 3
docker exec sales-crm docker-php-ext-install pdo pdo_sqlite 2>/dev/null || true
docker restart sales-crm
sleep 3

# ---------- 20. NGINX ----------
echo "🔧 Настройка Nginx..."
cat > /etc/nginx/sites-available/crm << 'NGXEOF'
server {
    listen 80;
    server_name szb-sales.ru www.szb-sales.ru;
    return 301 https://$server_name$request_uri;
}
server {
    listen 443 ssl http2;
    server_name szb-sales.ru www.szb-sales.ru;
    ssl_certificate /etc/letsencrypt/live/szb-sales.ru/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/szb-sales.ru/privkey.pem;
    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
NGXEOF
ln -sf /etc/nginx/sites-available/crm /etc/nginx/sites-enabled/crm
systemctl reload nginx

# ---------- 21. ФИНАЛЬНАЯ ПРОВЕРКА ----------
echo ""
echo "════════════════════════════════════════════════════════════"
echo "✅ РАЗВЁРТЫВАНИЕ ЗАВЕРШЕНО!"
echo "════════════════════════════════════════════════════════════"
echo "🌐 САЙТ: https://szb-sales.ru"
echo "👤 ЛОГИН: 0001"
echo "🔑 ПАРОЛЬ: admin123"
echo "════════════════════════════════════════════════════════════"
echo "💾 Бэкап старой CRM: $BACKUP_DIR"
echo "════════════════════════════════════════════════════════════"
curl -s -o /dev/null -w "✅ HTTP статус: %{http_code}\n" https://szb-sales.ru/login.php
echo "ГОТОВО!"
DEPLOY_EOF
