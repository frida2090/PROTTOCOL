<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/views/dashboard/sidebar.php';
require_once __DIR__ . '/../app/equipos.php';
require_once __DIR__ . '/../app/protocolos.php';

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

// Autorización de protocolos enviados por los alumnos (asigna los 3 sinodales)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['formulario'] ?? '') === 'autorizar_protocolo') {
    $formatoId = (int) ($_POST['formato_id'] ?? 0);
    $sinodales = enteros($_POST['sinodales'] ?? []);

    try {
        if (!csrfValid()) {
            throw new DomainException('La sesión expiró. Vuelve a abrir la lista e intenta de nuevo.');
        }
        if ($formatoId < 1) {
            throw new DomainException('Selecciona un protocolo válido.');
        }
        if (count($sinodales) !== 3) {
            throw new DomainException('Selecciona exactamente tres sinodales para autorizar el protocolo.');
        }

        $connection = database();
        $connection->beginTransaction();

        $formatoQuery = $connection->prepare('SELECT FormatoId, Status, EquipoId FROM Formatos_Subidos WHERE FormatoId = ? FOR UPDATE');
        $formatoQuery->execute([$formatoId]);
        $formato = $formatoQuery->fetch();
        if (!$formato) {
            throw new DomainException('El protocolo seleccionado ya no existe.');
        }
        if ($formato['Status'] !== 'Pendiente') {
            throw new DomainException('El protocolo ya fue procesado por la CATT.');
        }
        $equipoId = (int) $formato['EquipoId'];

        $marcas = implode(', ', array_fill(0, count($sinodales), '?'));
        $rolQuery = $connection->prepare("SELECT DISTINCT u.UserId FROM Usuarios u JOIN Usuario_Roles ur ON ur.UserId = u.UserId AND ur.Rol = 'sinodal' WHERE u.UserId IN ({$marcas}) FOR UPDATE");
        $rolQuery->execute($sinodales);
        if (count($rolQuery->fetchAll()) !== 3) {
            throw new DomainException('Algún seleccionado no existe o no tiene el rol de sinodal.');
        }

        $enEquipoQuery = $connection->prepare("SELECT UserId FROM Equipo_Asignacion WHERE EquipoId = ? AND UserId IN ({$marcas})");
        $enEquipoQuery->execute(array_merge([$equipoId], $sinodales));
        if ($enEquipoQuery->fetch()) {
            throw new DomainException('Algún sinodal seleccionado ya está asignado a este equipo (por ejemplo, es uno de sus directores).');
        }

        $limitQuery = $connection->prepare("SELECT UserId, COUNT(*) AS total FROM EmpleadoEquipo WHERE UserId IN ({$marcas}) GROUP BY UserId HAVING total >= " . MAX_EQUIPOS_POR_DOCENTE);
        $limitQuery->execute($sinodales);
        if ($limitQuery->fetch()) {
            throw new DomainException('Algún sinodal seleccionado ya alcanzó el máximo de ' . MAX_EQUIPOS_POR_DOCENTE . ' equipos.');
        }

        $update = $connection->prepare("UPDATE Formatos_Subidos SET Status = 'Aprobado', UserId = ?, FechaRevision = CURRENT_TIMESTAMP WHERE FormatoId = ? AND Status = 'Pendiente'");
        $update->execute([(int) $user['id'], $formatoId]);
        if ($update->rowCount() !== 1) {
            throw new DomainException('El protocolo ya fue procesado por la CATT.');
        }

        $assign = $connection->prepare('INSERT INTO Equipo_Asignacion (EquipoId, UserId, Rol, AsignadoPorUserId) VALUES (?, ?, ?, ?)');
        $link = $connection->prepare('INSERT IGNORE INTO EmpleadoEquipo (UserId, EquipoId) VALUES (?, ?)');
        foreach ($sinodales as $sinodalId) {
            $assign->execute([$equipoId, $sinodalId, 'sinodal', (int) $user['id']]);
            $link->execute([$sinodalId, $equipoId]);
        }

        $connection->commit();
        flash('success', 'El protocolo fue autorizado y los tres sinodales quedaron asignados al equipo.');
    } catch (DomainException $exception) {
        if (isset($connection) && $connection->inTransaction()) {
            $connection->rollBack();
        }
        flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        if (isset($connection) && $connection->inTransaction()) {
            $connection->rollBack();
        }
        flash('error', 'No fue posible autorizar el protocolo. Intenta de nuevo.');
    }
    redirect('panelMiembroCatt.php?modal=revisiones');
}

