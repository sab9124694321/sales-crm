<?php
if (!isset($_SESSION['user_id'])) return;

$role = $_SESSION['role'];
$current_page = basename($_SERVER['PHP_SELF']);
?>

<style>
.navbar {
    background: white;
    border-radius: 16px;
    padding: 0 20px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
}
.nav-links {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
    padding: 10px 0;
}
.nav-links a {
    color: #374151;
    text-decoration: none;
    padding: 8px 16px;
    border-radius: 8px;
    transition: all 0.2s;
    font-size: 14px;
}
.nav-links a:hover {
    background: #f3f4f6;
}
.nav-links a.active {
    background: #00a36c;
    color: white;
}
.logout-btn {
    background: #ef4444;
    color: white;
    text-decoration: none;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 14px;
}
.logout-btn:hover {
    background: #dc2626;
}
.user-info {
    color: #666;
    font-size: 13px;
}
@media (max-width: 768px) {
    .navbar { flex-direction: column; text-align: center; }
    .nav-links { justify-content: center; }
}
</style>

<div class="navbar">
    <div class="nav-links">
        <a href="dashboard.php" class="<?= $current_page == 'dashboard.php' ? 'active' : '' ?>">📊 Дашборд</a>
        <?php if ($role == 'admin'): ?>
        <a href="admin.php" class="<?= $current_page == 'admin.php' ? 'active' : '' ?>">👑 Админ-панель</a>
        <?php endif; ?>
        <a href="team.php" class="<?= $current_page == 'team.php' ? 'active' : '' ?>">👥 Команда</a>
        <a href="region_manager.php" class="<?= $current_page == 'region_manager.php' ? 'active' : '' ?>">🌍 Регионы</a>
        <a href="ai_dashboard.php" class="<?= $current_page == 'ai_dashboard.php' ? 'active' : '' ?>">🤖 ИИ-дашборд</a>
    </div>
    <div style="display: flex; align-items: center; gap: 15px;">
        <span class="user-info">👤 <?= htmlspecialchars($_SESSION['name']) ?> (<?= $role == 'admin' ? 'Администратор' : ($role == 'manager' ? 'Менеджер' : 'Сотрудник') ?>)</span>
        <a href="logout.php" class="logout-btn">🚪 Выйти</a>
    </div>
</div>
