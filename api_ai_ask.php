<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if(!isset($_SESSION['user_id'])){echo json_encode(['error'=>'Требуется авторизация']);exit;}

require_once 'db.php';
$question = json_decode(file_get_contents('php://input'), true)['question'] ?? '';
$tabel = $_SESSION['tabel'];
$role = $_SESSION['role'];
$month = date('Y-m');
$days_passed = date('j');
$work_days_left = max(22 - $days_passed, 1);

// Статистика пользователя
$st = $pdo->prepare("SELECT COALESCE(SUM(calls),0) as calls, COALESCE(SUM(calls_answered),0) as answered, COALESCE(SUM(meetings),0) as meetings, COALESCE(SUM(contracts),0) as contracts, COALESCE(SUM(registrations),0) as regs, COALESCE(SUM(smart_cash),0) as smart, COALESCE(SUM(pos_systems),0) as pos, COALESCE(SUM(inn_leads),0) as inn, COALESCE(SUM(teams),0) as teams, COALESCE(SUM(turnover),0) as turnover FROM daily_reports WHERE tabel_number=? AND strftime('%Y-%m',report_date)=?");
$st->execute([$tabel, $month]);
$s = $st->fetch();

// План
$pl = $pdo->prepare("SELECT * FROM plans WHERE tabel_number=? AND period=?");
$pl->execute([$tabel, $month]);
$p = $pl->fetch() ?: array_fill_keys(['contracts_plan','calls_plan','registrations_plan','smart_cash_plan','pos_systems_plan','inn_leads_plan','teams_plan','turnover_plan'], 0);

// Расчёты
$contracts_pct = $p['contracts_plan'] > 0 ? round(($s['contracts'] / $p['contracts_plan']) * 100) : 0;
$daily_need = $p['contracts_plan'] > 0 ? ceil(($p['contracts_plan'] - $s['contracts']) / $work_days_left) : 0;
$daily_avg = $days_passed > 0 ? round($s['contracts'] / $days_passed, 1) : 0;
$conv = $s['calls'] > 0 ? round(($s['contracts'] / $s['calls']) * 100, 1) : 0;
$meet_conv = $s['calls'] > 0 ? round(($s['meetings'] / $s['calls']) * 100, 1) : 0;

// Топ сотрудников
$top = $pdo->query("SELECT u.full_name, COALESCE(SUM(dr.contracts),0) as cnt FROM daily_reports dr JOIN users u ON dr.tabel_number=u.tabel_number WHERE strftime('%Y-%m',dr.report_date)='$month' AND u.role='manager' GROUP BY dr.tabel_number ORDER BY cnt DESC LIMIT 5")->fetchAll();

// Проблемные зоны
$problems = [];
if ($p['calls_plan'] > 0 && ($s['calls'] / ($p['calls_plan']/22*$days_passed)) < 0.7) $problems[] = 'звонки';
if ($conv < 5) $problems[] = 'конверсия';
if ($p['registrations_plan'] > 0 && ($s['regs'] / max($p['registrations_plan'],1)) < 0.5) $problems[] = 'регистрации ТЭ';

// Определяем интенты вопроса
$q = mb_strtolower($question);

if (strpos($q, 'план') !== false || strpos($q, 'прогноз') !== false || strpos($q, 'сколько') !== false) {
    $answer = "📊 <b>План на месяц:</b> {$p['contracts_plan']} договоров<br>";
    $answer .= "✅ <b>Выполнено:</b> {$s['contracts']} ({$contracts_pct}%)<br>";
    $answer .= "📈 <b>Дневная норма:</b> $daily_need договоров<br>";
    $answer .= "⏳ <b>Осталось дней:</b> $work_days_left<br>";
    $answer .= "🎯 <b>Прогноз:</b> " . ($contracts_pct >= ($days_passed/22*100) ? "✅ Выполните план" : "⚠️ Риск невыполнения");

} elseif (strpos($q, 'рекоменда') !== false || strpos($q, 'совет') !== false || strpos($q, 'улучшить') !== false) {
    if ($contracts_pct >= 90) $answer = "🌟 Вы отлично справляетесь! Рекомендация: увеличивайте средний чек — активнее предлагайте чаевые (ИНН чаевых: {$s['inn']} из {$p['inn_leads_plan']}) и POS-системы.";
    elseif ($contracts_pct >= 60) $answer = "👍 Хороший темп. Чтобы ускориться: увеличьте звонки на 20%, работайте над конверсией встреч в договоры (сейчас {$meet_conv}%).";
    else $answer = "⚠️ Нужно ускориться!<br>1. Звонков в день: нужно $daily_need<br>2. Конверсия: $conv% (низкая — работайте с отказами)<br>3. Спросите у руководителя тёплую базу.";

} elseif (strpos($q, 'лидер') !== false || strpos($q, 'топ') !== false || strpos($q, 'рейтинг') !== false) {
    $answer = "🏆 <b>Топ-5 по договорам:</b><br>";
    foreach ($top as $i => $t) $answer .= ($i+1).". {$t['full_name']} — {$t['cnt']}<br>";

} elseif (strpos($q, 'ритм') !== false || strpos($q, 'темп') !== false) {
    $status = $daily_avg >= $daily_need ? "✅ В ритме" : "⚠️ Отстаёте";
    $answer = "📈 <b>Ваш темп:</b> $daily_avg договоров/день (нужно $daily_need)<br><b>Статус:</b> $status";

} elseif (strpos($q, 'конверси') !== false) {
    $answer = "📐 <b>Конверсия звонки→встречи:</b> {$meet_conv}%<br>📐 <b>Конверсия звонки→договоры:</b> {$conv}%<br>" . ($conv > 8 ? "Хорошо! 👌" : "Низкая. Рекомендация: улучшайте скрипты.");

} elseif (strpos($q, 'привет') !== false || strpos($q, 'здравствуй') !== false) {
    $answer = "👋 Здравствуйте! Я AI-аналитик CRM.\nСпросите меня о:\n• Прогнозе выполнения плана\n• Рекомендациях\n• Рейтинге сотрудников\n• Конверсии\n• Ритме работы";

} else {
    $answer = "📊 <b>Ваша сводка за $month:</b><br>";
    $answer .= "📞 Звонки: {$s['calls']} | 🤝 Встречи: {$s['meetings']} | 📄 Договоры: {$s['contracts']}<br>";
    $answer .= "📝 ТЭ: {$s['regs']} | 💳 Смарт: {$s['smart']} | 🖥️ ПОС: {$s['pos']}<br>";
    $answer .= "🍵 Чаевые ИНН: {$s['inn']} | 👥 Команды: {$s['teams']}<br>";
    if (!empty($problems)) $answer .= "⚠️ Зоны роста: " . implode(', ', $problems);
}

echo json_encode(['response' => $answer]);
