<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'manager')) {
    header('Location: dashboard.php');
    exit;
}
require_once 'GigaChat.php';

$AUTH_KEY = 'NzA0OTMxMWYtMTJkNy00OTQ5LWI2MzUtN2ZhYjZiNWRjMzY3OmIyYWJhMDQxLWRiYzgtNGY4ZC1hZDEwLTBhOTY2ZDQ4ZTc3OA==';
$gigachat = new GigaChat($AUTH_KEY);

// Получаем совет
$test_prompts = [
    "Дай короткий мотивирующий совет менеджеру по продажам на сегодня. 2-3 предложения. Отвечай на русском, используй эмодзи.",
    "Как увеличить количество встреч с клиентами? Дай 2 коротких совета. Отвечай на русском.",
    "Что делать, если клиент говорит 'мне нужно подумать'? Дай короткий ответ. Отвечай на русском.",
    "Дай вдохновляющую цитату для менеджера по продажам. Отвечай на русском."
];

$selected_prompt = $_GET['prompt'] ?? 0;
$prompt = $test_prompts[$selected_prompt];
$advice = $gigachat->generateAdvice($prompt);
$error = $advice === false ? curl_error($ch) ?? 'Неизвестная ошибка' : null;
?>

<!DOCTYPE html>
<html>
<head>
    <title>Тест GigaChat — реальные ИИ-советы</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; background: #0f172a; padding: 40px 20px; min-height: 100vh; }
        .container { max-width: 800px; margin: 0 auto; }
        h1 { color: #00a36c; margin-bottom: 10px; font-size: 28px; }
        .subtitle { color: #94a3b8; margin-bottom: 30px; border-left: 3px solid #00a36c; padding-left: 15px; }
        .advice-card { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); border-radius: 24px; padding: 30px; margin-bottom: 30px; border: 1px solid #334155; box-shadow: 0 20px 35px -10px rgba(0,0,0,0.3); }
        .advice-header { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #334155; }
        .advice-icon { background: #00a36c; width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 28px; }
        .advice-title { font-size: 20px; font-weight: bold; color: white; }
        .advice-subtitle { font-size: 13px; color: #94a3b8; margin-top: 4px; }
        .advice-content { background: #0f172a; border-radius: 16px; padding: 25px; font-size: 18px; line-height: 1.5; color: #e2e8f0; border-left: 4px solid #00a36c; margin-bottom: 20px; }
        .advice-content.error { border-left-color: #ef4444; color: #fca5a5; }
        .prompt-info { background: #1e293b; border-radius: 12px; padding: 15px; margin-bottom: 20px; font-size: 13px; color: #94a3b8; }
        .prompt-label { color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; }
        .prompt-text { font-family: monospace; font-size: 14px; color: #cbd5e1; }
        .buttons { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 30px; }
        .btn { background: #334155; color: white; border: none; padding: 12px 24px; border-radius: 40px; cursor: pointer; font-size: 14px; transition: all 0.2s; text-decoration: none; display: inline-block; }
        .btn:hover { background: #475569; transform: translateY(-2px); }
        .btn-primary { background: #00a36c; }
        .btn-primary:hover { background: #008a5c; }
        .status { background: #1e293b; border-radius: 12px; padding: 12px 20px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; font-size: 13px; color: #94a3b8; }
        .status-dot { width: 10px; height: 10px; border-radius: 50%; background: #10b981; box-shadow: 0 0 8px #10b981; }
        .status-dot.error { background: #ef4444; box-shadow: 0 0 8px #ef4444; }
        .back-link { display: inline-block; margin-top: 20px; color: #00a36c; text-decoration: none; font-size: 14px; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="container">
    <h1>🤖 GigaChat — реальные ИИ-советы</h1>
    <div class="subtitle">Проверка работы нейросети Сбера в реальном времени</div>
    
    <div class="status">
        <div class="status-dot <?= $error ? 'error' : '' ?>"></div>
        <span><?= $error ? '⚠️ Ошибка подключения к GigaChat' : '✅ GigaChat API работает' ?></span>
        <span style="margin-left: auto; font-size: 11px;">Токен получен: <?= date('H:i:s') ?></span>
    </div>
    
    <div class="buttons">
        <a href="?prompt=0" class="btn <?= $selected_prompt == 0 ? 'btn-primary' : '' ?>">💡 Мотивация</a>
        <a href="?prompt=1" class="btn <?= $selected_prompt == 1 ? 'btn-primary' : '' ?>">🤝 Встречи</a>
        <a href="?prompt=2" class="btn <?= $selected_prompt == 2 ? 'btn-primary' : '' ?>">🗣️ Возражения</a>
        <a href="?prompt=3" class="btn <?= $selected_prompt == 3 ? 'btn-primary' : '' ?>">📖 Цитата</a>
    </div>
    
    <div class="advice-card">
        <div class="advice-header">
            <div class="advice-icon">🧠</div>
            <div>
                <div class="advice-title">Совет от GigaChat</div>
                <div class="advice-subtitle">Сгенерировано нейросетью Сбера • <?= date('d.m.Y H:i:s') ?></div>
            </div>
        </div>
        
        <div class="prompt-info">
            <div class="prompt-label">Запрос к модели:</div>
            <div class="prompt-text"><?= htmlspecialchars($prompt) ?></div>
        </div>
        
        <div class="advice-content <?= $error ? 'error' : '' ?>">
            <?php if ($advice && !$error): ?>
                <?= nl2br(htmlspecialchars($advice)) ?>
            <?php else: ?>
                ⚠️ Не удалось получить совет от GigaChat.<br>
                <small style="color: #fca5a5;">Ошибка: <?= htmlspecialchars($error ?? 'Таймаут запроса') ?></small>
            <?php endif; ?>
        </div>
        
        <div style="font-size: 12px; color: #64748b; text-align: center; margin-top: 15px;">
            ⚡ GigaChat — нейросеть от Сбера | Бесплатный пакет токенов активирован
        </div>
    </div>
    
    <a href="dashboard.php" class="back-link">← Вернуться на дашборд</a>
</div>
</body>
</html>
