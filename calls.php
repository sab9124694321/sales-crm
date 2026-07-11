<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once 'db.php';
$role = $_SESSION['role'];
if (!in_array($role, ['manager', 'head', 'admin', 'territory_head', 'ubr_middle'])) { die('Только для менеджеров'); }
$tabel = $_SESSION['tabel'];
$user_name = $_SESSION['name'];
$user_id = $_SESSION['user_id'];

// --- План на день ---
$stmt = $pdo->prepare("SELECT calls_plan FROM plans WHERE tabel_number = ? AND period = strftime('%Y-%m', 'now')");
$stmt->execute([$tabel]);
$month_plan = $stmt->fetchColumn() ?: 350;
$work_days = 22;
$daily_plan = ceil($month_plan / $work_days);

// --- Сегодняшние звонки ---
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT COALESCE(SUM(calls),0) FROM daily_reports WHERE tabel_number = ? AND report_date = ?");
$stmt->execute([$tabel, $today]);
$done_today = $stmt->fetchColumn();
$remaining = max(0, $daily_plan - $done_today);

// --- Загрузка задач из БД ---
// v2.2: включаем все статусы кроме завершённых (Согласен, Отказ подтверждён)
$stmt = $pdo->prepare("
    SELECT * FROM epk_tasks 
    WHERE user_tabel = ? 
      AND status NOT IN ('Согласен', 'Отказ подтверждён')
    ORDER BY 
        CASE 
            WHEN next_call_date IS NOT NULL AND date(next_call_date) = date('now') THEN 0
            WHEN next_call_date IS NOT NULL AND date(next_call_date) < date('now') THEN 1
            WHEN next_call_date IS NOT NULL THEN 2
            ELSE 3
        END,
        next_call_date ASC,
        imported_at DESC
");
$stmt->execute([$tabel]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Прогресс по задачам ---
$stmt_progress = $pdo->prepare("
    SELECT task_id, 
        COUNT(*) as total_calls,
        MAX(created_at) as last_call,
        MAX(deal_readiness) as max_readiness,
        MAX(next_call_date) as next_call_date,
        MAX(call_result) as last_result
    FROM call_comments 
    WHERE user_id = ? 
    GROUP BY task_id
");
$stmt_progress->execute([$user_id]);
$progress_rows = $stmt_progress->fetchAll(PDO::FETCH_ASSOC);
$progress = [];
foreach ($progress_rows as $row) {
    $progress[$row['task_id']] = $row;
}

// --- Конкурентные аргументы ---
$competitive = [
    'Тинькофф' => 'Сбер — больше точек обслуживания, интеграция с 1С, СБП: 0,3% (SberPay QR/FaceScan, первые 6 мес), без НДС 22%',
    'Альфа' => 'Сбер — АПМ (SberPay, QR, биометрия) без НДС 22%, поддержка 24/7, кэшбэк',
    'Райффайзен' => 'Сбер — АПМ от 0,3%, без НДС 22%. Обычный эквайринг от 1% (первые 3 мес)',
    'Модульбанк' => 'Сбер — надёжность №1, АПМ без НДС 22%, бонусы СберСпасибо'
];

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Я звоню — SZB CRM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { background:#f0f2f5; font-family:system-ui, -apple-system, sans-serif; padding:12px; }
        .container { max-width:1300px; margin:0 auto; }
        .nav { display:flex; align-items:center; padding:12px 20px; background:#fff; border-radius:12px; margin-bottom:12px; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
        .nav a { color:#1a73e8; text-decoration:none; font-weight:500; margin-right:20px; }
        .nav a:hover { text-decoration:underline; }
        .nav .right { margin-left:auto; font-size:0.85rem; color:#5f6368; }
        .stats-bar { display:flex; gap:16px; padding:12px 20px; background:#fff; border-radius:12px; margin-bottom:12px; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
        .stat { text-align:center; }
        .stat-value { font-size:1.5rem; font-weight:700; color:#1a73e8; }
        .stat-label { font-size:0.75rem; color:#5f6368; }
        .main { display:grid; grid-template-columns:320px 1fr; gap:12px; }
        @media(max-width:900px){ .main { grid-template-columns:1fr; } }
        .panel { background:#fff; border-radius:12px; padding:16px; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
        .panel h3 { margin-bottom:12px; font-size:1rem; color:#202124; }
        .task-item { padding:10px 12px; border-radius:8px; margin-bottom:6px; cursor:pointer; transition:all 0.2s; border-left:3px solid transparent; position:relative; }
        .task-item:hover { background:#f8f9fa; }
        .task-item.active { background:#e8f0fe; border-left-color:#1a73e8; }
        .task-item .task-num { font-weight:600; font-size:0.85rem; color:#1a73e8; }
        .task-item .task-status { font-size:0.7rem; padding:2px 6px; border-radius:4px; margin-left:6px; }
        .task-item .task-calls { font-size:0.7rem; color:#5f6368; margin-left:6px; }
        .task-item .task-think-time { font-size:0.7rem; color:#f9ab00; margin-left:6px; }
        .task-item .task-product { font-size:0.75rem; color:#5f6368; margin-top:2px; }
        .task-item .task-date { font-size:0.7rem; color:#80868b; }
        .task-item .delete-btn { position:absolute; right:8px; top:50%; transform:translateY(-50%); background:#fce8e6; color:#c5221f; border:none; border-radius:4px; padding:2px 6px; font-size:0.7rem; cursor:pointer; opacity:0; transition:opacity 0.2s; }
        .task-item:hover .delete-btn { opacity:1; }
        .status-badge { display:inline-block; padding:2px 8px; border-radius:12px; font-size:0.7rem; font-weight:500; }
        .status-new { background:#e8f0fe; color:#1a73e8; }
        .status-confirmed { background:#e6f4ea; color:#188038; }
        .status-rop { background:#fce8e6; color:#c5221f; }
        .status-think { background:#fef3e8; color:#b06000; }
        .status-noanswer { background:#f3e8fd; color:#9334e6; }
        .status-signed { background:#e6f4ea; color:#188038; }  /* Согласен — зелёный */
        .status-recall { background:#e8f0fe; color:#1a73e8; }  /* Перезвон — синий */
        .status-nocontact { background:#fce8e6; color:#c5221f; }  /* Нет контакта — красный */
        .smart-form { display:grid; gap:10px; }
        .smart-form label { font-size:0.8rem; font-weight:500; color:#5f6368; }
        .smart-form input, .smart-form select, .smart-form textarea { padding:8px 12px; border:1px solid #dadce0; border-radius:8px; font-size:0.9rem; width:100%; }
        .smart-form textarea { min-height:80px; resize:vertical; }
        .btn { padding:10px 16px; border:none; border-radius:8px; cursor:pointer; font-size:0.85rem; font-weight:500; transition:all 0.2s; }
        .btn-primary { background:#1a73e8; color:#fff; }
        .btn-primary:hover { background:#1557b0; }
        .btn-secondary { background:#f1f3f4; color:#3c4043; }
        .btn-secondary:hover { background:#e8eaed; }
        .btn-danger { background:#fce8e6; color:#c5221f; }
        .btn-danger:hover { background:#fad2cf; }
        .btn-success { background:#e6f4ea; color:#188038; }
        .btn-success:hover { background:#ceead6; }
        .btn-group { display:flex; gap:8px; flex-wrap:wrap; margin-top:12px; }
        .comment-preview { background:#f8f9fa; padding:12px; border-radius:8px; font-size:0.85rem; line-height:1.5; margin:12px 0; border-left:3px solid #1a73e8; }
        .toast { position:fixed; bottom:20px; right:20px; background:#323232; color:#fff; padding:12px 20px; border-radius:8px; opacity:0; transform:translateY(20px); transition:all 0.3s; z-index:1000; }
        .toast.show { opacity:1; transform:translateY(0); }
        .history-entry { padding:8px 0; border-bottom:1px solid #e8eaed; }
        .history-entry:last-child { border-bottom:none; }
        .h-date { font-size:0.7rem; color:#80868b; }
        .h-status { font-size:0.75rem; padding:2px 6px; border-radius:4px; }
        .ai-box { background:#f8f9fa; border-radius:8px; padding:12px; margin-top:12px; font-size:0.85rem; }
        .ai-box ul { padding-left:16px; margin:6px 0; }
        .ai-box li { margin:4px 0; }
        .competitor { background:#fff8e1; border-radius:8px; padding:10px; margin-top:8px; font-size:0.8rem; }
        .empty-state { text-align:center; padding:40px; color:#80868b; }
        .form-section { margin-bottom:16px; }
        .form-section-title { font-size:0.8rem; font-weight:600; color:#5f6368; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.5px; }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="dashboard.php">Дашборд</a>
        <a href="calls.php">Я звоню</a>
        <a href="rop_control.php">Контроль</a>
        <span class="right"><?= htmlspecialchars($user_name) ?> | <?= $tabel ?></span>
    </div>

    <div class="stats-bar">
        <div class="stat"><div class="stat-value"><?= $daily_plan ?></div><div class="stat-label">План на день</div></div>
        <div class="stat"><div class="stat-value"><?= $done_today ?></div><div class="stat-label">Сделано</div></div>
        <div class="stat"><div class="stat-value"><?= $remaining ?></div><div class="stat-label">Осталось</div></div>
        <div class="stat"><div class="stat-value"><?= count($tasks) ?></div><div class="stat-label">Задач</div></div>

        <!-- === ИМПОРТ ЗАДАЧ === -->
        <div class="import-box" style="margin-left:auto; display:flex; gap:8px; align-items:flex-start;">
            <textarea id="taskInput" placeholder="Вставьте UUID задач..." 
                style="width:280px; height:60px; padding:6px 10px; border:1px solid #dadce0; border-radius:8px; font-size:0.75rem; font-family:monospace; resize:vertical;"></textarea>
            <div style="display:flex; flex-direction:column; gap:6px;">
                <button class="btn btn-primary" onclick="addTasks()" style="padding:6px 12px; font-size:0.75rem;">➕ Добавить</button>
                <button class="btn btn-secondary" onclick="clearTaskInput()" style="padding:6px 12px; font-size:0.75rem;">🧹 Очистить</button>
            </div>
        </div>
    </div>

    <div class="main">
        <!-- Левая колонка: список задач -->
        <div class="panel">
            <h3>Задачи</h3>
            <?php if (empty($tasks)): ?>
                <div class="empty-state">Нет задач</div>
            <?php else: ?>
                <?php foreach ($tasks as $task): 
                    $tid = $task['task_id'];
                    $p = $progress[$tid] ?? null;
                    $calls = $p['total_calls'] ?? 0;
                    $last_result = $p['last_result'] ?? '';
                    $status_class = 'status-new';
                    $status_text = 'Новая';
                    if ($task['status'] === 'Подтверждена') { $status_class = 'status-confirmed'; $status_text = 'Подтверждена'; }
                    elseif ($task['status'] === 'На контроле РОП') { $status_class = 'status-rop'; $status_text = 'На контроле'; }
                    elseif ($task['status'] === 'Думает') { $status_class = 'status-think'; $status_text = 'Думает'; }
                    elseif ($task['status'] === 'Недозвон') { $status_class = 'status-noanswer'; $status_text = 'Недозвон'; }

                    // Время в статусе "думает"
                    $think_time_str = '';
                    if ($task['status'] === 'Думает' && $task['first_status_at']) {
                        $first = new DateTime($task['first_status_at']);
                        $now = new DateTime();
                        $diff = $first->diff($now);
                        $think_time_str = $diff->format('%dд %hч');
                    }
                ?>
                <div class="task-item" data-task="<?= htmlspecialchars($tid) ?>" onclick="selectTask('<?= htmlspecialchars($tid) ?>')">
                    <div style="display:flex;align-items:center;flex-wrap:wrap;">
                        <span class="task-num">...<?= htmlspecialchars(substr($tid, -8)) ?></span>
                        <span class="status-badge <?= $status_class ?>"><?= $status_text ?></span>
                        <?php if ($calls > 0): ?><span class="task-calls">(<?= $calls ?> зв.)</span><?php endif; ?>
                        <?php if ($think_time_str): ?><span class="task-think-time"><?= $think_time_str ?></span><?php endif; ?>
                        <?php if ($task['status'] === 'Назначена'): ?>
                            <button class="delete-btn" onclick="event.stopPropagation(); deleteTask('<?= htmlspecialchars($tid) ?>')" title="Удалить">×</button>
                        <?php endif; ?>
                    </div>
                    <div class="task-product"><?= htmlspecialchars($task['product'] ?? 'Торговый эквайринг') ?></div>
                    <div class="task-date"><?= $task['next_call_date'] ? date('d.m.Y H:i', strtotime($task['next_call_date'])) : 'Без даты' ?></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Правая колонка: форма -->
        <div class="panel" id="formPanel">
            <h3 id="formTitle">Выберите задачу</h3>
            <div id="ritmLink" style="display:none; margin-bottom:12px;">
                <a href="#" target="_blank" style="color:#1a73e8; font-size:0.85rem; text-decoration:none;">
                    🔗 Открыть в Ритм
                </a>
            </div>
            <div id="formContent" style="display:none;">

                <div class="form-section">
                    <div class="form-section-title">Результат звонка</div>
                    <select id="callStatus" onchange="onStatusChange()">
                        <option value="think">Думает</option>
                        <option value="signed">Согласен</option>
                        <option value="reject">Отказ</option>
                        <option value="noanswer">Недозвон</option>
                        <option value="contract">Согласен (договор)</option>
                        <option value="recall">Перезвон</option>
                        <option value="nocontact">Нет контакта</option>
                    </select>
                </div>

                <div id="smartFields">
                    <div class="form-section">
                        <div class="form-section-title">1. Что беспокоит клиента?</div>
                        <input type="text" id="painPoint" placeholder="Проблема клиента">
                    </div>
                    <div class="form-section">
                        <div class="form-section-title">2. Возражения</div>
                        <select id="objection">
                            <option value="">Выберите...</option>
                            <option value="цена">Цена</option>
                            <option value="конкуренты">Конкуренты</option>
                            <option value="не_нужен">Не нужен сейчас</option>
                            <option value="нет_решения">Нет решения</option>
                            <option value="другое">Другое</option>
                        </select>
                        <textarea id="objectionText" placeholder="Детали возражения" style="margin-top:6px;"></textarea>
                    </div>
                    <div class="form-section">
                        <div class="form-section-title">3. Что договорились?</div>
                        <textarea id="nextStep" placeholder="Конкретные договорённости"></textarea>
                    </div>
                    <div class="form-section">
                        <div class="form-section-title">4. Кто принимает решение?</div>
                        <input type="text" id="decisionMaker" placeholder="Роль (не ФИО)">
                    </div>
                    <div class="form-section">
                        <div class="form-section-title">5. Следующий контакт</div>
                        <input type="date" id="nextCallDate">
                        <input type="time" id="nextCallTime" style="margin-top:6px;">
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title">Комментарий</div>
                    <textarea id="freeComment" placeholder="Свободный комментарий..."></textarea>
                </div>

                <div class="comment-preview" id="assembledComment" style="display:none;">
                    <strong>Собранный комментарий:</strong><br>
                    <span id="commentText"></span>
                </div>

                <div class="btn-group">
                    <button class="btn btn-secondary" onclick="copyComment()">Копировать</button>
                    <button class="btn btn-primary" onclick="saveCall()">Сохранить звонок</button>
                    <button class="btn btn-secondary" onclick="copyAndGo()">Скопировать и перейти в Ритм</button>
                </div>

                <div id="aiSection" style="margin-top:16px;">
                    <button class="btn btn-secondary" onclick="getPlan()" style="width:100%;">Получить микроплан</button>
                    <div class="ai-box" id="aiPlan" style="display:none;"></div>
                </div>

                <div id="historySection" style="margin-top:16px; display:none;">
                    <h4 style="font-size:0.9rem; margin-bottom:8px;">История</h4>
                    <div id="historyList"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
let currentTask = null;
let commentHistory = [];

// ========== ВЫБОР ЗАДАЧИ ==========
function selectTask(taskId) {
    currentTask = taskId;
    document.querySelectorAll('.task-item').forEach(el => el.classList.remove('active'));
    document.querySelector('[data-task="' + taskId + '"]').classList.add('active');

    document.getElementById('formTitle').textContent = 'Задача: ' + taskId.substring(0, 12) + '...';
    document.getElementById('formContent').style.display = 'block';

    // Обновляем ссылку на Ритм
    const ritmLink = document.getElementById('ritmLink');
    const ritmUrl = 'https://new-tortuga.sigma.sbrf.ru/tort/tasks/sales/' + taskId;
    ritmLink.querySelector('a').href = ritmUrl;
    ritmLink.style.display = 'block';

    // Сброс формы
    document.getElementById('callStatus').value = 'think';
    document.getElementById('painPoint').value = '';
    document.getElementById('objection').value = '';
    document.getElementById('objectionText').value = '';
    document.getElementById('nextStep').value = '';
    document.getElementById('decisionMaker').value = '';
    document.getElementById('nextCallDate').value = '';
    document.getElementById('nextCallTime').value = '';
    document.getElementById('freeComment').value = '';
    document.getElementById('assembledComment').style.display = 'none';
    document.getElementById('aiPlan').style.display = 'none';

    onStatusChange();
    loadHistory(taskId);
}

// ========== ИЗМЕНЕНИЕ СТАТУСА ==========
function onStatusChange() {
    const status = document.getElementById('callStatus').value;
    const smartFields = document.getElementById('smartFields');

    // Финальные и технические статусы — скрываем smart-форму, комментарий необязателен
    const optionalStatuses = ['signed', 'contract', 'reject', 'noanswer', 'nocontact'];
    if (optionalStatuses.includes(status)) {
        smartFields.style.display = 'none';
    } else {
        smartFields.style.display = 'block';
    }

    updateAssembledComment();
}

// ========== СОБРАТЬ КОММЕНТАРИЙ ==========
function assembleComment() {
    const status = document.getElementById('callStatus').value;
    const freeComment = document.getElementById('freeComment').value.trim();

    // Для финальных/технических статусов — комментарий необязателен
    if (status === 'noanswer') {
        return freeComment || 'Недозвон';
    }
    if (status === 'signed' || status === 'contract') {
        return freeComment || 'Согласен';
    }
    if (status === 'nocontact') {
        return freeComment || 'Нет контакта';
    }
    if (status === 'reject') {
        return freeComment || 'Отказ';
    }

    const pain = document.getElementById('painPoint').value.trim();
    const objection = document.getElementById('objection').value;
    const objectionText = document.getElementById('objectionText').value.trim();
    const nextStep = document.getElementById('nextStep').value.trim();
    const decision = document.getElementById('decisionMaker').value.trim();
    const nextDate = document.getElementById('nextCallDate').value;
    const nextTime = document.getElementById('nextCallTime').value;

    let parts = [];
    if (pain) parts.push('Проблема: ' + pain);
    if (objection) parts.push('Возражение: ' + objection + (objectionText ? ' — ' + objectionText : ''));
    if (nextStep) parts.push('Договорились: ' + nextStep);
    if (decision) parts.push('Решение: ' + decision);
    if (nextDate) parts.push('Следующий контакт: ' + nextDate + (nextTime ? ' ' + nextTime : ''));
    if (freeComment) parts.push('Комментарий: ' + freeComment);

    return parts.join('. ');
}

function updateAssembledComment() {
    const comment = assembleComment();
    if (comment) {
        document.getElementById('commentText').textContent = comment;
        document.getElementById('assembledComment').style.display = 'block';
    } else {
        document.getElementById('assembledComment').style.display = 'none';
    }
}

// ========== СОБРАТЬ ПОЛНЫЙ ТЕКСТ (текущий + история) ==========
function assembleFullText() {
    const comment = assembleComment();
    if (!comment.trim()) return '';

    let fullText = comment;

    // Добавляем историю, если есть
    if (commentHistory && commentHistory.length > 0) {
        fullText += '\n\n--- История звонков ---';
        commentHistory.forEach((h) => {
            const statusMap = {
                think: 'Думает', signed: 'Согласен', reject: 'Отказ',
                nocontact: 'Нет контакта', recall: 'Перезвон',
                noanswer: 'Недозвон', contract: 'Договор'
            };
            const statusText = statusMap[h.call_result] || h.call_result;
            fullText += '\n\n[' + h.created_at + '] ' + statusText + ':\n' + (h.comment_text || '(без комментария)');
        });
    }

    return fullText;
}

// ========== КОПИРОВАТЬ КОММЕНТАРИЙ ==========
function copyComment() {
    const fullText = assembleFullText();
    if (!fullText) { showToast('Заполните форму'); return; }

    navigator.clipboard.writeText(fullText).then(() => {
        showToast('Скопировано в буфер (с историей)');
    });
}

// ========== СОХРАНИТЬ ЗВОНОК ==========
function saveCall() {
    const comment = assembleComment();
    const callResult = document.getElementById('callStatus').value;

    // Для финальных/технических статусов комментарий необязателен
    const optionalCommentStatuses = ['signed', 'contract', 'reject', 'noanswer', 'nocontact'];
    if (!comment.trim() && !optionalCommentStatuses.includes(callResult)) {
        showToast('Заполните форму');
        return;
    }

    const callResult = document.getElementById('callStatus').value;
    const nextDate = document.getElementById('nextCallDate').value;
    const nextTime = document.getElementById('nextCallTime').value;
    const nextCallDateTime = nextDate ? (nextTime ? nextDate + ' ' + nextTime : nextDate) : '';

    // ДИАГНОСТИКА: проверяем данные перед отправкой
    console.log('=== saveCall() ===');
    console.log('currentTask:', currentTask);
    console.log('callResult:', callResult);
    console.log('comment:', comment.substring(0, 50) + '...');

    const data = {
        task_id: currentTask,
        call_result: callResult,
        pain_point: document.getElementById('painPoint').value,
        objection: document.getElementById('objection').value,
        objection_text: document.getElementById('objectionText').value,
        next_step: document.getElementById('nextStep').value,
        decision_maker: document.getElementById('decisionMaker').value,
        next_call_date: nextCallDateTime,
        free_comment: document.getElementById('freeComment').value
    };

    console.log('Отправляем:', JSON.stringify(data, null, 2));

    fetch('api_save_call_comment.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(r => {
        console.log('Ответ сервера (HTTP):', r.status);
        return r.text();
    })
    .then(text => {
        console.log('Ответ сервера (текст):', text.substring(0, 200));
        let d;
        try {
            d = JSON.parse(text);
        } catch (e) {
            console.error('Ошибка парсинга JSON:', e);
            showToast('Ошибка сервера: ' + text.substring(0, 100));
            return;
        }
        if (d.success) {
            // Копируем в буфер (текущий + история)
            const fullText = assembleFullText();
            if (fullText) {
                navigator.clipboard.writeText(fullText).then(() => {
                    showToast('Сохранено и скопировано. Фрод-скор: ' + d.fraud_score);
                });
            } else {
                showToast('Сохранено. Фрод-скор: ' + d.fraud_score);
            }

            // Обновляем список задач без перезагрузки
            updateTaskInList(currentTask, d.new_status, d.call_count);

            // Если задача завершена — убираем из списка
            if (d.new_status === 'Согласен' || d.new_status === 'Отказ подтверждён') {
                removeTaskFromList(currentTask);
                document.getElementById('formContent').style.display = 'none';
                document.getElementById('formTitle').textContent = 'Выберите задачу';
                currentTask = null;
            }

            loadHistory(currentTask);
        } else {
            showToast('Ошибка: ' + (d.error || 'Неизвестная ошибка'));
        }
    })
    .catch(err => {
        console.error('Ошибка сети:', err);
        showToast('Ошибка сети: ' + err);
    });
}

// ========== ОБНОВИТЬ ЗАДАЧУ В СПИСКЕ (без перезагрузки) ==========
function updateTaskInList(taskId, newStatus, callCount) {
    const taskEl = document.querySelector('[data-task="' + taskId + '"]');
    if (!taskEl) return;

    // Обновляем счётчик звонков
    let callsBadge = taskEl.querySelector('.task-calls');
    if (!callsBadge && callCount > 0) {
        callsBadge = document.createElement('span');
        callsBadge.className = 'task-calls';
        taskEl.querySelector('.task-num').after(callsBadge);
    }
    if (callsBadge) callsBadge.textContent = '(' + callCount + ' зв.)';

    // Обновляем статус
    const statusBadge = taskEl.querySelector('.status-badge');
    const statusMap = {
        'Назначена': {text: 'Новая', cls: 'status-new'},
        'Подтверждена': {text: 'Подтверждена', cls: 'status-confirmed'},
        'На контроле РОП': {text: 'На контроле', cls: 'status-rop'},
        'Думает': {text: 'Думает', cls: 'status-think'},
        'Недозвон': {text: 'Недозвон', cls: 'status-noanswer'}
    };
    const s = statusMap[newStatus] || {text: newStatus, cls: 'status-new'};
    statusBadge.textContent = s.text;
    statusBadge.className = 'status-badge ' + s.cls;
}

function removeTaskFromList(taskId) {
    const taskEl = document.querySelector('[data-task="' + taskId + '"]');
    if (taskEl) taskEl.remove();
}

// ========== УДАЛИТЬ ЗАДАЧУ ==========
function deleteTask(taskId) {
    if (!confirm('Удалить задачу?')) return;

    fetch('api_clear_tasks.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({task_ids: [taskId]})
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            removeTaskFromList(taskId);
            showToast('Задача удалена');
            if (currentTask === taskId) {
                document.getElementById('formContent').style.display = 'none';
                document.getElementById('formTitle').textContent = 'Выберите задачу';
                currentTask = null;
            }
        } else {
            showToast('Ошибка: ' + (d.error || 'Не удалось удалить'));
        }
    });
}

// ========== ЗАГРУЗИТЬ ИСТОРИЮ ==========
function loadHistory(taskId) {
    fetch('api_call_history.php?task_id=' + encodeURIComponent(taskId))
    .then(r => r.json())
    .then(d => {
        commentHistory = d.history || [];
        const list = document.getElementById('historyList');
        const section = document.getElementById('historySection');

        if (commentHistory.length > 0) {
            section.style.display = 'block';
            list.innerHTML = commentHistory.map(h => {
                const statusClass = h.call_result || 'think';
                const statusMap = {
                    think: 'Думает', signed: 'Согласен', reject: 'Отказ', 
                    nocontact: 'Нет контакта', recall: 'Перезвон',
                    noanswer: 'Недозвон', contract: 'Договор'
                };
                const statusText = statusMap[h.call_result] || h.call_result;
                return '<div class="history-entry">' +
                    '<div class="h-date">' + h.created_at + ' (Звонок #' + (h.call_count || '?') + ')</div>' +
                    '<span class="h-status ' + statusClass + '">' + statusText + '</span>' +
                    '<div>' + (h.comment_text ? h.comment_text.substring(0, 200) + (h.comment_text.length > 200 ? '...' : '') : '') + '</div>' +
                    (h.next_call_date ? '<div style="font-size:0.7rem; color:#1a73e8; margin-top:2px;">След. контакт: ' + h.next_call_date + '</div>' : '') +
                    '</div>';
            }).join('');
        } else {
            section.style.display = 'none';
        }
    });
}

// ========== КОПИРОВАТЬ И ПЕРЕЙТИ ==========
function copyAndGo() {
    const fullText = assembleFullText();
    if (!fullText) { showToast('Заполните форму'); return; }

    navigator.clipboard.writeText(fullText).then(() => {
        showToast('Скопировано (с историей)');
        setTimeout(() => window.open('https://new-tortuga.sigma.sbrf.ru/tort/tasks/sales/' + currentTask, '_blank'), 500);
    });
}

// ========== МИКРОПЛАН ==========
function getPlan() {
    const btn = event.target;
    btn.textContent = 'Загрузка...';

    fetch('api_call_coach.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            mode: 'generate_plan',
            task_id: currentTask,
            history: commentHistory
        })
    })
    .then(r => r.json())
    .then(d => {
        btn.textContent = 'Получить микроплан';
        const box = document.getElementById('aiPlan');
        box.innerHTML = d.response || 'Нет данных';
        box.style.display = 'block';
    })
    .catch(() => {
        btn.textContent = 'Получить микроплан';
        showToast('Ошибка загрузки плана');
    });
}

// ========== TOAST ==========
function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3000);
}

// ========== ОБНОВЛЕНИЕ СБОРНОГО КОММЕНТАРИЯ ==========
document.querySelectorAll('.smart-form input, .smart-form select, .smart-form textarea').forEach(el => {
    el.addEventListener('input', updateAssembledComment);
});

// ========== ИМПОРТ ЗАДАЧ ==========
function isValidUUID(str) {
    const uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
    return uuidRegex.test(str.trim().toLowerCase());
}

function addTasks() {
    const raw = document.getElementById('taskInput').value;
    const lines = raw.split(/[\n,\s]+/).map(s => s.trim()).filter(s => s);

    const valid = [];
    const invalid = [];

    lines.forEach(id => {
        if (isValidUUID(id)) valid.push(id);
        else invalid.push(id);
    });

    if (valid.length === 0) {
        showToast('❌ Не найдено корректных номеров задач');
        return;
    }

    fetch('api_add_tasks.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({task_ids: valid})
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            let msg = `✅ Добавлено: ${d.added}`;
            if (d.skipped > 0) msg += ` (дублей: ${d.skipped})`;
            if (invalid.length > 0) msg += ` | ❌ Некорректных: ${invalid.length}`;
            showToast(msg);
            setTimeout(() => location.reload(), 800);
        } else {
            showToast('Ошибка: ' + (d.error || 'Неизвестная'));
        }
    })
    .catch(err => showToast('Ошибка сети: ' + err));
}

function clearTaskInput() {
    document.getElementById('taskInput').value = '';
}
</script>
</body>
</html>
