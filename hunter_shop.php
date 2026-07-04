<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['hunter_id'])) {
    header('Location: hunter_login.php');
    exit;
}

$hunter_id = $_SESSION['hunter_id'];

// Получаем данные охотника
$stmt = $pdo->prepare("SELECT * FROM hunters WHERE id = ?");
$stmt->execute([$hunter_id]);
$hunter = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$hunter) {
    session_destroy();
    header('Location: hunter_login.php');
    exit;
}

$message = '';
$error = '';

// --- ПОКУПКА СЕРТИФИКАТА ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buy_product'])) {
    $product_id = intval($_POST['product_id'] ?? 0);

    if (!$product_id) {
        $error = '❌ Товар не выбран';
    } else {
        // Получаем товар
        $stmt = $pdo->prepare("SELECT * FROM shop_products WHERE id = ? AND is_active = 1");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            $error = '❌ Товар не найден';
        } elseif ($hunter['points'] < $product['price_xp']) {
            $error = '❌ Недостаточно XP. У вас: ' . $hunter['points'] . ', нужно: ' . $product['price_xp'];
        } else {
            // Ищем свободный код
            $stmt = $pdo->prepare("SELECT * FROM shop_codes WHERE product_id = ? AND is_sold = 0 LIMIT 1");
            $stmt->execute([$product_id]);
            $code = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$code) {
                $error = '❌ Сертификаты закончились. Попробуйте позже.';
            } else {
                // Начинаем транзакцию
                $pdo->beginTransaction();

                try {
                    // Списываем XP
                    $stmt = $pdo->prepare("UPDATE hunters SET points = points - ? WHERE id = ? AND points >= ?");
                    $stmt->execute([$product['price_xp'], $hunter_id, $product['price_xp']]);

                    if ($stmt->rowCount() === 0) {
                        throw new Exception('Недостаточно XP');
                    }

                    // Отмечаем код как проданный
                    $stmt = $pdo->prepare("UPDATE shop_codes SET is_sold = 1, sold_to_hunter_id = ?, sold_at = datetime('now') WHERE id = ?");
                    $stmt->execute([$hunter_id, $code['id']]);

                    // Создаём заказ
                    $stmt = $pdo->prepare("INSERT INTO shop_orders (hunter_id, product_id, code_id, price_xp) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$hunter_id, $product_id, $code['id'], $product['price_xp']]);

                    // Уведомление в системе
                    $notif = "🎁 Вы купили сертификат «{$product['name']}» за {$product['price_xp']} XP! Код: {$code['code']}";
                    $stmt = $pdo->prepare("INSERT INTO hunter_notifications (hunter_id, message, type, is_read, created_at) VALUES (?, ?, 'success', 0, datetime('now'))");
                    $stmt->execute([$hunter_id, $notif]);

                    $pdo->commit();

                    $message = '✅ Сертификат «' . htmlspecialchars($product['name']) . '» куплен!<br><br>🎫 <strong>Ваш код: ' . htmlspecialchars($code['code']) . '</strong><br><br>💾 Сохраните код — он также доступен в разделе «Мои покупки».';

                    // Обновляем данные охотника
                    $hunter['points'] -= $product['price_xp'];

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = '❌ Ошибка: ' . $e->getMessage();
                }
            }
        }
    }
}

// Получаем товары
$products = $pdo->query("SELECT * FROM shop_products WHERE is_active = 1 ORDER BY price_xp ASC")->fetchAll(PDO::FETCH_ASSOC);

