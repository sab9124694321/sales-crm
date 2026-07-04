<?php
session_start();
header('Content-Type: application/json');
if(!isset($_SESSION['user_id'])){echo json_encode(['success'=>false,'error'=>'Auth']);exit;}
require_once 'db.php';
$data=json_decode(file_get_contents('php://input'),true);
$inn=trim($data['inn']??'');
$product=$data['product']??'';
if((strlen($inn)<10||strlen($inn)>12)||!ctype_digit($inn)){echo json_encode(['success'=>false,'error'=>'Invalid INN']);exit;}
try{
    $stmt=$pdo->prepare("INSERT INTO inn_records(inn,product,employee_tabel,employee_name,head_name,sale_date) VALUES(?,?,?,?,(SELECT full_name FROM users WHERE tabel_number=(SELECT head_tabel FROM users WHERE tabel_number=?)),date('now'))");
    $stmt->execute([$inn,$product,$_SESSION['tabel'],$_SESSION['name'],$_SESSION['tabel']]);
    echo json_encode(['success'=>true,'saved'=>$stmt->rowCount()]);
}catch(Exception $e){
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
