<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/seguimientos_tt.php';

$user = currentUser();
if (!$user) {
    flash('error', 'Inicia sesión para consultar el seguimiento de TT.');
    redirect('login.php');
}
$db = database();
$esCatt = seguimientoEsCatt($user);
$esProfesor = in_array('profesor_seguimiento', $user['roles'] ?? [], true);
$esAlumno = in_array('estudiante', $user['roles'] ?? [], true);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrfValid()) {
            throw new DomainException('La sesión expiró. Vuelve a abrir la página e intenta de nuevo.');
        }
        $action = trim((string) ($_POST['action'] ?? ''));
        $ttIdPost = (int) ($_POST['tt_id'] ?? 0);
        if ($action === 'guardar_datos') {
            seguimientoGuardarDatos($db, $ttIdPost, $user, $_POST);
            flash('success', 'Se guardaron los datos del formato de seguimiento.');
            redirect('seguimientoTT.php?id=' . $ttIdPost);
        }
        if ($action === 'guardar_sesion') {
            seguimientoGuardarSesion($db, $ttIdPost, $user, $_POST);
            flash('success', 'La sesión de seguimiento fue guardada.');
            redirect('seguimientoTT.php?id=' . $ttIdPost);
        }
        if ($action === 'firmar_asistencia') {
            $ttIdPost = (int) ($_POST['tt_id'] ?? 0);
            seguimientoSubirFirmaAsistencia(
                $db,
                (int) ($_POST['sesion_id'] ?? 0),
                $user,
                $_FILES['firma'] ?? []
            );
            flash('success', 'Tu asistencia y firma PDF quedaron registradas.');
            redirect('seguimientoTT.php?id=' . $ttIdPost);
        }
        throw new DomainException('No tienes permiso para realizar esta acción.');
    } catch (DomainException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $error = 'No fue posible guardar los cambios. Revisa la conexión y vuelve a intentarlo.';
    }
}

$trabajos = seguimientoListar($db, $user);
$ttId = (int) ($_GET['id'] ?? 0);
$detalle = null;
if ($ttId > 0) {
    $candidate = seguimientoDetalle($db, $ttId);
    if ($candidate && seguimientoPuedeAcceder($db, $user, $candidate)) {
        $detalle = $candidate;
    } else {
        $error = 'El Trabajo Terminal no existe o no tienes permiso para consultarlo.';
    }
}
$sesionEdicion = null;
$siguienteNumeroSesion = 1;
if ($detalle !== null && isset($_GET['editar_sesion'])) {
    $requestedSession = (int) $_GET['editar_sesion'];
    foreach ($detalle['sesiones'] as $session) {
        $siguienteNumeroSesion = max($siguienteNumeroSesion, (int) $session['numero_sesion'] + 1);
        if ((int) $session['id'] === $requestedSession) {
            $sesionEdicion = $session;
            break;
        }
    }
} elseif ($detalle !== null) {
    foreach ($detalle['sesiones'] as $session) {
        $siguienteNumeroSesion = max($siguienteNumeroSesion, (int) $session['numero_sesion'] + 1);
    }
}

