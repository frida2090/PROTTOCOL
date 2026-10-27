<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/equipos.php';
require_once __DIR__ . '/../app/protocolos.php';
require_once __DIR__ . '/../app/views/dashboard/sidebar.php';

$user = currentUser();
if (!$user) {
    redirect('login.php');
}
if ($user['rol'] !== 'profesor' || !in_array('sinodal', $user['roles'] ?? [], true)) {
    flash('error', 'Acceso denegado. Solo los sinodales pueden evaluar protocolos.');
    redirect('panelProfesor.php');
}

$formatoId = (int) ($_GET['formato'] ?? $_POST['formato'] ?? 0);
if ($formatoId < 1) {
    flash('error', 'Selecciona un protocolo para evaluar.');
    redirect('panelProfesor.php');
}

$db = database();

// Datos del formato vigente y su equipo.
$formatoQuery = $db->prepare('SELECT fs.FormatoId, fs.EquipoId, fs.Status, fs.FechaSubida, et.TituloProtocolo, et.Descripcion FROM Formatos_Subidos fs JOIN Equipo_TT et ON et.EquipoId = fs.EquipoId WHERE fs.FormatoId = ?');
$formatoQuery->execute([$formatoId]);
$formato = $formatoQuery->fetch();
if (!$formato) {
    flash('error', 'El protocolo seleccionado ya no existe.');
    redirect('panelProfesor.php');
}

$equipoId = (int) $formato['EquipoId'];
$sinodalId = (int) $user['id'];

// Debo estar asignado como sinodal y no haber dictado todavía.
$miAsignacion = $db->prepare("SELECT COUNT(*) FROM Equipo_Asignacion WHERE EquipoId = ? AND UserId = ? AND Rol = 'sinodal'");
$miAsignacion->execute([$equipoId, $sinodalId]);
if ((int) $miAsignacion->fetchColumn() === 0) {
    flash('error', 'No formas parte de la comisión de sinodales de este equipo.');
    redirect('panelProfesor.php');
}
$yaDicto = $db->prepare('SELECT COUNT(*) FROM Sinodal_Evaluacion WHERE FormatoId = ? AND UserId = ?');
$yaDicto->execute([$formatoId, $sinodalId]);
if ((int) $yaDicto->fetchColumn() > 0) {
    flash('error', 'Ya emitiste tu dictamen sobre este protocolo.');
    redirect('panelProfesor.php');
}
if (!in_array($formato['Status'], ['Aprobado', 'AprobadoSinodales'], true)) {
    flash('error', 'Este protocolo ya no está en evaluación.');
    redirect('panelProfesor.php');
}

// Número de TT (por asignar hasta que la CATT lo registre).
$tt = ttDeEquipo($db, $equipoId);
// Versión de la entrega (1a, 2a, ...).
$version = numeroEntregaEquipo($db, $equipoId);
// Mi dictamen anterior sobre la versión previa (si estoy re-evaluando la 2a).
$miDictamenAnterior = null;
if ($version > 1) {
    $antQuery = $db->prepare('SELECT se.Dictamen, se.Observaciones, se.FechaRevision FROM Sinodal_Evaluacion se JOIN Formatos_Subidos fs ON fs.FormatoId = se.FormatoId WHERE se.UserId = ? AND fs.EquipoId = ? AND fs.FormatoId < ? ORDER BY fs.FormatoId DESC LIMIT 1');
    $antQuery->execute([$sinodalId, $equipoId, $formatoId]);
    $miDictamenAnterior = $antQuery->fetch() ?: null;
}

// Datos del sinodal (academia, departamento, contacto).
$sinodalQuery = $db->prepare('SELECT u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno, u.Email AS Correo, e.NumeroEmpleado, ad.Nombre AS DeptNombre, ac.Nombre AS AcadNombre FROM Usuarios u LEFT JOIN Empleado e ON e.UserId = u.UserId LEFT JOIN Academia_Departamento ad ON ad.DeptId = e.DeptId LEFT JOIN Academia ac ON ac.AcadId = ad.AcadId WHERE u.UserId = ?');
$sinodalQuery->execute([$sinodalId]);
$sinodal = $sinodalQuery->fetch() ?: [];

$preguntas = preguntasEvaluacionProtocolo();

