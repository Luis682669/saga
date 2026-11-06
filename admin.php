<?php
require 'config.php';
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
$h  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
function ir(string $to): never { header("Location: $to"); exit; }

$escudo = '<svg class="escudo" viewBox="0 0 24 28" role="img" aria-label="Escudo institucional"><path d="M12 1 2 5v8c0 7 4.500 11.500 10 14 5.500-2.500 10-7 10-14V5z" fill="currentColor"/><polygon points="12,8 13.5,11.500 17.300,11.900 14.400,14.400 15.300,18.100 12,16.200 8.700,18.100 9.600,14.400 6.700,11.900 10.500,11.500" fill="#fff"/></svg>';

// ---------- Login (con límite de intentos) ----------
if (empty($_SESSION['admin'])) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $desde = date('Y-m-d H:i:s', time() - 900);
        $s = db()->prepare("SELECT COUNT(*) FROM intentos WHERE codigo = '__admin__' AND ip = ? AND creado > ?");
        $s->execute([$ip, $desde]);
        if ($s->fetchColumn() >= 5) {
            $err = 'Demasiados intentos. Espera 15 minutos.';
        } elseif (password_verify($_POST['clave'] ?? '', ADMIN_HASH)) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            db()->prepare("DELETE FROM intentos WHERE codigo = '__admin__' AND ip = ?")->execute([$ip]);
            ir('admin.php');
        } else {
            db()->prepare("INSERT INTO intentos (codigo, ip, creado) VALUES ('__admin__', ?, ?)")->execute([$ip, date('Y-m-d H:i:s')]);
            $err = 'Clave incorrecta.';
        }
    }
    ?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Administración | Sistema Penitenciario</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="institucional.css"></head><body class="login-body"><div class="gov-band"></div><main class="login-card">
<div class="d-flex align-items-center gap-3 mb-4"><?= $escudo ?><div><div class="inst-sub">República de Panamá</div><div class="inst-name">Sistema Penitenciario</div></div></div>
<h1 class="h4 fw-bold mb-2">Acceso administrativo</h1>
<p class="text-secondary mb-4">Ingrese la clave de administrador para gestionar funcionarios y consultar los registros de asistencia.</p>
<form method="post">
<label class="form-label small fw-semibold" for="clave">Clave de administrador</label>
<input id="clave" type="password" name="clave" class="form-control mb-3" required autofocus>
<?php if ($err): ?><p class="text-danger small" role="alert"><?= $h($err) ?></p><?php endif; ?>
<button class="btn btn-primary w-100">Entrar al panel</button>
</form></main></body></html><?php
    exit;
}

// ---------- Foto protegida ----------
if (isset($_GET['foto'])) {
    $st = db()->prepare('SELECT foto FROM asistencias WHERE id = ?');
    $st->execute([(int)$_GET['foto']]);
    $f = $st->fetchColumn();
    $p = __DIR__ . '/uploads/' . basename((string)$f);
    if (!$f || !is_file($p)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=3600');
    readfile($p);
    exit;
}

// ---------- Acciones (POST + CSRF + redirección) ----------
$flash = function (string $m): never { $_SESSION['flash'] = $m; header('Location: admin.php?tab=usuarios'); exit; };
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
    $a   = $_POST['accion'] ?? '';
    $pin = (string)($_POST['pin'] ?? '');
    $pinOk = ctype_digit($pin) && strlen($pin) >= 4 && strlen($pin) <= 8;
    if ($a === 'salir') { session_destroy(); ir('admin.php'); }
    if ($a === 'crear') {
        $cod = mb_substr(trim($_POST['codigo'] ?? ''), 0, 50); $nom = mb_substr(trim($_POST['nombre'] ?? ''), 0, 120);
        if ($cod === '' || $nom === '' || !$pinOk) $flash('Completa todos los campos. El PIN debe tener de 4 a 8 dígitos.');
        try {
            db()->prepare('INSERT INTO usuarios (codigo, nombre, pin_hash) VALUES (?,?,?)')
                ->execute([$cod, $nom, password_hash($pin, PASSWORD_DEFAULT)]);
            $flash('Usuario creado.');
        } catch (PDOException $e) { $flash('No se pudo crear: el código ya existe.'); }
    }
    if ($a === 'estado') {
        db()->prepare('UPDATE usuarios SET activo = IF(activo = 1, 0, 1) WHERE id = ?')->execute([(int)$_POST['id']]);
        $flash('Estado del usuario actualizado.');
    }
    if ($a === 'pin') {
        if (!$pinOk) $flash('El PIN debe tener de 4 a 8 dígitos.');
        db()->prepare('UPDATE usuarios SET pin_hash = ? WHERE id = ?')->execute([password_hash($pin, PASSWORD_DEFAULT), (int)$_POST['id']]);
        db()->prepare('DELETE FROM intentos WHERE codigo = (SELECT codigo FROM usuarios WHERE id = ?)')->execute([(int)$_POST['id']]);
        $flash('PIN actualizado.');
    }
}
$aviso = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);

