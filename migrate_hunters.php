<?php
require_once 'db.php';

// Создаём таблицу hunters
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS hunters (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        phone TEXT NOT NULL UNIQUE,
        email TEXT,
        password TEXT NOT NULL,
        referral_code TEXT UNIQUE,
        referred_by INTEGER,
        points INTEGER DEFAULT 0,
        hunter_level INTEGER DEFAULT 1,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✅ Таблица hunters создана\n";
} catch (PDOException $e) {
    echo "⚠️ hunters: " . $e->getMessage() . "\n";
}

// Создаём таблицу hunter_leads (лиды охотников)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS hunter_leads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        hunter_id INTEGER NOT NULL,
        shop_name TEXT NOT NULL,
        inn TEXT NOT NULL,
        contact_name TEXT NOT NULL,
        contact_phone TEXT NOT NULL,
        status TEXT DEFAULT 'pending',
        reward INTEGER DEFAULT 0,
        points INTEGER DEFAULT 50,
        manager_comment TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✅ Таблица hunter_leads создана\n";
} catch (PDOException $e) {
    echo "⚠️ hunter_leads: " . $e->getMessage() . "\n";
}

// Создаём таблицу hunter_notifications
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS hunter_notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        hunter_id INTEGER NOT NULL,
        message TEXT NOT NULL,
        type TEXT DEFAULT 'info',
        is_read INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✅ Таблица hunter_notifications создана\n";
} catch (PDOException $e) {
    echo "⚠️ hunter_notifications: " . $e->getMessage() . "\n";
}

echo "\n🎉 Миграция hunters завершена!\n";
