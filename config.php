<?php
// Drawsito v1.1.0 — Config base
session_start();

define('PERSISTENCIA', 'pg'); // 'pg' = PostgreSQL, 'fs' = archivos

// Ajusta si cambia tu entorno
define('PG_DSN',  'pgsql:host=localhost;port=5432;dbname=drawsito');
define('PG_USER', 'postgres');
define('PG_PASS', 'Malware123');

// Seguridad simple: crea un CSRF de sesión reutilizable
if (empty($_SESSION['token_csrf'])) {
  $_SESSION['token_csrf'] = bin2hex(random_bytes(16));
}