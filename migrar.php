<?php
// Migración única: asistencias_old (esquema anterior) -> usuarios + asistencias (esquema nuevo)
// Uso desde la consola:  php migrar.php
if (PHP_SAPI !== 'cli') exit('Ejecuta este script desde la consola.');
require 'config.php';

$pdo = db();
$dir = __DIR__ . '/uploads';
if (!is_dir($dir)) mkdir($dir, 0755);
if (!is_file("$dir/.htaccess")) file_put_contents("$dir/.htaccess", "Require all denied\n");

$usuarios = [];   // nombre normalizado => id
$pins = [];       // para imprimir al final

// Crea el usuario la primera vez que aparece un nombre, con un PIN aleatorio
$usuarioId = function (string $nombre) use ($pdo, &$usuarios, &$pins): int {
    $k = mb_strtolower(trim($nombre));
    if (!isset($usuarios[$k])) {
        $codigo = 'U' . str_pad((string)(count($usuarios) + 1), 3, '0', STR_PAD_LEFT);
        $pin = (string)random_int(1000, 9999);
        $pdo->prepare('INSERT INTO usuarios (codigo, nombre, pin_hash) VALUES (?,?,?)')
            ->execute([$codigo, trim($nombre), password_hash($pin, PASSWORD_DEFAULT)]);
        $usuarios[$k] = (int)$pdo->lastInsertId();
        $pins[] = [$codigo, trim($nombre), $pin];
    }
    return $usuarios[$k];
};

$migradas = 0; $sinFoto = 0; $ultimo = [];

$pdo->beginTransaction();
try {
    $ids = $pdo->query('SELECT id FROM asistencias_old ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $sel = $pdo->prepare('SELECT * FROM asistencias_old WHERE id = ?');
    $ins = $pdo->prepare('INSERT INTO asistencias (usuario_id, tipo, fecha_hora, latitud, longitud, distancia_m, foto) VALUES (?,?,?,?,?,?,?)');

    foreach ($ids as $id) {
        $sel->execute([$id]);
        $r = $sel->fetch();
        $uid = $usuarioId($r['usuario_nombre']);

        // La tabla vieja no tenía tipo: se alterna entrada/salida por usuario y por día
        $fecha = $r['fecha_hora'] ?? date('Y-m-d H:i:s');  // la tabla vieja permitía fecha nula
        $dia = substr($fecha, 0, 10);
        $tipo = (($ultimo[$uid][$dia] ?? 'salida') === 'salida') ? 'entrada' : 'salida';
        $ultimo[$uid][$dia] = $tipo;

        // base64 -> archivo JPEG
        $img = preg_match('#^data:image/\w+;base64,(.+)$#s', $r['foto'], $m) ? base64_decode($m[1], true) : false;
        if ($img !== false && !@getimagesizefromstring($img)) $img = false;
        if ($img !== false && function_exists('imagecreatefromstring') && ($im = @imagecreatefromstring($img))) {
            ob_start(); imagejpeg($im, null, 80); $img = ob_get_clean();
        }
        $archivo = bin2hex(random_bytes(12)) . '.jpg';
        if ($img !== false) file_put_contents("$dir/$archivo", $img); else $sinFoto++;

        $dist = (int)round(distancia_m((float)$r['latitud'], (float)$r['longitud'], SEDE_LAT, SEDE_LNG));
        $ins->execute([$uid, $tipo, $fecha, $r['latitud'], $r['longitud'], $dist, $archivo]);
        $migradas++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    exit("Falló y no se cambió nada en la base de datos: " . $e->getMessage() . "\n");
}

echo "Migradas: $migradas | sin foto válida: $sinFoto\n\n";
echo "Usuarios creados (entrega a cada persona su código y PIN):\n";
foreach ($pins as [$codigo, $nombre, $pin]) echo "  $codigo  $nombre  PIN: $pin\n";