<?php
session_start();
require_once 'db.php';

// Проверка прав (только admin, head, mmb_tp_head)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'head', 'mmb_tp_head'])) {
    header('Location: dashboard.php');
    exit;
}

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

$message = '';
$error = '';

// --- ДОБАВЛЕНИЕ ТОВАРА ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price_xp = intval($_POST['price_xp'] ?? 0);

    if (!$name || $price_xp <= 0) {
        $error = '❌ Укажите название и цену';
    } else {
        // Загрузка картинки
        $image_path = '';
        if (!empty($_FILES['image']['tmp_name'])) {
            $upload_dir = 'uploads/shop/';
            if (!is_dir('/var/www/html/' . $upload_dir)) {
                mkdir('/var/www/html/' . $upload_dir, 0777, true);
            }
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $filename = 'product_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            $target = '/var/www/html/' . $upload_dir . $filename;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                $image_path = $upload_dir . $filename;
            }
        }

        $stmt = $pdo->prepare("INSERT INTO shop_products (name, description, price_xp, image_path) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $description, $price_xp, $image_path]);

        $message = '✅ Товар «' . htmlspecialchars($name) . '» добавлен!';
    }
}

// --- ЗАГРУЗКА КОДОВ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_codes'])) {
    $product_id = intval($_POST['product_id'] ?? 0);
    $codes_text = trim($_POST['codes'] ?? '');

    if (!$product_id || !$codes_text) {
        $error = '❌ Выберите товар и введите коды';
    } else {
        $codes = array_filter(array_map('trim', explode("\n", $codes_text)));
        $added = 0;
        $skipped = 0;

        foreach ($codes as $code) {
            if (empty($code)) continue;
            try {
                $stmt = $pdo->prepare("INSERT INTO shop_codes (product_id, code) VALUES (?, ?)");
                $stmt->execute([$product_id, $code]);
                $added++;
            } catch (PDOException $e) {
                $skipped++; // Дубликат
            }
        }

        $message = "✅ Добавлено кодов: {$added}" . ($skipped > 0 ? " (пропущено дублей: {$skipped})" : '');
    }
}

// --- УДАЛЕНИЕ ТОВАРА ---
if (isset($_GET['delete_product'])) {
    $product_id = intval($_GET['delete_product']);

    // Удаляем связанные коды и заказы
    $pdo->prepare("DELETE FROM shop_codes WHERE product_id = ? AND is_sold = 0")->execute([$product_id]);
    $pdo->prepare("DELETE FROM shop_products WHERE id = ?")->execute([$product_id]);

    $message = '✅ Товар удалён';
}

