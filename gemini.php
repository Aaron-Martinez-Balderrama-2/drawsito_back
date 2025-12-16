<?php
// drawsito_back/gemini.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

// ==========================================
// CONFIGURACIÓN
// ==========================================
$API_KEY = "AIzaSyAZgfe1VMfiPyfYgytXoPa5qVU-0JUMHtM"; // <--- ¡Coloca tu API Key real aquí!
// ==========================================

// 1. Recibir datos
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

if (!$input) {
    echo json_encode(["error" => "No se recibieron datos (body vacío)"]);
    exit;
}

$modo = $input['modo'] ?? 'asistente';
$promptUsuario = $input['prompt'] ?? '';
$diagramaActual = $input['diagrama'] ?? [];
$imagenBase64 = $input['imagen'] ?? null;
$audioBase64 = $input['audio'] ?? null;

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

$contexto = "";
if ($modo === 'asistente') {
    $jsonDiagrama = json_encode($diagramaActual);
    $contexto = "DIAGRAMA ACTUAL: $jsonDiagrama. \n TAREA: Modifícalo según la petición. Usa los IDs/nombres exactos del JSON.";
} else {
    $contexto = "TAREA: Eres un Arquitecto de Software. Ignora lo previo. Diseña un diagrama UML completo desde cero.";
}

$systemPrompt = "Eres un motor UML. Tu salida debe ser EXCLUSIVAMENTE JSON válido siguiendo este esquema: $schemaEjemplo. \n $contexto \n NO uses markdown.";

// 3. Payload
$parts = [ ["text" => $systemPrompt . "\n\nPETICIÓN: " . $promptUsuario] ];

// Imagen
if ($imagenBase64) {
    if (strpos($imagenBase64, 'base64,') !== false) {
        $ex = explode('base64,', $imagenBase64);
        $mime = explode(';', explode(':', $ex[0])[1])[0];
        $imgData = $ex[1];
    } else {
        $mime = 'image/jpeg';
        $imgData = $imagenBase64;
    }
    $parts[] = [ "inline_data" => [ "mime_type" => $mime, "data" => $imgData ] ];
}

// Audio
if ($audioBase64) {
    if (strpos($audioBase64, 'base64,') !== false) {
        $ex = explode('base64,', $audioBase64);
        $mime = explode(';', explode(':', $ex[0])[1])[0];
        $audData = $ex[1];
    } else {
        $mime = 'audio/wav';
        $audData = $audioBase64;
    }
    $parts[] = [ "inline_data" => [ "mime_type" => $mime, "data" => $audData ] ];
}

$body = [
    "contents" => [ [ "parts" => $parts ] ],
    "generationConfig" => [ 
        "response_mime_type" => "application/json", 
        "temperature" => 0.2 
    ]
];

// 4. URL DE LA API (CORREGIDA AL MODELO GENÉRICO)
// Usamos 'gemini-flash-latest' que aparece en tu lista.
// Este alias siempre apunta a la versión Flash estable y gratuita.
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key=" . trim($API_KEY);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// Opciones de seguridad de red
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError || $httpCode !== 200) {
    $msg = "Error $httpCode: " . ($curlError ?: $response);
    // Log de error simple
    file_put_contents(__DIR__.'/debug_gemini_error.txt', date('Y-m-d H:i:s')." - ".$msg."\n", FILE_APPEND);
    echo json_encode(["error" => "Error de conexión con Google", "detalle" => $msg]);
} else {
    echo $response;
}
?>