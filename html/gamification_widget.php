<?php
if (!isset($_SESSION['user_id'])) return;
require_once 'gamification.php';

$user_id = $_SESSION['user_id'];
$rankInfo = getUserRankInfo($pdo, $user_id);
$notifications = getUnreadNotifications($pdo, $user_id);

if (!$rankInfo) return;

// Время последнего анализа
$last_analysis = '';
$stmt = $pdo->query("SELECT created_at FROM game_notifications ORDER BY created_at DESC LIMIT 1");
if ($last = $stmt->fetch()) {
    $last_analysis = date('d.m.Y H:i', strtotime($last['created_at']));
}
?>

<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 15px; padding: 20px; margin: 20px 0; color: white;">
    <div style="display: flex; justify-content: space-between;">
        <div>
            <h2 style="margin: 0;"><?= $rankInfo['icon'] ?> <?= htmlspecialchars($rankInfo['rank']) ?></h2>
            <p style="margin: 5px 0 0;"><?= htmlspecialchars($rankInfo['name']) ?></p>
        </div>
        <div style="text-align: right;">
            <div style="font-size: 28px; font-weight: bold;"><?= number_format($rankInfo['points']) ?></div>
            <div style="font-size: 11px;">очков рейтинга</div>
        </div>
    </div>
    <?php if ($rankInfo['next_rank']): ?>
    <div style="margin-top: 15px;">
        <div style="font-size: 12px;">До ранга <?= $rankInfo['next_rank'] ?>: <?= $rankInfo['progress'] ?>%</div>
        <div style="background: rgba(255,255,255,0.3); border-radius: 10px; margin-top: 5px;">
            <div style="background: white; width: <?= $rankInfo['progress'] ?>%; height: 8px; border-radius: 10px;"></div>
        </div>
        <div style="font-size: 11px; margin-top: 5px;">Осталось <?= number_format($rankInfo['next_points'] - $rankInfo['points']) ?> очков</div>
    </div>
    <?php endif; ?>
    <?php if ($last_analysis): ?>
    <div style="margin-top: 15px; font-size: 11px; opacity: 0.8; border-top: 1px solid rgba(255,255,255,0.2); padding-top: 10px;">
        📊 Последнее обновление рейтинга: <?= $last_analysis ?>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($notifications)): ?>
<div style="background: #fff3e0; border-radius: 10px; padding: 15px; margin: 15px 0;">
    <h3 style="margin: 0 0 10px 0;">🔔 Новые уведомления (<?= count($notifications) ?>)</h3>
    <?php foreach($notifications as $n): ?>
    <div style="padding: 8px 0; border-bottom: 1px solid #ffe0b3; font-size: 14px;">
        <?= htmlspecialchars($n['message']) ?>
        <div style="font-size: 10px; color: #999;"><?= date('d.m.Y H:i', strtotime($n['created_at'])) ?></div>
    </div>
    <?php endforeach; ?>
    <button onclick="fetch('api/gamification.php?action=mark_read').then(()=>location.reload())" 
            style="margin-top: 10px; background: #ff9800; color: white; border: none; padding: 5px 15px; border-radius: 5px; cursor: pointer;">
        Отметить как прочитанные
    </button>
</div>
<?php endif; ?>
