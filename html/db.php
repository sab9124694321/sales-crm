<?php
$db_file = __DIR__ . '/sales.db';
$pdo = new PDO("sqlite:$db_file");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Создание таблиц
$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tabel_number TEXT UNIQUE,
    full_name TEXT,
    phone TEXT,
    role TEXT DEFAULT 'employee',
    manager_id INTEGER,
    password TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS monthly_plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    year INTEGER,
    month INTEGER,
    plan_contracts INTEGER DEFAULT 30
);

CREATE TABLE IF NOT EXISTS daily_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    report_date DATE DEFAULT CURRENT_DATE,
    calls INTEGER DEFAULT 0,
    calls_answered INTEGER DEFAULT 0,
    meetings INTEGER DEFAULT 0,
    contracts INTEGER DEFAULT 0,
    registrations INTEGER DEFAULT 0,
    smart_cash INTEGER DEFAULT 0,
    pos_systems INTEGER DEFAULT 0,
    inn_leads INTEGER DEFAULT 0,
    teams INTEGER DEFAULT 0,
    turnover REAL DEFAULT 0
);
");

// Создание администратора по умолчанию
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
$stmt->execute();
if ($stmt->fetchColumn() == 0) {
    $password = password_hash('admin123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (tabel_number, full_name, phone, role, password) VALUES ('0001', 'Администратор', '+70000000000', 'admin', ?)")->execute([$password]);
}
?>
