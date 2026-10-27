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

$formatoId = (int) ($_GET['formato'] ?? 0);
$db = database();
$formatoQuery = $db->prepare(
    'SELECT fs.FormatoId, fs.EquipoId, fs.Status, fs.FechaSubida, fs.Observaciones, fs.UserId AS RevisorUserId, fs.FechaRevision, '
    . 'et.TituloProtocolo, et.Descripcion, revisor.Nombre AS RevisorNombre, revisor.ApellidoPaterno AS RevisorApellidoPaterno, revisor.ApellidoMaterno AS RevisorApellidoMaterno, '
    . 'revisor.Email AS RevisorCorreo, revisorEmpleado.NumeroEmpleado AS RevisorNumeroEmpleado '
    . 'FROM Formatos_Subidos fs '
    . "JOIN Catalogo_Formatos cf ON cf.TipoFormatoId = fs.TipoFormatoId AND cf.Clave = 'Protocolo' "
    . 'JOIN Equipo_TT et ON et.EquipoId = fs.EquipoId '
    . 'LEFT JOIN Usuarios revisor ON revisor.UserId = fs.UserId '
    . 'LEFT JOIN Empleado revisorEmpleado ON revisorEmpleado.UserId = revisor.UserId '
    . 'WHERE fs.FormatoId = ?'
);
$formatoQuery->execute([$formatoId]);
$formato = $formatoQuery->fetch();
if (!$formato) {
    flash('error', 'La evaluación de protocolo solicitada no existe.');
    redirect('panelAlumno.php');
}
if (!puedeVerFormato($db, $user, $formato)) {
    flash('error', 'No tienes permiso para consultar esta evaluación.');
    $destino = match ($user['rol']) {
        'admin' => 'panelAdmin.php',
        'miembroCatt' => 'panelMiembroCatt.php',
        'profesor' => 'panelProfesor.php',
        default => 'panelAlumno.php',
    };
    redirect($destino);
}

$equipo = equipoPorId($db, (int) $formato['EquipoId']);
$evaluaciones = evaluacionesDeFormato($db, $formatoId);
$versionQuery = $db->prepare(
    'SELECT COUNT(*) FROM Formatos_Subidos fs '
    . "JOIN Catalogo_Formatos cf ON cf.TipoFormatoId = fs.TipoFormatoId AND cf.Clave = 'Protocolo' "
    . 'WHERE fs.EquipoId = ? AND fs.FormatoId <= ?'
);
$versionQuery->execute([(int) $formato['EquipoId'], $formatoId]);
$version = (int) $versionQuery->fetchColumn();
$integrantesTexto = implode(', ', array_map(
    static fn (array $integrante): string => nombreDeUsuario($integrante) . ' · Boleta ' . $integrante['NumeroBoleta'] . ($integrante['EsRepresentante'] ? ' (representante)' : ''),
    $equipo['integrantes']
));
$docentesTexto = implode(', ', array_map(
    static fn (array $docente): string => nombreDeUsuario($docente) . ' · ' . etiquetaRolEquipo($docente['Rol']),
    $equipo['docentes']
));
$preguntas = preguntasEvaluacionProtocolo();
$dictamenCatt = match ($formato['Status']) {
    'Pendiente' => 'Pendiente de dictamen',
    'Rechazado' => 'Rechazado',
    default => 'Aprobado',
};
$revisorCatt = trim(implode(' ', array_filter([
    $formato['RevisorNombre'],
    $formato['RevisorApellidoPaterno'],
    $formato['RevisorApellidoMaterno'],
])));
$volver = match ($user['rol']) {
    'admin' => 'panelAdmin.php',
    'miembroCatt' => 'panelMiembroCatt.php',
    'profesor' => 'panelProfesor.php',
    default => 'panelAlumno.php',
};

