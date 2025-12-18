<?php
// drawsito_back/db.php
require_once __DIR__ . '/config.php';

function db_pg(){
  static $pdo = null;
  if ($pdo) return $pdo;

  try {
    $pdo = new PDO(PG_DSN, PG_USER, PG_PASS, [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_PERSISTENT         => true,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET application_name = 'drawsito_rt'");
  } catch(Throwable $e){
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'msg'=>'No se pudo conectar a PostgreSQL']);
    exit;
  }
  return $pdo;
}
