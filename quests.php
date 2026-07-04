<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once 'db.php';

$role = $_SESSION['role'];
$tabel = $_SESSION['tabel'];
$user_id = $_SESSION['user_id'];
$is_head = in_array($role, ['admin', 'head', 'territory_head']);

$message = '';

// ---------- Обработка действий ----------

// 1. Создание / редактирование квеста (руководитель)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_quest']) && $is_head) {
    $quest_id = $_POST['quest_id'] ?? null;
    $type = $_POST['type'] ?? 'individual';
    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $points = (int)($_POST['points'] ?? 0);
    $deadline = $_POST['deadline'] ?? null;
    $mandatory = isset($_POST['mandatory']) ? 1 : 0;
    $assign_tabels = $_POST['assign_to'] ?? [];
    $assign_text = trim($_POST['assign_to_text'] ?? '');
    if ($assign_text !== '') {
        $extra = array_map('trim', explode(',', $assign_text));
        $assign_tabels = array_merge($assign_tabels, $extra);
    }
    $assign_tabels = array_unique(array_filter($assign_tabels));

    if ($title && $points > 0) {
        if ($quest_id) {
            $stmt = $pdo->prepare("UPDATE quests SET type=?, title=?, description=?, points=?, ends_at=?, mandatory=?, is_active=1 WHERE id=? AND head_tabel=?");
            $stmt->execute([$type, $title, $desc, $points, $deadline ?: null, $mandatory, $quest_id, $tabel]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO quests (type, head_tabel, title, description, points, is_active, ends_at, mandatory) VALUES (?,?,?,?,?,1,?,?)");
            $stmt->execute([$type, $tabel, $title, $desc, $points, $deadline ?: null, $mandatory]);
            $quest_id = $pdo->lastInsertId();
        }
        // Назначение сотрудников
        if (!empty($assign_tabels)) {
            $insert_taker = $pdo->prepare("INSERT OR IGNORE INTO quest_takers (quest_id, employee_tabel, status) VALUES (?, ?, ?)");
            // Для индивидуальных без поручения – статус pending, иначе taken
            $status = ($type == 'individual' && !$mandatory) ? 'pending' : 'taken';
            foreach ($assign_tabels as $emp_tabel) {
                $insert_taker->execute([$quest_id, trim($emp_tabel), $status]);
            }
        }
        $message = '<div class="success">✅ Квест сохранён</div>';
    } else {
        $message = '<div class="error">❌ Укажите название и цену квеста</div>';
    }
}

// 2. Принятие квеста (менеджер) – переводит pending → taken
if (isset($_GET['take']) && $role === 'manager') {
    $qid = (int)$_GET['take'];
    $chk = $pdo->prepare("SELECT id, mandatory FROM quests WHERE id=? AND is_active=1");
    $chk->execute([$qid]);
    if ($chk->fetch()) {
        $stmt = $pdo->prepare("UPDATE quest_takers SET status = 'taken', taken_at = datetime('now') WHERE quest_id = ? AND employee_tabel = ? AND status = 'pending'");
        $stmt->execute([$qid, $tabel]);
        // Если записи не было (групповой квест), то создаём
        if ($stmt->rowCount() == 0) {
            $pdo->prepare("INSERT OR IGNORE INTO quest_takers (quest_id, employee_tabel, status) VALUES (?, ?, 'taken')")
               ->execute([$qid, $tabel]);
        }
    }
    header("Location: quests.php?msg=taken");
    exit;
}

// 3. Отметка выполнения (менеджер)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_quest']) && $role === 'manager') {
    $qid = (int)$_POST['quest_id'];
    $report = trim($_POST['report'] ?? '');
    $stmt = $pdo->prepare("UPDATE quest_takers SET status='completed', report=?, completed_at=datetime('now') WHERE quest_id=? AND employee_tabel=?");
    $stmt->execute([$report, $qid, $tabel]);
    header("Location: quests.php?msg=completed");
    exit;
}

