<?php
require 'config.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

const MAX_FALLOS_CODIGO = 5;    // fallos por código en la ventana
const MAX_FALLOS_IP     = 40;   // más alto: toda la oficina comparte IP
const VENTANA_MIN       = 15;
function out(bool $ok, string $msg, int $code = 200, array $extra = []): never {
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'msg' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(false, 'Método no permitido', 405);
    $d = json_decode(file_get_contents('php://input'), true);
    if (!is_array($d)) out(false, 'Solicitud inválida', 400);

    $codigo = mb_substr(trim((string)($d['codigo'] ?? '')), 0, 50);
    $pin    = (string)($d['pin'] ?? '');
    $lat    = filter_var($d['latitud'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng    = filter_var($d['longitud'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false || abs($lat) > 90 || abs($lng) > 180) {
        out(false, 'Ubicación inválida', 400);
    }
    $acc = filter_var($d['precision'] ?? null, FILTER_VALIDATE_FLOAT);
    $acc = ($acc === false || $acc < 0) ? null : (int)round($acc);
    if ($acc !== null && $acc > PRECISION_MAX_M) {
        out(false, "La señal GPS es imprecisa (±$acc m). Espera unos segundos o acércate a una ventana.", 422);
    }

    // Foto: JPEG/PNG válido, < 1 MB y dimensiones razonables
    if (!preg_match('#^data:(image/(?:jpeg|jpg|png));base64,(.+)$#i', (string)($d['foto'] ?? ''), $m)) {
        out(false, 'Foto inválida', 400);
    }
    $img  = base64_decode($m[2], true);
    $info = $img !== false && strlen($img) <= 1_000_000 ? @getimagesizefromstring($img) : false;
    if (!$info || $info[0] > 2000 || $info[1] > 2000) out(false, 'Foto inválida', 400);
    $im = @imagecreatefromstring($img);          // re-codificar: elimina EXIF y cargas ocultas
    if (!$im) out(false, 'Foto inválida', 400);
    ob_start(); imagejpeg($im, null, 82); $jpg = ob_get_clean();

    // Anti fuerza bruta (un PIN de 4 dígitos son solo 10 000 combinaciones)
    $ip    = $_SERVER['REMOTE_ADDR'] ?? '';
    $desde = date('Y-m-d H:i:s', time() - VENTANA_MIN * 60);
    $cnt = function (string $col, string $val) use ($desde): int {
        $s = db()->prepare("SELECT COUNT(*) FROM intentos WHERE $col = ? AND creado > ?");
        $s->execute([$val, $desde]);
        return (int)$s->fetchColumn();
    };
    if ($cnt('codigo', $codigo) >= MAX_FALLOS_CODIGO || $cnt('ip', $ip) >= MAX_FALLOS_IP) {
        out(false, 'Demasiados intentos fallidos. Espera ' . VENTANA_MIN . ' minutos.', 429);
    }

    // Identidad: código + PIN
    $st = db()->prepare('SELECT id, nombre, pin_hash FROM usuarios WHERE codigo = ? AND activo = 1');
    $st->execute([$codigo]);
    $u = $st->fetch();
    if (!$u || !password_verify($pin, $u['pin_hash'])) {
        db()->prepare('INSERT INTO intentos (codigo, ip, creado) VALUES (?,?,?)')
            ->execute([$codigo, $ip, date('Y-m-d H:i:s')]);
        out(false, 'Código o PIN incorrecto', 401);
    }
    db()->prepare('DELETE FROM intentos WHERE codigo = ?')->execute([$codigo]);

    // Geocerca
    $dist = (int)round(distancia_m($lat, $lng, SEDE_LAT, SEDE_LNG));
    if ($dist > RADIO_M) out(false, "Estás a $dist m de la sede (máximo " . RADIO_M . " m).", 403);

    // Entrada/salida según la última marca de hoy (fechas desde PHP: sin depender de la zona horaria de MySQL)
    $ini = date('Y-m-d 00:00:00');
    $fin = date('Y-m-d 00:00:00', strtotime('+1 day'));
    $st = db()->prepare('SELECT tipo, fecha_hora FROM asistencias WHERE usuario_id = ? AND fecha_hora >= ? AND fecha_hora < ? ORDER BY id DESC LIMIT 1');
    $st->execute([$u['id'], $ini, $fin]);
    $ult = $st->fetch();
    if ($ult && time() - strtotime($ult['fecha_hora']) < 60) out(false, 'Ya marcaste hace menos de un minuto.', 429);
    $tipo = (!$ult || $ult['tipo'] === 'salida') ? 'entrada' : 'salida';

    // Guardar foto (carpeta protegida) y solo el nombre en la BD
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) mkdir($dir, 0755);
    if (!is_file("$dir/.htaccess")) file_put_contents("$dir/.htaccess", "Require all denied\n");
    $archivo = bin2hex(random_bytes(12)) . '.jpg';
    file_put_contents("$dir/$archivo", $jpg);

    try {
        db()->prepare('INSERT INTO asistencias (usuario_id, tipo, fecha_hora, latitud, longitud, distancia_m, precision_m, foto, ip) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$u['id'], $tipo, date('Y-m-d H:i:s'), $lat, $lng, $dist, $acc, $archivo, $ip ?: null]);
    } catch (PDOException $e) {
        @unlink("$dir/$archivo");                // no dejar fotos huérfanas
        throw $e;
    }

    out(true, ucfirst($tipo) . " registrada: {$u['nombre']} a las " . date('H:i'), 200, ['tipo' => $tipo, 'distancia' => $dist]);
} catch (PDOException $e) {
    error_log($e);
    $c = $e->errorInfo[1] ?? null;
    out(false, match ($c) {
        1045    => 'No se pudo conectar a la base de datos. Revisa DB_USER y DB_PASS en config.php.',
        1049    => 'La base de datos saga_db no existe. Créala en phpMyAdmin.',
        1146    => 'Faltan tablas. Ejecuta mejoras.sql y el esquema de SAGA.',
        1054    => 'Falta una columna nueva. Ejecuta mejoras.sql en phpMyAdmin.',
        default => 'Error de base de datos. Revisa el registro de errores de PHP.',
    }, 500);
} catch (Throwable $e) {
    error_log($e);
    out(false, 'Error interno. Intenta de nuevo.', 500);
}