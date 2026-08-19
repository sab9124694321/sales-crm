<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$role = $_SESSION['role'] ?? '';
$allowed = ['terman','territory_head','admin','head'];
if (!in_array($role, $allowed)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── 1. Сохранение отсутствия ────────────────────────────
if ($action === 'save_absence') {
    $tabel = trim($_POST['tabel'] ?? '');
    $date  = trim($_POST['date'] ?? '');
    $type  = trim($_POST['type'] ?? '');

    if (!$tabel || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['error' => 'Некорректные данные']);
        exit;
    }

    try {
        if ($type === '' || $type === null) {
            $stmt = $pdo->prepare("DELETE FROM employee_absences WHERE employee_tabel = ? AND absence_date = ?");
            $stmt->execute([$tabel, $date]);
        } else {
            $stmt = $pdo->prepare("REPLACE INTO employee_absences (employee_tabel, absence_date, absence_type, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$tabel, $date, $type, $_SESSION['user_id']]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── 2. Сохранение цветов ─────────────────────────────────
if ($action === 'save_colors') {
    $red    = (int)($_POST['red_max'] ?? 1);
    $yellow = (int)($_POST['yellow_max'] ?? 2);
    $terbank = (int)($_SESSION['terbank_id'] ?? 1);

    if ($red >= $yellow) {
        echo json_encode(['error' => 'Красный порог должен быть меньше жёлтого']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM terman_color_settings WHERE terbank_id = ? AND territory_id IS NULL");
        $stmt->execute([$terbank]);
        $stmt = $pdo->prepare("INSERT INTO terman_color_settings (terbank_id, territory_id, red_max, yellow_max, updated_by) VALUES (?, NULL, ?, ?, ?)");
        $stmt->execute([$terbank, $red, $yellow, $_SESSION['user_id']]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── 3. Сохранение даты ввода в должность ────────────────
if ($action === 'save_position_date') {
    $tabel = trim($_POST['tabel'] ?? '');
    $date  = trim($_POST['date'] ?? '');

    if (!$tabel) {
        echo json_encode(['error' => 'Не указан табельный номер']);
        exit;
    }
    if ($date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['error' => 'Неверный формат даты (ГГГГ-ММ-ДД)']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE users SET position_start_date = ? WHERE tabel_number = ?");
        $stmt->execute([$date ?: null, $tabel]);
        echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── 4. Сохранение комментария руководителя ──────────────
if ($action === 'save_head_comment') {
    $head_tabel = trim($_POST['head_tabel'] ?? '');
    $date       = trim($_POST['date'] ?? '');
    $comment    = trim($_POST['comment'] ?? '');

    if (!$head_tabel || !$date) {
        echo json_encode(['error' => 'Не все поля заполнены']);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['error' => 'Неверный формат даты']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO head_comments (head_tabel, comment_date, comment, created_by) VALUES (?, ?, ?, ?)");
        $stmt->execute([$head_tabel, $date, $comment, $_SESSION['user_id']]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── 5. Получение комментария руководителя ────────────────
if ($action === 'get_head_comment') {
    $head_tabel = trim($_GET['head_tabel'] ?? '');
    $date       = trim($_GET['date'] ?? '');

    if (!$head_tabel || !$date) {
        echo json_encode(['comment' => '']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT comment FROM head_comments WHERE head_tabel = ? AND comment_date = ?");
        $stmt->execute([$head_tabel, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['comment' => $row['comment'] ?? '']);
    } catch (Exception $e) {
        echo json_encode(['comment' => '']);
    }
    exit;
}

echo json_encode(['error' => 'Unknown action: ' . $action]);