$pageTitle = 'Dictámenes del protocolo · CATT';
require __DIR__ . '/../app/views/header.php';
?>
<div class="dashboard-container">
    <?php renderSidebar('inicio'); ?>
    <main class="dashboard-main">
        <header class="dashboard-topbar">
            <div>
                <p class="eyebrow">Comisión Académica de Trabajos Terminales</p>
                <h1>Dictámenes del protocolo</h1>
            </div>
            <a class="button button-primary" href="<?= e($volver) ?>">Volver al panel</a>
        </header>

        <section class="dashboard-panel">
            <div class="panel-header"><h2><?= e($formato['TituloProtocolo']) ?></h2></div>
            <dl class="profile-details">
                <div><dt>Equipo</dt><dd>#<?= (int) $formato['EquipoId'] ?></dd></div>
                <div><dt>Versión</dt><dd><?= $version ?>a</dd></div>
                <div><dt>Estado actual</dt><dd><?= e(etiquetaEstadoProtocolo((string) $formato['Status'])) ?></dd></div>
                <div><dt>Enviado</dt><dd><?= e(date('d/m/Y', strtotime((string) $formato['FechaSubida']))) ?></dd></div>
                <div><dt>Dictamen CATT</dt><dd><?= e($dictamenCatt) ?></dd></div>
                <div><dt>Revisó CATT</dt><dd><?= e($revisorCatt !== '' ? $revisorCatt : '—') ?></dd></div>
                <div><dt>Contacto CATT</dt><dd><?= e((string) ($formato['RevisorCorreo'] ?? '—')) ?></dd></div>
                <div><dt>Número de empleado CATT</dt><dd><?= e((string) ($formato['RevisorNumeroEmpleado'] ?? '—')) ?></dd></div>
                <div><dt>Fecha de revisión CATT</dt><dd><?= !empty($formato['FechaRevision']) ? e(date('d/m/Y', strtotime((string) $formato['FechaRevision']))) : '—' ?></dd></div>
            </dl>
            <?php if ($formato['Status'] === 'Rechazado' && !empty($formato['Observaciones'])): ?>
                <div class="observaciones-box">
                    <strong>Observaciones de la CATT</strong>
                    <p><?= nl2br(e((string) $formato['Observaciones'])) ?></p>
                </div>
            <?php endif; ?>
            <p><a class="button button-dark" href="descargarFormato.php?id=<?= (int) $formatoId ?>" target="_blank">Ver PDF del protocolo</a></p>
        </section>

        <section class="dashboard-panel">
            <div class="panel-header"><h2>Alumno(s) y docentes asignados</h2></div>
            <ul class="protocol-pending-people">
                <li><strong>Integrantes:</strong> <?= e($integrantesTexto !== '' ? $integrantesTexto : '—') ?></li>
                <li><strong>Docentes:</strong> <?= e($docentesTexto !== '' ? $docentesTexto : '—') ?></li>
            </ul>
        </section>

        <section class="dashboard-panel">
            <div class="panel-header"><h2>Evaluaciones de sinodales</h2><span><?= count($evaluaciones) ?></span></div>
            <?php if (!$evaluaciones): ?>
                <p>Este protocolo todavía no tiene dictámenes de sinodales.</p>
            <?php else: ?>
                <?php foreach ($evaluaciones as $evaluacion): ?>
                    <article class="protocol-pending">
                        <header class="protocol-pending-head">
                            <div>
                                <strong><?= e(trim($evaluacion['Nombre'] . ' ' . $evaluacion['ApellidoPaterno'] . ' ' . $evaluacion['ApellidoMaterno'])) ?></strong>
                                <small>Fecha de evaluación: <?= e(date('d/m/Y', strtotime((string) $evaluacion['FechaRevision']))) ?></small>
                                <small>Empleado: <?= e((string) ($evaluacion['NumeroEmpleado'] ?? '—')) ?> · Academia: <?= e((string) ($evaluacion['AcadNombre'] ?? '—')) ?> · Departamento: <?= e((string) ($evaluacion['DeptNombre'] ?? '—')) ?> · Correo: <?= e((string) $evaluacion['Email']) ?></small>
                            </div>
                            <span class="status-chip status-chip--<?= $evaluacion['Dictamen'] === 'aprobado' ? 'aprobado' : 'rechazado' ?>"><?= e(ucfirst((string) $evaluacion['Dictamen'])) ?></span>
                        </header>
                        <?php if ($evaluacion['Respuestas']): ?>
                            <table class="dictamen-table">
                                <thead><tr><th>Pregunta</th><th>Respuesta</th><th>Observaciones</th></tr></thead>
                                <tbody>
                                    <?php foreach ($evaluacion['Respuestas'] as $respuesta): ?>
                                        <tr>
                                            <td><?= e($preguntas[(int) $respuesta['NumeroPregunta']] ?? 'Pregunta') ?></td>
                                            <td><?= e((string) $respuesta['Respuesta']) ?></td>
                                            <td class="dictamen-obs"><?= !empty($respuesta['Observaciones']) ? nl2br(e((string) $respuesta['Observaciones'])) : '—' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p class="field-hint">Este dictamen fue registrado antes de habilitar el guardado de respuestas detalladas.</p>
                        <?php endif; ?>
                        <div class="observaciones-box">
                            <strong>Dictamen general: <?= e(ucfirst((string) $evaluacion['Dictamen'])) ?></strong>
                            <p><?= !empty($evaluacion['Observaciones']) ? nl2br(e((string) $evaluacion['Observaciones'])) : 'Sin observaciones generales.' ?></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
