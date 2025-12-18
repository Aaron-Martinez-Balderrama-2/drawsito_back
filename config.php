<?php
// drawsito_back/config.php

// 1. CORS STRICTO (Necesario para cookies)
$origen = getenv('CORS_ORIGIN');
if ($origen && isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] === $origen) {
    header("Access-Control-Allow-Origin: $origen");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
}

// OPTIONS
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    exit(0);
}

// 2. SESIÓN SEGURA (CRÍTICO: SameSite None para Vercel->Railway)
@ini_set('session.cookie_samesite', 'None');
@ini_set('session.cookie_secure', '1'); 
session_start(); // <--- AQUÍ es el único lugar donde debe iniciarse

if (!defined('PERSISTENCIA')) define('PERSISTENCIA', 'pg');

// 3. CONEXIÓN ROBUSTA A RAILWAY
// Railway a veces da variables separadas (PGHOST) y a veces URL completa. Esto maneja ambos.
if (getenv('PGHOST')) {
    $host = getenv('PGHOST');
    $port = getenv('PGPORT');
    $db   = getenv('PGDATABASE');
    $user = getenv('PGUSER');
    $pass = getenv('PGPASSWORD');
    if (!defined('PG_DSN')) {
        define('PG_DSN', "pgsql:host=$host;port=$port;dbname=$db");
        define('PG_USER', $user);
        define('PG_PASS', $pass);
    }
} elseif (getenv('DATABASE_URL')) {
    $p = parse_url(getenv('DATABASE_URL'));
    $host = $p['host'];
    $port = $p['port'];
    $user = $p['user'];
    $pass = $p['pass'];
    $dbname = ltrim($p['path'], '/');
    if (!defined('PG_DSN')) {
        define('PG_DSN', "pgsql:host=$host;port=$port;dbname=$dbname");
        define('PG_USER', $user);
        define('PG_PASS', $pass);
    }
} else {
    // Localhost
    if (!defined('PG_DSN')) {
        define('PG_DSN', 'pgsql:host=localhost;port=5432;dbname=drawsito');
        define('PG_USER', 'postgres');
        define('PG_PASS', 'Malware123');
    }
}

// Token CSRF
if (empty($_SESSION['token_csrf'])) {
    $_SESSION['token_csrf'] = bin2hex(random_bytes(16));
}

// API Key
function obtenerApiKey() {
    $k = getenv('GEMINI_API_KEY');
    if ($k) return trim($k);
    return null; 
}
?>