// Rechazo de protocolos: se marca como rechazado con observaciones para que el
// alumno corrija y pueda volver a enviar (no se borra nada).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['formulario'] ?? '') === 'rechazar_protocolo') {
    $formatoId = (int) ($_POST['formato_id'] ?? 0);
    $observaciones = trim($_POST['observaciones_rechazo'] ?? '');

    try {
        if (!csrfValid()) {
            throw new DomainException('La sesión expiró. Vuelve a abrir la lista e intenta de nuevo.');
        }
        if ($formatoId < 1) {
            throw new DomainException('Selecciona un protocolo válido.');
        }
        if ($observaciones === '') {
            throw new DomainException('Captura las observaciones de corrección para el alumno.');
        }

        $connection = database();
        $connection->beginTransaction();

        $formatoQuery = $connection->prepare('SELECT FormatoId, Status, EquipoId FROM Formatos_Subidos WHERE FormatoId = ? FOR UPDATE');
        $formatoQuery->execute([$formatoId]);
        $formato = $formatoQuery->fetch();
        if (!$formato) {
            throw new DomainException('El protocolo seleccionado ya no existe.');
        }
        if ($formato['Status'] !== 'Pendiente') {
            throw new DomainException('El protocolo ya fue procesado por la CATT.');
        }

        $update = $connection->prepare("UPDATE Formatos_Subidos SET Status = 'Rechazado', Observaciones = ?, UserId = ?, FechaRevision = CURRENT_TIMESTAMP WHERE FormatoId = ? AND Status = 'Pendiente'");
        $update->execute([$observaciones, (int) $user['id'], $formatoId]);
        if ($update->rowCount() !== 1) {
            throw new DomainException('El protocolo ya fue procesado por la CATT.');
        }

        $connection->commit();
        flash('success', 'El protocolo fue rechazado con observaciones. El alumno podrá corregirlo y volver a enviarlo.');
    } catch (DomainException $exception) {
        if (isset($connection) && $connection->inTransaction()) {
            $connection->rollBack();
        }
        flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        if (isset($connection) && $connection->inTransaction()) {
            $connection->rollBack();
        }
        flash('error', 'No fue posible rechazar el protocolo. Intenta de nuevo.');
    }
    redirect('panelMiembroCatt.php?modal=revisiones');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['formulario'] ?? '') === 'equipo') {
    try {
        crearEquipoTT(
            database(),
            (int) $user['id'],
            trim($_POST['titulo_equipo'] ?? ''),
            trim($_POST['descripcion_equipo'] ?? ''),
            enteros($_POST['estudiantes'] ?? []),
            enteros($_POST['directores'] ?? []),
            enteros($_POST['sinodales'] ?? []),
            (int) ($_POST['seguimiento'] ?? 0)
        );
        flash('success', 'El equipo de TT fue registrado y sus directores, sinodales y profesor de seguimiento quedaron asignados.');
    } catch (DomainException $exception) {
        flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        flash('error', 'No fue posible registrar el equipo. Intenta de nuevo.');
    }
    redirect('panelMiembroCatt.php?modal=equipos');
}