// 4. Подтверждение руководителем (начисление баллов)
if (isset($_GET['confirm']) && $is_head) {
    $taker_id = (int)$_GET['confirm'];
    $stmt = $pdo->prepare("SELECT qt.*, q.points, q.type FROM quest_takers qt JOIN quests q ON qt.quest_id = q.id WHERE qt.id = ?");
    $stmt->execute([$taker_id]);
    $taker = $stmt->fetch();
    if ($taker && $taker['status'] == 'completed') {
        $emp_tabel = $taker['employee_tabel'];
        $pts = $taker['points'];
        $pdo->prepare("UPDATE users SET total_points = total_points + ?, experience = experience + ? WHERE tabel_number = ?")
           ->execute([$pts, $pts, $emp_tabel]);

        $user = $pdo->prepare("SELECT total_points FROM users WHERE tabel_number = ?");
        $user->execute([$emp_tabel]);
        $total = $user->fetchColumn();
        $levels = [0 => 'Новичок', 200 => 'Охотник', 500 => 'Мастер', 1000 => 'Эксперт', 2000 => 'Легенда'];
        $new_rank = 'Новичок';
        $new_level = 1;
        foreach ($levels as $pts_needed => $rank) {
            if ($total >= $pts_needed) {
                $new_rank = $rank;
                $new_level = array_search($rank, array_values($levels)) + 1;
            }
        }
        $pdo->prepare("UPDATE users SET rank = ?, level = ?, next_level_exp = ? WHERE tabel_number = ?")
           ->execute([$new_rank, $new_level, $new_level * 200, $emp_tabel]);

        $pdo->prepare("UPDATE quest_takers SET status = 'rewarded' WHERE id = ?")->execute([$taker_id]);
        $message = '<div class="success">✅ Баллы начислены</div>';
    }
    header("Location: quests.php?tab=overview");
    exit;
}

// 5. Штраф за просрочку
if (isset($_GET['penalize']) && $is_head) {
    $taker_id = (int)$_GET['penalize'];
    $stmt = $pdo->prepare("SELECT qt.*, q.points FROM quest_takers qt JOIN quests q ON qt.quest_id = q.id WHERE qt.id = ?");
    $stmt->execute([$taker_id]);
    $taker = $stmt->fetch();
    if ($taker && !in_array($taker['status'], ['rewarded','failed','closed'])) {
        $pts = $taker['points'];
        $pdo->prepare("UPDATE users SET total_points = MAX(0, total_points - ?) WHERE tabel_number = ?")
           ->execute([$pts, $taker['employee_tabel']]);
        $pdo->prepare("UPDATE quest_takers SET status = 'failed' WHERE id = ?")->execute([$taker_id]);
        $message = '<div class="success">✅ Штраф начислен</div>';
    }
    header("Location: quests.php?tab=overview");
    exit;
}

// 6. Закрыть без исполнения (после штрафа)
if (isset($_GET['close']) && $is_head) {
    $taker_id = (int)$_GET['close'];
    $pdo->prepare("UPDATE quest_takers SET status = 'closed' WHERE id = ? AND status = 'failed'")->execute([$taker_id]);
    header("Location: quests.php?tab=overview");
    exit;
}

// 7. Удаление квеста (руководитель)
if (isset($_GET['delete_quest']) && $is_head) {
    $qid = (int)$_GET['delete_quest'];
    // Удаляем связанные записи quest_takers и сам квест
    $pdo->prepare("DELETE FROM quest_takers WHERE quest_id = ?")->execute([$qid]);
    $pdo->prepare("DELETE FROM quests WHERE id = ? AND head_tabel = ?")->execute([$qid, $tabel]);
    header("Location: quests.php?tab=overview");
    exit;
}

// ---------- Данные для отображения ----------
$selected_tab = $_GET['tab'] ?? ($is_head ? 'overview' : 'my');

$rating = [];
if ($is_head) {
    $rating = $pdo->query("SELECT full_name, tabel_number, total_points, level, rank FROM users WHERE role='manager' AND is_active=1 ORDER BY total_points DESC")->fetchAll();
}

$quests = [];
$takers_by_quest = [];
if ($is_head) {
    $sql = "SELECT q.*, GROUP_CONCAT(u.full_name, ', ') as assignees
            FROM quests q
            LEFT JOIN quest_takers qt ON q.id = qt.quest_id
            LEFT JOIN users u ON qt.employee_tabel = u.tabel_number
            WHERE q.head_tabel = ?
            GROUP BY q.id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tabel]);
    $quests = $stmt->fetchAll();

    $all_takers = $pdo->prepare("SELECT qt.*, u.full_name FROM quest_takers qt JOIN users u ON qt.employee_tabel = u.tabel_number WHERE qt.quest_id IN (SELECT id FROM quests WHERE head_tabel = ?)");
    $all_takers->execute([$tabel]);
    $takers = $all_takers->fetchAll();
    foreach ($takers as $t) {
        $takers_by_quest[$t['quest_id']][] = $t;
    }
}

