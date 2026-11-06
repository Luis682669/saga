<?php require_once 'config.php'; ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0a2f6b">
    <title>Registro de asistencia | Sistema Penitenciario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="institucional.css">
</head>
<body>
<a class="skip-link" href="#contenido">Saltar al contenido</a>
<div class="gov-band"></div>
<header class="topbar"><div class="container py-3 d-flex flex-wrap justify-content-between align-items-center gap-3" style="max-width:1080px">
    <div class="d-flex align-items-center gap-3"><svg class="escudo" viewBox="0 0 24 28" role="img" aria-label="Escudo institucional"><path d="M12 1 2 5v8c0 7 4.500 11.500 10 14 5.500-2.500 10-7 10-14V5z" fill="currentColor"/><polygon points="12,8 13.5,11.500 17.300,11.900 14.400,14.400 15.300,18.100 12,16.200 8.700,18.100 9.600,14.400 6.700,11.900 10.500,11.500" fill="#fff"/></svg><div>
        <div class="inst-sub">República de Panamá</div>
        <div class="inst-name">Sistema Penitenciario</div>
        <div class="small text-secondary">Control de asistencia del personal</div></div></div>
    <div class="text-end"><div class="clock" id="reloj">--:--:--</div><div class="small text-secondary" id="fecha">&nbsp;</div></div>
</div></header>

<main id="contenido" class="container py-4 py-md-5" style="max-width:1080px">
    <section class="main-card" aria-labelledby="titulo">
        <div class="row g-0">
            <div class="col-md-7"><div class="form-panel">
                <span class="section-tag">REGISTRO DE ASISTENCIA</span>
                <h1 class="h3 fw-bold mb-2" id="titulo">Marcación de entrada y salida</h1>
                <p class="text-secondary mb-4">Ingrese sus credenciales institucionales y confirme su ubicación en el centro para registrar su asistencia.</p>

                <div class="mb-3">
                    <label class="form-label" for="codigoUsuario"><span class="step-number">1</span>Código de funcionario</label>
                    <input type="text" id="codigoUsuario" class="form-control" placeholder="Ej. U001" autocomplete="username" required>
                </div>
                <div class="mb-4">
                    <label class="form-label" for="pinUsuario"><span class="step-number">2</span>PIN personal</label>
                    <input type="password" id="pinUsuario" class="form-control" placeholder="Ingrese su PIN" autocomplete="current-password" required>
                </div>

                <button id="btnMarcar" class="btn btn-primary btn-lg w-100"><span id="btnTexto">Registrar asistencia</span></button>
                <p id="mensaje" class="message mt-3 mb-3" role="status" aria-live="polite"></p>
                <div class="notice">Uso exclusivo del personal autorizado. Cada registro guarda fotografía, ubicación y hora oficial del servidor, y está sujeto a auditoría.</div>
            </div></div>

            <div class="col-md-5"><aside class="camera-panel">
                <div class="text-uppercase small fw-semibold opacity-75 mb-2">Verificación de identidad</div>
                <h2 class="h4 fw-bold mb-1">Fotografía de registro</h2>
                <p class="camera-caption mb-0">La cámara se activará únicamente al presionar «Registrar asistencia» y se apagará al finalizar.</p>
                <div class="camera-frame mt-4" id="cameraFrame">
                    <video id="camara" autoplay playsinline muted aria-label="Vista previa de la cámara"></video>
                    <div class="camera-placeholder"><div><span class="camera-icon" aria-hidden="true">◉</span>La vista de cámara aparecerá aquí</div></div>
                    <canvas id="canvas" width="640" height="480" class="d-none"></canvas>
                </div>
            </aside></div>
        </div>
    </section>
</main>
<footer class="site-footer"><div class="container py-3 text-center">© <span id="anio"></span> República de Panamá · Sistema Penitenciario · Control de asistencia del personal</div></footer>

