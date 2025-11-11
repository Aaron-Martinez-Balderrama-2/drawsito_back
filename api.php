<?php
// Drawsito v1.1.0 — API guardar/cargar + token
@ini_set('display_errors','0');
session_start();

require_once __DIR__.'/config.php';
if (PERSISTENCIA === 'pg') require_once __DIR__.'/db.php';

$accion = $_GET['accion'] ?? $_POST['accion'] ?? '';

if ($accion === 'token'){
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>true,'token'=>$_SESSION['token_csrf']]); exit;
}

if ($accion === 'guardar' && $_SERVER['REQUEST_METHOD']==='POST'){
  header('Content-Type: application/json; charset=utf-8');
  if (($_POST['token'] ?? '') !== ($_SESSION['token_csrf'] ?? '')){
    http_response_code(403); echo json_encode(['ok'=>false,'msg'=>'CSRF inválido']); exit;
  }
  $json = $_POST['json'] ?? '';
  if ($json==='' || strlen($json)>2_000_000){ http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'JSON vacío o grande']); exit; }
  json_decode($json); if (json_last_error()!==JSON_ERROR_NONE){ http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'JSON inválido']); exit; }

  $id = 'dibujo-'.date('Ymd-His');
  if (PERSISTENCIA==='pg'){
    $pdo = db_pg();
    $stmt=$pdo->prepare('INSERT INTO diagramas(id, owner_id, titulo, json) VALUES(:id, :owner, :tit, :j::jsonb)');
    $owner = $_SESSION['user_id'] ?? null;
    $stmt->execute([':id'=>$id, ':owner'=>$owner, ':tit'=>$id, ':j'=>$json]);
  } else {
    $dir = __DIR__.'/diagramas'; if (!is_dir($dir)) @mkdir($dir,0755,true);
    file_put_contents($dir.'/'.$id.'.json',$json);
  }
  echo json_encode(['ok'=>true,'id'=>$id]); exit;
}

if ($accion === 'cargar'){
  $id = preg_replace('~[^a-zA-Z0-9\-_]~','', $_GET['id'] ?? '');
  if ($id===''){ http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'ID vacío']); exit; }
  header('Content-Type: application/json; charset=utf-8');

  if (PERSISTENCIA==='pg'){
    $pdo=db_pg();
    $s=$pdo->prepare('SELECT json FROM diagramas WHERE id=:id');
    $s->execute([':id'=>$id]);
    $row=$s->fetch(PDO::FETCH_NUM);
    if (!$row){ http_response_code(404); echo json_encode(['ok'=>false,'msg'=>'No existe']); exit; }
    echo $row[0]; // JSON puro
    exit;
  } else {
    $ruta=__DIR__.'/diagramas/'.$id.'.json';
    if (!is_file($ruta)){ http_response_code(404); echo json_encode(['ok'=>false,'msg'=>'No existe']); exit; }
    readfile($ruta); exit;
  }
}

http_response_code(404);
echo 'No encontrado';
