<?php
// --- Base de datos ---
const DB_DSN  = 'mysql:host=127.0.0.1;port=3306;dbname=saga_db;charset=utf8mb4';
const DB_USER = 'root'; // Usuario MySQL de WAMP
const DB_PASS = '07280408luis'; // Contraseña del usuario MySQL de WAMP

// --- Sede: solo se permite marcar dentro de este radio ---
const SEDE_LAT = 9.023218;
const SEDE_LNG = -79.532296;
const RADIO_M  = 200;
const PRECISION_MAX_M = 100;

// --- Clave del panel admin (hash). Genera el tuyo con:
//     php -r "echo password_hash('TuClaveSegura', PASSWORD_DEFAULT);"
const ADMIN_HASH = '$2y$12$G8G/XDJ4kszlEjuvueeD1O2CokWh8EBrIsgTnv0INwQ0Q4X7YBokK';

date_default_timezone_set('America/Panama');

function db(): PDO {
    static $pdo = null;
    return $pdo ??= new PDO(DB_DSN, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

// Distancia en metros entre dos coordenadas (fórmula de Haversine)
function distancia_m(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 6371000;
    $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;
    return 2 * $r * asin(sqrt($a));
}