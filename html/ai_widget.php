<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) return;
require_once 'db.php';

$advice = "🌟 Заполняй отчёты каждый день для персонализированных советов!";
try {
    $stmt = $pdo->query("SELECT advice_text FROM ai_advice_cache ORDER BY RANDOM() LIMIT 1");
    if ($stmt && $row = $stmt->fetch()) $advice = $row['advice_text'];
} catch (Exception $e) {}
?>
<div style="background:#e3f2fd; border-radius:12px; padding:15px; margin-bottom:20px; border-left:4px solid #667eea;">
    <div style="display:flex; align-items:center; gap:12px;">
        <div style="font-size:32px;">🤖</div>
        <div style="flex:1;"><strong>AI Наставник</strong><br><?= htmlspecialchars($advice) ?></div>
    </div>
</div>
