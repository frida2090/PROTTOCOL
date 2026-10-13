<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/views/dashboard/sidebar.php';
require_once __DIR__ . '/../app/equipos.php';

$user = currentUser();
if(!$user){
    redirect('login.php');
}

if($user['rol'] !== 'miembroCatt') {
    flash('error', 'Acceso denegado. Solo los miembros de la CATT pueden acceder a este panel.');
    redirect('login.php');
}

// Registro de dictámenes emitidos por el miembro CATT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['formulario'] ?? '') === 'dictamen') {
    $tipoDictamen   = trim($_POST['tipo_dictamen'] ?? '');
    $noBoletaAlumno = trim($_POST['no_boleta_alumno'] ?? '');
    $resultado      = trim($_POST['resultado'] ?? '');
    $comentarios    = trim($_POST['comentarios'] ?? '');

    $tiposPermitidos      = ['protocolo', 'trabajo-terminal-i', 'trabajo-terminal-ii'];
    $resultadosPermitidos = ['aprobado', 'observaciones', 'rechazado'];

    if (
        in_array($tipoDictamen, $tiposPermitidos, true)
        && $noBoletaAlumno !== ''
        && in_array($resultado, $resultadosPermitidos, true)
    ) {
        flash('success', 'El dictamen fue registrado correctamente y quedará disponible para el alumno.');
    } else {
        flash('error', 'Completa el número de boleta del alumno y selecciona un tipo de trámite y un resultado válidos.');
    }
    redirect('panelMiembroCatt.php?modal=formatos');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['formulario'] ?? '') === 'equipo') {
    $quiereJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    $resultado = ['ok' => false, 'message' => ''];
    try {
        $equipoId = crearEquipoTT(
            database(),
            (int) $user['id'],
            trim($_POST['titulo_equipo'] ?? ''),
            trim($_POST['descripcion_equipo'] ?? ''),
            enteros($_POST['estudiantes'] ?? []),
            enteros($_POST['directores'] ?? []),
            enteros($_POST['sinodales'] ?? []),
            (int) ($_POST['seguimiento'] ?? 0)
        );
        $resultado = ['ok' => true, 'message' => 'El equipo de TT fue registrado y sus directores, sinodales y profesor de seguimiento quedaron asignados.', 'html' => renderEquipoDetalleHtml(equipoPorId(database(), $equipoId))];
    } catch (DomainException $exception) {
        $resultado['message'] = $exception->getMessage();
    } catch (Throwable $exception) {
        $resultado['message'] = 'No fue posible registrar el equipo. Intenta de nuevo.';
    }
    if ($quiereJson) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code($resultado['ok'] ? 200 : 422);
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash($resultado['ok'] ? 'success' : 'error', $resultado['message']);
    redirect('panelMiembroCatt.php?modal=equipos');
}

$pageTitle = 'Panel del Miembro · CATT';
require __DIR__ . '/../app/views/header.php';
$modal = $_GET['modal'] ?? '';
$modalPermitido = in_array($modal, ['perfil', 'revisiones', 'formatos', 'equipos'], true);

$estudiantesLibres = $docentesDisponibles = $equiposRegistrados = [];
if ($modal === 'equipos') {
    $db = database();
    $estudiantesLibres = estudiantesSinEquipo($db);
    $docentesDisponibles = docentesConCarga($db);
    $equiposRegistrados = array_map(
        static fn (array $equipo): array => equipoCompleto($db, $equipo),
        $db->query('SELECT EquipoId, TituloProtocolo, Descripcion, RepresentanteUserId FROM Equipo_TT ORDER BY EquipoId DESC')->fetchAll()
    );
}

