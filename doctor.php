<?php
// drawsito_back/doctor.php
header("Content-Type: application/json");
require_once __DIR__.'/config.php';

$API_KEY = obtenerApiKey();

if (!$API_KEY) {
    echo json_encode(["error" => "Falta configuración .env"]);
    exit;
}

// Consultar la lista de modelos
$url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . trim($API_KEY);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode(["error" => "Fallo de conexión", "detalle" => $err]);
} else {
    echo $response;
}
?>