$fechaEvaluacion = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValid()) {
        flash('error', 'La sesión expiró. Vuelve a abrir el formulario e intenta de nuevo.');
        redirect('evaluarProtocolo.php?formato=' . $formatoId);
    }

    $dictamen = trim($_POST['dictamen'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');
    $respuestas = is_array($_POST['preguntas'] ?? null) ? $_POST['preguntas'] : [];
    $fechaEvaluacion = trim($_POST['fecha_evaluacion'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaEvaluacion)) {
        $fechaEvaluacion = date('Y-m-d');
    }

    $resultado = guardarDictamen($db, $formatoId, $sinodalId, $dictamen, $observaciones, $fechaEvaluacion, $respuestas);
    if (!$resultado['ok']) {
        flash('error', $resultado['mensaje']);
        redirect('evaluarProtocolo.php?formato=' . $formatoId);
    }
    flash('success', $resultado['mensaje']);
    redirect('panelProfesor.php');
}

$pageTitle = 'Evaluar protocolo · CATT';
require __DIR__ . '/../app/views/header.php';
?>
<div class="dashboard-container">
    <?php renderSidebar('inicio'); ?>
    <main class="dashboard-main">
        <header class="dashboard-topbar">
            <div>
                <p class="eyebrow">Comisión Académica de Trabajos Terminales</p>
                <h1>Evaluación de protocolo</h1>
            </div>
            <a class="button button-primary" href="panelProfesor.php">Volver al panel</a>
        </header>

        <section class="dashboard-panel evaluar-panel">
            <div class="panel-header"><h2>Evaluación para propuestas de Trabajo Terminal</h2></div>

            <div class="dictamen-head">
                <div><strong>No. de registro del TT:</strong> <?= $tt ? e((string) $tt['NumeroTT']) : 'Por asignar' ?></div>
                <div><strong>Título del TT:</strong> <?= e($formato['TituloProtocolo']) ?></div>
                <div><strong>Versión:</strong> <?= $version ?>a</div>
                <div><strong>Fecha de evaluación:</strong> <?= e(date('d/m/Y', strtotime($fechaEvaluacion))) ?></div>
                <a class="protocol-doc-link" href="descargarFormato.php?id=<?= (int) $formato['FormatoId'] ?>" target="_blank">Ver PDF del protocolo</a>
            </div>

            <?php if ($miDictamenAnterior): ?>
                <div class="observaciones-box">
                    <strong>Tu dictamen anterior (<?= e((string) $miDictamenAnterior['Dictamen']) ?>):</strong>
                    <?php if (!empty($miDictamenAnterior['Observaciones'])): ?><p><?= nl2br(e((string) $miDictamenAnterior['Observaciones'])) ?></p><?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="post" class="modal-form evaluar-form">
                <?= csrfField() ?>
                <input type="hidden" name="formato" value="<?= (int) $formatoId ?>">

                <table class="dictamen-table">
                    <thead>
                        <tr>
                            <th>Pregunta</th>
                            <th>SI</th>
                            <th>NO</th>
                            <th>Observaciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preguntas as $num => $texto): ?>
                        <tr>
                            <td><label><?= e($texto) ?></label></td>
                            <td class="dictamen-op"><input type="radio" name="preguntas[<?= $num ?>][respuesta]" value="SI" required></td>
                            <td class="dictamen-op"><input type="radio" name="preguntas[<?= $num ?>][respuesta]" value="NO" required></td>
                            <td><textarea name="preguntas[<?= $num ?>][observaciones]" class="dictamen-obs" rows="2"></textarea></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <fieldset class="dictamen-fieldset">
                    <legend>Dictamen</legend>
                    <label class="radio-inline"><input type="radio" name="dictamen" value="aprobado" required> Aprobado</label>
                    <label class="radio-inline"><input type="radio" name="dictamen" value="rechazado" required> No aprobado</label>
                </fieldset>

                <label for="observaciones">RECOMENDACIONES ADICIONALES</label>
                <textarea id="observaciones" name="observaciones" rows="4" placeholder="Comentarios generales y recomendaciones para el alumno"></textarea>

                <label for="fecha_evaluacion">Fecha de evaluación</label>
                <input type="date" id="fecha_evaluacion" name="fecha_evaluacion" value="<?= e($fechaEvaluacion) ?>" required>

                <dl class="profile-details dictamen-firma">
                    <div><dt>Nombre y firma del sinodal</dt><dd><?= e($sinodal['Nombre'] ?? '') ?> <?= e($sinodal['ApellidoPaterno'] ?? '') ?> <?= e($sinodal['ApellidoMaterno'] ?? '') ?> (<?= e((string) ($sinodal['NumeroEmpleado'] ?? '—')) ?>)</dd></div>
                    <div><dt>Academia</dt><dd><?= e((string) ($sinodal['AcadNombre'] ?? '—')) ?></dd></div>
                    <div><dt>Departamento</dt><dd><?= e((string) ($sinodal['DeptNombre'] ?? '—')) ?></dd></div>
                    <div><dt>Contacto</dt><dd><?= e((string) ($sinodal['Correo'] ?? '')) ?></dd></div>
                </dl>

                <button class="button button-primary" type="submit">Registrar dictamen <span>→</span></button>
            </form>
        </section>
    </main>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