// ---------- Filtros ----------
$tab   = in_array($_GET['tab'] ?? '', ['marcas', 'usuarios'], true) ? $_GET['tab'] : 'horas';
$fd    = fn(string $k, string $def) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$k] ?? '') ? $_GET[$k] : $def;
$desde = $fd('desde', date('Y-m-01'));
$hasta = $fd('hasta', date('Y-m-d'));
$uid   = (int)($_GET['u'] ?? 0);
$w  = 'a.fecha_hora >= ? AND a.fecha_hora < ?';
$pr = [$desde . ' 00:00:00', date('Y-m-d', strtotime("$hasta +1 day")) . ' 00:00:00'];
if ($uid) { $w .= ' AND a.usuario_id = ?'; $pr[] = $uid; }
$qs = http_build_query(['desde' => $desde, 'hasta' => $hasta, 'u' => $uid ?: '']);
$usuarios = db()->query('SELECT id, codigo, nombre, activo FROM usuarios ORDER BY nombre')->fetchAll();

function marcas(string $w, array $pr, string $lim = ''): array {
    $s = db()->prepare("SELECT a.id, a.tipo, a.fecha_hora, a.latitud, a.longitud, a.distancia_m, a.precision_m, u.codigo, u.nombre
                        FROM asistencias a JOIN usuarios u ON u.id = a.usuario_id WHERE $w ORDER BY a.id DESC $lim");
    $s->execute($pr);
    return $s->fetchAll();
}
// Empareja entrada -> salida por usuario y día
function horas(string $w, array $pr): array {
    $s = db()->prepare("SELECT a.usuario_id, a.tipo, a.fecha_hora, u.codigo, u.nombre FROM asistencias a
                        JOIN usuarios u ON u.id = a.usuario_id WHERE $w ORDER BY a.usuario_id, a.fecha_hora, a.id");
    $s->execute($pr);
    $d = []; $open = [];
    foreach ($s as $r) {
        $dia = substr($r['fecha_hora'], 0, 10); $k = $r['usuario_id'] . '|' . $dia;
        $d[$k] ??= ['fecha' => $dia, 'codigo' => $r['codigo'], 'nombre' => $r['nombre'], 'ent' => null, 'sal' => null, 'seg' => 0, 'abierta' => false];
        $t = strtotime($r['fecha_hora']);
        if ($r['tipo'] === 'entrada') { $d[$k]['ent'] ??= $r['fecha_hora']; $open[$k] = $t; $d[$k]['abierta'] = true; }
        elseif (isset($open[$k])) { $d[$k]['seg'] += $t - $open[$k]; unset($open[$k]); $d[$k]['sal'] = $r['fecha_hora']; $d[$k]['abierta'] = false; }
    }
    $d = array_values($d);
    usort($d, fn($x, $y) => [$y['fecha'], $x['nombre']] <=> [$x['fecha'], $y['nombre']]);
    return $d;
}
$hm = fn(int $s) => sprintf('%d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60));

// ---------- CSV ----------
$csv = $_GET['csv'] ?? '';
if ($csv === 'horas' || $csv === 'marcas') {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=saga_{$csv}_{$desde}_{$hasta}.csv");
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    $safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : $v;   // evita inyección de fórmulas
    $put  = fn(array $row) => fputcsv($o, $row, ';', '"', '');
    if ($csv === 'horas') {
        $put(['Fecha', 'Código', 'Nombre', 'Primera entrada', 'Última salida', 'Horas', 'Estado']);
        foreach (horas($w, $pr) as $r) $put([$r['fecha'], $safe($r['codigo']), $safe($r['nombre']), $r['ent'], $r['sal'], $hm($r['seg']), $r['abierta'] ? 'Sin salida' : 'OK']);
    } else {
        $put(['ID', 'Código', 'Nombre', 'Tipo', 'Fecha y hora', 'Distancia (m)', 'Precisión (m)', 'Latitud', 'Longitud']);
        foreach (marcas($w, $pr) as $r) $put([$r['id'], $safe($r['codigo']), $safe($r['nombre']), $r['tipo'], $r['fecha_hora'], $r['distancia_m'], $r['precision_m'], $r['latitud'], $r['longitud']]);
    }
    exit;
}

// ---------- Datos de la pestaña ----------
if ($tab === 'horas') {
    $rep = horas($w, $pr);
    $total = array_sum(array_column($rep, 'seg'));
} elseif ($tab === 'marcas') {
    $pp = 25;
    $c = db()->prepare("SELECT COUNT(*) FROM asistencias a WHERE $w"); $c->execute($pr);
    $tot = (int)$c->fetchColumn();
    $pages = max(1, (int)ceil($tot / $pp));
    $pg = min($pages, max(1, (int)($_GET['p'] ?? 1)));
    $rows = marcas($w, $pr, 'LIMIT ' . $pp . ' OFFSET ' . (($pg - 1) * $pp));
}
$csrf = '<input type="hidden" name="csrf" value="' . $h($_SESSION['csrf']) . '">';
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Panel de administración | Sistema Penitenciario</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="institucional.css">
</head>
<body>
<a class="skip-link" href="#contenido">Saltar al contenido</a>
<div class="gov-band"></div>
<header class="topbar">
  <div class="container py-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div class="d-flex align-items-center gap-3"><?= $escudo ?><div>
      <div class="inst-sub">República de Panamá</div><div class="inst-name">Sistema Penitenciario</div>
      <div class="small text-secondary">Panel de administración · Control de asistencia</div></div></div>
    <form method="post"><?= $csrf ?><input type="hidden" name="accion" value="salir">
      <button class="btn btn-outline-secondary btn-sm px-3">Cerrar sesión</button></form>
  </div>
</header>
<main id="contenido" class="container py-4">
  <?php if ($aviso): ?><div class="alert alert-info py-2" role="status"><?= $h($aviso) ?></div><?php endif; ?>
  <ul class="nav nav-pills mb-3">
    <?php foreach (['horas' => 'Horas por día', 'marcas' => 'Marcaciones', 'usuarios' => 'Usuarios'] as $k => $t): ?>
      <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="?tab=<?= $k ?>&<?= $h($qs) ?>"><?= $t ?></a></li>
    <?php endforeach; ?>
  </ul>

<?php if ($tab !== 'usuarios'): ?>
  <form method="get" class="panel-card p-3 mb-3 row g-2 align-items-end">
    <input type="hidden" name="tab" value="<?= $h($tab) ?>">
    <div class="col-6 col-md-2"><label class="form-label small fw-semibold" for="desde">Desde</label><input type="date" id="desde" name="desde" value="<?= $h($desde) ?>" class="form-control"></div>
    <div class="col-6 col-md-2"><label class="form-label small fw-semibold" for="hasta">Hasta</label><input type="date" id="hasta" name="hasta" value="<?= $h($hasta) ?>" class="form-control"></div>
    <div class="col-md-4"><label class="form-label small fw-semibold" for="u">Usuario</label>
      <select id="u" name="u" class="form-select"><option value="0">Todos</option>
      <?php foreach ($usuarios as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $uid === (int)$u['id'] ? 'selected' : '' ?>><?= $h($u['codigo'] . ' · ' . $u['nombre']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4 d-flex gap-2">
      <button class="btn btn-primary flex-fill" style="min-height:44px;border-radius:10px">Filtrar</button>
      <a class="btn btn-outline-success flex-fill d-flex align-items-center justify-content-center" style="border-radius:10px" href="?csv=<?= $tab ?>&<?= $h($qs) ?>">Exportar CSV</a>
    </div>
  </form>
<?php endif; ?>

<?php if ($tab === 'horas'): ?>
  <section class="panel-card overflow-hidden">
    <div class="d-flex justify-content-between align-items-center p-3 p-md-4 pb-2">
      <div><h2 class="h5 fw-bold mb-1">Horas trabajadas</h2><p class="small text-secondary mb-0">Entrada emparejada con salida, por usuario y día</p></div>
      <span class="badge rounded-pill text-bg-light border">Total: <?= $hm($total) ?> h</span>
    </div>
    <div class="table-responsive"><table class="table mb-0">
      <thead><tr><th>Fecha</th><th>Código</th><th>Nombre</th><th>Entrada</th><th>Salida</th><th>Horas</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($rep as $r): ?>
        <tr><td class="text-nowrap"><?= $h($r['fecha']) ?></td><td class="fw-semibold"><?= $h($r['codigo']) ?></td><td><?= $h($r['nombre']) ?></td>
          <td><?= $h($r['ent'] ? substr($r['ent'], 11, 5) : '—') ?></td><td><?= $h($r['sal'] ? substr($r['sal'], 11, 5) : '—') ?></td>
          <td class="fw-semibold"><?= $hm($r['seg']) ?></td>
          <td><span class="badge rounded-pill text-bg-<?= $r['abierta'] ? 'warning' : 'success' ?>"><?= $r['abierta'] ? 'Sin salida' : 'OK' ?></span></td></tr>
      <?php endforeach; if (!$rep): ?><tr><td colspan="7" class="text-center text-muted py-5">Sin marcaciones en este rango.</td></tr><?php endif; ?>
      </tbody></table></div>
  </section>

<?php elseif ($tab === 'marcas'): ?>
  <section class="panel-card overflow-hidden">
    <div class="d-flex justify-content-between align-items-center p-3 p-md-4 pb-2">
      <div><h2 class="h5 fw-bold mb-1">Marcaciones</h2><p class="small text-secondary mb-0">Página <?= $pg ?> de <?= $pages ?></p></div>
      <span class="badge rounded-pill text-bg-light border"><?= $tot ?> registros</span>
    </div>
    <div class="table-responsive"><table class="table mb-0">
      <thead><tr><th>ID</th><th>Código</th><th>Nombre</th><th>Tipo</th><th>Fecha y hora</th><th>Distancia</th><th>Mapa</th><th>Foto</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr><td class="text-secondary">#<?= (int)$r['id'] ?></td><td class="fw-semibold"><?= $h($r['codigo']) ?></td><td><?= $h($r['nombre']) ?></td>
          <td><span class="badge rounded-pill text-bg-<?= $r['tipo'] === 'entrada' ? 'success' : 'secondary' ?>"><?= $h($r['tipo']) ?></span></td>
          <td class="text-nowrap"><?= $h($r['fecha_hora']) ?></td>
          <td class="text-nowrap"><?= $h($r['distancia_m']) ?> m<?= $r['precision_m'] !== null ? ' <span class="text-secondary small">(±' . (int)$r['precision_m'] . ')</span>' : '' ?></td>
          <td><a href="https://www.google.com/maps?q=<?= $h($r['latitud']) ?>,<?= $h($r['longitud']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary rounded-pill">Ver mapa</a></td>
          <td><img src="admin.php?foto=<?= (int)$r['id'] ?>" data-foto="<?= (int)$r['id'] ?>" width="64" height="48" loading="lazy" class="thumb rounded-3 border object-fit-cover" alt="Foto de <?= $h($r['nombre']) ?>"></td></tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-5">Sin marcaciones en este rango.</td></tr><?php endif; ?>
      </tbody></table></div>
    <?php if ($pages > 1): ?>
    <nav class="p-3 d-flex justify-content-center gap-2">
      <a class="btn btn-sm btn-outline-secondary rounded-pill px-3 <?= $pg <= 1 ? 'disabled' : '' ?>" href="?tab=marcas&<?= $h($qs) ?>&p=<?= $pg - 1 ?>">← Anterior</a>
      <a class="btn btn-sm btn-outline-secondary rounded-pill px-3 <?= $pg >= $pages ? 'disabled' : '' ?>" href="?tab=marcas&<?= $h($qs) ?>&p=<?= $pg + 1 ?>">Siguiente →</a>
    </nav>
    <?php endif; ?>
  </section>

<?php else: ?>
  <section class="panel-card p-3 p-md-4 mb-4">
    <h2 class="h5 fw-bold mb-3">Crear usuario</h2>
    <form method="post" class="row g-3 align-items-end"><?= $csrf ?><input type="hidden" name="accion" value="crear">
      <div class="col-md-3"><label class="form-label small fw-semibold" for="codigo">Código</label><input id="codigo" name="codigo" class="form-control" placeholder="Ej. U001" required></div>
      <div class="col-md-4"><label class="form-label small fw-semibold" for="nombre">Nombre completo</label><input id="nombre" name="nombre" class="form-control" required></div>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="pin">PIN (4 a 8 dígitos)</label><input id="pin" name="pin" type="password" inputmode="numeric" pattern="\d{4,8}" class="form-control" required></div>
      <div class="col-md-2"><button class="btn btn-primary w-100" style="min-height:44px;border-radius:10px">Crear</button></div>
    </form>
  </section>
  <section class="panel-card overflow-hidden">
    <div class="table-responsive"><table class="table mb-0">
      <thead><tr><th>Código</th><th>Nombre</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
      <tbody>
      <?php foreach ($usuarios as $u): ?>
        <tr><td class="fw-semibold"><?= $h($u['codigo']) ?></td><td><?= $h($u['nombre']) ?></td>
          <td><span class="badge rounded-pill text-bg-<?= $u['activo'] ? 'success' : 'secondary' ?>"><?= $u['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
          <td class="text-end text-nowrap">
            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill" data-reset="<?= (int)$u['id'] ?>" data-nombre="<?= $h($u['nombre']) ?>">Resetear PIN</button>
            <form method="post" class="d-inline"><?= $csrf ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <button class="btn btn-sm btn-outline-<?= $u['activo'] ? 'danger' : 'success' ?> rounded-pill"><?= $u['activo'] ? 'Desactivar' : 'Activar' ?></button></form>
          </td></tr>
      <?php endforeach; if (!$usuarios): ?><tr><td colspan="4" class="text-center text-muted py-5">Aún no hay usuarios.</td></tr><?php endif; ?>
      </tbody></table></div>
  </section>
<?php endif; ?>
</main>

<footer class="site-footer mt-4"><div class="container py-3 text-center">© <?= date('Y') ?> República de Panamá · Sistema Penitenciario · Acceso restringido y auditado</div></footer>

<div class="modal fade" id="mFoto" tabindex="-1" aria-label="Foto ampliada"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content border-0 bg-transparent">
  <img id="fotoGrande" class="img-fluid rounded-4" alt="Foto de la marcación"></div></div></div>

<div class="modal fade" id="mPin" tabindex="-1" aria-labelledby="mPinT"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content" style="border-radius:18px">
  <?= $csrf ?><input type="hidden" name="accion" value="pin"><input type="hidden" name="id" id="pinId">
  <div class="modal-header border-0"><h2 class="h5 fw-bold mb-0" id="mPinT">Resetear PIN</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
  <div class="modal-body pt-0"><p class="text-secondary small">Nuevo PIN para <strong id="pinNombre"></strong>. También se levanta cualquier bloqueo por intentos fallidos.</p>
    <input name="pin" type="password" inputmode="numeric" pattern="\d{4,8}" class="form-control" placeholder="4 a 8 dígitos" required></div>
  <div class="modal-footer border-0 pt-0"><button class="btn btn-primary" style="border-radius:10px">Guardar PIN</button></div>
</form></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('click', e => {
  const f = e.target.closest('[data-foto]');
  if (f) { document.getElementById('fotoGrande').src = 'admin.php?foto=' + f.dataset.foto; new bootstrap.Modal('#mFoto').show(); }
  const r = e.target.closest('[data-reset]');
  if (r) { document.getElementById('pinId').value = r.dataset.reset; document.getElementById('pinNombre').textContent = r.dataset.nombre; new bootstrap.Modal('#mPin').show(); }
});
</script>
</body>
</html>