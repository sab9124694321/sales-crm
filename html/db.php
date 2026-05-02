<?php
// Подключение к SQLite базе данных
try {
    $db_path = __DIR__ . '/sales.db';
    $pdo = new PDO("sqlite:" . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("Database connection error. Please check logs.");
}

// Функция для проверки и добавления колонок
function ensureColumns($pdo) {
    // Проверяем наличие колонок в users
    $stmt = $pdo->query("PRAGMA table_info(users)");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
    
    if (!in_array('rank', $columns)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN rank TEXT DEFAULT 'Новичок'");
    }
    if (!in_array('total_points', $columns)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN total_points INTEGER DEFAULT 0");
    }
    
    // Создаём таблицы если их нет
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS game_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            type TEXT,
            message TEXT,
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rank_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            old_rank TEXT,
            new_rank TEXT,
            reason TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // Устанавливаем значения по умолчанию
    $pdo->exec("UPDATE users SET rank = 'Новичок', total_points = 0 WHERE rank IS NULL");
}

// Вызываем проверку
ensureColumns($pdo);
?>
