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
}
.nav-link {
    padding: 16px 20px;
    text-decoration: none;
    color: #4b5563;
    font-weight: 500;
    transition: all 0.2s;
    display: inline-block;
    border-bottom: 3px solid transparent;
}
.nav-link:hover {
    color: #00a36c;
    background: #f0fdf4;
}
.nav-link.active {
    color: #00a36c;
    border-bottom-color: #00a36c;
}
.nav-user {
    display: flex;
    align-items: center;
    gap: 15px;
}
.user-name {
    color: #374151;
    font-weight: 500;
}
.logout-btn {
    background: #ef4444;
    color: white;
    padding: 8px 16px;
    border-radius: 8px;
    text-decoration: none;
    font-size: 14px;
    transition: background 0.2s;
}
.logout-btn:hover {
    background: #dc2626;
}
@media (max-width: 768px) {
    .navbar {
        flex-direction: column;
        padding: 10px;
    }
    .nav-link {
        padding: 10px 12px;
        font-size: 14px;
    }
    .nav-user {
        margin-top: 10px;
        padding-bottom: 10px;
    }
}
</style>

<div class="navbar">
    <div class="nav-links">
        <a href="dashboard.php" class="nav-link <?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
            📊 Дашборд
        </a>
        
        <?php if ($role == 'admin'): ?>
            <a href="admin.php" class="nav-link <?= $current_page == 'admin.php' ? 'active' : '' ?>">
                👑 Админ-панель
            </a>
            <a href="team.php" class="nav-link <?= $current_page == 'team.php' ? 'active' : '' ?>">
                👥 Команда
            </a>
            <a href="region_manager.php" class="nav-link <?= $current_page == 'region_manager.php' ? 'active' : '' ?>">
                🌍 Регионы
            </a>
        <?php elseif ($role == 'manager'): ?>
            <a href="team.php" class="nav-link <?= $current_page == 'team.php' ? 'active' : '' ?>">
                👥 Моя команда
            </a>
            <a href="region_manager.php" class="nav-link <?= $current_page == 'region_manager.php' ? 'active' : '' ?>">
                🌍 Регионы
            </a>
        <?php endif; ?>
    </div>
    
    <div class="nav-user">
        <span class="user-name">
            👤 <?= htmlspecialchars($_SESSION['name'] ?? 'Пользователь') ?>
            <span style="font-size: 12px; color: #6b7280;">(<?= $role == 'admin' ? 'Администратор' : ($role == 'manager' ? 'Руководитель' : 'Сотрудник') ?>)</span>
        </span>
        <a href="logout.php" class="logout-btn">🚪 Выйти</a>
    </div>
</div>
