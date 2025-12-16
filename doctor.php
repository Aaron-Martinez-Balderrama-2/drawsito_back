<?php
// drawsito_back/doctor.php
header("Content-Type: application/json");

// ==========================================
// PEGA TU API KEY AQUÍ
$API_KEY = "AIzaSyAZgfe1VMfiPyfYgytXoPa5qVU-0JUMHtM"; 
// ==========================================

// Vamos a consultar la lista oficial de modelos disponibles para TU cuenta
$url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . trim($API_KEY);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); // Mantenemos el fix de IPv4

$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode(["error" => "Fallo de conexión", "detalle" => $err]);
} else {
    // Mostramos la respuesta tal cual la manda Google
    echo $response;
}
?>