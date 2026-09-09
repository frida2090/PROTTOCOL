<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/views/dashboard/sidebar.php';

$user = currentUser();
if(!$user){
    redirect('login.php');
}

if($user['rol'] !== 'estudiante') {
    flash('error', 'Acceso denegado. Solo los estudiantes pueden acceder a este panel.');
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['formulario'] ?? '') === 'formato') {
    $tipoFormato = trim($_POST['tipo_formato'] ?? '');
    $formatosPermitidos = ['protocolo', 'trabajo-terminal-i', 'trabajo-terminal-ii', 'reinscripcion'];
    if (in_array($tipoFormato, $formatosPermitidos, true)) {
        flash('success', 'Tu solicitud de formato fue registrada. Próximamente podrás continuar el trámite desde este panel.');
    } else {
        flash('error', 'Selecciona un tipo de trámite válido.');
    }
    redirect('panelAlumno.php?modal=formatos');
}

$pageTitle = 'Panel del Alumno';
require __DIR__ . '/../app/views/header.php';
$modal = $_GET['modal'] ?? '';
$modalPermitido = in_array($modal, ['perfil', 'tramites', 'formatos'], true);

$query = database()->prepare("SELECT fecha, fecha_fin, actividad, descripcion FROM calendario WHERE COALESCE(fecha_fin, fecha) >= CURRENT_DATE ORDER BY fecha ASC LIMIT 3");
$query->execute();
$proximasFechas = $query->fetchAll();
?>
<div class="dashboard-container">
    <?php renderSidebar('inicio'); ?>

    <main class="dashboard-main">
        <header class="dashboard-topbar">
            <div>
                <p class="eyebrow">Bienvenido</p>
                <h1>Panel de alumno</h1>
            </div>
            <a class="button button-primary" href="calendario.php">Ver calendario</a>
        </header>

        <section class="dashboard-grid">
            <article class="dashboard-card highlight">
                <p class="dashboard-kicker">Paso 1</p>
                <h2>Protocolo de TT</h2>
                <p class="card-copy">Inicia el proceso para tu Trabajo Terminal subiendo tu Protocolo a la plataforma con los requisitos necesarios.</p>
            </article>

            <article class="dashboard-card">
                <p class="dashboard-kicker">Paso 2</p>
                <h2>Trabajo Terminal I</h2>
                <p class="card-copy">Si cumples con los requisitos necesarios en tu trayectoria escolar, podras inscribir TTI</p>
                <div class="stat-row">
                    <strong>70%</strong>
                    <span>de creditos</span>
                </div>
            </article>

            <article class="dashboard-card">
                <p class="dashboard-kicker">Paso 3</p>
                <h2>Trabajo Terminal II</h2>
                <p class="card-copy">Continúa con el proceso de tu Trabajo Terminal II, una vez que hayas aprobado TTI</p>
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
            <p class="eyebrow">Mi cuenta</p><h2 id="modal-perfil-title">Datos personales</h2><p class="modal-intro">Información asociada a tu cuenta de estudiante.</p>
            <dl class="profile-details"><div><dt>Nombre completo</dt><dd><?= e($user['nombre']) ?></dd></div><div><dt>Correo electrónico</dt><dd><?= e($user['correo']) ?></dd></div><div><dt>Número de boleta</dt><dd><?= e($user['noBoleta']) ?></dd></div><div><dt>Rol</dt><dd>Estudiante</dd></div></dl>
        </section>
    <?php elseif ($modal === 'tramites'): ?>
        <section class="dashboard-modal-content" aria-labelledby="modal-tramites-title">
            <button class="modal-close" type="button" data-close-modal aria-label="Cerrar">×</button>
            <p class="eyebrow">Seguimiento</p><h2 id="modal-tramites-title">Mis trámites</h2><div class="empty-modal"><span>—</span><h3>No tienes trámites iniciados</h3><p>Cuando comiences un proceso académico, aquí podrás consultar su avance.</p><a class="button button-primary" href="panelAlumno.php?modal=formatos">Iniciar un trámite</a></div>
        </section>
    <?php elseif ($modal === 'formatos'): ?>
        <section class="dashboard-modal-content" aria-labelledby="modal-formatos-title">
            <button class="modal-close" type="button" data-close-modal aria-label="Cerrar">×</button>
            <p class="eyebrow">Nueva solicitud</p><h2 id="modal-formatos-title">Selecciona un trámite</h2><p class="modal-intro">Elige el formato que quieres solicitar para comenzar tu proceso.</p>
            <form method="post" class="modal-form"><input type="hidden" name="formulario" value="formato"><label for="tipo_formato">Tipo de trámite</label><select id="tipo_formato" name="tipo_formato" required><option value="">Selecciona una opción</option><option value="protocolo">Registro de protocolo</option><option value="trabajo-terminal-i">Trabajo Terminal I</option><option value="trabajo-terminal-ii">Trabajo Terminal II</option><option value="reinscripcion">Reinscripción o seguimiento</option></select><button class="button button-primary" type="submit">Continuar solicitud <span>→</span></button></form>
        </section>
    <?php endif; ?>
</div>
<script>
document.querySelectorAll('[data-close-modal]').forEach((element) => {
    element.addEventListener('click', () => { window.location.href = 'panelAlumno.php'; });
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && document.querySelector('.dashboard-modal.is-open')) { window.location.href = 'panelAlumno.php'; }
});
</script>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