// Asignación del Número de TT a un protocolo aprobado por los sinodales
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['formulario'] ?? '') === 'asignar_numero_tt') {
    $equipoId = (int) ($_POST['equipo_id'] ?? 0);
    $numeroTT = trim($_POST['numero_tt'] ?? '');

    try {
        if (!csrfValid()) {
            throw new DomainException('La sesión expiró. Vuelve a abrir la lista e intenta de nuevo.');
        }
        if ($equipoId < 1) {
            throw new DomainException('Selecciona un protocolo válido.');
        }
        $resultado = asignarNumeroTT(database(), $equipoId, $numeroTT, (int) $user['id']);
        if (!$resultado['ok']) {
            throw new DomainException($resultado['mensaje']);
        }
        flash('success', $resultado['mensaje']);
    } catch (DomainException $exception) {
        flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        flash('error', 'No fue posible asignar el número de TT. Intenta de nuevo.');
    }
    redirect('panelMiembroCatt.php?modal=numero_tt');
}

$pageTitle = 'Panel del Miembro · CATT';
require __DIR__ . '/../app/views/header.php';
$modal = $_GET['modal'] ?? '';
$modalPermitido = in_array($modal, ['perfil', 'revisiones', 'formatos', 'equipos', 'numero_tt'], true);

$estudiantesLibres = $directoresDisponibles = $sinodalesDisponibles = $docentesDisponibles = $equiposRegistrados = $protocolosPendientes = [];
$db = database();
$porRol = static function (string $rol) use ($db): array {
    $query = $db->prepare('SELECT u.UserId, u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno FROM Usuarios u JOIN Usuario_Roles ur ON ur.UserId = u.UserId WHERE ur.Rol = ? ORDER BY u.ApellidoPaterno, u.ApellidoMaterno, u.Nombre');
    $query->execute([$rol]);
    return $query->fetchAll();
};
if ($modal === 'equipos') {
    $estudiantesLibres = $db->query("SELECT u.UserId, u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno FROM Usuarios u JOIN Usuario_Roles ur ON ur.UserId = u.UserId AND ur.Rol = 'estudiante' JOIN Estudiante e ON e.UserId = u.UserId WHERE e.EquipoId IS NULL ORDER BY u.ApellidoPaterno, u.ApellidoMaterno, u.Nombre")->fetchAll();
    $directoresDisponibles = $porRol('director');
    $sinodalesDisponibles = $porRol('sinodal');
    $docentesDisponibles = $db->query("SELECT DISTINCT u.UserId, u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno FROM Usuarios u JOIN Usuario_Roles ur ON ur.UserId = u.UserId AND ur.Rol IN ('director', 'sinodal') ORDER BY u.ApellidoPaterno, u.ApellidoMaterno, u.Nombre")->fetchAll();
    $equiposRegistrados = array_map(
        static fn (array $equipo): array => equipoCompleto($db, $equipo),
        $db->query('SELECT EquipoId, TituloProtocolo, Descripcion, RepresentanteUserId FROM Equipo_TT ORDER BY EquipoId DESC')->fetchAll()
    );
}
if ($modal === 'revisiones') {
    foreach (protocolosPendientesDeRevision($db) as $pendiente) {
        $equipoId = (int) $pendiente['EquipoId'];
        $pendiente['integrantes'] = integrantesDeEquipo($db, $equipoId);
        $pendiente['docentes'] = docentesDeEquipo($db, $equipoId);
        $pendiente['sinodalesDisponibles'] = sinodalesElegiblesParaEquipo($db, $equipoId);
        $protocolosPendientes[] = $pendiente;
    }
}
$protocolosListosTT = [];
if ($modal === 'numero_tt') {
    foreach (protocolosListosParaNumeroTT($db) as $pendiente) {
        $equipoId = (int) $pendiente['EquipoId'];
        $pendiente['integrantes'] = integrantesDeEquipo($db, $equipoId);
        $pendiente['docentes'] = docentesDeEquipo($db, $equipoId);
        $protocolosListosTT[] = $pendiente;
    }
}
$protocolosConDictamenes = protocolosRevisados($db);

