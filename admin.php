<?php
session_start();
if(!isset($_SESSION['user_id'])||$_SESSION['role']!='admin'){header('Location:dashboard.php');exit;}
require_once 'db.php';
$msg='';
$tab=isset($_GET['tab'])?$_GET['tab']:'employees';

// Обработка изменения роли через AJAX
if(isset($_GET['change_role']) && isset($_GET['new_role'])){
    $id = intval($_GET['change_role']);
    $new_role = $_GET['new_role'];
    $allowed = ['manager','head','territory_head','terman','admin','mmb_manager','mmb_tp_head','ubr_middle'];
    if(in_array($new_role, $allowed)){
        $pdo->prepare("UPDATE users SET role=? WHERE id=?")->execute([$new_role, $id]);
        echo 'ok';
    } else { echo 'invalid'; }
    exit;
}

// НОВОЕ: обработка смены начальника
if(isset($_GET['change_manager']) && isset($_GET['new_manager_id'])){
    $id = intval($_GET['change_manager']);
    $new_manager_id = $_GET['new_manager_id'] ? intval($_GET['new_manager_id']) : null;
    $pdo->prepare("UPDATE users SET manager_id=? WHERE id=?")->execute([$new_manager_id, $id]);
    echo 'ok';
    exit;
}

if(isset($_GET['delete'])){$r=$pdo->prepare("SELECT role FROM users WHERE id=?");$r->execute([$_GET['delete']]);if($r->fetchColumn()!='admin'){$pdo->prepare("DELETE FROM users WHERE id=?")->execute([$_GET['delete']]);}header("Location: admin.php?tab=employees");exit;}
if(isset($_GET['move_user'])){if(isset($_GET['new_territory'])&&$_GET['new_territory']!=='')$pdo->prepare("UPDATE users SET territory_id=? WHERE id=?")->execute([$_GET['new_territory'],$_GET['move_user']]);header("Location: admin.php?tab=employees");exit;}
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['save_user'])){
    $pass=password_hash($_POST['password']??'123456',PASSWORD_DEFAULT);
    $manager_id = null;
    if (!empty($_POST['head_tabel'])) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE tabel_number = ?");
        $stmt->execute([$_POST['head_tabel']]);
        $manager_id = $stmt->fetchColumn() ?: null;
    }
    if($_POST['user_id']){
        $pdo->prepare("UPDATE users SET full_name=?,email=?,role=?,head_tabel=?,territory_id=?,is_active=?,manager_id=? WHERE id=?")
           ->execute([$_POST['full_name'],$_POST['email'],$_POST['role'],$_POST['head_tabel']?:null,$_POST['territory_id']?:null,$_POST['is_active']??1,$manager_id,$_POST['user_id']]);
    } else {
        $pdo->prepare("INSERT INTO users(tabel_number,full_name,email,password_hash,role,head_tabel,territory_id,manager_id) VALUES(?,?,?,?,?,?,?,?)")
           ->execute([$_POST['tabel_number'],$_POST['full_name'],$_POST['email'],$pass,$_POST['role'],$_POST['head_tabel']?:null,$_POST['territory_id']?:null,$manager_id]);
    }
    $msg='<div style="background:#d4edda;padding:10px;border-radius:8px;margin-bottom:15px">✅ Сохранено</div>';
}
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['create_territory'])){$pdo->prepare("INSERT INTO territories(name,code) VALUES(?,?)")->execute([$_POST['terr_name'],$_POST['terr_code']]);$msg='<div style="background:#d4edda;padding:10px;border-radius:8px;margin-bottom:15px">✅ Территория создана</div>';}
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['set_plan'])){
    $values=[$_POST['plan_tabel'],$_POST['period'],$_POST['calls_plan']??0,$_POST['calls_answered_plan']??0,$_POST['meetings_plan']??0,$_POST['contracts_plan']??0,$_POST['registrations_plan']??0,$_POST['smart_cash_plan']??0,$_POST['pos_systems_plan']??0,$_POST['inn_leads_plan']??0,$_POST['teams_plan']??0,$_POST['turnover_plan']??0];
    if(isset($_POST['apply_all'])&&$_POST['apply_all']=='1'){
        $all=$pdo->query("SELECT tabel_number FROM users WHERE role='manager' AND is_active=1")->fetchAll();
        foreach($all as $a){$values[0]=$a['tabel_number'];$pdo->prepare("INSERT INTO plans(tabel_number,period,calls_plan,calls_answered_plan,meetings_plan,contracts_plan,registrations_plan,smart_cash_plan,pos_systems_plan,inn_leads_plan,teams_plan,turnover_plan) VALUES(?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(tabel_number,period) DO UPDATE SET calls_plan=excluded.calls_plan,calls_answered_plan=excluded.calls_answered_plan,meetings_plan=excluded.meetings_plan,contracts_plan=excluded.contracts_plan,registrations_plan=excluded.registrations_plan,smart_cash_plan=excluded.smart_cash_plan,pos_systems_plan=excluded.pos_systems_plan,inn_leads_plan=excluded.inn_leads_plan,teams_plan=excluded.teams_plan,turnover_plan=excluded.turnover_plan")->execute($values);}
        $msg='<div style="background:#d4edda;padding:10px;border-radius:8px;margin-bottom:15px">✅ План назначен '.count($all).' сотрудникам</div>';
    }else{
        $pdo->prepare("INSERT INTO plans(tabel_number,period,calls_plan,calls_answered_plan,meetings_plan,contracts_plan,registrations_plan,smart_cash_plan,pos_systems_plan,inn_leads_plan,teams_plan,turnover_plan) VALUES(?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(tabel_number,period) DO UPDATE SET calls_plan=excluded.calls_plan,calls_answered_plan=excluded.calls_answered_plan,meetings_plan=excluded.meetings_plan,contracts_plan=excluded.contracts_plan,registrations_plan=excluded.registrations_plan,smart_cash_plan=excluded.smart_cash_plan,pos_systems_plan=excluded.pos_systems_plan,inn_leads_plan=excluded.inn_leads_plan,teams_plan=excluded.teams_plan,turnover_plan=excluded.turnover_plan")->execute($values);
        $msg='<div style="background:#d4edda;padding:10px;border-radius:8px;margin-bottom:15px">✅ План сохранён</div>';
    }
}
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['assign_terman'])){$pdo->prepare("INSERT OR IGNORE INTO terman_territories(terman_tabel,territory_id) VALUES(?,?)")->execute([$_POST['terman_tabel'],$_POST['territory_id']]);$msg='<div style="background:#d4edda;padding:10px;border-radius:8px;margin-bottom:15px">✅ Термен назначен</div>';}
if(isset($_GET['remove_terman'])){$pdo->prepare("DELETE FROM terman_territories WHERE id=?")->execute([$_GET['remove_terman']]);header("Location: admin.php?tab=terman");exit;}