$query = database()->prepare("SELECT c.fecha, c.fecha_fin, c.actividad, c.descripcion FROM calendario c WHERE COALESCE(c.fecha_fin, c.fecha) >= CURRENT_DATE AND (c.tipo_tt IS NULL OR EXISTS (SELECT 1 FROM calendario_participante cp WHERE cp.calendario_id = c.id AND cp.UserId = ?)) ORDER BY c.fecha ASC LIMIT 3");
$query->execute([$user['id']]);
$proximasFechas = $query->fetchAll();
?>
<div class="dashboard-container">
    <?php renderSidebar('inicio'); ?>

    <main class="dashboard-main">
        <header class="dashboard-topbar">
            <div>
                <p class="eyebrow">Comisión Académica de Trabajos Terminales</p>
                <h1>Panel del Miembro CATT</h1>
            </div>
            <a class="button button-primary" href="calendario.php">Ver calendario</a>
        </header>

        <section class="dashboard-grid">
            <article class="dashboard-card highlight">
                <p class="dashboard-kicker">Revisión</p>
                <h2>Protocolos de TT</h2>
                <p class="card-copy">Revisa los protocolos registrados por los alumnos y emite el dictamen correspondiente de la CATT.</p>
                <div class="stat-row">
                    <strong>0</strong>
                    <span>pendientes por revisar</span>
                </div>
                <a class="card-list-link" href="panelMiembroCatt.php?modal=revisiones&amp;tipo=protocolo">Ver lista</a>
            </article>

            <article class="dashboard-card">
                <p class="dashboard-kicker">Procesa</p>
                <h2>Protocolo, TT I y II</h2>
                <p class="card-copy">Procesa las solicitudes de modificación, reprogramación, extraordinario y bajas de alumnos.</p>
                <div class="stat-row">
                    <strong>0</strong>
                    <span>pendientes por procesar</span>
                </div>
                 <a class="card-list-link" href="panelMiembroCatt.php?modal=revisiones&amp;tipo=trabajo-terminal-i">Ver lista</a>
            </article>

            <article class="dashboard-card">
                <p class="dashboard-kicker">Emite</p>
                <h2>TT I y II</h2>
                <p class="card-copy">Genera el listado de alumnos que son candidatos para inscribir TTI y TTII</p>
                <div class="stat-row">
                    <strong>0</strong>
                    <span>alumnos candidatos</span>
                </div>
                <a class="card-list-link" href="panelMiembroCatt.php?modal=revisiones&amp;tipo=trabajo-terminal-ii">Ver lista</a>
            </article>
        </section>

        <section class="dashboard-panel">
            <div class="panel-header">
                <h2>Próximas Fechas</h2>
                <a href="calendario.php">Ver todas</a>
            </div>

            <div class="activity-summary">
                <?php if(empty($proximasFechas)):?>
                    <p>No hay fechas próximas registradas en el calendario.</p>
                <?php else: ?>
                    <?php foreach($proximasFechas as $fecha): ?>
                        <div class="summary-item">
                            <span class="summary-date"><?= e(date('d/m/Y', strtotime($fecha['fecha']))) ?></span>
                            <div>
                                <strong><?= e($fecha['actividad']) ?></strong>
                                <small><?= e($fecha['descripcion']) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>