$pageTitle = 'Seguimiento de TT';
require __DIR__ . '/../app/views/header.php';
?>
<section class="form-page seguimiento-page">
    <header class="form-heading">
        <p class="eyebrow">TT1 y TT2</p>
        <h1>Formato de Seguimiento y Control de Asistencias</h1>
    </header>
    <?php if ($error !== null): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>

    <?php if ($detalle === null): ?>
        <section class="form-panel wide-panel formulario1-list">
            <h2><?= $esProfesor ? 'Trabajos Terminales asignados' : ($esCatt ? 'Trabajos Terminales' : 'Mis Trabajos Terminales') ?></h2>
            <?php if (!$trabajos): ?>
                <p>No hay Trabajos Terminales disponibles para tu cuenta.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($trabajos as $trabajo): ?>
                        <li>
                            <a href="seguimientoTT.php?id=<?= (int) $trabajo['TT_id'] ?>">
                                <?= e(($trabajo['TipoTT'] ?? 'TT') . ' · ' . $trabajo['NumeroTT'] . ' · ' . $trabajo['Titulo']) ?>
                            </a>
                            <span><?= $trabajo['seguimiento_id'] ? 'Formato iniciado' : 'Sin formato' ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <?php
        $esProfesorAsignado = $esProfesor && seguimientoEstaAsignado($db, (int) $user['id'], (int) $detalle['TT_id']);
        $alumnoActual = null;
        foreach ($detalle['alumnos'] as $alumno) {
            if ((int) $alumno['UserId'] === (int) $user['id']) {
                $alumnoActual = $alumno;
                break;
            }
        }
        ?>
        <section class="form-panel wide-panel seguimiento-summary">
            <div class="formulario1-detail-head">
                <div>
                    <p class="eyebrow"><?= e(($detalle['TipoTT'] ?? 'TT') . ' · ' . $detalle['NumeroTT']) ?></p>
                    <h2><?= e($detalle['Titulo']) ?></h2>
                </div>
                <?php if ($detalle['seguimiento_id']): ?><span class="status-chip status-chip--aprobado">Avance total: <?= e(number_format((float) $detalle['avance_total'], 2)) ?>%</span><?php endif; ?>
            </div>
            <div class="seguimiento-students">
                <strong>Alumnos:</strong>
                <?= e(implode(', ', array_map(
                    static fn (array $alumno): string => trim($alumno['Nombre'] . ' ' . $alumno['ApellidoPaterno'] . ' ' . $alumno['ApellidoMaterno']),
                    $detalle['alumnos']
                )) ?: 'Sin alumnos asignados') ?>
            </div>

            <?php if ($esProfesorAsignado): ?>
                <h3>Datos generales del formato</h3>
                <form method="post" class="form-grid seguimiento-general-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="guardar_datos">
                    <input type="hidden" name="tt_id" value="<?= (int) $detalle['TT_id'] ?>">
                    <label for="unidad_aprendizaje">Unidad de Aprendizaje</label>
                    <input id="unidad_aprendizaje" name="unidad_aprendizaje" maxlength="200" value="<?= e($detalle['unidad_aprendizaje'] ?? '') ?>" required>
                    <label for="grupo">Grupo</label>
                    <input id="grupo" name="grupo" maxlength="30" value="<?= e($detalle['grupo'] ?? '') ?>" required>
                    <label for="ciclo_escolar">Ciclo escolar</label>
                    <input id="ciclo_escolar" name="ciclo_escolar" maxlength="30" placeholder="Ej. 2026-2" value="<?= e($detalle['ciclo_escolar'] ?? '') ?>" required>
                    <label for="lugar_reunion">Lugar de reunión</label>
                    <input id="lugar_reunion" name="lugar_reunion" maxlength="200" value="<?= e($detalle['lugar_reunion'] ?? '') ?>" required>
                    <button class="button button-dark field-full" type="submit">Guardar datos generales</button>
                </form>

                <?php if ($detalle['seguimiento_id']): ?>
                    <h3><?= $sesionEdicion ? 'Editar sesión ' . (int) $sesionEdicion['numero_sesion'] : 'Registrar sesión de revisión' ?></h3>
                    <form method="post" class="form-grid">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="guardar_sesion">
                        <input type="hidden" name="tt_id" value="<?= (int) $detalle['TT_id'] ?>">
                        <label for="numero_sesion">Número de sesión</label>
                        <input id="numero_sesion" name="numero_sesion" type="number" min="1" max="65535" value="<?= (int) ($sesionEdicion['numero_sesion'] ?? $siguienteNumeroSesion) ?>"<?= $sesionEdicion ? ' readonly' : '' ?> required>
                        <label for="fecha_hora">Fecha y hora</label>
                        <input id="fecha_hora" name="fecha_hora" type="datetime-local" value="<?= $sesionEdicion ? e(date('Y-m-d\TH:i', strtotime($sesionEdicion['fecha_hora']))) : '' ?>" required>
                        <label class="field-full" for="actividades_programadas">Actividades programadas</label>
                        <textarea class="field-full" id="actividades_programadas" name="actividades_programadas" rows="3" maxlength="5000" required><?= e($sesionEdicion['actividades_programadas'] ?? '') ?></textarea>
                        <label for="porcentaje_avance">Porcentaje de avance programado</label>
                        <input id="porcentaje_avance" name="porcentaje_avance" type="number" min="0" max="100" step="0.01" value="<?= $sesionEdicion ? e((string) $sesionEdicion['porcentaje_avance']) : '' ?>" required>
                        <label class="field-full" for="observaciones">Observaciones</label>
                        <textarea class="field-full" id="observaciones" name="observaciones" rows="3" maxlength="5000"><?= e($sesionEdicion['observaciones'] ?? '') ?></textarea>
                        <p class="form-note field-full">Avance registrado: <?= e(number_format((float) $detalle['avance_total'], 2)) ?>%. La suma de todas las sesiones no puede exceder 100%.</p>
                        <button class="button button-primary field-full" type="submit"><?= $sesionEdicion ? 'Actualizar sesión' : 'Guardar sesión' ?></button>
                        <?php if ($sesionEdicion): ?><a class="field-full" href="seguimientoTT.php?id=<?= (int) $detalle['TT_id'] ?>">Cancelar edición</a><?php endif; ?>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($detalle['seguimiento_id']): ?>
                <div class="seguimiento-export-actions">
                    <a class="button button-primary" href="descargarSeguimientoTT.php?id=<?= (int) $detalle['TT_id'] ?>">Exportar formato a PDF</a>
                    <button class="button button-dark" type="button" onclick="window.print()">Imprimir formato</button>
                </div>
                <h3>Sesiones y asistencias bilaterales</h3>
                <?php if (!$detalle['sesiones']): ?>
                    <p>Aún no hay sesiones registradas.</p>
                <?php else: ?>
                    <div class="seguimiento-sessions">
                        <?php foreach ($detalle['sesiones'] as $session): ?>
                            <?php
                            $asistenciaAlumno = null;
                            foreach ($session['asistencias'] as $attendance) {
                                if ((int) $attendance['alumno_id'] === (int) $user['id']) {
                                    $asistenciaAlumno = $attendance;
                                    break;
                                }
                            }
                            ?>
                            <article class="seguimiento-session">
                                <header>
                                    <div><h4>Sesión <?= (int) $session['numero_sesion'] ?></h4><small><?= e(date('d/m/Y H:i', strtotime($session['fecha_hora']))) ?></small></div>
                                    <strong><?= e(number_format((float) $session['porcentaje_avance'], 2)) ?>% de avance</strong>
                                </header>
                                <p><strong>Actividades:</strong> <?= nl2br(e($session['actividades_programadas'])) ?></p>
                                <?php if ($session['observaciones']): ?><p><strong>Observaciones:</strong> <?= nl2br(e($session['observaciones'])) ?></p><?php endif; ?>
                                <p><strong>Profesor:</strong> <?= !empty($session['firma_profesor_pdf_path']) ? 'Asistencia firmada' : 'Pendiente de firma' ?></p>
                                <?php if ($esProfesorAsignado && empty($session['firma_profesor_pdf_path'])): ?>
                                    <form method="post" enctype="multipart/form-data" class="formulario1-sign-upload">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="firmar_asistencia">
                                        <input type="hidden" name="tt_id" value="<?= (int) $detalle['TT_id'] ?>">
                                        <input type="hidden" name="sesion_id" value="<?= (int) $session['id'] ?>">
                                        <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_PDF_FIRMADO_BYTES ?>">
                                        <label for="firma-profesor-<?= (int) $session['id'] ?>">Firma PDF del profesor (máximo 1 MB)</label>
                                        <input id="firma-profesor-<?= (int) $session['id'] ?>" name="firma" type="file" accept=".pdf,application/pdf" required>
                                        <button class="button button-dark" type="submit">Confirmar asistencia</button>
                                    </form>
                                <?php endif; ?>
                                <h5>Alumnos</h5>
                                <ul class="seguimiento-attendance">
                                    <?php foreach ($detalle['alumnos'] as $alumno): ?>
                                        <?php
                                        $attendance = null;
                                        foreach ($session['asistencias'] as $row) {
                                            if ((int) $row['alumno_id'] === (int) $alumno['UserId']) {
                                                $attendance = $row;
                                                break;
                                            }
                                        }
                                        ?>
                                        <li>
                                            <span><?= e(trim($alumno['Nombre'] . ' ' . $alumno['ApellidoPaterno'] . ' ' . $alumno['ApellidoMaterno'])) ?> — <?= $attendance && $attendance['firma_alumno_pdf_path'] ? 'Asistencia firmada' : 'Pendiente' ?></span>
                                            <?php if ($alumnoActual && (int) $alumnoActual['UserId'] === (int) $alumno['UserId'] && (!$attendance || !$attendance['firma_alumno_pdf_path'])): ?>
                                                <form method="post" enctype="multipart/form-data" class="formulario1-sign-upload">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="firmar_asistencia">
                                                    <input type="hidden" name="tt_id" value="<?= (int) $detalle['TT_id'] ?>">
                                                    <input type="hidden" name="sesion_id" value="<?= (int) $session['id'] ?>">
                                                    <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_PDF_FIRMADO_BYTES ?>">
                                                    <label for="firma-alumno-<?= (int) $session['id'] ?>">Firma PDF (máximo 1 MB)</label>
                                                    <input id="firma-alumno-<?= (int) $session['id'] ?>" name="firma" type="file" accept=".pdf,application/pdf" required>
                                                    <button class="button button-dark" type="submit">Confirmar asistencia</button>
                                                </form>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php if ($esProfesorAsignado && empty($session['firma_profesor_pdf_path']) && !array_filter($session['asistencias'], static fn (array $attendance): bool => !empty($attendance['firma_alumno_pdf_path']))): ?>
                                    <a class="form-note" href="seguimientoTT.php?id=<?= (int) $detalle['TT_id'] ?>&amp;editar_sesion=<?= (int) $session['id'] ?>">Editar sesión antes de firmar</a>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
