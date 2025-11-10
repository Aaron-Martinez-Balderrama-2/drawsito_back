<?php
// Drawsito v1.1.0 — Auth (login/registro/logout/whoami)
@ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__.'/config.php';
require_once __DIR__.'/db.php';

$act = $_GET['accion'] ?? $_POST['accion'] ?? '';

function out($x){ echo json_encode($x, JSON_UNESCAPED_UNICODE); exit; }
function need_csrf(){
  $t = $_POST['token'] ?? '';
  if ($t !== ($_SESSION['token_csrf'] ?? '')) out(['ok'=>false,'msg'=>'CSRF inválido']);
}

if ($act === 'whoami'){
  $pdo = db_pg();
  $uid = $_SESSION['user_id'] ?? null;
  $user = null;
  if ($uid){
    $s=$pdo->prepare('SELECT id, alias, email, creado_at FROM usuarios WHERE id=:id');
    $s->execute([':id'=>$uid]);
    $user = $s->fetch();
  }
  out(['ok'=>true,'token'=>$_SESSION['token_csrf'],'user'=>$user]);
}

if ($act === 'registrar'){
  need_csrf();
  $alias = trim($_POST['alias'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $pass1 = $_POST['pass1'] ?? '';
  $pass2 = $_POST['pass2'] ?? '';
  if ($alias==='' || $email==='' || $pass1==='') out(['ok'=>false,'msg'=>'Faltan datos']);
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) out(['ok'=>false,'msg'=>'Email inválido']);
  if ($pass1 !== $pass2) out(['ok'=>false,'msg'=>'Las contraseñas no coinciden']);
  if (strlen($pass1) < 6) out(['ok'=>false,'msg'=>'Usa al menos 6 caracteres']);

  $pdo = db_pg();
  $hash = password_hash($pass1, PASSWORD_DEFAULT);
  try{
    $s=$pdo->prepare('INSERT INTO usuarios(alias,email,pass_hash) VALUES(:a,:e,:h) RETURNING id');
    $s->execute([':a'=>$alias, ':e'=>$email, ':h'=>$hash]);
    $id=(int)$s->fetchColumn();
    $_SESSION['user_id']=$id;
    out(['ok'=>true,'user'=>['id'=>$id,'alias'=>$alias,'email'=>$email]]);
  }catch(Throwable $e){
    out(['ok'=>false,'msg'=>'Email ya registrado o error']);
  }
}

if ($act === 'login'){
  need_csrf();
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['pass'] ?? '';
  if ($email==='' || $pass==='') out(['ok'=>false,'msg'=>'Faltan datos']);

  $pdo = db_pg();
  $s=$pdo->prepare('SELECT id, alias, email, pass_hash FROM usuarios WHERE email=:e');
  $s->execute([':e'=>$email]);
  $u=$s->fetch();
  if (!$u || !password_verify($pass, $u['pass_hash'] ?? '')){
    out(['ok'=>false,'msg'=>'Credenciales inválidas']);
  }
  $_SESSION['user_id']=(int)$u['id'];
  $pdo->prepare('UPDATE usuarios SET ultimo_login=now() WHERE id=:id')->execute([':id'=>$u['id']]);
  out(['ok'=>true,'user'=>['id'=>(int)$u['id'],'alias'=>$u['alias'],'email'=>$u['email']]]);
}

if ($act === 'logout'){
  need_csrf();
  $_SESSION = [];
  if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
  }
  session_destroy();
  out(['ok'=>true]);
}

out(['ok'=>false,'msg'=>'Acción inválida']);
