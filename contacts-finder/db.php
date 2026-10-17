<?php
function getContactsDb(): PDO {
    $pdo = new PDO('sqlite:' . __DIR__ . '/contacts.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            inn TEXT UNIQUE,
            company_name TEXT,
            status TEXT,
            director_name TEXT,
            okved_main TEXT,
            okved_description TEXT,
            activity_note TEXT,
            address TEXT,
            phones TEXT,
            emails TEXT,
            websites TEXT,
            sources TEXT,
            raw_json TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    return $pdo;
}
