<?php
// drawsito_back/config.php

// 1. CORS: Manejo estricto para Vercel
// Asegúrate que en Railway la variable CORS_ORIGIN sea: https://drawsitofront2.vercel.app
$origenPermitido = getenv('CORS_ORIGIN');

if ($origenPermitido && isset($_SERVER['HTTP_ORIGIN'])) {
    if ($_SERVER['HTTP_ORIGIN'] === $origenPermitido) {
        header("Access-Control-Allow-Origin: $origenPermitido");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');
    }
}

// Preflight OPTIONS (Respuesta rápida al navegador)
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    exit(0);
}

// 2. Configuración de Sesión Segura
@ini_set('session.cookie_samesite', 'None');
@ini_set('session.cookie_secure', '1'); // Obligatorio para Vercel->Railway
session_start();

if (!defined('PERSISTENCIA')) define('PERSISTENCIA', 'pg');

// 3. CONEXIÓN A BASE DE DATOS (PRIORIDAD FERREA)
// Railway ofrece variables desglosadas (PGHOST, etc). Usémoslas, son más seguras.

if (getenv('PGHOST')) {
    // --- MODO NUBE (RAILWAY NATINO) ---
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
    // --- MODO NUBE (URL STRING - FALLBACK) ---
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
    // --- MODO LOCAL ---
    if (!defined('PG_DSN')) {
        define('PG_DSN', 'pgsql:host=localhost;port=5432;dbname=drawsito');
        define('PG_USER', 'postgres');
        define('PG_PASS', 'Malware123');
    }
}

// 4. Token CSRF (Generación)
if (empty($_SESSION['token_csrf'])) {
    $_SESSION['token_csrf'] = bin2hex(random_bytes(16));
}

// Helper API Key
function obtenerApiKey() {
    $k = getenv('GEMINI_API_KEY');
    if ($k) return trim($k);
    // Fallback local .env
    $archivoEnv = __DIR__ . '/.env';
    if (file_exists($archivoEnv)) {
        $lines = file($archivoEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            list($name, $value) = explode('=', $line, 2);
            if (trim($name) === 'GEMINI_API_KEY') return trim($value);
        }
    }
    return null;
}
?>