<div class="dashboard-modal <?= $modalPermitido ? 'is-open' : '' ?>" id="modal-<?= e($modalPermitido ? $modal : 'none') ?>" role="dialog" aria-modal="true" aria-hidden="<?= $modalPermitido ? 'false' : 'true' ?>">
    <div class="dashboard-modal-backdrop" data-close-modal></div>
    <?php if ($modal === 'perfil'): ?>
        <section class="dashboard-modal-content" aria-labelledby="modal-perfil-title">
            <button class="modal-close" type="button" data-close-modal aria-label="Cerrar">×</button>
            <p class="eyebrow">Mi cuenta</p><h2 id="modal-perfil-title">Datos personales</h2><p class="modal-intro">Información asociada a tu cuenta de miembro de la CATT.</p>
            <dl class="profile-details">
                <div><dt>Nombre completo</dt><dd><?= e(fullName($user)) ?></dd></div>
                <div><dt>Correo electrónico</dt><dd><?= e($user['correo']) ?></dd></div>
                <div><dt>Número de empleado</dt><dd><?= e($user['numero_empleado']) ?></dd></div>
                <div><dt>Rol</dt><dd>Miembro CATT · Comisión Académica de Trabajos Terminales</dd></div>
            </dl>
        </section>
    <?php elseif ($modal === 'revisiones'): ?>
        <section class="dashboard-modal-content" aria-labelledby="modal-revisiones-title">
            <button class="modal-close" type="button" data-close-modal aria-label="Cerrar">×</button>
            <p class="eyebrow">Seguimiento</p><h2 id="modal-revisiones-title">Mis revisiones asignadas</h2>
            <div class="empty-modal">
                <span>—</span>
                <h3>No tienes revisiones pendientes</h3>
                <p>Ningun alumno ha enviado formatos para revisar.</p>
                <a class="button button-primary" href="panelMiembroCatt.php?modal=formatos">Registrar un dictamen</a>
            </div>
        </section>
    <?php elseif ($modal === 'equipos'): ?>
        <?php
        $selector = static function (string $id, string $titulo, string $nombre, int $max, string $tipo, array $personas, bool $conCarga) : void {
            ?>
            <fieldset class="picker" data-picker data-max="<?= $max ?>" data-role="<?= e($id) ?>" data-label="<?= e(mb_strtolower($titulo)) ?>">
                <legend><?= e($titulo) ?> <span class="picker-count" data-count><?= $max > 0 ? "0/{$max}" : '0' ?></span></legend>
                <input type="search" class="picker-search" placeholder="Buscar por nombre…" aria-label="Buscar <?= e(mb_strtolower($titulo)) ?>" autocomplete="off">
                <div class="picker-chips" data-chips aria-live="polite"></div>
                <div class="picker-options">
                    <?php foreach ($personas as $persona): $carga = (int) ($persona['Carga'] ?? 0); $lleno = $conCarga && $carga >= MAX_EQUIPOS_POR_DOCENTE; ?>
                        <label class="picker-option<?= $lleno ? ' is-disabled' : '' ?>" data-id="<?= e((string) $persona['UserId']) ?>" data-name="<?= e(nombreDeUsuario($persona)) ?>" data-sort="<?= e(claveAlfabetica($persona)) ?>" data-search="<?= e(mb_strtolower(nombreDeUsuario($persona) . ' ' . ($persona['NumeroBoleta'] ?? ''))) ?>"<?= $conCarga ? ' data-load="' . $carga . '" data-max-load="' . MAX_EQUIPOS_POR_DOCENTE . '"' : '' ?>>
                                <input type="<?= e($tipo) ?>" name="<?= e($nombre) ?>" value="<?= e((string) $persona['UserId']) ?>"<?= $lleno ? ' disabled' : '' ?>>
                                <span class="picker-name"><?= e(nombreDeUsuario($persona)) ?></span>
                                <small class="picker-meta" data-meta><?= $conCarga ? e($lleno ? 'Límite alcanzado' : "{$carga} de " . MAX_EQUIPOS_POR_DOCENTE . ' equipos') : e($persona['NumeroBoleta'] ?? '') ?></small>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="picker-empty" data-empty<?= $personas ? ' hidden' : '' ?>><?= $personas ? 'Sin resultados.' : 'No hay opciones disponibles.' ?></p>
            </fieldset>
            <?php
        };
        ?>
        <section class="dashboard-modal-content modal-wide" aria-labelledby="modal-equipos-title">
            <button class="modal-close" type="button" data-close-modal aria-label="Cerrar">×</button>
            <p class="eyebrow">Equipos de TT</p><h2 id="modal-equipos-title">Asignar equipo de TT</h2>
            <p class="modal-intro">Elige los integrantes y se validará al instante. El representante será el primer estudiante en orden alfabético y cada docente puede tener máximo <?= MAX_EQUIPOS_POR_DOCENTE ?> equipos.</p>

            <div class="alert" data-feedback hidden role="alert"></div>

            <form method="post" class="modal-form team-form" data-team-form novalidate>
                <input type="hidden" name="formulario" value="equipo">

                <div class="team-form-fields">
                    <div>
                        <label for="titulo_equipo">Título del protocolo <small class="field-counter" data-title-counter>0/100</small></label>
                        <input type="text" id="titulo_equipo" name="titulo_equipo" maxlength="100" required>
                    </div>
                    <div>
                        <label for="descripcion_equipo">Descripción</label>
                        <textarea id="descripcion_equipo" name="descripcion_equipo" rows="2" required></textarea>
                    </div>
                </div>

                <div class="team-pickers">
                    <?php
                    $selector('estudiantes', 'Estudiantes', 'estudiantes[]', 0, 'checkbox', $estudiantesLibres, false);
                    $selector('directores', 'Directores', 'directores[]', 2, 'checkbox', array_values(array_filter($docentesDisponibles, static fn (array $d): bool => in_array('director', $d['Roles'], true))), true);
                    $selector('sinodales', 'Sinodales', 'sinodales[]', 3, 'checkbox', array_values(array_filter($docentesDisponibles, static fn (array $d): bool => in_array('sinodal', $d['Roles'], true))), true);
                    $selector('seguimiento', 'Profesor de seguimiento', 'seguimiento', 1, 'radio', $docentesDisponibles, true);
                    ?>
                </div>

                <aside class="team-summary" aria-live="polite">
                    <p class="team-rep">Representante: <strong data-rep>—</strong></p>
                    <ul class="team-checklist" data-checklist>
                        <li data-check="titulo">Título y descripción</li>
                        <li data-check="estudiantes">Al menos 1 estudiante</li>
                        <li data-check="directores">2 directores</li>
                        <li data-check="sinodales">3 sinodales</li>
                        <li data-check="seguimiento">1 profesor de seguimiento</li>
                    </ul>
                </aside>

                <button class="button button-primary" type="submit" data-submit disabled>Registrar equipo <span>→</span></button>
            </form>

            <div class="panel-header team-list-header">
                <h3>Equipos registrados (<span data-team-count><?= count($equiposRegistrados) ?></span>)</h3>
                <input type="search" class="picker-search" data-team-filter placeholder="Filtrar equipos…" aria-label="Filtrar equipos registrados" autocomplete="off">
            </div>
            <div class="activity-summary" data-team-list>
                <?php foreach ($equiposRegistrados as $equipo): renderEquipoDetalle($equipo); endforeach; ?>
            </div>
            <p class="picker-empty" data-team-empty<?= $equiposRegistrados ? ' hidden' : '' ?>>Aún no hay equipos registrados.</p>
        </section>    <?php elseif ($modal === 'formatos'): ?>
        <section class="dashboard-modal-content" aria-labelledby="modal-formatos-title">
            <button class="modal-close" type="button" data-close-modal aria-label="Cerrar">×</button>
            <p class="eyebrow">Nuevo dictamen</p><h2 id="modal-formatos-title">Registrar dictamen de la CATT</h2><p class="modal-intro">Captura el resultado de la revisión de un alumno.</p>
            <form method="post" class="modal-form">
                <input type="hidden" name="formulario" value="dictamen">

                <label for="tipo_dictamen">Tipo de trámite</label>
                <select id="tipo_dictamen" name="tipo_dictamen" required>
                    <option value="">Selecciona una opción</option>
                    <option value="protocolo">Protocolo de TT</option>
                    <option value="trabajo-terminal-i">Trabajo Terminal I</option>
                    <option value="trabajo-terminal-ii">Trabajo Terminal II</option>
                </select>

                <label for="no_boleta_alumno">Número de boleta del alumno</label>
                <input type="text" id="no_boleta_alumno" name="no_boleta_alumno" placeholder="Ej. 2021630123" required>

                <label for="resultado">Resultado</label>
                <select id="resultado" name="resultado" required>
                    <option value="">Selecciona una opción</option>
                    <option value="aprobado">Aprobado</option>
                    <option value="observaciones">Con observaciones</option>
                    <option value="rechazado">Rechazado</option>
                </select>

                <label for="comentarios">Comentarios para el alumno</label>
                <textarea id="comentarios" name="comentarios" rows="4" placeholder="Observaciones o retroalimentación"></textarea>

                <button class="button button-primary" type="submit">Registrar dictamen <span>→</span></button>
            </form>
        </section>
    <?php endif; ?>
</div>
<script>
document.querySelectorAll('[data-close-modal]').forEach((element) => {
    element.addEventListener('click', () => { window.location.href = 'panelMiembroCatt.php'; });
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && document.querySelector('.dashboard-modal.is-open')) { window.location.href = 'panelMiembroCatt.php'; }
});
</script>
<?php if ($modal === 'equipos'): ?><script src="assets/js/equipos.js"></script><?php endif; ?>
<?php require __DIR__ . '/../app/views/footer.php'; ?>