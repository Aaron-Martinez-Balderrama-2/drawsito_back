<?php
// drawsito_back/config.php

// 1. CORS: Manejo estricto para Vercel
$origenPermitido = getenv('CORS_ORIGIN');

if ($origenPermitido && isset($_SERVER['HTTP_ORIGIN'])) {
    $origin = rtrim($_SERVER['HTTP_ORIGIN'], '/');
    $allowed = rtrim($origenPermitido, '/');
    
    if ($origin === $allowed) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');
    }
}

// OPTIONS
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    exit(0);
}

// 2. Sesión Segura
@ini_set('session.cookie_samesite', 'None');
@ini_set('session.cookie_secure', '1');
session_start();

if (!defined('PERSISTENCIA')) define('PERSISTENCIA', 'pg');

// 3. CONEXIÓN A BASE DE DATOS (FIX SSL RAILWAY)
$url = getenv('DATABASE_URL');

if ($url) {
    // --- MODO NUBE (RAILWAY) ---
    $db = parse_url($url);
    
    $host = $db['host'];
    $port = $db['port'];
    $user = $db['user'];
    $pass = $db['pass'];
    $dbname = ltrim($db['path'], '/');
    
    // ¡AQUÍ ESTABA EL ERROR! Faltaba el sslmode=require
    define('PG_DSN', "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require");
    define('PG_USER', $user);
    define('PG_PASS', $pass);
} else {
    // --- MODO LOCAL ---
    // (Solo usa esto si no detecta la nube)
    define('PG_DSN', 'pgsql:host=localhost;port=5432;dbname=drawsito');
    define('PG_USER', 'postgres');
    define('PG_PASS', 'Malware123');
}

// 4. Token CSRF
if (empty($_SESSION['token_csrf'])) {
    $_SESSION['token_csrf'] = bin2hex(random_bytes(16));
}

// Helper API Key
function obtenerApiKey() {
    $k = getenv('GEMINI_API_KEY');
    if ($k) return trim($k);
    return null;
}
?>