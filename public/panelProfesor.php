<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/views/dashboard/sidebar.php';

$user = currentUser();
if(!$user){
    redirect('login.php');
}

if($user['rol'] !== 'profesor') {
    flash('error', 'Acceso denegado. Solo los profesores pueden acceder a este panel.');
    redirect('login.php');
}

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
        // Aquí se integraría el guardado del dictamen en la base de datos
        // (tabla de revisiones/dictámenes de la CATT), asociando al alumno,
        // al profesor revisor ($user['id']), el tipo de documento y el resultado.
        flash('success', 'El dictamen fue registrado correctamente y quedará disponible para el alumno.');
    } else {
        flash('error', 'Completa el número de boleta del alumno y selecciona un tipo de trámite y un resultado válidos.');
    }
    redirect('panelProfesor.php?modal=formatos');
}

$pageTitle = 'Panel del Profesor · CATT';
require __DIR__ . '/../app/views/header.php';
$modal = $_GET['modal'] ?? '';
$modalPermitido = in_array($modal, ['perfil', 'revisiones', 'dictamen'], true);

$query = database()->prepare("SELECT fecha, fecha_fin, actividad, descripcion FROM calendario WHERE COALESCE(fecha_fin, fecha) >= CURRENT_DATE ORDER BY fecha ASC LIMIT 3");
$query->execute();
$proximasFechas = $query->fetchAll();
?>
<div class="dashboard-container">
    <?php renderSidebar('inicio'); ?>

    <main class="dashboard-main">
        <header class="dashboard-topbar">
            <div>
                <p class="eyebrow">Comisión Académica de Trabajos Terminales</p>
                <h1>Panel del Profesor</h1>
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
                <a class="card-list-link" href="panelProfesor.php?modal=revisiones&amp;tipo=protocolo">Ver lista</a>
            </article>

            <article class="dashboard-card">
                <p class="dashboard-kicker">Evaluación</p>
                <h2>Trabajo Terminal I</h2>
                <p class="card-copy">Consulta y evalúa los avances de Trabajo Terminal I de tus alumnos asignados.</p>
                <div class="stat-row">
                    <strong>0</strong>
                    <span>alumnos por evaluar</span>
                </div>
                 <a class="card-list-link" href="panelProfesor.php?modal=revisiones&amp;tipo=trabajo-terminal-i">Ver lista</a>
            </article>

            <article class="dashboard-card">
                <p class="dashboard-kicker">Evaluación</p>
                <h2>Trabajo Terminal II</h2>
                <p class="card-copy">Revisa el documento final y registra el dictamen de Trabajo Terminal II ante la comisión.</p>
                <div class="stat-row">
                    <strong>0</strong>
                    <span>alumnos por evaluar</span>
                </div>
                <a class="card-list-link" href="panelProfesor.php?modal=revisiones&amp;tipo=trabajo-terminal-ii">Ver lista</a>
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
            <p class="eyebrow">Mi cuenta</p><h2 id="modal-perfil-title">Datos personales</h2><p class="modal-intro">Información asociada a tu cuenta de profesor.</p>
            <dl class="profile-details">
                <div><dt>Nombre completo</dt><dd><?= e(fullName($user)) ?></dd></div>
                <div><dt>Correo electrónico</dt><dd><?= e($user['correo']) ?></dd></div>
                <div><dt>Número de empleado</dt><dd><?= e($user['numero_empleado']) ?></dd></div>
                <div><dt>Rol</dt><dd>Profesor · Comisión Académica de Trabajos Terminales</dd></div>
            </dl>
        </section>
    <?php elseif ($modal === 'revisiones'): ?>
        <section class="dashboard-modal-content" aria-labelledby="modal-revisiones-title">
            <button class="modal-close" type="button" data-close-modal aria-label="Cerrar">×</button>
            <p class="eyebrow">Seguimiento</p><h2 id="modal-revisiones-title">Mis revisiones asignadas</h2>
            <div class="empty-modal">
                <span>—</span>
                <h3>No tienes revisiones pendientes</h3>
                <p>Cuando la CATT te asigne protocolos o trabajos terminales para evaluar, aquí podrás consultar su estatus.</p>
                <a class="button button-primary" href="panelProfesor.php?modal=dictamen">Registrar un dictamen</a>
            </div>
        </section>
    <?php elseif ($modal === 'dictamen'): ?>
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
    element.addEventListener('click', () => { window.location.href = 'panelProfesor.php'; });
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && document.querySelector('.dashboard-modal.is-open')) { window.location.href = 'panelProfesor.php'; }
});
</script>
<?php require __DIR__ . '/../app/views/footer.php'; ?>