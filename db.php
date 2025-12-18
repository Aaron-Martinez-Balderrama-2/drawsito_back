<?php
// drawsito_back/db.php
// Versión Producción (Limpia)
require_once __DIR__ . '/config.php';

function db_pg(){
  static $pdo = null;
  if ($pdo) return $pdo;

  try {
    $pdo = new PDO(PG_DSN, PG_USER, PG_PASS, [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_PERSISTENT         => true,
      PDO::ATTR_TIMEOUT            => 5
    ]);
    return $pdo;
  } catch(Throwable $e){
    // En producción, solo devolvemos error genérico 500
    http_response_code(500);
    echo json_encode(['ok'=>false, 'msg'=>'Error de conexión a BD']);
    exit;
  }
}
?>