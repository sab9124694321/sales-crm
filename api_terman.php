<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Сохранить отсутствие ──────────────────────────────────
if ($action === 'save_absence') {
    $tabel = trim($_POST['tabel'] ?? '');
    $date = trim($_POST['date'] ?? '');
    $type = trim($_POST['type'] ?? '');
    $created_by = $_SESSION['user_id'] ?? 0;

    if (!$tabel || !$date) {
        echo json_encode(['success' => false, 'error' => 'Не хватает данных']);
        exit;
    }

    try {
        if ($type === 'X') {
            $stmt = $pdo->prepare("INSERT OR IGNORE INTO employee_absences (employee_tabel, absence_date, absence_type, created_by, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)");
            $stmt->execute([$tabel, $date, $type, $created_by]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM employee_absences WHERE employee_tabel = ? AND absence_date = ?");
            $stmt->execute([$tabel, $date]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Сохранить дату ввода в должность ─────────────────────
if ($action === 'save_position_date') {
    $tabel = trim($_POST['tabel'] ?? '');
    $date = trim($_POST['date'] ?? '');
    if (!$tabel || !$date) {
        echo json_encode(['success' => false, 'error' => 'Не хватает данных']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("UPDATE users SET position_start_date = ? WHERE tabel_number = ?");
        $stmt->execute([$date, $tabel]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Сохранить настройки цветов (исправлено под структуру таблицы) ──
if ($action === 'save_colors') {
    $red = (int) ($_POST['red_max'] ?? 1);
    $yellow = (int) ($_POST['yellow_max'] ?? 2);
    if ($red >= $yellow) {
        echo json_encode(['success' => false, 'error' => 'Красный порог должен быть меньше жёлтого']);
        exit;
    }
    try {
        // Проверяем, есть ли глобальная запись (territory_id IS NULL AND terbank_id IS NULL)
        $stmt = $pdo->prepare("SELECT id FROM terman_color_settings WHERE territory_id IS NULL AND terbank_id IS NULL LIMIT 1");
        $stmt->execute();
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Обновляем существующую запись
            $stmt = $pdo->prepare("UPDATE terman_color_settings SET red_max = ?, yellow_max = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$red, $yellow, $existing['id']]);
        } else {
            // Вставляем новую запись
            $stmt = $pdo->prepare("INSERT INTO terman_color_settings (red_max, yellow_max, territory_id, terbank_id, updated_at) VALUES (?, ?, NULL, NULL, CURRENT_TIMESTAMP)");
            $stmt->execute([$red, $yellow]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Получить комментарий (для head или manager) ──────────
if ($action === 'get_comment') {
    $user_tabel = trim($_GET['user_tabel'] ?? '');
    $date = trim($_GET['date'] ?? '');
    $role = trim($_GET['role'] ?? 'head');
    if (!$user_tabel || !$date) {
        echo json_encode(['comment' => '']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("SELECT comment FROM head_comments WHERE head_tabel = ? AND comment_date = ? AND target_role = ?");
        $stmt->execute([$user_tabel, $date, $role]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['comment' => $row['comment'] ?? '']);
    } catch (Exception $e) {
        echo json_encode(['comment' => '']);
    }
    exit;
}

// ── Сохранить комментарий (для head или manager) ──────────
if ($action === 'save_comment') {
    $user_tabel = trim($_POST['user_tabel'] ?? '');
    $date = trim($_POST['date'] ?? '');
    $comment = trim($_POST['comment'] ?? '');
    $role = trim($_POST['role'] ?? 'head');
    $created_by = $_SESSION['user_id'] ?? 0;
    if (!$user_tabel || !$date) {
        echo json_encode(['success' => false, 'error' => 'Не хватает данных']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO head_comments (head_tabel, comment_date, comment, created_by, created_at, target_role) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?)");
        $stmt->execute([$user_tabel, $date, $comment, $created_by, $role]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['error' => 'Unknown action']);