<script>
    const camara = document.getElementById('camara');
    const canvas = document.getElementById('canvas');
    const btnMarcar = document.getElementById('btnMarcar');
    const btnTexto = document.getElementById('btnTexto');
    const mensaje = document.getElementById('mensaje');
    const cameraFrame = document.getElementById('cameraFrame');
    const inputCodigo = document.getElementById('codigoUsuario');
    const inputPin = document.getElementById('pinUsuario');
    const contexto = canvas.getContext('2d');
    const CLAVE_CODIGO = 'saga_codigo';
    const PRECISION_MAX_M = <?= PRECISION_MAX_M ?>;
    let streamCamara = null;

    const pausa = ms => new Promise(r => setTimeout(r, ms));

    // Recordar solo el código (nunca el PIN)
    try {
        const guardado = localStorage.getItem(CLAVE_CODIGO);
        if (guardado) { inputCodigo.value = guardado; inputPin.focus(); }
    } catch (e) { /* almacenamiento no disponible */ }

    async function activarCamara() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            throw new Error('Tu navegador no soporta acceso a la cámara. Abra la página desde localhost o HTTPS.');
        }

        if (!streamCamara) {
            streamCamara = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'user' },
                audio: false
            });
            camara.srcObject = streamCamara;
            await camara.play();
            cameraFrame.classList.add('active');
        }
    }

    // Espera a que haya imagen real y deja que la cámara ajuste exposición
    async function esperarCamaraLista() {
        for (let i = 0; i < 30 && !(camara.videoWidth > 0); i++) await pausa(100);
        if (!(camara.videoWidth > 0)) throw new Error('La cámara aún no está lista. Inténtelo de nuevo.');
        await pausa(700);
    }

    function apagarCamara() {
        if (streamCamara) {
            streamCamara.getTracks().forEach(t => t.stop());
            streamCamara = null;
        }
        camara.srcObject = null;
        cameraFrame.classList.remove('active');
    }

    function obtenerUbicacionPrecisa() {
        return new Promise((resolve, reject) => {
            let watchId = null;
            let timeoutId = null;
            let mejorPrecision = Infinity;
            const finalizar = (callback, valor) => {
                if (watchId !== null) navigator.geolocation.clearWatch(watchId);
                if (timeoutId !== null) clearTimeout(timeoutId);
                callback(valor);
            };

            timeoutId = setTimeout(() => {
                const detalle = Number.isFinite(mejorPrecision)
                    ? ` Mejor precisión obtenida: ±${Math.round(mejorPrecision)} m.`
                    : '';
                finalizar(reject, new Error(`No se obtuvo una ubicación precisa en 30 segundos.${detalle} Verifica la ubicación del dispositivo e inténtalo de nuevo.`));
            }, 30000);

            watchId = navigator.geolocation.watchPosition(position => {
                const precision = position.coords.accuracy;
                if (!Number.isFinite(precision)) return;
                mejorPrecision = Math.min(mejorPrecision, precision);
                if (precision <= PRECISION_MAX_M) {
                    finalizar(resolve, position);
                } else {
                    mensaje.innerText = `Buscando una ubicación más precisa (±${Math.round(precision)} m)...`;
                }
            }, error => finalizar(reject, error), {
                enableHighAccuracy: true,
                maximumAge: 0
            });
        });
    }

    btnMarcar.addEventListener('click', async () => {
        const codigo = inputCodigo.value.trim();
        const pin = inputPin.value;

        if (!codigo || !pin) {
            mensaje.className = 'message mt-3 text-danger';
            mensaje.innerText = 'Ingrese su código y PIN para continuar.';
            return;
        }

        btnMarcar.disabled = true;
        try {
            mensaje.className = 'message mt-3 text-secondary';
            btnTexto.innerText = 'Solicitando permisos...';

            if (!navigator.geolocation) {
                throw new Error('Tu navegador no permite obtener la ubicación.');
            }
            mensaje.innerText = 'Obteniendo ubicación precisa...';
            const position = await obtenerUbicacionPrecisa();

            mensaje.innerText = 'Solicitando permiso de cámara...';
            await activarCamara();
            mensaje.innerText = 'Mire a la cámara...';
            await esperarCamaraLista();
            contexto.drawImage(camara, 0, 0, canvas.width, canvas.height);
            const fotoBase64 = canvas.toDataURL('image/jpeg', 0.85);
            mensaje.innerText = 'Procesando tu marcaje...';
            btnTexto.innerText = 'Procesando...';

            const res = await fetch('procesar.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    codigo: codigo,
                    pin: pin,
                    latitud: position.coords.latitude,
                    longitud: position.coords.longitude,
                    precision: position.coords.accuracy,
                    foto: fotoBase64
                })
            });
            const data = await res.json().catch(() => ({ ok: false, msg: 'Respuesta inválida del servidor.' }));
            if (!res.ok || !data.ok) {
                throw new Error(data.msg || 'No se pudo registrar la asistencia.');
            }

            mensaje.className = 'message mt-3 text-success fw-semibold';
            mensaje.innerText = data.msg;
            inputPin.value = '';
            try { localStorage.setItem(CLAVE_CODIGO, codigo); } catch (e) { /* ignorar */ }
        } catch (err) {
            mensaje.className = 'message mt-3 text-danger';
            if (err && err.name === 'NotAllowedError') {
                mensaje.innerText = 'Permiso denegado. Permita el acceso a cámara y ubicación en la configuración del navegador.';
            } else if (err && err.name === 'NotFoundError') {
                mensaje.innerText = 'No se encontró una cámara disponible en este equipo.';
            } else if (err && err.code === 1) {
                mensaje.innerText = 'No se permitió el acceso a la ubicación. Actívela para continuar.';
            } else if (err && err.code === 3) {
                mensaje.innerText = 'No se pudo obtener la ubicación a tiempo. Inténtelo nuevamente.';
            } else {
                mensaje.innerText = err.message || 'Ocurrió un error al registrar la asistencia.';
            }
        } finally {
            apagarCamara();
            btnMarcar.disabled = false;
            btnTexto.innerText = 'Registrar asistencia';
        }
    });

    // Reloj informativo (la hora oficial del registro la fija el servidor)
    const reloj = document.getElementById('reloj'), fecha = document.getElementById('fecha');
    function tic() {
        const n = new Date(), z = { timeZone: 'America/Panama' };
        reloj.textContent = n.toLocaleTimeString('es-PA', { ...z, hour12: false });
        fecha.textContent = n.toLocaleDateString('es-PA', { ...z, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    }
    tic(); setInterval(tic, 1000);
    document.getElementById('anio').textContent = new Date().getFullYear();
</script>
</body>
</html>