// ДОСТУПНЫЕ КВЕСТЫ: групповые + индивидуальные со статусом pending
$available_quests = [];
if ($role === 'manager') {
    // Групповые, которые менеджер ещё не принял
    $stmt = $pdo->prepare("SELECT q.* FROM quests q
        WHERE q.is_active = 1
        AND q.type = 'group'
        AND NOT EXISTS (SELECT 1 FROM quest_takers qt WHERE qt.quest_id = q.id AND qt.employee_tabel = ?)
        ORDER BY q.created_at DESC");
    $stmt->execute([$tabel]);
    $group_quests = $stmt->fetchAll();

    // Индивидуальные со статусом pending
    $stmt = $pdo->prepare("SELECT q.* FROM quests q
        JOIN quest_takers qt ON q.id = qt.quest_id
        WHERE q.is_active = 1
        AND qt.employee_tabel = ?
        AND qt.status = 'pending'
        ORDER BY q.created_at DESC");
    $stmt->execute([$tabel]);
    $pending_individual = $stmt->fetchAll();

    $available_quests = array_merge($group_quests, $pending_individual);
}

// МОИ КВЕСТЫ (принятые)
$my_quests = [];
if ($role === 'manager') {
    $stmt = $pdo->prepare("SELECT q.*, qt.status, qt.completed_at, qt.taken_at, qt.report
        FROM quests q
        JOIN quest_takers qt ON q.id = qt.quest_id
        WHERE qt.employee_tabel = ?
        AND qt.status NOT IN ('pending','closed')
        ORDER BY q.ends_at ASC");
    $stmt->execute([$tabel]);
    $my_quests = $stmt->fetchAll();
}

$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$filter_overdue = isset($_GET['overdue']) ? true : false;

$all_managers = [];
if ($is_head) {
    $all_managers = $pdo->query("SELECT full_name, tabel_number FROM users WHERE role='manager' AND is_active=1 ORDER BY full_name")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>🎯 Квесты — SZB CRM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css">
    <style>
        .nav { display:flex; align-items:center; padding:12px 20px; background:linear-gradient(135deg,#1a1a2e,#16213e); color:#fff; border-radius:16px; margin-bottom:20px; gap:12px; flex-wrap:wrap; }
        .nav a { color:#ccc; text-decoration:none; padding:8px 14px; border-radius:8px; font-size:13px; font-weight:500; }
        .nav a:hover, .nav a.active { background:rgba(255,255,255,0.1); color:#fff; }
        .nav .logo { font-size:20px; font-weight:700; color:#fff; margin-right:auto; }
        .nav .user { margin-left:auto; color:#aaa; font-size:13px; }
        .nav a.logout { color:#e03131; }

        .container { max-width:1400px; margin:0 auto; padding:24px; }
        .card { background:#fff; border-radius:16px; padding:20px; margin-bottom:16px; box-shadow:0 2px 12px rgba(0,0,0,0.04); border:1px solid #e8ecf1; }
        .grid2 { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; }
        .form-group { display:flex; flex-direction:column; }
        .form-group label { font-size:12px; color:#666; margin-bottom:4px; font-weight:500; }
        .form-group input, .form-group select { padding:8px 12px; border:1px solid #dee2e6; border-radius:10px; font-size:14px; }
        .btn { padding:10px 20px; background:#1a73e8; color:#fff; border:none; border-radius:10px; cursor:pointer; font-weight:500; }
        .btn-sm { padding:6px 12px; font-size:12px; background:#6c757d; }
        .btn-danger { background:#e03131; }
        .badge { display:inline-block; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:600; }
        .badge-success { background:#d3f9d8; color:#0ca678; }
        .badge-warning { background:#fff3bf; color:#f08c00; }
        .badge-danger { background:#ffe3e3; color:#e03131; }
        .badge-primary { background:#d0ebff; color:#1a73e8; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:10px 12px; text-align:left; border-bottom:1px solid #e8ecf1; }
        th { background:#f8f9fa; color:#666; font-size:11px; font-weight:600; text-transform:uppercase; }
        .red-text { color:#e03131; font-weight:bold; }
        .quest-card { border-left:4px solid #1a73e8; margin-bottom:10px; }
        .mandatory { border-left-color: #e03131; }
        .filters { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:20px; align-items:flex-end; }
        .tabs { display:flex; gap:8px; margin-bottom:20px; }

        .employee-checkboxes {
            max-height: 250px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 10px;
            background: #fff;
            min-width: 280px;
        }
        .employee-checkboxes label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            padding: 3px 0;
            cursor: pointer;
            white-space: normal;
            word-break: break-word;
        }
        .employee-checkboxes input[type="checkbox"] {
            margin: 0;
            flex-shrink: 0;
            width: 16px;
            height: 16px;
        }
        .select-all-btn {
            margin-top: 6px;
            margin-right: 6px;
        }
        tr.overdue { background-color: #fff0f0; }
        tr.completed { background-color: #f0fff0; }
        .quest-actions { margin-top: 8px; display: flex; gap: 8px; }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="dashboard.php" class="logo">🚀 SZB</a>
        <a href="dashboard.php">Дашборд</a>
        <a href="team.php">Команда</a>
        <a href="territories.php">Территории</a>
        <a href="export_inn.php">ИНН</a>
        <a href="quests.php" class="active">Квесты</a>
        <a href="ai.php">AI</a>
        <?php if ($role === 'admin'): ?><a href="admin.php">Админ</a><?php endif; ?>
        <span class="user"><?= htmlspecialchars($_SESSION['name']) ?></span>
        <a href="logout.php" class="logout">Выйти</a>
    </div>

    <?= $message ?>

    <?php if ($is_head): ?>
        <h2>🎯 Управление квестами</h2>

        <div class="tabs">
            <a href="?tab=overview" class="btn btn-sm <?= $selected_tab=='overview'?'active':'' ?>">Обзор</a>
            <a href="?tab=create" class="btn btn-sm <?= $selected_tab=='create'?'active':'' ?>">Создать квест</a>
            <a href="?tab=rating" class="btn btn-sm <?= $selected_tab=='rating'?'active':'' ?>">Рейтинг</a>
        </div>

        <?php if ($selected_tab == 'overview'): ?>
            <form class="filters" method="get">
                <input type="hidden" name="tab" value="overview">
                <div class="form-group">
                    <label>Назначен с</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($filter_date_from) ?>">
                </div>
                <div class="form-group">
                    <label>по</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($filter_date_to) ?>">
                </div>
                <div class="form-group">
                    <label> </label>
                    <label><input type="checkbox" name="overdue" <?= $filter_overdue?'checked':'' ?>> Просроченные</label>
                </div>
                <button type="submit" class="btn btn-sm">🔍 Фильтр</button>
            </form>

            <?php foreach ($quests as $quest): 
                $is_overdue = !empty($quest['ends_at']) && strtotime($quest['ends_at']) < time();
            ?>
                <div class="card quest-card <?= !empty($quest['mandatory']) ? 'mandatory' : '' ?>">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <strong><?= htmlspecialchars($quest['title']) ?></strong>
                        <div>
                            <span class="badge badge-<?= $quest['type']=='group'?'warning':'primary' ?>"><?= $quest['type']=='group'?'Групповой':'Личный' ?></span>
                            <?= !empty($quest['mandatory']) ? '<span class="red-text">(поручение)</span>' : '' ?>
                        </div>
                    </div>
                    <p><?= htmlspecialchars($quest['description']) ?></p>
                    <div>Баллы: <strong><?= $quest['points'] ?></strong> | Срок: <?= !empty($quest['ends_at']) ? date('d.m.Y', strtotime($quest['ends_at'])) : 'без срока' ?></div>
                    <div>Назначены: <?= $quest['assignees'] ?: '—' ?></div>

                    <?php if (!empty($takers_by_quest[$quest['id']])): ?>
                        <table style="margin-top:10px;">
                            <tr><th>Сотрудник</th><th>Статус</th><th>Отчёт</th><th>Действие</th></tr>
                            <?php foreach ($takers_by_quest[$quest['id']] as $taker): ?>
                                <tr>
                                    <td><?= htmlspecialchars($taker['full_name']) ?></td>
                                    <td><?= htmlspecialchars($taker['status']) ?></td>
                                    <td><?= htmlspecialchars($taker['report'] ?? '') ?></td>
                                    <td>
                                        <?php if ($taker['status'] == 'completed'): ?>
                                            <a href="?confirm=<?= $taker['id'] ?>&tab=overview" class="btn btn-sm">✅ Подтвердить</a>
                                        <?php elseif ($taker['status'] == 'failed'): ?>
                                            <a href="?close=<?= $taker['id'] ?>&tab=overview" class="btn btn-sm btn-danger">🚫 Закрыть без исполнения</a>
                                        <?php elseif ($is_overdue && !in_array($taker['status'], ['rewarded','failed','closed'])): ?>
                                            <a href="?penalize=<?= $taker['id'] ?>&tab=overview" class="btn btn-sm btn-danger">⚠️ Штраф</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php endif; ?>
                    <div class="quest-actions">
                        <a href="?tab=create&edit=<?= $quest['id'] ?>" class="btn btn-sm">✏️ Редактировать</a>
                        <a href="?delete_quest=<?= $quest['id'] ?>&tab=overview" class="btn btn-sm btn-danger" onclick="return confirm('Удалить квест и все связанные записи?')">✕ Удалить</a>
                    </div>
                </div>
            <?php endforeach; ?>

        <?php elseif ($selected_tab == 'create'): ?>
            <?php
            // Если передан параметр edit, загружаем квест для редактирования
            $edit_mode = false;
            $edit_quest = null;
            if (isset($_GET['edit']) && $is_head) {
                $stmt = $pdo->prepare("SELECT * FROM quests WHERE id = ? AND head_tabel = ?");
                $stmt->execute([$_GET['edit'], $tabel]);
                $edit_quest = $stmt->fetch();
                if ($edit_quest) {
                    $edit_mode = true;
                    // Загружаем уже назначенных сотрудников
                    $stmt_takers = $pdo->prepare("SELECT employee_tabel FROM quest_takers WHERE quest_id = ?");
                    $stmt_takers->execute([$edit_quest['id']]);
                    $assigned_tabels = $stmt_takers->fetchAll(PDO::FETCH_COLUMN);
                }
            }
            ?>
            <div class="card">
                <h3><?= $edit_mode ? '✏️ Редактировать квест' : '➕ Новый квест' ?></h3>
                <form method="post" class="grid2">
                    <input type="hidden" name="save_quest" value="1">
                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="quest_id" value="<?= $edit_quest['id'] ?>">
                    <?php endif; ?>
                    <div class="form-group">
                        <label>Тип</label>
                        <select name="type">
                            <option value="individual" <?= ($edit_quest['type'] ?? '') == 'individual' ? 'selected' : '' ?>>Личный</option>
                            <option value="group" <?= ($edit_quest['type'] ?? '') == 'group' ? 'selected' : '' ?>>Групповой</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Название</label>
                        <input type="text" name="title" required value="<?= htmlspecialchars($edit_quest['title'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Описание (что нужно сделать)</label>
                        <input type="text" name="description" value="<?= htmlspecialchars($edit_quest['description'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Баллы</label>
                        <input type="number" name="points" value="<?= $edit_quest['points'] ?? 10 ?>" min="1">
                    </div>
                    <div class="form-group">
                        <label>Срок выполнения</label>
                        <input type="date" name="deadline" value="<?= $edit_quest['ends_at'] ?? '' ?>">
                    </div>
                    <div class="form-group">
                        <label>Обязательный (поручение)</label>
                        <input type="checkbox" name="mandatory" value="1" <?= !empty($edit_quest['mandatory']) ? 'checked' : '' ?>>
                    </div>

                    <div class="form-group">
                        <label>Назначить сотрудников</label>
                        <div class="employee-checkboxes" id="employeeList">
                            <?php foreach ($all_managers as $m): 
                                $checked = $edit_mode && in_array($m['tabel_number'], $assigned_tabels ?? []);
                            ?>
                                <label>
                                    <input type="checkbox" name="assign_to[]" value="<?= htmlspecialchars($m['tabel_number']) ?>" <?= $checked ? 'checked' : '' ?>>
                                    <?= htmlspecialchars($m['full_name']) ?> (<?= htmlspecialchars($m['tabel_number']) ?>)
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top:6px;">
                            <button type="button" class="btn btn-sm select-all-btn" onclick="checkAllEmployees()">Выбрать всех</button>
                            <button type="button" class="btn btn-sm select-all-btn" onclick="uncheckAllEmployees()">Снять выделение</button>
                        </div>
                        <div class="form-group" style="margin-top:10px;">
                            <label>Или введите табельные номера через запятую</label>
                            <input type="text" name="assign_to_text" placeholder="123,456,789">
                        </div>
                    </div>

                    <button type="submit" class="btn">💾 Сохранить</button>
                </form>
            </div>

        <?php elseif ($selected_tab == 'rating'): ?>
            <div class="card">
                <h3>🏆 Рейтинг менеджеров</h3>
                <table>
                    <tr><th>Сотрудник</th><th>Баллы</th><th>Уровень</th><th>Звание</th></tr>
                    <?php foreach ($rating as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['full_name']) ?></td>
                            <td><?= $r['total_points'] ?></td>
                            <td><?= $r['level'] ?></td>
                            <td><?= htmlspecialchars($r['rank']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>

    <?php elseif ($role === 'manager'): ?>
        <h2>🎯 Квесты</h2>
        <div class="card">
            <h3>📌 Доступные квесты</h3>
            <?php if (empty($available_quests)): ?>
                <p>Нет новых квестов.</p>
            <?php else: ?>
                <?php foreach ($available_quests as $q): 
                    $is_pending = ($q['type'] == 'individual'); // pending индивидуальные
                ?>
                    <div class="quest-card card <?= !empty($q['mandatory']) ? 'mandatory' : '' ?>" style="margin-bottom:10px;">
                        <div style="display:flex; justify-content:space-between;">
                            <strong><?= htmlspecialchars($q['title']) ?></strong>
                            <span class="badge badge-<?= $q['type']=='group'?'warning':'primary' ?>"><?= $q['type']=='group'?'Групповой':'Личный' ?></span>
                        </div>
                        <p><?= htmlspecialchars($q['description']) ?></p>
                        <div>Баллы: <strong><?= $q['points'] ?></strong> | Срок: <?= !empty($q['ends_at']) ? date('d.m.Y', strtotime($q['ends_at'])) : 'без срока' ?></div>
                        <?php if (!empty($q['mandatory'])): ?><div class="red-text">(поручение)</div><?php endif; ?>
                        <a href="?take=<?= $q['id'] ?>" class="btn btn-sm" style="margin-top:6px;"><?= $q['type']=='group'?'Принять участие':'Принять' ?></a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>📋 Мои квесты</h3>
            <?php if (empty($my_quests)): ?>
                <p>Вы пока не приняли ни одного квеста.</p>
            <?php else: ?>
                <table>
                    <tr>
                        <th>Название</th>
                        <th>Тип</th>
                        <th>Баллы</th>
                        <th>Срок</th>
                        <th>Описание</th>
                        <th>Назначен</th>
                        <th>Выполнен</th>
                        <th>Статус</th>
                        <th>Отчёт</th>
                        <th>Действия</th>
                    </tr>
                    <?php foreach ($my_quests as $mq): 
                        $is_overdue = !empty($mq['ends_at']) && strtotime($mq['ends_at']) < time() && !in_array($mq['status'], ['rewarded','failed','closed']);
                        $is_completed = in_array($mq['status'], ['completed', 'rewarded']);
                    ?>
                        <tr class="<?= $is_overdue ? 'overdue' : '' ?><?= $is_completed ? 'completed' : '' ?>">
                            <td><?= htmlspecialchars($mq['title']) ?> <?= !empty($mq['mandatory']) ? '<span class="red-text">(поручение)</span>' : '' ?></td>
                            <td><?= $mq['type']=='group'?'Групповой':'Личный' ?></td>
                            <td><?= $mq['points'] ?></td>
                            <td><?= !empty($mq['ends_at']) ? date('d.m.Y', strtotime($mq['ends_at'])) : '—' ?></td>
                            <td><?= htmlspecialchars($mq['description']) ?></td>
                            <td><?= !empty($mq['taken_at']) ? date('d.m.Y H:i', strtotime($mq['taken_at'])) : '—' ?></td>
                            <td><?= !empty($mq['completed_at']) ? date('d.m.Y H:i', strtotime($mq['completed_at'])) : '—' ?></td>
                            <td><?= htmlspecialchars($mq['status']) ?></td>
                            <td><?= htmlspecialchars($mq['report'] ?? '') ?></td>
                            <td>
                                <?php if ($mq['status'] == 'taken'): ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="complete_quest" value="1">
                                        <input type="hidden" name="quest_id" value="<?= $mq['id'] ?>">
                                        <input type="text" name="report" placeholder="Отчёт" style="width:120px;">
                                        <button type="submit" class="btn btn-sm">✔️ Выполнено</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function checkAllEmployees() {
    const checkboxes = document.querySelectorAll('#employeeList input[type="checkbox"]');
    checkboxes.forEach(cb => cb.checked = true);
}
function uncheckAllEmployees() {
    const checkboxes = document.querySelectorAll('#employeeList input[type="checkbox"]');
    checkboxes.forEach(cb => cb.checked = false);
}
</script>
</body>
</html>