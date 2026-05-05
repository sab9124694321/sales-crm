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

function getUserById($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function getUserByTabelNumber($pdo, $tabel_number) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE tabel_number = ?");
    $stmt->execute([$tabel_number]);
    return $stmt->fetch();
}

function getAllEmployees($pdo, $manager_id = null) {
    if ($manager_id) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE role = 'employee' AND manager_id = ? ORDER BY full_name");
        $stmt->execute([$manager_id]);
    } else {
        $stmt = $pdo->query("SELECT * FROM users WHERE role = 'employee' ORDER BY full_name");
    }
    return $stmt->fetchAll();
}

function getTeamManagers($pdo) {
    $stmt = $pdo->query("SELECT * FROM users WHERE role = 'manager' ORDER BY full_name");
    return $stmt->fetchAll();
}
?>
