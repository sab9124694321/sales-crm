<?php
session_start();
if(!isset($_SESSION['user_id'])){header('Location:login.php');exit;}
require_once 'db.php';
$tabel=$_SESSION['tabel'];
// Генерация уведомлений при входе
$last_gen=$pdo->prepare("SELECT MAX(created_at) FROM notifications WHERE user_tabel=? AND date(created_at)=date('now')")->execute([$tabel])->fetchColumn();
if(!$last_gen){
    // Сравнение с коллегами
    $my_contracts=$pdo->prepare("SELECT COALESCE(SUM(contracts),0) FROM daily_reports WHERE tabel_number=? AND date(report_date)=date('now','-1 day')")->execute([$tabel])->fetchColumn();
    $team_avg=$pdo->query("SELECT AVG(cnt) FROM (SELECT COALESCE(SUM(contracts),0) as cnt FROM daily_reports WHERE date(report_date)=date('now','-1 day') AND tabel_number IN (SELECT tabel_number FROM users WHERE head_tabel=(SELECT head_tabel FROM users WHERE tabel_number='$tabel')) GROUP BY tabel_number)")->fetchColumn();
    if($my_contracts>0&&$my_contracts<$team_avg){
        $pdo->prepare("INSERT INTO notifications(user_tabel,message) VALUES(?,?)")->execute([$tabel,"📊 Вчера средний результат команды: ".round($team_avg,1)." договоров. Ваш: $my_contracts. Подтянитесь!"]);
    }
    if($my_contracts>=$team_avg&&$team_avg>0){
        $pdo->prepare("INSERT INTO notifications(user_tabel,message) VALUES(?,?)")->execute([$tabel,"🌟 Вы вчера были выше среднего по команде! Отличная работа!"]);
    }
}
$notifs=$pdo->prepare("SELECT * FROM notifications WHERE user_tabel=? AND is_read=0 ORDER BY created_at DESC LIMIT 7")->execute([$tabel])->fetchAll();
// Отметка прочитанным
if(isset($_GET['read'])){
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_tabel=?")->execute([$_GET['read'],$tabel]);
    header('Location:'.$_SERVER['HTTP_REFERER']??'dashboard.php');
    exit;
}
