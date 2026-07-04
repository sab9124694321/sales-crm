<?php
$db_file = __DIR__ . '/sales.db';
try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("PRAGMA foreign_keys = ON");
} catch (PDOException $e) { die("Ошибка БД: " . $e->getMessage()); }
function getUserByTabel($pdo, $tabel) { $stmt = $pdo->prepare("SELECT * FROM users WHERE tabel_number = ?"); $stmt->execute([$tabel]); return $stmt->fetch(); }