// Получаем историю покупок
$stmt = $pdo->prepare("SELECT o.*, p.name as product_name, p.image_path, c.code 
    FROM shop_orders o 
    JOIN shop_products p ON o.product_id = p.id 
    JOIN shop_codes c ON o.code_id = c.id 
    WHERE o.hunter_id = ? 
    ORDER BY o.created_at DESC");
$stmt->execute([$hunter_id]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем уведомления
$stmt = $pdo->prepare("SELECT COUNT(*) FROM hunter_notifications WHERE hunter_id = ? AND is_read = 0");
$stmt->execute([$hunter_id]);
$unread = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🎁 Магазин — Программа «Охотник»</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; color: #1e293b; padding-bottom: 80px; }
        .container { max-width: 800px; margin: 0 auto; padding: 20px; }

        .header { background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); color: white; padding: 20px; border-radius: 16px; margin-bottom: 20px; }
        .header h1 { font-size: 22px; }
        .header .balance { margin-top: 10px; font-size: 14px; opacity: 0.9; }
        .header .balance strong { font-size: 24px; color: #fbbf24; }

        .alert { padding: 15px; border-radius: 12px; margin-bottom: 20px; }
        .alert-success { background: #d1fae5; color: #065f46; }
        .alert-error { background: #fee2e2; color: #991b1b; }

        .section-title { font-size: 18px; font-weight: 600; margin: 30px 0 15px; color: #1e293b; }

        .products-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
        .product-card { background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.06); transition: transform 0.2s, box-shadow 0.2s; }
        .product-card:hover { transform: translateY(-4px); box-shadow: 0 8px 24px rgba(0,0,0,0.12); }
        .product-image { width: 100%; height: 200px; object-fit: cover; background: #f8fafc; display: flex; align-items: center; justify-content: center; font-size: 60px; }
        .product-image img { width: 100%; height: 100%; object-fit: cover; }
        .product-body { padding: 20px; }
        .product-name { font-size: 18px; font-weight: 600; margin-bottom: 8px; }
        .product-desc { font-size: 13px; color: #64748b; margin-bottom: 15px; line-height: 1.5; }
        .product-footer { display: flex; justify-content: space-between; align-items: center; }
        .product-price { font-size: 20px; font-weight: 700; color: #f59e0b; }
        .product-price span { font-size: 13px; color: #94a3b8; font-weight: 400; }
        .btn-buy { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; border: none; padding: 10px 24px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-buy:hover { transform: scale(1.05); }
        .btn-buy:disabled { background: #cbd5e1; cursor: not-allowed; transform: none; }
        .btn-buy .emoji { margin-right: 4px; }

        .orders-list { display: flex; flex-direction: column; gap: 12px; }
        .order-item { background: white; border-radius: 12px; padding: 15px; display: flex; gap: 15px; align-items: center; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
        .order-image { width: 60px; height: 60px; border-radius: 10px; object-fit: cover; background: #f8fafc; display: flex; align-items: center; justify-content: center; font-size: 30px; flex-shrink: 0; }
        .order-image img { width: 100%; height: 100%; border-radius: 10px; object-fit: cover; }
        .order-info { flex: 1; }
        .order-name { font-weight: 600; font-size: 15px; }
        .order-code { font-family: 'Courier New', monospace; background: #1e293b; color: #22c55e; padding: 4px 10px; border-radius: 6px; font-size: 14px; display: inline-block; margin-top: 5px; }
        .order-date { font-size: 12px; color: #94a3b8; }
        .order-price { font-weight: 700; color: #f59e0b; font-size: 16px; }

        .empty { text-align: center; padding: 40px; color: #94a3b8; }
        .empty-icon { font-size: 48px; margin-bottom: 10px; }

        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: white; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-around; padding: 10px 0; z-index: 100; }
        .bottom-nav a { text-decoration: none; color: #64748b; font-size: 11px; display: flex; flex-direction: column; align-items: center; gap: 4px; }
        .bottom-nav a.active { color: #3b82f6; }
        .bottom-nav a .icon { font-size: 20px; }
        .bottom-nav .badge { position: absolute; top: 2px; right: calc(50% - 20px); background: #ef4444; color: white; font-size: 10px; padding: 2px 6px; border-radius: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎁 Магазин сертификатов</h1>
            <div class="balance">Ваш баланс: <strong><?= $hunter['points'] ?? 0 ?></strong> XP</div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= $message ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= $error ?></div>
        <?php endif; ?>

        <div class="section-title">🏷️ Доступные сертификаты</div>

        <?php if (empty($products)): ?>
            <div class="empty">
                <div class="empty-icon">🏪</div>
                <div>Магазин пока пуст. Загляните позже!</div>
            </div>
        <?php else: ?>
            <div class="products-grid">
                <?php foreach ($products as $product): 
                    $can_buy = ($hunter['points'] ?? 0) >= $product['price_xp'];
                    // Проверяем, есть ли свободные коды
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM shop_codes WHERE product_id = ? AND is_sold = 0");
                    $stmt->execute([$product['id']]);
                    $in_stock = $stmt->fetchColumn() > 0;
                ?>
                <div class="product-card">
                    <div class="product-image">
                        <?php if ($product['image_path'] && file_exists('/var/www/html/' . $product['image_path'])): ?>
                            <img src="<?= htmlspecialchars($product['image_path']) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
                        <?php else: ?>
                            🎫
                        <?php endif; ?>
                    </div>
                    <div class="product-body">
                        <div class="product-name"><?= htmlspecialchars($product['name']) ?></div>
                        <div class="product-desc"><?= htmlspecialchars($product['description'] ?? '') ?></div>
                        <div class="product-footer">
                            <div class="product-price"><?= $product['price_xp'] ?> <span>XP</span></div>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                <button type="submit" name="buy_product" class="btn-buy" <?= (!$can_buy || !$in_stock) ? 'disabled' : '' ?>>
                                    <?php if (!$in_stock): ?>📭 Нет в наличии
                                    <?php elseif (!$can_buy): ?>💰 Не хватает
                                    <?php else: ?><span class="emoji">🛒</span> Купить
                                    <?php endif; ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="section-title">📦 Мои покупки</div>

        <?php if (empty($orders)): ?>
            <div class="empty">
                <div class="empty-icon">🛍️</div>
                <div>У вас пока нет покупок. Накапливайте XP и покупайте сертификаты!</div>
            </div>
        <?php else: ?>
            <div class="orders-list">
                <?php foreach ($orders as $order): ?>
                <div class="order-item">
                    <div class="order-image">
                        <?php if ($order['image_path'] && file_exists('/var/www/html/' . $order['image_path'])): ?>
                            <img src="<?= htmlspecialchars($order['image_path']) ?>" alt="">
                        <?php else: ?>
                            🎫
                        <?php endif; ?>
                    </div>
                    <div class="order-info">
                        <div class="order-name"><?= htmlspecialchars($order['product_name']) ?></div>
                        <div class="order-code"><?= htmlspecialchars($order['code']) ?></div>
                        <div class="order-date"><?= date('d.m.Y H:i', strtotime($order['created_at'])) ?></div>
                    </div>
                    <div class="order-price">-<?= $order['price_xp'] ?> XP</div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="bottom-nav">
        <a href="hunter_dashboard.php"><span class="icon">🎯</span>Лиды</a>
        <a href="hunter_rating.php"><span class="icon">🏆</span>Рейтинг</a>
        <a href="hunter_referrals.php"><span class="icon">👥</span>Друзья</a>
        <a href="hunter_shop.php" class="active"><span class="icon">🎁</span>Магазин</a>
        <a href="hunter_profile.php"><span class="icon">👤</span>Профиль</a>
    </div>
</body>
</html>
