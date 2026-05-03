<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once 'db.php';
$role = $_SESSION['role'];
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-t');
$sql = "SELECT * FROM inn_records WHERE report_date BETWEEN ? AND ?";
$params = [$from, $to];
if ($role != 'admin') { $sql .= " AND user_id = ?"; $params[] = $_SESSION['user_id']; }
$sql .= " ORDER BY created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inn.csv"');
    $f = fopen('php://output', 'w');
    fputcsv($f, ['ID', 'ИНН', 'Тип', 'Сотрудник', 'Дата']);
    foreach ($records as $r) fputcsv($f, [$r['id'], $r['inn_value'], $r['product_type'], $r['user_name'], $r['report_date']]);
    fclose($f); exit;
}
?>
<!DOCTYPE html><html><head><title>Выгрузка ИНН</title><meta charset="utf-8"><style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:system-ui;background:#f5f7fb;padding:20px}
.container{max-width:1200px;margin:0 auto}
h1{color:#00a36c;margin-bottom:20px}
.card{background:white;border-radius:16px;padding:20px;margin-bottom:20px}
.filters{display:flex;gap:15px;flex-wrap:wrap}
.filters input,select{padding:8px;border:1px solid #ddd;border-radius:8px}
button{background:#00a36c;color:white;border:none;padding:8px 16px;border-radius:8px;cursor:pointer}
.btn-csv{background:#f59e0b}
table{width:100%;border-collapse:collapse}
th,td{padding:10px;text-align:left;border-bottom:1px solid #eee}
th{background:#f8f9fa}
</style></head>
<body><div class="container">
<?php require_once 'navbar.php'; ?>
<h1>📊 Выгрузка ИНН</h1>
<div class="card">
<form method="get" class="filters">
<input type="date" name="from" value="<?=$from?>">
<input type="date" name="to" value="<?=$to?>">
<button type="submit">🔍 Фильтр</button>
<button type="submit" name="csv" value="1" class="btn-csv">📥 CSV</button>
</form>
</div>
<div class="card">
<table><thead><tr><th>ID</th><th>ИНН</th><th>Тип</th><th>Сотрудник</th><th>Дата</th></tr></thead>
<tbody><?php foreach($records as $r): ?><tr><td><?=$r['id']?></td><td><?=htmlspecialchars($r['inn_value'])?></td><td><?=$r['product_type']?></td><td><?=htmlspecialchars($r['user_name'])?></td><td><?=$r['report_date']?></td></tr><?php endforeach; ?></tbody>
</table>
</div>
<a href="dashboard.php">← Назад</a>
</div></body></html>
