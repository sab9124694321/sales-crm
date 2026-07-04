<?php
require_once 'db.php';

try {
    // Добавляем поля согласия
    $pdo->exec("ALTER TABLE hunters ADD COLUMN consent_given INTEGER DEFAULT 0");
    $pdo->exec("ALTER TABLE hunters ADD COLUMN consent_date DATETIME");
    echo "✅ Таблица hunters обновлена (consent_given, consent_date).\n";

    // Проверяем hunter_leads – оставляем только нужные поля
    $stmt = $pdo->query("PRAGMA table_info(hunter_leads)");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('shop_phone', $cols)) {
        $pdo->exec("ALTER TABLE hunter_leads ADD COLUMN shop_phone TEXT");
    }
    if (!in_array('contact_name', $cols)) {
        $pdo->exec("ALTER TABLE hunter_leads ADD COLUMN contact_name TEXT");
    }
    echo "✅ Таблица hunter_leads готова.\n";
} catch (PDOException $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
}
