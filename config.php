<?php
// drawsito_back/config.php

@ini_set('session.cookie_samesite', 'None');
@ini_set('session.cookie_secure', '1');

session_start();

if (!defined('PERSISTENCIA')) define('PERSISTENCIA', 'pg');

// CORS (para Vercel -> Railway con cookies/sesión)
$origen = getenv('CORS_ORIGIN') ?: '';
if ($origen !== '') {
  header("Access-Control-Allow-Origin: $origen");
  header("Access-Control-Allow-Credentials: true");
  header("Access-Control-Allow-Headers: Content-Type, X-Requested-With");
  header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}

// ===== PostgreSQL desde Railway (DATABASE_URL) =====
if (!defined('PG_DSN')) {
  $url_bd = getenv('DATABASE_URL') ?: (getenv('DATABASE_URL_') ?: ''); // soporte por si lo creaste con _
  if ($url_bd) {
    $p = parse_url($url_bd);
    $host = $p['host'] ?? 'localhost';
    $port = $p['port'] ?? 5432;
    $db   = ltrim($p['path'] ?? '/drawsito', '/');
    $user = urldecode($p['user'] ?? 'postgres');
    $pass = urldecode($p['pass'] ?? '');

    $ssl = (stripos($url_bd, 'sslmode=require') !== false) ? ';sslmode=require' : '';
    define('PG_DSN',  "pgsql:host=$host;port=$port;dbname=$db$ssl");
    define('PG_USER', $user);
    define('PG_PASS', $pass);
  } else {
    // Fallback local
    define('PG_DSN',  'pgsql:host=localhost;port=5432;dbname=drawsito');
    define('PG_USER', 'postgres');
    define('PG_PASS', 'Malware123');
  }
}

// Token CSRF
if (empty($_SESSION['token_csrf'])) {
  $_SESSION['token_csrf'] = bin2hex(random_bytes(16));
}

// Lee GEMINI_API_KEY desde Railway primero, si no existe usa .env (local)
function obtenerApiKey() {
  $k = getenv('GEMINI_API_KEY');
  if ($k) return trim($k);

  $archivoEnv = __DIR__ . '/.env';
  if (!is_file($archivoEnv)) return null;

  foreach (file($archivoEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
    $linea = trim($linea);
    if ($linea === '' || $linea[0] === '#') continue;
    $partes = explode('=', $linea, 2);
    if (count($partes) === 2 && trim($partes[0]) === 'GEMINI_API_KEY') return trim($partes[1]);
  }
  return null;
}
