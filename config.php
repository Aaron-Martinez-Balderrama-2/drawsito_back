<?php
// drawsito_back/config.php

// 1. CORS STRICTO
$origen = getenv('CORS_ORIGIN'); // En Railway poner: https://drawsitofront2.vercel.app
if ($origen && isset($_SERVER['HTTP_ORIGIN']) && rtrim($_SERVER['HTTP_ORIGIN'], '/') === rtrim($origen, '/')) {
    header("Access-Control-Allow-Origin: $origen");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
}

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    exit(0);
}

// 2. SESIÓN SEGURA (Aquí es donde DEBE ir)
@ini_set('session.cookie_samesite', 'None');
@ini_set('session.cookie_secure', '1');
session_start(); 

if (!defined('PERSISTENCIA')) define('PERSISTENCIA', 'pg');

// 3. CONEXIÓN A BASE DE DATOS (Soporte SSL Nativo)
// Prioridad: Variables desglosadas de Railway (PGHOST, etc)
if (getenv('PGHOST')) {
    $host = getenv('PGHOST');
    $port = getenv('PGPORT');
    $db   = getenv('PGDATABASE');
    $user = getenv('PGUSER');
    $pass = getenv('PGPASSWORD');
    if (!defined('PG_DSN')) {
        define('PG_DSN', "pgsql:host=$host;port=$port;dbname=$db;sslmode=require"); // SSL vital
        define('PG_USER', $user);
        define('PG_PASS', $pass);
    }
} elseif (getenv('DATABASE_URL')) {
    // Fallback: URL completa
    $p = parse_url(getenv('DATABASE_URL'));
    $host = $p['host'];
    $port = $p['port'];
    $user = $p['user'];
    $pass = $p['pass'];
    $dbname = ltrim($p['path'], '/');
    if (!defined('PG_DSN')) {
        define('PG_DSN', "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require");
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