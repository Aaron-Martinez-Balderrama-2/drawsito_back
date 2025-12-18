<?php
// drawsito_back/doctor.php

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// (Opcional) si defines DOCTOR_TOKEN en Railway, protege este endpoint
$tok = getenv('DOCTOR_TOKEN');
if ($tok && (($_GET['token'] ?? '') !== $tok)) {
  http_response_code(403);
  echo json_encode(["error" => "Forbidden"]);
  exit;
}

$API_KEY = obtenerApiKey();
if (!$API_KEY) {
  http_response_code(500);
  echo json_encode(["error" => "Falta GEMINI_API_KEY en el servidor"]);
  exit;
}

$url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . trim($API_KEY);

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_TIMEOUT => 30,
  CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
]);

if (getenv('CURL_SSL_INSEGURO') === '1') {
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
}

$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($err || $httpCode !== 200) {
  $msg = "Doctor HTTP $httpCode: " . ($err ?: $response);
  error_log($msg);
  http_response_code(502);
  echo json_encode(["error" => "Fallo de conexión", "detalle" => $msg]);
  exit;
}

echo $response;
