<?php
session_start();
if(!isset($_SESSION['user_id'])){header('Location:login.php');exit;}
require_once 'db.php';
$tabel=$_SESSION['tabel'];
$today=isset($_POST['edit_date'])?$_POST['edit_date']:date('Y-m-d');
$fields=['calls','calls_answered','meetings','contracts','registrations','smart_cash','pos_systems','inn_leads','teams','turnover','rko'];
$vals=[];$sets=[];
foreach($fields as $f){
    $v=floatval($_POST[$f]??0);
    $vals[]=$v;
    $sets[]="$f=?";
}
// Сохранение отчёта
$sql="INSERT INTO daily_reports(user_id,tabel_number,report_date,".implode(',',$fields).",ai_calls)
      VALUES(?,?,?,".implode(',',array_fill(0,count($fields),'?')).",0)
      ON CONFLICT(tabel_number,report_date) DO UPDATE SET
      user_id=excluded.user_id, ".implode(',',$sets).", ai_calls=0";
$params=array_merge([$_SESSION['user_id'],$tabel,$today],$vals,$vals);
$pdo->prepare($sql)->execute($params);

// ---- Новая логика начисления баллов ----
$points = 0;
if ($vals[0] > 0) $points += 1;  // calls
if ($vals[1] > 0) $points += 2;  // calls_answered
if ($vals[2] > 0) $points += 3;  // meetings
// contracts – 0 баллов
if ($vals[4] > 0) $points += 5;  // registrations
if ($vals[5] > 0) $points += 7;  // smart_cash
if ($vals[6] > 0) $points += 10; // pos_systems
if ($vals[7] > 0) $points += 10; // inn_leads
if ($vals[8] > 0) $points += 4;  // teams
if ($vals[9] > 0) $points += floor($vals[9] / 10000); // turnover
if ($vals[10] > 0) $points += 1; // rko (факт наличия)

// Штраф за пустой день (ничего не заполнено)
if ($vals[0] == 0 && $vals[1] == 0 && $vals[2] == 0 && $vals[3] == 0 &&
    $vals[4] == 0 && $vals[5] == 0 && $vals[6] == 0 && $vals[7] == 0 &&
    $vals[8] == 0 && $vals[9] == 0 && $vals[10] == 0) {
    $points -= 5;
}

// Обновляем total_points и experience
$pdo->prepare("UPDATE users SET total_points=total_points+?, experience=experience+? WHERE tabel_number=?")->execute([$points,$points,$tabel]);

// Обновляем ранг и уровень
$u=$pdo->query("SELECT total_points,level FROM users WHERE tabel_number='$tabel'")->fetch();
$levels=[0=>'Новичок',200=>'Охотник',500=>'Мастер',1000=>'Эксперт',2000=>'Легенда'];
$new_level=1;$new_rank='Новичок';
foreach($levels as $pts=>$rank){
    if($u['total_points']>=$pts){
        $new_rank=$rank;
        $new_level=array_search($rank,array_values($levels))+1;
    }
}
$pdo->prepare("UPDATE users SET rank=?, level=?, next_level_exp=? WHERE tabel_number=?")->execute([$new_rank,$new_level,($new_level*200),$tabel]);

header('Location:dashboard.php?saved=1');