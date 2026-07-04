<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once 'db.php';
$role = $_SESSION['role'];
if (!in_array($role, ['manager'])) { die('Только для менеджеров'); }
$tabel = $_SESSION['tabel'];
$user_name = $_SESSION['name'];

$stmt = $pdo->prepare("SELECT calls_plan FROM plans WHERE tabel_number = ? AND period = strftime('%Y-%m', 'now')");
$stmt->execute([$tabel]);
$month_plan = $stmt->fetchColumn() ?: 350;
$daily_plan = ceil($month_plan / 22);
$stmt = $pdo->prepare("SELECT COALESCE(SUM(calls),0) FROM daily_reports WHERE tabel_number = ? AND report_date = date('now')");
$stmt->execute([$tabel]);
$done_today = $stmt->fetchColumn();
$remaining_calls = max(0, $daily_plan - $done_today);

$work_minutes = 4 * 60;
$avg_call_seconds = $remaining_calls > 0 ? round(($work_minutes * 60) / $remaining_calls) : 300;

define('MIN_CALL_SECONDS', 20);
define('MIN_CALL_WORDS', 35);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>📞 Телефония — SZB CRM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css">
    <style>
        .nav { display:flex; align-items:center; padding:12px 20px; background:linear-gradient(135deg,#1a1a2e,#16213e); color:#fff; border-radius:16px; margin-bottom:20px; gap:12px; flex-wrap:wrap; }
        .nav a { color:#ccc; text-decoration:none; padding:8px 14px; border-radius:8px; font-size:13px; font-weight:500; }
        .nav a:hover, .nav a.active { background:rgba(255,255,255,0.1); color:#fff; }
        .nav .logo { font-size:20px; font-weight:700; color:#fff; margin-right:auto; }
        .nav .user { margin-left:auto; color:#aaa; font-size:13px; }
        .nav a.logout { color:#e03131; }

        .container { max-width:800px; margin:0 auto; padding:24px; }
        .card { background:#fff; border-radius:16px; padding:20px; margin-bottom:16px; box-shadow:0 2px 12px rgba(0,0,0,0.04); border:1px solid #e8ecf1; }
        .btn { padding:12px 24px; border:none; border-radius:12px; font-size:1.1rem; cursor:pointer; font-weight:600; }
        .btn-start { background:#28a745; color:#fff; }
        .btn-stop { background:#dc3545; color:#fff; }
        .btn:disabled { opacity:0.6; cursor:default; }
        .timer { font-size:2rem; font-weight:bold; margin:15px 0; }
        .live-text { background:#f8f9fa; border-radius:12px; padding:12px; min-height:100px; max-height:300px; overflow-y:auto; white-space:pre-wrap; font-size:14px; margin:10px 0; }
        .ai-box { margin-top:20px; }
        .hidden { display:none; }
        .error { color:#dc3545; }
        .stats { font-size:1.1rem; margin:15px 0; }
        .call-timer { font-size:1.5rem; }
        .call-timer.warning { color: #ffc107; }
        .call-timer.danger { color: #dc3545; }
        .plan-info { margin-bottom:20px; font-size:0.9rem; color:#555; }
        .call-history { margin-top:20px; }
        .call-history-item { border-left: 3px solid #dee2e6; padding:8px 12px; margin-bottom:8px; }
        .call-history-item.valid { border-left-color: #28a745; }
        .call-history-item.invalid { border-left-color: #dc3545; }
        .call-history-item .summary { font-size:0.85rem; color:#555; margin-top:4px; }
        .pause-info { background: #fff3cd; border-radius:12px; padding:12px; margin:10px 0; display: flex; justify-content: space-between; align-items: center; }
        .next-call-alert { background: #d4edda; border-radius:12px; padding:12px; margin:10px 0; text-align: center; }
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
        <a href="calls.php" class="active">📞 Телефония</a>
        <a href="ai.php">AI</a>
        <span class="user"><?= htmlspecialchars($user_name) ?></span>
        <a href="logout.php" class="logout">Выйти</a>
    </div>

    <h2>📞 Телефонные звонки</h2>

    <div class="plan-info">
        📊 План на день: <strong><?= $daily_plan ?></strong> звонков |
        Сделано сегодня: <strong><?= $done_today ?></strong> |
        Осталось: <strong><?= $remaining_calls ?></strong><br>
        ⏱️ Среднее время на один звонок: <strong><?= gmdate("i:s", $avg_call_seconds) ?></strong>
    </div>

    <div class="card">
        <div class="call-controls" style="margin-bottom:15px;">
            <button id="startBtn" class="btn btn-start">🎤 Я звоню</button>
            <button id="stopBtn" class="btn btn-stop hidden">⏹️ Завершить сеанс</button>
        </div>
        <div id="callTimerDisplay" class="call-timer hidden">
            Звонок: <span id="callTimer">00:00</span> / <?= gmdate("i:s", $avg_call_seconds) ?>
        </div>
        <div id="timerDisplay" class="timer hidden">Сеанс: 00:00</div>
        <div id="callCounterDisplay" class="stats hidden">📞 Звонков за сеанс: <span id="callCount">0</span> (засчитано: <span id="validCount">0</span>)</div>
        <div id="status" class="error"></div>
        <div id="liveText" class="live-text hidden"></div>
        <div id="coachAdvice" class="ai-box hidden" style="background:#e6f7ff; border-left:5px solid #1890ff; padding:12px; border-radius:12px;">
            <strong>🤖 AI-помощник:</strong> <span id="coachText"></span>
        </div>
        <div id="pauseSection" class="hidden">
            <div class="pause-info">
                <span>⏸️ Пауза после звонка – запишите договорённости</span>
                <span id="pauseTimer">01:00</span>
            </div>
        </div>
        <div id="nextCallAlert" class="next-call-alert hidden">
            🔊 Пора звонить! Ожидание нового звонка...
        </div>
        <div id="callHistory" class="call-history hidden">
            <h4>📋 Журнал звонков</h4>
            <div id="historyList"></div>
        </div>
        <div id="sessionStats" class="stats hidden">
            <div>🕐 Сеанс: <span id="sessionStart"></span> – <span id="sessionEnd"></span></div>
            <div>📞 Всего звонков: <span id="sessionCalls"></span> (засчитано: <span id="sessionValidCalls"></span>)</div>
        </div>
        <div id="aiResponse" class="ai-box hidden">
            <h3>🤖 Итоговый разбор сеанса</h3>
            <div id="aiContent"></div>
        </div>
    </div>
</div>

<script>
const avgCallSeconds = <?= $avg_call_seconds ?>;
const MIN_SEC = <?= MIN_CALL_SECONDS ?>;
const MIN_WORDS = <?= MIN_CALL_WORDS ?>;
const PAUSE_SECONDS = 60;

const startBtn = document.getElementById('startBtn');
const stopBtn = document.getElementById('stopBtn');
const timerDisplay = document.getElementById('timerDisplay');
const callTimerDisplay = document.getElementById('callTimerDisplay');
const callTimerSpan = document.getElementById('callTimer');
const liveText = document.getElementById('liveText');
const statusDiv = document.getElementById('status');
const coachAdvice = document.getElementById('coachAdvice');
const coachText = document.getElementById('coachText');
const callCounterDisplay = document.getElementById('callCounterDisplay');
const callCountSpan = document.getElementById('callCount');
const validCountSpan = document.getElementById('validCount');
const sessionStats = document.getElementById('sessionStats');
const sessionStartSpan = document.getElementById('sessionStart');
const sessionEndSpan = document.getElementById('sessionEnd');
const sessionCallsSpan = document.getElementById('sessionCalls');
const sessionValidCallsSpan = document.getElementById('sessionValidCalls');
const aiResponse = document.getElementById('aiResponse');
const aiContent = document.getElementById('aiContent');
const pauseSection = document.getElementById('pauseSection');
const pauseTimerSpan = document.getElementById('pauseTimer');
const nextCallAlert = document.getElementById('nextCallAlert');
const callHistoryDiv = document.getElementById('callHistory');
const historyList = document.getElementById('historyList');

let recognition;
let isRecognizing = false;
let sessionActive = false;
let finalTranscript = '';
let timerInterval;
let seconds = 0;
let totalCalls = 0;
let validCalls = 0;
let sessionStartTime = null;
let sessionEndTime = null;
let currentCallActive = false;
let callTimerInterval;
let callSeconds = 0;
let currentCallText = '';
let currentCallStartTime = null;
let coachTimer;
let pauseInterval;
let pauseSeconds = 0;
let callHistory = [];
let restartRecognitionTimer = null;

const startPhrases = ['добрый день', 'здравствуйте', 'приветствую', 'здорово', 'доброе утро', 'добрый вечер'];
const endPhrases = ['до свидания', 'всего доброго', 'счастливо', 'хорошего дня', 'удачи', 'пока'];

if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
    statusDiv.textContent = 'Ваш браузер не поддерживает распознавание речи. Используйте Chrome.';
    startBtn.disabled = true;
} else {
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    recognition = new SpeechRecognition();
    recognition.lang = 'ru-RU';
    recognition.continuous = true;
    recognition.interimResults = true;

    recognition.onstart = function() { isRecognizing = true; statusDiv.textContent = ''; };
    recognition.onresult = function(event) {
        let interim = '';
        for (let i = event.resultIndex; i < event.results.length; ++i) {
            const res = event.results[i];
            const text = res[0].transcript;
            if (res.isFinal) {
                finalTranscript += text + '\n';
                if (currentCallActive) {
                    currentCallText += text + '\n';
                    checkForCallEnd(text);
                } else {
                    checkForCallStart(text);
                }
            } else {
                interim += text;
            }
        }
        liveText.textContent = finalTranscript + interim;
    };
    recognition.onerror = function(event) {
        console.error(event.error);
        if (event.error === 'not-allowed') statusDiv.textContent = 'Доступ к микрофону запрещён.';
        else if (event.error === 'aborted') { if (sessionActive) startRecognition(); }
        else statusDiv.textContent = 'Ошибка распознавания: ' + event.error;
    };
    recognition.onend = function() {
        isRecognizing = false;
        if (sessionActive) {
            clearTimeout(restartRecognitionTimer);
            restartRecognitionTimer = setTimeout(() => { if (sessionActive) startRecognition(); }, 500);
        }
    };

    startBtn.addEventListener('click', startSession);
    stopBtn.addEventListener('click', stopSession);
}

function startSession() {
    navigator.mediaDevices.getUserMedia({ audio: true })
        .then(function(stream) {
            sessionActive = true;
            sessionStartTime = new Date();
            sessionStartSpan.textContent = sessionStartTime.toLocaleTimeString();
            startBtn.classList.add('hidden');
            stopBtn.classList.remove('hidden');
            timerDisplay.classList.remove('hidden');
            liveText.classList.remove('hidden');
            liveText.textContent = '';
            finalTranscript = '';
            statusDiv.textContent = 'Ожидание звонка...';
            aiResponse.classList.add('hidden');
            sessionStats.classList.add('hidden');
            coachAdvice.classList.add('hidden');
            callHistoryDiv.classList.add('hidden');
            totalCalls = 0; validCalls = 0;
            callCountSpan.textContent = '0'; validCountSpan.textContent = '0';
            callCounterDisplay.classList.remove('hidden');
            seconds = 0;
            updateTimer();
            timerInterval = setInterval(updateTimer, 1000);
            startRecognition();
        })
        .catch(function(err) { statusDiv.textContent = 'Нет доступа к микрофону: ' + err.message; });
}

function startRecognition() { try { if (!isRecognizing) recognition.start(); } catch(e) {} }

function stopSession() {
    sessionActive = false;
    clearTimeout(restartRecognitionTimer);
    if (isRecognizing) { recognition.stop(); isRecognizing = false; }
    clearInterval(timerInterval); clearInterval(callTimerInterval); clearTimeout(coachTimer); clearInterval(pauseInterval);
    startBtn.classList.remove('hidden'); stopBtn.classList.add('hidden');
    timerDisplay.classList.add('hidden'); callTimerDisplay.classList.add('hidden');
    nextCallAlert.classList.add('hidden'); pauseSection.classList.add('hidden');
    if (currentCallActive) endCurrentCall(false);
    sessionEndTime = new Date();
    sessionEndSpan.textContent = sessionEndTime.toLocaleTimeString();
    callCountSpan.textContent = totalCalls; validCountSpan.textContent = validCalls;
    sessionCallsSpan.textContent = totalCalls; sessionValidCallsSpan.textContent = validCalls;
    sessionStats.classList.remove('hidden');
    callCounterDisplay.classList.add('hidden');
    if (callHistory.length > 0) { getFinalAnalysis(); }
    else { statusDiv.textContent = 'Нет завершённых звонков.'; }
}

function updateTimer() { seconds++; const m = Math.floor(seconds/60).toString().padStart(2,'0'); const s = (seconds%60).toString().padStart(2,'0'); timerDisplay.textContent = `Сеанс: ${m}:${s}`; }

function checkForCallStart(text) {
    if (currentCallActive) return;
    const lower = text.toLowerCase();
    for (let phrase of startPhrases) { if (lower.includes(phrase)) { startNewCall(); break; } }
}
function checkForCallEnd(text) {
    if (!currentCallActive) return;
    const lower = text.toLowerCase();
    for (let phrase of endPhrases) { if (lower.includes(phrase)) { endCurrentCall(true); break; } }
}

function startNewCall() {
    nextCallAlert.classList.add('hidden'); pauseSection.classList.add('hidden'); clearInterval(pauseInterval);
    currentCallActive = true; callSeconds = 0; currentCallText = ''; currentCallStartTime = new Date();
    updateCallTimer(); callTimerInterval = setInterval(updateCallTimer, 1000);
    callTimerDisplay.classList.remove('hidden');
    coachTimer = setTimeout(askCoach, 15000);
    statusDiv.textContent = 'Идёт звонок...';
}

function updateCallTimer() {
    callSeconds++;
    const m = Math.floor(callSeconds/60).toString().padStart(2,'0'); const s = (callSeconds%60).toString().padStart(2,'0');
    callTimerSpan.textContent = `${m}:${s}`;
    if (callSeconds > avgCallSeconds * 1.2) callTimerDisplay.className = 'call-timer danger';
    else if (callSeconds > avgCallSeconds) callTimerDisplay.className = 'call-timer warning';
    else callTimerDisplay.className = 'call-timer';
}

function endCurrentCall(increment = true) {
    clearInterval(callTimerInterval); clearTimeout(coachTimer);
    currentCallActive = false; callTimerDisplay.classList.add('hidden'); coachAdvice.classList.add('hidden');
    if (!increment) return;
    const duration = callSeconds;
    const words = currentCallText.trim().split(/\s+/).filter(w => w.length > 0).length;
    const valid = duration >= MIN_SEC && words >= MIN_WORDS;
    totalCalls++;
    if (valid) {
        validCalls++;
        // Увеличиваем AI-счётчик на сервере
        fetch('api_increment_ai_calls.php', { method: 'POST' })
            .catch(e => console.error(e));
    }
    callCountSpan.textContent = totalCalls; validCountSpan.textContent = validCalls;
    const entry = { start: currentCallStartTime, duration, words, valid, text: currentCallText.trim(), summary: null };
    callHistory.push(entry);
    if (valid && currentCallText.trim().length > 0) { getCallSummary(entry); }
    else { renderCallHistory(); }
    startPause();
}

function startPause() { pauseSeconds = PAUSE_SECONDS; pauseSection.classList.remove('hidden'); updatePauseTimer(); pauseInterval = setInterval(updatePauseTimer, 1000); }
function updatePauseTimer() {
    pauseSeconds--;
    const m = Math.floor(Math.max(0,pauseSeconds)/60).toString().padStart(2,'0'); const s = (Math.max(0,pauseSeconds)%60).toString().padStart(2,'0');
    pauseTimerSpan.textContent = `${m}:${s}`;
    if (pauseSeconds <= 0) {
        clearInterval(pauseInterval); pauseSection.classList.add('hidden'); nextCallAlert.classList.remove('hidden');
        try {
            const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = audioCtx.createOscillator(); const gain = audioCtx.createGain();
            osc.connect(gain); gain.connect(audioCtx.destination);
            osc.frequency.value = 800; osc.type = 'sine'; gain.gain.value = 0.3;
            osc.start(); osc.stop(audioCtx.currentTime + 0.15);
        } catch(e) {}
    }
}

function askCoach() {
    if (!currentCallText.trim() || !currentCallActive) return;
    fetch('api_call_coach.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({text: currentCallText}) })
    .then(r => r.json()).then(d => { if (d.response && currentCallActive) { coachText.textContent = d.response; coachAdvice.classList.remove('hidden'); } })
    .catch(e => console.error(e))
    .finally(() => { if (currentCallActive) coachTimer = setTimeout(askCoach, 30000); });
}

function getCallSummary(entry) {
    fetch('api_call_coach.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({text: entry.text}) })
    .then(r => r.json()).then(d => { entry.summary = d.response || d.error || 'AI не ответил'; })
    .catch(e => { entry.summary = 'Ошибка соединения с AI-коучем'; })
    .finally(() => { renderCallHistory(); });
}

function renderCallHistory() {
    callHistoryDiv.classList.remove('hidden');
    historyList.innerHTML = '';
    callHistory.forEach((entry, idx) => {
        const div = document.createElement('div');
        div.className = 'call-history-item ' + (entry.valid ? 'valid' : 'invalid');
        const time = entry.start ? entry.start.toLocaleTimeString() : '—';
        div.innerHTML = `<strong>Звонок #${idx+1}</strong> (${time}, ${entry.duration}с, ${entry.words} слов) – ${entry.valid ? '✅ засчитан' : '❌ не засчитан (менее 20с или 35 слов)'}
            ${entry.summary ? '<div class="summary">🤖 ' + entry.summary + '</div>' : '<div class="summary">⏳ Ожидание резюме...</div>'}`;
        historyList.appendChild(div);
    });
}

function getFinalAnalysis() {
    aiResponse.classList.remove('hidden');
    aiContent.innerHTML = '⏳ AI анализирует сеанс...';
    const stats = `Всего звонков: ${totalCalls}, засчитано: ${validCalls}.`;
    const summaries = callHistory.filter(e => e.valid && e.summary && !e.summary.includes('не ответил')).map(e => e.summary).join('\n');
    const prompt = `Проанализируй сеанс телефонных звонков менеджера по продажам эквайринга. Статистика: ${stats}. Резюме AI-коуча по звонкам:\n${summaries || 'Нет данных'}\n\nДай общие мотивирующие рекомендации по улучшению навыков звонков и работе с клиентами. Не предлагай обращаться к руководителю за тёплой базой.`;
    fetch('api_ai_ask.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({question: prompt}) })
    .then(r => r.json()).then(d => { if (d.response) { aiContent.innerHTML = d.response.replace(/\n/g, '<br>'); } else { aiContent.innerHTML = 'Не удалось получить итоговый разбор.'; } })
    .catch(e => { aiContent.innerHTML = 'Ошибка соединения.'; });
}
</script>
</body>
</html>