// НОВЫЙ ИМПОРТ ЧЕРЕЗ ТЕКСТОВОЕ ПОЛЕ (с поддержкой кода территории)
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['import_csv_text'])){
    $csv_text = trim($_POST['csv_data']);
    $lines = explode("\n", $csv_text);
    $c = 0;
    $errors = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        $row = str_getcsv($line, ',', '"', '\\');
        if (count($row) >= 3 && strlen(trim($row[0])) > 0) {
            $tabel = trim($row[0]);
            $full_name = trim($row[1]);
            $role = trim($row[2]);
            $password = $row[3] ?? '123456';
            $email = trim($row[4] ?? '');
            $head_tabel = trim($row[5] ?? '');
            $territory_input = trim($row[6] ?? '');
            
            // Проверка дубликата
            $stmt = $pdo->prepare("SELECT id FROM users WHERE tabel_number = ?");
            $stmt->execute([$tabel]);
            if ($stmt->fetch()) continue;
            
            // Определяем territory_id: если указано число, считаем ID, иначе ищем по коду
            $territory_id = null;
            if (!empty($territory_input)) {
                if (is_numeric($territory_input)) {
                    // Сначала пробуем как ID
                    $stmt = $pdo->prepare("SELECT id FROM territories WHERE id = ?");
                    $stmt->execute([$territory_input]);
                    $territory_id = $stmt->fetchColumn();
                    if (!$territory_id) {
                        // Если не найден, пробуем как code
                        $stmt = $pdo->prepare("SELECT id FROM territories WHERE code = ?");
                        $stmt->execute([$territory_input]);
                        $territory_id = $stmt->fetchColumn();
                    }
                } else {
                    // Ищем по коду
                    $stmt = $pdo->prepare("SELECT id FROM territories WHERE code = ?");
                    $stmt->execute([$territory_input]);
                    $territory_id = $stmt->fetchColumn();
                }
            }
            
            $rp = password_hash($password, PASSWORD_DEFAULT);
            $manager_id = null;
            if (!empty($head_tabel)) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE tabel_number = ?");
                $stmt->execute([$head_tabel]);
                $manager_id = $stmt->fetchColumn() ?: null;
            }
            try {
                $pdo->prepare("INSERT INTO users(tabel_number,full_name,email,password_hash,role,head_tabel,territory_id,manager_id) VALUES(?,?,?,?,?,?,?,?)")
                   ->execute([$tabel, $full_name, $email, $rp, $role, $head_tabel ?: null, $territory_id, $manager_id]);
                $c++;
            } catch (Exception $e) {
                $errors[] = "Ошибка при вставке $tabel: " . $e->getMessage();
            }
        } else {
            $errors[] = "Пропущена строка: " . htmlspecialchars($line);
        }
    }
    if (!empty($errors)) {
        $msg = '<div class="error" style="background:#f8d7da;padding:10px;border-radius:8px;margin-bottom:15px">';
        foreach ($errors as $err) $msg .= $err . '<br>';
        $msg .= '</div>';
    } else {
        $msg = '<div style="background:#d4edda;padding:10px;border-radius:8px;margin-bottom:15px">✅ Импортировано ' . $c . ' сотрудников</div>';
    }
}
if(isset($_GET['export'])){header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename=export_'.date('Y-m-d').'.csv');$out=fopen('php://output','w');fputcsv($out,['Табельный','ФИО']);foreach($pdo->query("SELECT * FROM users ORDER BY full_name")->fetchAll() as$r)fputcsv($out,[$r['tabel_number'],$r['full_name']]);fclose($out);exit;}
$users=$pdo->query("SELECT u.*,t.name as tname FROM users u LEFT JOIN territories t ON u.territory_id=t.id ORDER BY u.role,u.full_name")->fetchAll();
// Исправлено: добавлена роль mmb_tp_head
$heads=$pdo->query("SELECT * FROM users WHERE role IN('head','territory_head','mmb_tp_head') AND is_active=1 ORDER BY full_name")->fetchAll();
$territories=$pdo->query("SELECT * FROM territories ORDER BY name")->fetchAll();
$managers=$pdo->query("SELECT * FROM users WHERE role='manager' AND is_active=1 ORDER BY full_name")->fetchAll();
$termans=$pdo->query("SELECT * FROM users WHERE role='terman' AND is_active=1 ORDER BY full_name")->fetchAll();
$terman_links=$pdo->query("SELECT tt.*,u.full_name as terman_name,t.name as terr_name FROM terman_territories tt JOIN users u ON tt.terman_tabel=u.tabel_number JOIN territories t ON tt.territory_id=t.id ORDER BY u.full_name")->fetchAll();

$all_roles = [
    'manager' => 'Менеджер',
    'head' => 'Начальник',
    'territory_head' => 'Нач.территории',
    'terman' => 'Термен',
    'admin' => 'Админ',
    'mmb_manager' => 'Менеджер ММБ',
    'mmb_tp_head' => 'Руководитель ТП ММБ',
    'ubr_middle' => 'УБР Middle'
];
?>
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Админ</title><link rel="stylesheet" href="style.css">
<script>
function changeRole(userId, selectEl) {
    let newRole = selectEl.value;
    if(!newRole) return;
    if(confirm('Изменить роль на "'+newRole+'"?')) {
        fetch('admin.php?change_role='+userId+'&new_role='+encodeURIComponent(newRole))
        .then(r => r.text())
        .then(res => {
            if(res.trim() === 'ok') location.reload();
            else alert('Ошибка при смене роли');
        });
    } else {
        selectEl.value = selectEl.getAttribute('data-old');
    }
}
function moveUser(id,val){if(val)location.href='?move_user='+id+'&new_territory='+val;}

// НОВОЕ: функция смены начальника
function changeManager(userId, selectEl) {
    let newManagerId = selectEl.value;
    if(confirm('Изменить начальника?')) {
        fetch('admin.php?change_manager='+userId+'&new_manager_id='+encodeURIComponent(newManagerId))
        .then(r => r.text())
        .then(res => {
            if(res.trim() === 'ok') location.reload();
            else alert('Ошибка при смене начальника');
        });
    } else {
        // восстанавливаем предыдущее значение
        selectEl.value = selectEl.getAttribute('data-old');
    }
}
</script>
</head><body>
<div class="nav"><a href="dashboard.php" class="logo">🚀 SZB</a><a href="dashboard.php">Дашборд</a><a href="team.php">Команда</a><a href="admin.php" class="active">Админ</a><a href="admin_shop.php">🎁 Магазин</a><a href="support_settings.php">🆘 Поддержка</a><a href="logout.php" style="color:#e03131">Выйти</a></div>
<div class="container"><h2>Админ-панель</h2><?= $msg ?>
<a href="?tab=employees" class="btn btn-sm">👥</a> <a href="?tab=plans" class="btn btn-sm">📊</a> <a href="?tab=territories" class="btn btn-sm">🌍</a> <a href="?tab=terman" class="btn btn-sm">👔</a> <a href="?tab=import" class="btn btn-sm">📥</a> <a href="?tab=export" class="btn btn-sm">📤</a> <a href="support_settings.php" class="btn btn-sm">🆘</a>
<hr>
<?php if($tab=='employees'): ?>
<h3>Сотрудники (<?= count($users) ?>)</h3>
<button onclick="document.getElementById('add_form').style.display='block'" class="btn btn-sm">➕</button>
<form id="add_form" method="POST" style="display:none;margin:15px 0;padding:15px;background:#f8f9fa;border-radius:10px">
<input type="hidden" name="save_user" value="1"><input type="hidden" name="user_id">
<div class="grid2"><input name="tabel_number" placeholder="Таб.номер" required><input name="full_name" placeholder="ФИО" required><input name="email" placeholder="Email"><input name="password" placeholder="Пароль" value="123456">
<select name="role">
<?php foreach($all_roles as $val=>$label): ?>
<option value="<?= $val ?>"><?= $label ?></option>
<?php endforeach; ?>
</select>
<select name="head_tabel"><option value="">Без начальника</option><?php foreach($heads as$h)echo"<option value='{$h['tabel_number']}'>".htmlspecialchars($h['full_name'])."</option>"; ?></select>
<select name="territory_id"><option value="">Без территории</option><?php foreach($territories as$t)echo"<option value='{$t['id']}'>".htmlspecialchars($t['name'])."</option>"; ?></select>
<select name="is_active"><option value="1">Активен</option><option value="0">Уволен</option></select></div><button type="submit" class="btn" style="margin-top:10px">💾</button></form>
<table>
<th>Таб.№</th><th>ФИО</th><th>Роль</th><th>Начальник</th><th>Территория</th><th>Статус</th><th></th>
<?php foreach($users as$u): ?>
<tr>
<td><?= $u['tabel_number'] ?></td>
<td><?= htmlspecialchars($u['full_name']) ?></td>
<td>
<?php if($u['role']=='admin'): ?>
    <?= htmlspecialchars($u['role']) ?>
<?php else: ?>
    <select onchange="changeRole(<?= $u['id'] ?>, this)" data-old="<?= $u['role'] ?>">
        <?php foreach($all_roles as $val=>$label): ?>
        <option value="<?= $val ?>" <?= $u['role']==$val?'selected':'' ?>><?= $label ?></option>
        <?php endforeach; ?>
    </select>
<?php endif; ?>
</td>
<!-- НОВОЕ: колонка с выбором начальника -->
<td>
    <select onchange="changeManager(<?= $u['id'] ?>, this)" data-old="<?= $u['manager_id'] ?? '' ?>">
        <option value="">—</option>
        <?php foreach($heads as $h): ?>
            <option value="<?= $h['id'] ?>" <?= ($u['manager_id'] == $h['id']) ? 'selected' : '' ?>><?= htmlspecialchars($h['full_name']) ?></option>
        <?php endforeach; ?>
    </select>
</td>
<td><select onchange="moveUser(<?= $u['id'] ?>,this.value)"><option value=""><?= $u['tname']??'—' ?></option><?php foreach($territories as$t)echo"<option value='{$t['id']}'>".htmlspecialchars($t['name'])."</option>"; ?></select></td>
<td><?= $u['is_active']?'✅':'❌' ?></td>
<td><?php if($u['role']!='admin'): ?><a href="?delete=<?= $u['id'] ?>" onclick="return confirm('Удалить?')" style="color:red">✕</a><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</table>

<?php elseif($tab=='plans'): ?><h3>Планы</h3><form method="POST"><input type="hidden" name="set_plan" value="1">
<div style="display:flex;gap:10px;margin-bottom:15px">
<select name="plan_tabel"><option value="">Сотрудник</option><?php foreach($managers as$m)echo"<option value='{$m['tabel_number']}'>".htmlspecialchars($m['full_name'])."</option>"; ?></select>
<input type="month" name="period" value="<?= date('Y-m') ?>" required></div>
<div class="grid2">
<div class="form-group"><label>📞 Звонки</label><input type="number" name="calls_plan" value="350"></div>
<div class="form-group"><label>✅ Дозвоны</label><input type="number" name="calls_answered_plan" value="245"></div>
<div class="form-group"><label>🤝 Встречи</label><input type="number" name="meetings_plan" value="35"></div>
<div class="form-group"><label>📄 Договоры</label><input type="number" name="contracts_plan" value="21"></div>
<div class="form-group"><label>📝 ТЭ</label><input type="number" name="registrations_plan" value="15"></div>
<div class="form-group"><label>💳 Смарт-кассы</label><input type="number" name="smart_cash_plan" value="10"></div>
<div class="form-group"><label>🖥️ ПОС</label><input type="number" name="pos_systems_plan" value="5"></div>
<div class="form-group"><label>🍵 ИНН чаевые</label><input type="number" name="inn_leads_plan" value="5"></div>
<div class="form-group"><label>👥 Команды</label><input type="number" name="teams_plan" value="3"></div>
<div class="form-group"><label>💰 Оборот</label><input type="number" name="turnover_plan" value="1500000" step="1000"></div>
</div>
<label><input type="checkbox" name="apply_all" value="1"> Применить ко всем менеджерам</label>
<button type="submit" class="btn" style="margin-top:10px">💾 Сохранить</button></form>

<?php elseif($tab=='territories'): ?><h3>Территории</h3><form method="POST"><input type="hidden" name="create_territory" value="1"><input name="terr_name" placeholder="Название" required><input name="terr_code" placeholder="Код" required><button type="submit" class="btn btn-sm">➕</button></form>
<table><?php foreach($territories as$t)echo"<tr><td>{$t['name']}</td><td>{$t['code']}</td></tr>"; ?></table>

<?php elseif($tab=='terman'): ?><h3>Термены</h3><form method="POST"><input type="hidden" name="assign_terman" value="1"><select name="terman_tabel"><option value="">Термен</option><?php foreach($termans as$tm)echo"<option value='{$tm['tabel_number']}'>".htmlspecialchars($tm['full_name'])."</option>"; ?></select><select name="territory_id"><option value="">Территория</option><?php foreach($territories as$t)echo"<option value='{$t['id']}'>".htmlspecialchars($t['name'])."</option>"; ?></select><button type="submit" class="btn btn-sm">🔗</button></form>
<table><?php foreach($terman_links as$tl)echo"<tr><td>{$tl['terman_name']}</td><td>{$tl['terr_name']}</td><td><a href='?remove_terman={$tl['id']}' style='color:red'>✕</a></td></tr>"; ?></table>

<?php elseif($tab=='import'): ?>
<h3>Импорт сотрудников</h3>
<div style="background:#f8f9fa;border-radius:10px;padding:15px;margin-bottom:20px">
  <p style="margin-top:0"><strong>📋 Структура CSV-данных:</strong></p>
  <p><code>Табельный номер, ФИО, Роль, Пароль, Email, Таб.номер начальника (опционально), ID территории (опционально, можно указать код)</code></p>
  <p style="margin-bottom:5px">Роли: manager, head, territory_head, terman, admin, mmb_manager, mmb_tp_head, ubr_middle.</p>
  <p style="margin-bottom:0">Если указан таб.номер начальника, сотрудник автоматически будет подчинён ему в иерархии.</p>
  <p><strong>Территория:</strong> можно указать числовой ID или код (например, <code>9055</code>).</p>
</div>
<form method="POST" id="importForm">
  <textarea name="csv_data" rows="10" style="width:100%; font-family:monospace;" placeholder="Вставьте сюда CSV-строки, каждая с новой строки, например:
1755413,АНУШЯН Самвел Араевич,mmb_tp_head,654321,anushyan.s.ar@sberbank.ru,,9055
1807463,КУЛЕМИН Максим Евгеньевич,mmb_tp_head,654321,mekulemin@sberbank.ru,,9055"></textarea>
  <button type="submit" name="import_csv_text" class="btn btn-sm" style="margin-top:10px">📥 Импортировать из текста</button>
</form>

<?php else: ?><h3>Экспорт</h3><a href="?export=1" class="btn btn-sm">👥 CSV</a><?php endif; ?>
</div></body></html>