// --- ПОЛУЧЕНИЕ ДАННЫХ ---
$products = $pdo->query("SELECT p.*, 
    (SELECT COUNT(*) FROM shop_codes c WHERE c.product_id = p.id AND c.is_sold = 0) as in_stock,
    (SELECT COUNT(*) FROM shop_codes c WHERE c.product_id = p.id AND c.is_sold = 1) as sold
    FROM shop_products p ORDER BY p.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Получаем статистику
$total_products = count($products);
$total_codes = $pdo->query("SELECT COUNT(*) FROM shop_codes")->fetchColumn();
$total_sold = $pdo->query("SELECT COUNT(*) FROM shop_codes WHERE is_sold = 1")->fetchColumn();
$total_orders = $pdo->query("SELECT COUNT(*) FROM shop_orders")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>🏪 Админка магазина</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .card { background:#fff; border-radius:16px; padding:20px; margin-bottom:20px; box-shadow:0 2px 12px rgba(0,0,0,0.04); }
        .form-group { margin-bottom:15px; }
        .form-group label { display:block; margin-bottom:5px; font-weight:500; }
        .form-group input, .form-group select, .form-group textarea { width:100%; padding:8px 12px; border:1px solid #ccc; border-radius:8px; }
        .btn { background:#1a73e8; color:#fff; border:none; padding:10px 20px; border-radius:8px; cursor:pointer; }
        .btn-success { background:#28a745; }
        .btn-danger { background:#e03131; }
        .btn-sm { padding:6px 12px; font-size:12px; }
        .error { background:#f8d7da; color:#721c24; padding:10px; border-radius:8px; margin-bottom:15px; }
        .success { background:#d4edda; color:#155724; padding:10px; border-radius:8px; margin-bottom:15px; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:10px; text-align:left; border-bottom:1px solid #eee; }
        th { background:#f8f9fa; }
        .stats-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap:15px; margin-bottom:20px; }
        .stat-card { background:white; padding:20px; border-radius:12px; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
        .stat-value { font-size:28px; font-weight:700; color:#1e40af; }
        .stat-label { font-size:12px; color:#64748b; margin-top:5px; }
        .product-img { width:60px; height:60px; object-fit:cover; border-radius:8px; background:#f8fafc; }
        .codes-textarea { font-family:'Courier New', monospace; min-height:150px; }
        .nav { display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap; align-items:center; }
        .nav a { padding:8px 16px; background:#f0f2f5; border-radius:20px; text-decoration:none; color:#1a1a2e; }
        .nav a.active { background:#1a73e8; color:#fff; }
        .nav .logout { background:#e03131; color:#fff; }
        .tabs { display:flex; gap:10px; margin-bottom:20px; border-bottom:2px solid #e2e8f0; }
        .tab { padding:10px 20px; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-2px; }
        .tab.active { border-bottom-color:#1a73e8; color:#1a73e8; font-weight:600; }
        .tab-content { display:none; }
        .tab-content.active { display:block; }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="dashboard.php">← Назад</a>
        <a href="admin_shop.php" class="active">🏪 Магазин</a>
        <span style="margin-left:auto;">👤 <?= htmlspecialchars($_SESSION['name'] ?? '') ?></span>
        <a href="logout.php" class="logout">Выйти</a>
    </div>

    <h2>🏪 Управление магазином сертификатов</h2>

    <?= $error ? '<div class="error">' . $error . '</div>' : '' ?>
    <?= $message ? '<div class="success">' . $message . '</div>' : '' ?>

    <!-- Статистика -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= $total_products ?></div>
            <div class="stat-label">Товаров</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $total_codes ?></div>
            <div class="stat-label">Всего кодов</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $total_sold ?></div>
            <div class="stat-label">Продано</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $total_orders ?></div>
            <div class="stat-label">Заказов</div>
        </div>
    </div>

    <!-- Табы -->
    <div class="tabs">
        <div class="tab active" onclick="showTab('products')">📦 Товары</div>
        <div class="tab" onclick="showTab('codes')">🔑 Коды</div>
        <div class="tab" onclick="showTab('add')">➕ Добавить товар</div>
    </div>

    <!-- Товары -->
    <div id="tab-products" class="tab-content active">
        <div class="card">
            <h3>📦 Список товаров</h3>
            <table>
                <thead>
                    <tr><th>ID</th><th>Картинка</th><th>Название</th><th>Цена (XP)</th><th>В наличии</th><th>Продано</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($products as $p): ?>
                <tr>
                    <td><?= $p['id'] ?></td>
                    <td>
                        <?php if ($p['image_path']): ?>
                            <img src="<?= htmlspecialchars($p['image_path']) ?>" class="product-img">
                        <?php else: ?>🎫<?php endif; ?>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($p['name']) ?></strong><br>
                        <small style="color:#64748b;"><?= htmlspecialchars($p['description'] ?? '') ?></small>
                    </td>
                    <td><strong style="color:#f59e0b;"><?= $p['price_xp'] ?> XP</strong></td>
                    <td><?= $p['in_stock'] ?></td>
                    <td><?= $p['sold'] ?></td>
                    <td>
                        <a href="?delete_product=<?= $p['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Удалить товар и все невыкупленные коды?')">🗑️</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Коды -->
    <div id="tab-codes" class="tab-content">
        <div class="card">
            <h3>🔑 Загрузка кодов сертификатов</h3>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Выберите товар</label>
                    <select name="product_id" required>
                        <option value="">— Выберите —</option>
                        <?php foreach ($products as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= $p['price_xp'] ?> XP)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Коды сертификатов (по одному на строку)</label>
                    <textarea name="codes" class="codes-textarea" placeholder="ABC123&#10;DEF456&#10;GHI789" required></textarea>
                </div>
                <button type="submit" name="add_codes" class="btn btn-success">➕ Добавить коды</button>
            </form>
        </div>
    </div>

    <!-- Добавить товар -->
    <div id="tab-add" class="tab-content">
        <div class="card">
            <h3>➕ Новый товар</h3>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Название *</label>
                    <input type="text" name="name" required placeholder="Сертификат 500₽">
                </div>
                <div class="form-group">
                    <label>Описание</label>
                    <textarea name="description" rows="3" placeholder="Подарочный сертификат на 500 рублей..."></textarea>
                </div>
                <div class="form-group">
                    <label>Цена в XP *</label>
                    <input type="number" name="price_xp" required min="1" placeholder="1000">
                </div>
                <div class="form-group">
                    <label>Картинка сертификата</label>
                    <input type="file" name="image" accept="image/*">
                </div>
                <button type="submit" name="add_product" class="btn">💾 Сохранить товар</button>
            </form>
        </div>
    </div>
</div>

<script>
function showTab(tab) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    event.target.classList.add('active');
    document.getElementById('tab-' + tab).classList.add('active');
}
</script>
</body>
</html>
