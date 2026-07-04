<?php
require_once 'db.php';
try {
    $pdo->exec("PRAGMA foreign_keys = OFF;");
    $check = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
    if (strpos($check, 'mmb_manager') === false) {
        $pdo->exec("CREATE TABLE users_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tabel_number TEXT UNIQUE NOT NULL,
            full_name TEXT NOT NULL,
            email TEXT UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ('admin','terman','territory_head','head','manager','mmb_manager','ubr_middle','mmb_tp_head')),
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
            manager_id INTEGER DEFAULT NULL,
            FOREIGN KEY (territory_id) REFERENCES territories(id)
        )");
        $pdo->exec("INSERT INTO users_new SELECT * FROM users");
        $pdo->exec("DROP TABLE users");
        $pdo->exec("ALTER TABLE users_new RENAME TO users");
        echo "✅ Роли расширены\n";
    }
    $tables = [
        "CREATE TABLE IF NOT EXISTS support_request_types (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, description TEXT, is_active INTEGER DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS sla_policies (id INTEGER PRIMARY KEY AUTOINCREMENT, request_type_id INTEGER NOT NULL, response_hours INTEGER NOT NULL, resolution_hours INTEGER NOT NULL, is_active INTEGER DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (request_type_id) REFERENCES support_request_types(id))",
        "CREATE TABLE IF NOT EXISTS clients (id INTEGER PRIMARY KEY AUTOINCREMENT, inn TEXT NOT NULL UNIQUE, name TEXT NOT NULL, is_active INTEGER DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS support_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_number TEXT UNIQUE NOT NULL, client_inn TEXT NOT NULL, client_name TEXT, request_type_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'new', priority TEXT DEFAULT 'normal', created_by_tabel TEXT NOT NULL, assigned_to_tabel TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, first_response_deadline DATETIME, resolution_deadline DATETIME, first_response_at DATETIME, resolved_at DATETIME, closed_at DATETIME, last_notification_sent DATETIME, mmb_head_tabel TEXT, ubr_head_tabel TEXT, FOREIGN KEY (request_type_id) REFERENCES support_request_types(id), FOREIGN KEY (client_inn) REFERENCES clients(inn))",
        "CREATE TABLE IF NOT EXISTS support_mailboxes (id INTEGER PRIMARY KEY AUTOINCREMENT, email_address TEXT NOT NULL UNIQUE, ubr_head_tabel TEXT, is_active INTEGER DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS mmb_head_to_ubr_head (id INTEGER PRIMARY KEY AUTOINCREMENT, mmb_head_tabel TEXT NOT NULL, ubr_head_tabel TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE(mmb_head_tabel, ubr_head_tabel))",
        "CREATE TABLE IF NOT EXISTS sla_violations (id INTEGER PRIMARY KEY AUTOINCREMENT, request_id INTEGER NOT NULL, violation_type TEXT CHECK(violation_type IN ('first_response', 'resolution')), notified_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (request_id) REFERENCES support_requests(id))"
    ];
    foreach ($tables as $sql) { $pdo->exec($sql); echo "✅ Таблица создана/проверена\n"; }
    $columnsToAdd = [
        "support_requests" => ["mmb_head_tabel" => "ALTER TABLE support_requests ADD COLUMN mmb_head_tabel TEXT", "ubr_head_tabel" => "ALTER TABLE support_requests ADD COLUMN ubr_head_tabel TEXT"],
        "support_mailboxes" => ["ubr_head_tabel" => "ALTER TABLE support_mailboxes ADD COLUMN ubr_head_tabel TEXT"]
    ];
    foreach ($columnsToAdd as $table => $columns) {
        $info = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_COLUMN, 1);
        foreach ($columns as $col => $sql) {
            if (!in_array($col, $info)) { $pdo->exec($sql); echo "✅ Добавлен столбец $col в $table\n"; }
        }
    }
    $pdo->exec("PRAGMA foreign_keys = ON;");
    echo "🎉 Миграция БД завершена!\n";
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    $pdo->exec("PRAGMA foreign_keys = ON;");
}
