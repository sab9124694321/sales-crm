<?php
require __DIR__ . '/db.php';
$db = getContactsDb();
$rows  = $db->query("SELECT * FROM contacts ORDER BY created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$total = $db->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Поиск контактов по ИНН</title>
    <style>
        body { font-family: -apple-system, sans-serif; max-width: 1400px; margin: 20px auto; padding: 0 20px; }
        .btn { display: inline-block; padding: 10px 20px; background: #007bff; color: #fff; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 14px; }
        .btn:hover { background: #0056b3; }
        .btn-green { background: #28a745; }
        .btn-green:hover { background: #1e7e34; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 11px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; vertical-align: top; }
        th { background: #f4f4f4; }
        textarea { width: 100%; height: 120px; font-family: monospace; padding: 10px; box-sizing: border-box; }
        .msg { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .msg-ok { background: #d4edda; color: #155724; }
        .msg-err { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <h1>Поиск контактов по ИНН</h1>
    <?php if (!empty($_GET['ok'])): ?>
        <div class="msg msg-ok">Обработано ИНН: <?= (int)$_GET['ok'] ?></div>
    <?php endif; ?>
    <?php if (!empty($_GET['err'])): ?>
        <div class="msg msg-err"><?= htmlspecialchars($_GET['err']) ?></div>
    <?php endif; ?>

    <form action="import.php" method="post" enctype="multipart/form-data">
        <p><strong>Вставьте ИНН (по одному на строку) или загрузите файл:</strong></p>
        <textarea name="inns" placeholder="7707083893&#10;7736207543"></textarea>
        <p>Или файл (.txt / .csv): <input type="file" name="file" accept=".txt,.csv"></p>
        <button type="submit" class="btn">Обработать</button>
        <a href="export.php" class="btn btn-green">Скачать CSV (<?= $total ?>)</a>
    </form>

    <h2>Последние записи (<?= $total ?> всего)</h2>
    <div style="overflow:auto; max-height: 70vh;">
    <table>
        <tr>
            <th>ИНН</th><th>Компания</th><th>Статус</th><th>Руководитель</th>
            <th>ОКВЭД</th><th>Род деятельности</th><th>Адрес</th>
            <th>Телефоны</th><th>Email</th><th>Сайты</th><th>Источники</th>
        </tr>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['inn']) ?></td>
                <td><?= htmlspecialchars($r['company_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['status'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['director_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['okved_main'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['okved_description'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['address'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['phones'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['emails'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['websites'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['sources'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
</body>
</html>