$query = database()->prepare("SELECT c.fecha, c.fecha_fin, c.actividad, c.descripcion FROM calendario c WHERE COALESCE(c.fecha_fin, c.fecha) >= CURRENT_DATE AND (c.tipo_tt IS NULL OR EXISTS (SELECT 1 FROM calendario_participante cp WHERE cp.calendario_id = c.id AND cp.UserId = ?)) ORDER BY c.fecha ASC LIMIT 3");
$query->execute([$user['id']]);
$proximasFechas = $query->fetchAll();
$protocolosPendientesCount = (int) database()->query("SELECT COUNT(*) FROM Formatos_Subidos fs JOIN Catalogo_Formatos cf ON cf.TipoFormatoId = fs.TipoFormatoId AND cf.Clave = 'Protocolo' WHERE fs.Status = 'Pendiente'")->fetchColumn();
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
                    <strong><?= $protocolosPendientesCount ?></strong>
                    <span>pendientes por revisar</span>
                </div>
                <a class="card-list-link" href="panelMiembroCatt.php?modal=revisiones&amp;tipo=protocolo">Ver lista</a>
                <a class="card-list-link" href="panelMiembroCatt.php?modal=numero_tt">Asignar número de TT</a>
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
            <div class="panel-header"><h2>Dictámenes de protocolos</h2><span><?= count($protocolosConDictamenes) ?></span></div>
            <div class="activity-summary">
                <?php if (!$protocolosConDictamenes): ?>
                    <p>Aún no hay dictámenes de sinodales registrados.</p>
                <?php else: foreach ($protocolosConDictamenes as $protocoloEvaluado): ?>
                    <article class="summary-item">
                        <span class="summary-date">TT</span>
                        <div>
                            <strong><?= e($protocoloEvaluado['TituloProtocolo']) ?></strong>
                            <small>Equipo #<?= (int) $protocoloEvaluado['EquipoId'] ?> · <?= e(etiquetaEstadoProtocolo((string) $protocoloEvaluado['Status'])) ?></small>
                            <a class="protocol-doc-link" href="verEvaluacionProtocolo.php?formato=<?= (int) $protocoloEvaluado['FormatoId'] ?>">Consultar respuestas y dictámenes</a>
                        </div>
                    </article>
                <?php endforeach; endif; ?>
            </div>
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
        <section class="dashboard-modal-content modal-wide" aria-labelledby="modal-revisiones-title">
            <button class="modal-close" type="button" data-close-modal aria-label="Cerrar">×</button>
            <p class="eyebrow">Seguimiento</p><h2 id="modal-revisiones-title">Protocolos por revisar</h2>
            <?php if (!$protocolosPendientes): ?>
                <div class="empty-modal">
                    <span>—</span>
                    <h3>No hay protocolos pendientes</h3>
                    <p>Cuando un alumno envíe su protocolo aparecerá aquí para que lo autorices con sus tres sinodales.</p>
                </div>
            <?php else: ?>
                <p class="modal-intro">Revisa el documento y, si está completo, autorízalo asignando los tres sinodales del equipo.</p>
                <?php foreach ($protocolosPendientes as $pendiente): ?>
                    <article class="protocol-pending">
                        <header class="protocol-pending-head">
                            <div>
                                <strong><?= e($pendiente['TituloProtocolo']) ?></strong>
                                <small>Equipo #<?= (int) $pendiente['EquipoId'] ?> · enviado el <?= e(date('d/m/Y', strtotime($pendiente['FechaSubida']))) ?></small>
                            </div>
                            <span class="status-chip status-chip--pendiente">Pendiente</span>
                        </header>
                        <p class="protocol-pending-desc"><?= e($pendiente['Descripcion']) ?></p>
                        <ul class="protocol-pending-people">
                            <li><strong>Integrantes:</strong> <?= e(implode(', ', array_map(static fn (array $i): string => nombreDeUsuario($i) . ($i['EsRepresentante'] ? ' (representante)' : ''), $pendiente['integrantes'])) ?: '—') ?></li>
                            <li><strong>Directores:</strong> <?= e(implode(', ', array_map(static fn (array $d): string => nombreDeUsuario($d) . ' · ' . etiquetaRolEquipo($d['Rol']), $pendiente['docentes'])) ?: '—') ?></li>
                        </ul>
                        <div class="protocol-pending-actions">
                            <a class="button button-dark" href="descargarFormato.php?id=<?= (int) $pendiente['FormatoId'] ?>" target="_blank">Ver PDF</a>
                            <details class="protocol-review">
                                <summary>Autorizar y asignar sinodales</summary>
                                <form method="post" class="protocol-review-form">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="formulario" value="autorizar_protocolo">
                                    <input type="hidden" name="formato_id" value="<?= (int) $pendiente['FormatoId'] ?>">
                                    <label for="sinodales-<?= (int) $pendiente['FormatoId'] ?>">Selecciona exactamente tres sinodales (usa Ctrl para elegir varios)</label>
                                    <select id="sinodales-<?= (int) $pendiente['FormatoId'] ?>" name="sinodales[]" multiple size="6" class="multi-select" required>
                                        <?php foreach ($pendiente['sinodalesDisponibles'] as $sinodal): ?>
                                            <option value="<?= e((string) $sinodal['UserId']) ?>"><?= e(nombreDeUsuario($sinodal)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (count($pendiente['sinodalesDisponibles']) < 3): ?>
                                        <p class="field-hint">Hay menos de tres sinodales elegibles para este equipo (los directores del propio protocolo no se ofrecen como sinodales). Registra más docentes con rol sinodal para poder autorizar.</p>
                                    <?php endif; ?>
                                    <button class="button button-primary" type="submit">Autorizar protocolo</button>
                                </form>
                            </details>
                            <form method="post" class="protocol-reject-form">
                                <?= csrfField() ?>
                                <input type="hidden" name="formulario" value="rechazar_protocolo">
                                <input type="hidden" name="formato_id" value="<?= (int) $pendiente['FormatoId'] ?>">
                                <label class="protocol-reject-label" for="obs-rechazo-<?= (int) $pendiente['FormatoId'] ?>">Observaciones de corrección (obligatorias)</label>
                                <textarea id="obs-rechazo-<?= (int) $pendiente['FormatoId'] ?>" name="observaciones_rechazo" rows="3" required placeholder="Describe lo que el alumno debe corregir."></textarea>
                                <button class="protocol-reject" type="submit" onclick="return confirm('¿Rechazar este protocolo con las observaciones indicadas?');">Rechazar</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    <?php elseif ($modal === 'numero_tt'): ?>
        <section class="dashboard-modal-content modal-wide" aria-labelledby="modal-numero-tt-title">
            <button class="modal-close" type="button" data-close-modal aria-label="Cerrar">×</button>
            <p class="eyebrow">Asignación de TT</p><h2 id="modal-numero-tt-title">Protocolos aprobados · asignar número de TT</h2>
            <?php if (!$protocolosListosTT): ?>
                <div class="empty-modal">
                    <span>—</span>
                    <h3>No hay protocolos aprobados sin número de TT</h3>
                    <p>Cuando los sinodales aprueben un protocolo aparecerá aquí para que le asignes su número de TT.</p>
                </div>
            <?php else: ?>
                <p class="modal-intro">Estos protocolos fueron aprobados por la comisión de sinodales. Asigna a cada uno su número de TT para registrarlo en el sistema.</p>
                <?php foreach ($protocolosListosTT as $pendiente): ?>
                    <article class="protocol-pending">
                        <header class="protocol-pending-head">
                            <div>
                                <strong><?= e($pendiente['TituloProtocolo']) ?></strong>
                                <small>Equipo #<?= (int) $pendiente['EquipoId'] ?> · aprobado el <?= !empty($pendiente['FechaRevision']) ? e(date('d/m/Y', strtotime($pendiente['FechaRevision']))) : '—' ?></small>
                            </div>
                            <span class="status-chip status-chip--aprobado">Aprobado</span>
                        </header>
                        <p class="protocol-pending-desc"><?= e($pendiente['Descripcion']) ?></p>
                        <ul class="protocol-pending-people">
                            <li><strong>Integrantes:</strong> <?= e(implode(', ', array_map(static fn (array $i): string => nombreDeUsuario($i) . ($i['EsRepresentante'] ? ' (representante)' : ''), $pendiente['integrantes'])) ?: '—') ?></li>
                            <li><strong>Directores:</strong> <?= e(implode(', ', array_map(static fn (array $d): string => nombreDeUsuario($d) . ' · ' . etiquetaRolEquipo($d['Rol']), $pendiente['docentes'])) ?: '—') ?></li>
                        </ul>
                        <form method="post" class="protocol-reject-form">
                            <?= csrfField() ?>
                            <input type="hidden" name="formulario" value="asignar_numero_tt">
                            <input type="hidden" name="equipo_id" value="<?= (int) $pendiente['EquipoId'] ?>">
                            <label for="numero-tt-<?= (int) $pendiente['EquipoId'] ?>">Número de TT (ej. TT-2026-001)</label>
                            <input type="text" id="numero-tt-<?= (int) $pendiente['EquipoId'] ?>" name="numero_tt" maxlength="20" required placeholder="TT-2026-001">
                            <button class="button button-primary" type="submit">Asignar número de TT</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    <?php elseif ($modal === 'equipos'): ?>
        <?php
        $opciones = static function (array $usuarios): void {
            foreach ($usuarios as $usuario) {
                echo '<option value="' . e((string) $usuario['UserId']) . '">' . e(nombreDeUsuario($usuario)) . '</option>';
            }
        };
        ?>
        <section class="dashboard-modal-content" aria-labelledby="modal-equipos-title">
            <button class="modal-close" type="button" data-close-modal aria-label="Cerrar">×</button>
            <p class="eyebrow">Equipos de TT</p><h2 id="modal-equipos-title">Asignar equipo de TT</h2>
            <p class="modal-intro">El representante será el primer estudiante en orden alfabético. Cada docente puede tener máximo <?= MAX_EQUIPOS_POR_DOCENTE ?> equipos. Usa Ctrl para elegir varias opciones.</p>
            <form method="post" class="modal-form">
                <input type="hidden" name="formulario" value="equipo">

                <label for="titulo_equipo">Título del protocolo</label>
                <input type="text" id="titulo_equipo" name="titulo_equipo" maxlength="100" required>

                <label for="descripcion_equipo">Descripción</label>
                <textarea id="descripcion_equipo" name="descripcion_equipo" rows="3" required></textarea>

                <label for="estudiantes">Estudiantes del equipo (sin equipo asignado)</label>
                <select id="estudiantes" name="estudiantes[]" multiple size="4" required><?php $opciones($estudiantesLibres); ?></select>

                <label for="directores">Directores (2)</label>
                <select id="directores" name="directores[]" multiple size="3" required><?php $opciones($directoresDisponibles); ?></select>

                <label for="sinodales">Sinodales (3)</label>
                <select id="sinodales" name="sinodales[]" multiple size="4" required><?php $opciones($sinodalesDisponibles); ?></select>

                <label for="seguimiento">Profesor de seguimiento</label>
                <select id="seguimiento" name="seguimiento" required><option value="">Selecciona una opción</option><?php $opciones($docentesDisponibles); ?></select>

                <button class="button button-primary" type="submit">Registrar equipo <span>→</span></button>
            </form>

            <h3>Equipos registrados (<?= count($equiposRegistrados) ?>)</h3>
            <div class="activity-summary">
                <?php if (!$equiposRegistrados): ?>
                    <p>Aún no hay equipos registrados.</p>
                <?php else: foreach ($equiposRegistrados as $equipo): renderEquipoDetalle($equipo); endforeach; endif; ?>
            </div>
        </section>
    <?php elseif ($modal === 'formatos'): ?>
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
<?php require __DIR__ . '/../app/views/footer.php'; ?>