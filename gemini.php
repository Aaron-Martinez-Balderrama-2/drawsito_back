<?php
// drawsito_back/gemini.php

require_once __DIR__ . '/config.php'; // CORS + getenv + sesión (y OPTIONS 204)

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo json_encode(["error" => "Método no permitido (usa POST)"]);
  exit;
}

$API_KEY = obtenerApiKey();
if (!$API_KEY) {
  http_response_code(500);
  echo json_encode(["error" => "Falta GEMINI_API_KEY en el servidor"]);
  exit;
}

$inputJSON = file_get_contents('php://input');
if ($inputJSON === '' || $inputJSON === false) {
  http_response_code(400);
  echo json_encode(["error" => "Body vacío"]);
  exit;
}

$input = json_decode($inputJSON, true);
if (!is_array($input)) {
  http_response_code(400);
  echo json_encode(["error" => "JSON inválido"]);
  exit;
}

$modo          = $input['modo'] ?? 'asistente';
$promptUsuario = (string)($input['prompt'] ?? '');
$diagramaActual= $input['diagrama'] ?? [];
$imagenBase64  = $input['imagen'] ?? null;
$audioBase64   = $input['audio'] ?? null;

// 2. Construir Prompt del Sistema
$schemaEjemplo = '{
  "acciones": [
    { "tipo": "agregar_clase", "datos": { "titulo": "Usuario", "x": 100, "y": 100, "atributos": ["- id: int"], "metodos": ["+ login()"] } },
    { "tipo": "editar_clase", "busca_titulo": "Usuario", "nuevos_datos": { "atributos": ["- id: int", "- email: string"] } },
    { "tipo": "conectar", "origen": "Usuario", "destino": "Rol", "card_o": "1", "card_d": "*", "nav": "o2d" },
    { "tipo": "borrar_clase", "titulo": "ClaseVieja" }
  ],
  "mensaje": "Resumen breve."
}';

$contexto = '';
if ($modo === 'asistente') {
  $jsonDiagrama = json_encode($diagramaActual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $contexto = "DIAGRAMA ACTUAL: $jsonDiagrama.\nTAREA: Modifícalo según la petición. Usa los IDs/nombres exactos del JSON.";
} else {
  $contexto = "TAREA: Eres un Arquitecto de Software. Ignora lo previo. Diseña un diagrama UML completo desde cero.";
}

$systemPrompt = "Eres un motor UML. Tu salida debe ser EXCLUSIVAMENTE JSON válido siguiendo este esquema: $schemaEjemplo.\n$contexto\nNO uses markdown.";

// 3. Payload
$parts = [
  ["text" => $systemPrompt . "\n\nPETICIÓN: " . $promptUsuario]
];

// Imagen (si viene)
if (is_string($imagenBase64) && $imagenBase64 !== '') {
  if (strpos($imagenBase64, 'base64,') !== false) {
    $ex = explode('base64,', $imagenBase64, 2);
    $mime = 'image/jpeg';
    if (isset($ex[0]) && strpos($ex[0], 'data:') === 0 && strpos($ex[0], ';') !== false) {
      $mime = explode(';', substr($ex[0], 5))[0] ?: 'image/jpeg';
    }
    $imgData = $ex[1] ?? '';
  } else {
    $mime = 'image/jpeg';
    $imgData = $imagenBase64;
  }
  if ($imgData !== '') {
    $parts[] = ["inline_data" => ["mime_type" => $mime, "data" => $imgData]];
  }
}

// Audio (si viene)
if (is_string($audioBase64) && $audioBase64 !== '') {
  if (strpos($audioBase64, 'base64,') !== false) {
    $ex = explode('base64,', $audioBase64, 2);
    $mime = 'audio/wav';
    if (isset($ex[0]) && strpos($ex[0], 'data:') === 0 && strpos($ex[0], ';') !== false) {
      $mime = explode(';', substr($ex[0], 5))[0] ?: 'audio/wav';
    }
    $audData = $ex[1] ?? '';
  } else {
    $mime = 'audio/wav';
    $audData = $audioBase64;
  }
  if ($audData !== '') {
    $parts[] = ["inline_data" => ["mime_type" => $mime, "data" => $audData]];
  }
}

$body = [
  "contents" => [[ "parts" => $parts ]],
  "generationConfig" => [
    "response_mime_type" => "application/json",
    "temperature" => 0.2
  ]
];

// Modelo (opcional por variable)
$modelo = getenv('GEMINI_MODEL') ?: 'gemini-flash-latest';

$url = "https://generativelanguage.googleapis.com/v1beta/models/{$modelo}:generateContent?key=" . trim($API_KEY);

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_TIMEOUT => 60,
  CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
]);

// Si necesitas forzar inseguro por pruebas: CURL_SSL_INSEGURO=1
if (getenv('CURL_SSL_INSEGURO') === '1') {
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
}

$response  = curl_exec($ch);
$httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError || $httpCode !== 200) {
  $msg = "Gemini HTTP $httpCode: " . ($curlError ?: $response);
  error_log($msg); // queda en Logs de Railway
  http_response_code(502);
  echo json_encode(["error" => "Error de conexión con Gemini", "detalle" => $msg]);
  exit;
}

echo $response;
