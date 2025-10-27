<?php
// drawsito_back/api.php — Backend sencillo para Drawsito (guardar/cargar JSON)
@ini_set('display_errors','0');
session_start();

if (empty($_SESSION['token_csrf'])) {
  $_SESSION['token_csrf'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['token_csrf'];

$dir_guardado = __DIR__ . DIRECTORY_SEPARATOR . 'diagramas';
if (!is_dir($dir_guardado)) { @mkdir($dir_guardado, 0755, true); }

$accion = $_GET['accion'] ?? ($_POST['accion'] ?? '');

if ($accion === 'token') {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>true,'token'=>$token]); exit;
}

if ($accion === 'guardar' && $_SERVER['REQUEST_METHOD']==='POST') {
  header('Content-Type: application/json; charset=utf-8');
  if (($_POST['token'] ?? '') !== $token) { http_response_code(403); echo json_encode(['ok'=>false,'msg'=>'CSRF inválido']); exit; }
  $json = $_POST['json'] ?? '';
  if ($json === '' || strlen($json) > 2_000_000) { http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'JSON vacío o muy grande']); exit; }
  json_decode($json); if (json_last_error()!==JSON_ERROR_NONE) { http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'JSON inválido']); exit; }
  $id = 'dibujo-'.date('Ymd-His');
  file_put_contents($dir_guardado.DIRECTORY_SEPARATOR.$id.'.json',$json);
  echo json_encode(['ok'=>true,'id'=>$id]); exit;
}

if ($accion === 'cargar') {
  header('Content-Type: application/json; charset=utf-8');
  $id = preg_replace('~[^a-zA-Z0-9\-_]~','', $_GET['id'] ?? '');
  if ($id===''){ http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'ID vacío']); exit; }
  $ruta = $dir_guardado.DIRECTORY_SEPARATOR.$id.'.json';
  if (!is_file($ruta)){ http_response_code(404); echo json_encode(['ok'=>false,'msg'=>'No existe']); exit; }
  readfile($ruta); exit;
}

http_response_code(404);
echo 'No encontrado';