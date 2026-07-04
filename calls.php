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
$stmt = $pdo->prepare("
    SELECT * FROM epk_tasks 
    WHERE user_tabel = ? AND status IN ('Назначена', 'Подтверждена', 'На контроле РОП')
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
    <title>📞 Я звоню — SZB CRM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { background:#f0f2f5; font-family:system-ui, -apple-system, sans-serif; padding:12px; }
        .container { max-width:1300px; margin:0 auto; }
        .nav { display:flex; align-items:center; padding:12px 20px; background:linear-gradient(135deg,#1a1a2e,#16213e); color:#fff; border-radius:16px; margin-bottom:20px; gap:12px; flex-wrap:wrap; }
        .nav a { color:#ccc; text-decoration:none; padding:8px 14px; border-radius:8px; font-size:13px; font-weight:500; }
        .nav a:hover, .nav a.active { background:rgba(255,255,255,0.1); color:#fff; }
        .nav .logo { font-size:20px; font-weight:700; color:#fff; margin-right:auto; }
        .nav .user { margin-left:auto; color:#aaa; font-size:13px; }
        .nav a.logout { color:#e03131; }

        .stats-bar { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px,1fr)); gap:12px; margin-bottom:20px; }
        .stat-card { background:#fff; border-radius:16px; padding:16px; box-shadow:0 1px 3px rgba(0,0,0,0.05); text-align:center; }
        .stat-card .value { font-size:2rem; font-weight:800; }
        .stat-card .label { font-size:0.8rem; color:#666; }
        .stat-card.plan { border-left:4px solid #1a73e8; }
        .stat-card.done { border-left:4px solid #28a745; }
        .stat-card.remaining { border-left:4px solid #ffc107; }
        .stat-card.control { border-left:4px solid #dc3545; }

        .main-grid { display:grid; grid-template-columns:380px 1fr; gap:16px; }
        @media (max-width:1000px) { .main-grid { grid-template-columns:1fr; } }

        .card { background:#fff; border-radius:16px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:16px; }
        .card h3 { margin-bottom:12px; font-size:1.1rem; display:flex; align-items:center; gap:8px; }
        .card h3 .badge { background:#e8f0fe; color:#1a73e8; padding:2px 10px; border-radius:12px; font-size:0.75rem; }

        .task-input { width:100%; padding:12px; border:1px solid #ddd; border-radius:12px; font-size:0.85rem; font-family:monospace; resize:vertical; min-height:60px; }
        .task-input:focus { outline:none; border-color:#1a73e8; }
        .btn { padding:10px 18px; border:none; border-radius:10px; font-size:0.85rem; cursor:pointer; font-weight:600; transition:all 0.2s; }
        .btn-primary { background:#1a73e8; color:#fff; }
        .btn-primary:hover { background:#1557b0; }
        .btn-success { background:#28a745; color:#fff; }
        .btn-success:hover { background:#218838; }
        .btn-outline { background:#fff; color:#1a73e8; border:1px solid #1a73e8; }
        .btn-outline:hover { background:#f0f7ff; }
        .btn-warning { background:#ffc107; color:#333; }
        .btn-danger { background:#dc3545; color:#fff; }
        .btn-group { display:flex; gap:8px; margin-top:12px; flex-wrap:wrap; }

        .task-item { border:1px solid #e8ecf1; border-radius:12px; padding:12px; margin-bottom:10px; cursor:pointer; transition:all 0.2s; position:relative; }
        .task-item:hover { border-color:#1a73e8; box-shadow:0 2px 8px rgba(26,115,232,0.1); transform:translateY(-1px); }
        .task-item.active { border-color:#1a73e8; background:#f8fbff; }
        .task-item.urgent { border-left:4px solid #dc3545; background:#fff5f5; }
        .task-item.soon { border-left:4px solid #ffc107; background:#fffbf0; }
        .task-item.control { border-left:4px solid #dc3545; background:#fff5f5; border:2px solid #dc3545; }
        .task-item .task-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:6px; }
        .task-item .task-title { font-weight:600; font-size:0.9rem; }
        .task-item .task-meta { font-size:0.75rem; color:#888; }
        .task-item .task-time { font-size:0.7rem; font-weight:600; margin-top:4px; }
        .task-item .task-time.urgent { color:#dc3545; }
        .task-item .task-time.soon { color:#ed6c02; }
        .task-item .readiness-bar { height:4px; background:#e0e0e0; border-radius:2px; margin-top:8px; overflow:hidden; }
        .task-item .readiness-fill { height:100%; border-radius:2px; transition:width 0.3s; }
        .readiness-low { background:#d32f2f; }
        .readiness-mid { background:#ed6c02; }
        .readiness-high { background:#2e7d32; }
        .readiness-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:0.7rem; font-weight:600; }
        .readiness-badge.low { background:#ffebee; color:#c62828; }
        .readiness-badge.mid { background:#fff3e0; color:#ef6c00; }
        .readiness-badge.high { background:#e8f5e9; color:#2e7d32; }
        .control-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:0.7rem; font-weight:600; background:#ffebee; color:#c62828; }

        .ai-assistant { background:linear-gradient(135deg,#e6f7ff,#f0f9ff); border-left:4px solid #1890ff; border-radius:12px; padding:16px; margin-bottom:16px; }
        .ai-assistant .ai-header { display:flex; align-items:center; gap:8px; margin-bottom:10px; font-weight:600; color:#1a73e8; font-size:0.95rem; }
        .ai-assistant .ai-plan { background:#fff; border-radius:8px; padding:12px; font-size:0.85rem; line-height:1.5; }
        .ai-assistant .ai-plan ul { margin:6px 0; padding-left:18px; }
        .ai-assistant .ai-plan li { margin-bottom:4px; }
        .ai-assistant .ai-loading { color:#888; font-style:italic; }

        .smart-form { background:#f8f9fa; border-radius:12px; padding:16px; margin-bottom:16px; }
        .smart-form .form-section { margin-bottom:14px; }
        .smart-form .form-label { font-size:0.8rem; font-weight:600; color:#555; margin-bottom:6px; display:block; }
        .smart-form .form-label .required { color:#dc3545; }
        .smart-form .form-hint { font-size:0.75rem; color:#888; margin-top:2px; }
        .smart-form input, .smart-form select, .smart-form textarea { width:100%; padding:10px 12px; border:1px solid #ddd; border-radius:10px; font-size:0.85rem; font-family:inherit; }
        .smart-form input:focus, .smart-form select:focus, .smart-form textarea:focus { outline:none; border-color:#1a73e8; }
        .smart-form textarea { min-height:60px; resize:vertical; }
        .smart-form .field-error { border-color:#dc3545 !important; background:#fff5f5; }
        .smart-form .field-success { border-color:#28a745 !important; background:#f0fff4; }

        .assembled-comment { background:#fff; border:1px solid #e0e0e0; border-radius:10px; padding:12px; font-size:0.85rem; line-height:1.5; margin-bottom:12px; }
        .assembled-comment .comment-header { font-weight:600; color:#333; margin-bottom:6px; font-size:0.8rem; }
        .assembled-comment .comment-body { color:#555; white-space:pre-wrap; }

        .fraud-score { display:flex; align-items:center; gap:12px; padding:12px; border-radius:10px; margin-bottom:12px; }
        .fraud-score.green { background:#e8f5e9; border:1px solid #28a745; }
        .fraud-score.yellow { background:#fff3e0; border:1px solid #ffc107; }
        .fraud-score.red { background:#ffebee; border:1px solid #dc3545; }
        .fraud-score .score-circle { width:48px; height:48px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:1.1rem; color:#fff; }
        .fraud-score.green .score-circle { background:#28a745; }
        .fraud-score.yellow .score-circle { background:#ffc107; color:#333; }
        .fraud-score.red .score-circle { background:#dc3545; }
        .fraud-score .score-text { font-size:0.85rem; }
        .fraud-score .score-text strong { display:block; font-size:0.9rem; margin-bottom:2px; }

        .history-block { background:#f8f9fa; border-radius:10px; padding:12px; margin-bottom:12px; max-height:200px; overflow-y:auto; }
        .history-entry { padding:8px; background:#fff; border-radius:8px; margin-bottom:6px; font-size:0.8rem; border-left:3px solid #1a73e8; }
        .history-entry .h-date { font-size:0.7rem; color:#888; margin-bottom:2px; }
        .history-entry .h-status { display:inline-block; padding:1px 6px; border-radius:6px; font-size:0.7rem; font-weight:600; margin-bottom:2px; }

        .copy-toast { position:fixed; bottom:20px; right:20px; background:#28a745; color:#fff; padding:12px 20px; border-radius:12px; font-size:0.9rem; box-shadow:0 4px 12px rgba(0,0,0,0.15); opacity:0; transition:opacity 0.3s; z-index:1000; }
        .copy-toast.show { opacity:1; }

        .empty-state { text-align:center; padding:40px; color:#888; }
        .empty-state .icon { font-size:3rem; margin-bottom:12px; }

        .tortuga-link { display:inline-flex; align-items:center; gap:6px; background:#f0f7ff; color:#1a73e8; padding:8px 16px; border-radius:10px; text-decoration:none; font-size:0.85rem; font-weight:500; }
        .tortuga-link:hover { background:#e8f0fe; }

        .datetime-row { display:grid; grid-template-columns:1fr 1fr; gap:8px; }

        .rop-warning { background:#fff3e0; border:1px solid #ffc107; border-radius:10px; padding:12px; margin-bottom:12px; font-size:0.85rem; color:#856404; }
        .rop-warning strong { color:#856404; }

        .security-notice { background:#e8f5e9; border:1px solid #28a745; border-radius:10px; padding:10px; margin-bottom:12px; font-size:0.8rem; color:#2e7d32; }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="dashboard.php" class="logo">🚀 SZB</a>
        <a href="dashboard.php">Дашборд</a>
        <a href="team.php">Команда</a>
        <a href="export_inn.php">ИНН</a>
        <a href="quests.php">Квесты</a>
        <a href="calls.php" class="active">📞 Я звоню</a>
        <a href="rop_control.php">🛡️ Контроль</a>
        <a href="ai.php">AI</a>
        <span class="user">👤 <?= htmlspecialchars($user_name) ?></span>
        <a href="logout.php" class="logout">Выйти</a>
    </div>

    <div class="stats-bar">
        <div class="stat-card plan"><div class="value"><?= $daily_plan ?></div><div class="label">📊 План на день</div></div>
        <div class="stat-card done"><div class="value"><?= $done_today ?></div><div class="label">✅ Сделано</div></div>
        <div class="stat-card remaining"><div class="value"><?= $remaining ?></div><div class="label">⏳ Осталось</div></div>
        <div class="stat-card control"><div class="value"><?= count(array_filter($tasks, fn($t) => ($t['status'] ?? '') === 'На контроле РОП')) ?></div><div class="label">🚨 На контроле</div></div>
    </div>

    <div class="main-grid">
        <!-- Левая колонка -->
        <div>
            <div class="card">
                <h3>📋 Пул задач <span class="badge"><?= count($tasks) ?></span></h3>
                <div style="margin-bottom:16px;">
                    <label style="font-weight:600; font-size:0.85rem; display:block; margin-bottom:6px;">📝 Вставьте номера задач из Ритм</label>
                    <textarea id="taskIdsInput" class="task-input" placeholder="00def9ac-df58-43b8-b075-e5cc8d611910
f7ebd7bb-49ff-40dc-bd52-25a6a54d2bb0"></textarea>
                    <div style="font-size:0.75rem; color:#888; margin-top:4px;">💡 Система найдёт UUID автоматически</div>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="addTasks()">➕ Добавить</button>
                        <button class="btn btn-outline" onclick="clearTasks()">🗑️ Очистить</button>
                    </div>
                    <div id="addResult" style="margin-top:8px; font-size:0.8rem;"></div>
                </div>

                <div id="taskList">
                    <?php if (empty($tasks)): ?>
                        <div class="empty-state" id="emptyTasks"><div class="icon">📭</div><div>Задач пока нет</div><div style="font-size:0.8rem; margin-top:8px;">Вставьте номера из Ритм</div></div>
                    <?php else: 
                        $now = new DateTime();
                        foreach ($tasks as $task): 
                            $task_id = $task['task_id'];
                            $prog = $progress[$task_id] ?? null;
                            $readiness = $prog ? (int)$prog['max_readiness'] : 0;
                            $next_call = $prog && $prog['next_call_date'] ? new DateTime($prog['next_call_date']) : null;
                            $is_control = ($task['status'] ?? '') === 'На контроле РОП';

                            $urgency_class = '';
                            $time_text = '';
                            if ($is_control) {
                                $urgency_class = 'control';
                                $time_text = '🚨 На контроле РОП';
                            } elseif ($next_call) {
                                $diff = $now->diff($next_call);
                                $hours = ($diff->days * 24) + $diff->h;
                                if ($next_call < $now) {
                                    $urgency_class = 'urgent';
                                    $time_text = '⚠️ Просрочено: ' . $next_call->format('d.m H:i');
                                } elseif ($hours < 1) {
                                    $urgency_class = 'urgent';
                                    $time_text = '🔥 Скоро: ' . $next_call->format('H:i');
                                } elseif ($hours < 24) {
                                    $urgency_class = 'soon';
                                    $time_text = '⏰ ' . $next_call->format('d.m H:i');
                                } else {
                                    $time_text = '📅 ' . $next_call->format('d.m H:i');
                                }
                            }
                            $readiness_class = $readiness < 30 ? 'low' : ($readiness < 70 ? 'mid' : 'high');
                    ?>
                        <div class="task-item <?= $urgency_class ?>" data-task-id="<?= htmlspecialchars($task_id) ?>" onclick="selectTask(this)">
                            <div class="task-header">
                                <div>
                                    <div class="task-title"><?= htmlspecialchars($task['product'] ?: 'Торговый эквайринг') ?></div>
                                    <div class="task-meta">Задача: ...<?= htmlspecialchars(substr($task_id, -8)) ?></div>
                                    <?php if ($time_text): ?>
                                        <div class="task-time <?= $urgency_class ?>"><?= $time_text ?></div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <?php if ($is_control): ?>
                                        <span class="control-badge">🚨 КОНТРОЛЬ</span>
                                    <?php else: ?>
                                        <span class="readiness-badge <?= $readiness_class ?>"><?= $readiness ?>%</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="readiness-bar"><div class="readiness-fill <?= $readiness_class ?>" style="width:<?= $readiness ?>%"></div></div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <!-- Правая колонка -->
        <div>
            <div class="card" id="workArea" style="display:none;">
                <h3>📞 Звонок <span id="taskTitle" class="badge">—</span></h3>

                <div class="security-notice">
                    🔒 <strong>Безопасность:</strong> ПДН клиентов не хранятся. Все данные — только в Ритм. Комментарии обезличены.
                </div>

                <div style="margin-bottom:12px; padding:10px; background:#f8f9fa; border-radius:8px; font-size:0.8rem;">
                    <div><strong>Продукт:</strong> <span id="productInfo">—</span></div>
                    <div style="margin-top:4px; font-family:monospace; font-size:0.75rem; color:#1a73e8;"><strong>Задача Ритм:</strong> <span id="taskIdDisplay">—</span></div>
                </div>

                <div style="margin-bottom:12px;">
                    <a id="tortugaLink" href="#" target="_blank" class="tortuga-link">🔗 Открыть задачу в Ритм</a>
                </div>

                <!-- === ИИ-АССИСТЕНТ === -->
                <div class="ai-assistant" id="aiAssistant">
                    <div class="ai-header">🤖 ИИ-ассистент Сбер</div>
                    <div class="ai-plan" id="aiPlan">
                        <div class="ai-loading">Анализирую историю задачи...</div>
                    </div>
                </div>

                <!-- === SMART-ФОРМА === -->
                <div class="smart-form">
                    <h4 style="margin-bottom:12px; font-size:0.95rem;">📝 Структура разговора</h4>
                    
                    <div class="form-section">
                        <label class="form-label">Что беспокоит клиента? <span class="required">*</span></label>
                        <textarea id="painPoint" placeholder="Например: высокая комиссия у текущего банка, нет СБП, сложная отчётность..."></textarea>
                        <div class="form-hint">Ключевая проблема, которую озвучил клиент (без имен и данных)</div>
                    </div>

                    <div class="form-section">
                        <label class="form-label">Какие возражения звучали? <span class="required">*</span></label>
                        <select id="objectionsSelect">
                            <option value="">— Выберите основное возражение —</option>
                            <option value="price">💰 Дорого / высокая комиссия</option>
                            <option value="competitor">🏦 Уже есть / ушёл к конкуренту</option>
                            <option value="not_now">⏱️ Не нужен сейчас / нет времени</option>
                            <option value="think">🤔 Подумаю / обсужу с руководством</option>
                            <option value="bad_exp">😞 Плохой опыт с Сбером</option>
                            <option value="other">📝 Другое</option>
                        </select>
                        <textarea id="objectionsText" style="margin-top:6px;" placeholder="Как отработали возражение? Что сказали клиенту?"></textarea>
                    </div>

                    <div class="form-section">
                        <label class="form-label">Что договорились? <span class="required">*</span></label>
                        <textarea id="nextStep" placeholder="Например: отправить КП до пятницы, перезвонить после совета директоров, подготовить сравнительную таблицу..."></textarea>
                        <div class="form-hint">Конкретное действие с ответственным и дедлайном</div>
                    </div>

                    <div class="form-section">
                        <label class="form-label">Кто принимает решение? <span class="required">*</span></label>
                        <input type="text" id="decisionMaker" placeholder="Например: директор, бухгалтер, владелец бизнеса...">
                        <div class="form-hint">Роль лица, которое решает о подключении (не ФИО)</div>
                    </div>

                    <div class="form-section">
                        <label class="form-label">📅 Когда следующий контакт? <span class="required">*</span></label>
                        <div class="datetime-row">
                            <input type="date" id="nextCallDate">
                            <input type="time" id="nextCallTime" value="10:00">
                        </div>
                        <div class="form-hint">Дата и время следующего звонка или встречи</div>
                    </div>

                    <div class="form-section">
                        <label class="form-label">Свободный комментарий (дополнительно)</label>
                        <textarea id="freeComment" placeholder="Всё, что не вошло в структуру (без ПДН: имен, телефонов, ИНН)..."></textarea>
                        <div class="form-hint">Не указывайте ФИО, телефоны, ИНН — это запрещено политикой безопасности</div>
                    </div>
                </div>

                <!-- === СБОРНЫЙ КОММЕНТАРИЙ === -->
                <div class="assembled-comment" id="assembledComment" style="display:none;">
                    <div class="comment-header">📋 Сборный комментарий (будет сохранён):</div>
                    <div class="comment-body" id="commentBody"></div>
                </div>

                <!-- === ФРОД-СКОР === -->
                <div id="fraudScore" style="display:none;"></div>

                <!-- === РОП ПРЕДУПРЕЖДЕНИЕ === -->
                <div class="rop-warning" id="ropWarning" style="display:none;">
                    <strong>⚠️ Задача отправлена на контроль руководителю</strong><br>
                    Ваш звонок будет проверен. Руководитель может запросить перепрозвон.
                </div>

                <!-- === ИСТОРИЯ === -->
                <div id="historySection" style="margin-bottom:12px; display:none;">
                    <div style="font-weight:600; font-size:0.85rem; margin-bottom:6px;">📜 История комментариев</div>
                    <div class="history-block" id="historyList"></div>
                </div>

                <div class="btn-group">
                    <button class="btn btn-success" id="saveBtn" onclick="saveComment()">💾 Сохранить звонок</button>
                    <button class="btn btn-outline" onclick="copyAndGo()">📋 Копировать и перейти в задачу</button>
                </div>
            </div>

            <div class="card" id="emptyWorkArea">
                <div class="empty-state"><div class="icon">👆</div><div>Выберите задачу из пула</div></div>
            </div>
        </div>
    </div>
</div>

<div class="copy-toast" id="copyToast">✅ Скопировано! Переходим в задачу...</div>

<script>
let currentTask = null;
let currentTaskData = null;
let commentHistory = [];
let fraudScore = 0;

// ========== Добавление задач ==========
function addTasks() {
    const input = document.getElementById('taskIdsInput').value.trim();
    if (!input) { alert('Вставьте номера задач'); return; }
    const matches = input.match(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/gi);
    if (!matches) {
        document.getElementById('addResult').innerHTML = '<span style="color:#dc3545;">❌ UUID не найден</span>';
        return;
    }
    fetch('api_add_tasks.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({task_ids: [...new Set(matches.map(m => m.toLowerCase()))]})
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            document.getElementById('addResult').innerHTML = `<span style="color:#28a745;">✅ Добавлено ${d.added}, пропущено ${d.skipped}</span>`;
            document.getElementById('taskIdsInput').value = '';
            setTimeout(() => location.reload(), 10000);
        } else {
            document.getElementById('addResult').innerHTML = `<span style="color:#dc3545;">❌ ${d.error}</span>`;
        }
    });
}
function clearTasks() {
    if (!confirm('Очистить пул?')) return;
    fetch('api_clear_tasks.php', {method: 'POST'})
    .then(r => r.json())
    .then(d => { if (d.success) location.reload(); });
}

// ========== Выбор задачи ==========
function selectTask(el) {
    document.querySelectorAll('.task-item').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    currentTask = el.dataset.taskId;
    const title = el.querySelector('.task-title').textContent;
    currentTaskData = {id: currentTask, product: title};

    document.getElementById('workArea').style.display = 'block';
    document.getElementById('emptyWorkArea').style.display = 'none';
    document.getElementById('taskTitle').textContent = currentTaskData.product;
    document.getElementById('productInfo').textContent = currentTaskData.product;
    document.getElementById('taskIdDisplay').textContent = '...' + currentTask.slice(-8);
    document.getElementById('tortugaLink').href = 'https://new-tortuga.sigma.sbrf.ru/tort/tasks/sales/' + currentTask;

    loadHistory(currentTask);
    resetForm();
    loadAIPlan(currentTask);
}

function resetForm() {
    document.getElementById('painPoint').value = '';
    document.getElementById('objectionsSelect').value = '';
    document.getElementById('objectionsText').value = '';
    document.getElementById('nextStep').value = '';
    document.getElementById('decisionMaker').value = '';
    document.getElementById('nextCallDate').value = '';
    document.getElementById('nextCallTime').value = '10:00';
    document.getElementById('freeComment').value = '';
    document.getElementById('assembledComment').style.display = 'none';
    document.getElementById('fraudScore').style.display = 'none';
    document.getElementById('ropWarning').style.display = 'none';
    document.getElementById('saveBtn').textContent = '💾 Сохранить звонок';
    document.getElementById('saveBtn').disabled = false;
    document.getElementById('saveBtn').className = 'btn btn-success';
    
    document.querySelectorAll('.smart-form input, .smart-form select, .smart-form textarea').forEach(el => {
        el.classList.remove('field-error', 'field-success');
    });
}

// ========== ИИ-АССИСТЕНТ ==========
function loadAIPlan(taskId) {
    const aiPlan = document.getElementById('aiPlan');
    aiPlan.innerHTML = '<div class="ai-loading">Анализирую историю задачи...</div>';
    
    fetch('api_call_coach.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            mode: 'generate_plan',
            task_id: taskId,
            product: currentTaskData?.product || '',
            history: commentHistory
        })
    })
    .then(r => r.json())
    .then(d => {
        if (d.response) {
            aiPlan.innerHTML = d.response;
        } else {
            aiPlan.innerHTML = '<strong>🎯 Микроплан звонка:</strong><br>' +
                '1. Поздороваться, представиться<br>' +
                '2. Уточнить, что беспокоит клиента<br>' +
                '3. Предложить решение (эквайринг Сбер)<br>' +
                '4. Отработать возражения<br>' +
                '5. Зафиксировать договорённости<br>' +
                '6. Назначить следующий контакт';
        }
    })
    .catch(() => {
        aiPlan.innerHTML = '<strong>🎯 Микроплан звонка:</strong><br>' +
            '1. Поздороваться, представиться<br>' +
            '2. Уточнить, что беспокоит клиента<br>' +
            '3. Предложить решение (эквайринг Сбер)<br>' +
            '4. Отработать возражения<br>' +
            '5. Зафиксировать договорённости<br>' +
            '6. Назначить следующий контакт';
    });
}

// ========== Валидация и сборка комментария ==========
function validateForm() {
    let valid = true;
    const fields = [
        {id: 'painPoint', name: 'Что беспокоит клиента'},
        {id: 'objectionsSelect', name: 'Возражения'},
        {id: 'nextStep', name: 'Что договорились'},
        {id: 'decisionMaker', name: 'Кто решает'},
        {id: 'nextCallDate', name: 'Дата следующего контакта'}
    ];
    
    fields.forEach(f => {
        const el = document.getElementById(f.id);
        if (!el.value.trim()) {
            el.classList.add('field-error');
            el.classList.remove('field-success');
            valid = false;
        } else {
            el.classList.remove('field-error');
            el.classList.add('field-success');
        }
    });
    
    return valid;
}

function assembleComment() {
    const pain = document.getElementById('painPoint').value.trim();
    const objSel = document.getElementById('objectionsSelect');
    const objText = document.getElementById('objectionsText').value.trim();
    const next = document.getElementById('nextStep').value.trim();
    const dec = document.getElementById('decisionMaker').value.trim();
    const free = document.getElementById('freeComment').value.trim();
    
    const objMap = {
        'price': 'Дорого / высокая комиссия',
        'competitor': 'Уже есть / ушёл к конкуренту',
        'not_now': 'Не нужен сейчас / нет времени',
        'think': 'Подумаю / обсужу с руководством',
        'bad_exp': 'Плохой опыт с Сбером',
        'other': 'Другое'
    };
    
    let comment = `🔴 Проблема клиента: ${pain}\n\n`;
    comment += `⚠️ Возражение: ${objMap[objSel.value] || '—'}\n`;
    if (objText) comment += `✅ Отработка: ${objText}\n`;
    comment += `\n🎯 Договорённости: ${next}\n\n`;
    comment += `👤 Решает: ${dec}\n`;
    if (free) comment += `\n📝 Дополнительно: ${free}`;
    
    return comment;
}

function updateAssembledComment() {
    const comment = assembleComment();
    document.getElementById('commentBody').textContent = comment;
    document.getElementById('assembledComment').style.display = 'block';
}

// ========== Сохранение ==========
function saveComment() {
    if (!validateForm()) {
        alert('Заполните все обязательные поля (отмечены красным)');
        return;
    }
    if (!currentTask) { alert('Выберите задачу'); return; }
    
    const comment = assembleComment();
    
    const nextCallDate = document.getElementById('nextCallDate').value;
    const nextCallTime = document.getElementById('nextCallTime').value || '10:00';
    const nextCall = nextCallDate ? `${nextCallDate} ${nextCallTime}` : null;
    
    const saveBtn = document.getElementById('saveBtn');
    saveBtn.disabled = true;
    saveBtn.textContent = '⏳ Сохраняю...';
    
    fetch('api_save_call_comment.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            task_id: currentTask,
            comment_text: comment,
            product: currentTaskData?.product || '',
            status: 'think',
            next_call_date: nextCall,
            pain_point: document.getElementById('painPoint').value.trim(),
            objection: document.getElementById('objectionsSelect').value,
            objection_text: document.getElementById('objectionsText').value.trim(),
            next_step: document.getElementById('nextStep').value.trim(),
            decision_maker: document.getElementById('decisionMaker').value.trim(),
            free_comment: document.getElementById('freeComment').value.trim()
        })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            fraudScore = d.fraud_score || 0;
            showFraudScore(fraudScore, d.rop_control);
            
            if (d.rop_control) {
                document.getElementById('ropWarning').style.display = 'block';
                saveBtn.textContent = '⚠️ Отправлено на контроль';
                saveBtn.className = 'btn btn-warning';
            } else {
                saveBtn.textContent = '✅ Сохранено';
                saveBtn.className = 'btn btn-success';
            }
            
            loadHistory(currentTask);
            
            if (d.next_call_date) {
                setTimeout(() => location.reload(), 10000);
            }
        } else {
            alert('Ошибка: ' + d.error);
            saveBtn.disabled = false;
            saveBtn.textContent = '💾 Сохранить звонок';
        }
    })
    .catch(e => {
        alert('Ошибка соединения');
        saveBtn.disabled = false;
        saveBtn.textContent = '💾 Сохранить звонок';
    });
}

function showFraudScore(score, ropControl) {
    const el = document.getElementById('fraudScore');
    let html = '';
    
    if (score >= 70) {
        html = `<div class="fraud-score green">
            <div class="score-circle">${score}</div>
            <div class="score-text"><strong>✅ Звонок верифицирован</strong>Система подтвердила достоверность</div>
        </div>`;
    } else if (score >= 40) {
        html = `<div class="fraud-score yellow">
            <div class="score-circle">${score}</div>
            <div class="score-text"><strong>⚠️ Требует внимания</strong>Некоторые поля заполнены слабо</div>
        </div>`;
    } else {
        html = `<div class="fraud-score red">
            <div class="score-circle">${score}</div>
            <div class="score-text"><strong>🚨 Подозрение на фрод</strong>Звонок отправлен руководителю на проверку</div>
        </div>`;
    }
    
    el.innerHTML = html;
    el.style.display = 'block';
}

// ========== История ==========
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
                const statusText = {think:'🤔 Думает', signed:'✅ Подписан', reject:'❌ Отказ', nocontact:'📵 Нет контакта', recall:'📞 Перезвон', '':'📝'}[h.call_result] || h.call_result;
                return `<div class="history-entry">
                    <div class="h-date">${h.created_at}</div>
                    <span class="h-status ${statusClass}">${statusText}</span>
                    <div>${h.comment_text.substring(0, 200)}${h.comment_text.length > 200 ? '...' : ''}</div>
                    ${h.next_call_date ? `<div style="font-size:0.7rem; color:#1a73e8; margin-top:2px;">📅 След. контакт: ${h.next_call_date}</div>` : ''}
                </div>`;
            }).join('');
        } else {
            section.style.display = 'none';
        }
    });
}

// ========== Копировать и перейти ==========
function copyAndGo() {
    const comment = assembleComment();
    if (!comment.trim()) { alert('Заполните форму'); return; }
    
    navigator.clipboard.writeText(comment).then(() => {
        const toast = document.getElementById('copyToast');
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 3000);
        setTimeout(() => window.open('https://new-tortuga.sigma.sbrf.ru/tort/tasks/sales/' + currentTask, '_blank'), 500);
    });
}

// ========== Обновление сборного комментария при вводе ==========
document.querySelectorAll('.smart-form input, .smart-form select, .smart-form textarea').forEach(el => {
    el.addEventListener('input', updateAssembledComment);
});
</script>
</body>
</html>
PHPEOF
echo "calls.php создан (без ПДН)"