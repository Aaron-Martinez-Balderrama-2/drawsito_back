<?php
// drawsito_back/db.php
// MODO DEBUG EXTREMO: Nos dirá EXACTAMENTE por qué falla la conexión
require_once __DIR__ . '/config.php';

function db_pg(){
    try {
        // Intenta conectar usando los datos de config.php
        $pdo = new PDO(PG_DSN, PG_USER, PG_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5 // 5 segundos maximo de espera
        ]);
        $pdo->exec("SET application_name = 'drawsito_debug'");
        return $pdo;
    } catch(Throwable $e) {
        // SI FALLA, MATA EL PROCESO Y MUESTRA EL ERROR TÉCNICO
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        
        // Preparamos los detalles (Ocultando la contraseña por seguridad visual)
        $dsn_seguro = str_replace(PG_PASS, '******', PG_DSN);
        
        die(json_encode([
            "ok" => false,
            "titulo" => "ERROR DE CONEXIÓN",
            "error_tecnico" => $e->getMessage(), // <--- ESTO ES LO QUE NECESITO LEER
            "debug_info" => [
                "usuario" => PG_USER,
                "dsn_usado" => $dsn_seguro
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
?>