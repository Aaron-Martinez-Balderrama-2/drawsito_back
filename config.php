<?php
// drawsito_back/config.php
session_start();

// Configuración de Base de Datos (Mantenemos la que ya tenías para que no falle)
define('PERSISTENCIA', 'pg'); 
define('PG_DSN',  'pgsql:host=localhost;port=5432;dbname=drawsito');
define('PG_USER', 'postgres');
define('PG_PASS', 'Malware123');

// Seguridad: Token CSRF
if (empty($_SESSION['token_csrf'])) {
  $_SESSION['token_csrf'] = bin2hex(random_bytes(16));
}

// --- NUEVA FUNCIÓN DE SEGURIDAD ---
// Busca la API KEY en el archivo .env para no tenerla escrita en el código
function obtenerApiKey() {
    $archivoEnv = __DIR__ . '/.env';
    
    if (!file_exists($archivoEnv)) {
        return null;
    }

    $lineas = file($archivoEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lineas as $linea) {
        $linea = trim($linea);
        if (empty($linea) || strpos($linea, '#') === 0) continue;

        $partes = explode('=', $linea, 2);
        if (count($partes) === 2) {
            $clave = trim($partes[0]);
            $valor = trim($partes[1]);
            
            if ($clave === 'GEMINI_API_KEY') {
                return $valor;
            }
        }
    }
    return null;
}
?>