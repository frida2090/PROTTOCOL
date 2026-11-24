<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/formulario1.php';

$user = currentUser();
if (!$user) {
    flash('error', 'Inicia sesión para consultar el Formulario 1.');
    redirect('login.php');
}

$db = database();
$esCatt = formulario1EsCatt($user);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrfValid()) {
            throw new DomainException('La sesión expiró. Vuelve a abrir el formulario e intenta de nuevo.');
        }

        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action === 'crear' && in_array('estudiante', $user['roles'] ?? [], true)) {
            $formularioId = formulario1Crear($db, $user, $_POST);
            flash('success', 'El Formulario 1 fue creado. Los actores asignados ya pueden subir sus firmas.');
            redirect('formulario1.php?id=' . $formularioId);
        }
        if ($action === 'subir_firma') {
            $formularioId = (int) ($_POST['formulario_id'] ?? 0);
            $rol = trim((string) ($_POST['rol'] ?? ''));
            formulario1SubirFirma($db, $formularioId, $user, $rol, $_FILES['firma'] ?? []);
            flash('success', 'Tu firma en PDF fue cargada correctamente.');
            redirect('formulario1.php?id=' . $formularioId);
        }
        if ($action === 'revisar' && $esCatt) {
            formulario1Revisar(
                $db,
                (int) ($_POST['formulario_id'] ?? 0),
                trim((string) ($_POST['decision'] ?? '')),
                trim((string) ($_POST['observaciones'] ?? ''))
            );
            flash('success', 'La decisión de la CATT fue guardada.');
            redirect('formulario1.php?id=' . (int) ($_POST['formulario_id'] ?? 0));
        }
        if ($action === 'asignar_tipo_tt' && $esCatt) {
            formulario1AsignarTipoTT($db, (int) ($_POST['tt_id'] ?? 0), trim((string) ($_POST['tipo_tt'] ?? '')));
            flash('success', 'El tipo del Trabajo Terminal fue actualizado.');
            redirect('formulario1.php');
        }
        throw new DomainException('No tienes permiso para realizar esta acción.');
    } catch (DomainException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $error = 'No fue posible guardar los cambios. Revisa la conexión y vuelve a intentarlo.';
    }
}

$formularios = formulario1Listar($db, $user);
$formularioId = (int) ($_GET['id'] ?? 0);
$formulario = null;
if ($formularioId > 0) {
    $candidato = formulario1Detalle($db, $formularioId);
    if ($candidato && formulario1PuedeAcceder($user, $candidato)) {
        $formulario = $candidato;
    } else {
        $error = 'El formulario no existe o no tienes permiso para consultarlo.';
    }
}

$trabajosTerminales = [];
if (in_array('estudiante', $user['roles'] ?? [], true)) {
    $query = $db->prepare(
        'SELECT tt.TT_id, tt.NumeroTT, tt.TipoTT, tt.Titulo '
        . 'FROM TT tt JOIN Estudiante e ON e.EquipoId = tt.EquipoId '
        . 'WHERE e.UserId = ? AND tt.TipoTT IS NOT NULL ORDER BY tt.TT_id DESC'
    );
    $query->execute([(int) $user['id']]);
    $trabajosTerminales = $query->fetchAll();
}

$ttSinFormulario = [];
if ($esCatt) {
    $ttSinFormulario = $db->query(
        'SELECT tt.TT_id, tt.NumeroTT, tt.TipoTT, tt.Titulo '
        . 'FROM TT tt WHERE NOT EXISTS (SELECT 1 FROM Formulario1 f WHERE f.TT_id = tt.TT_id) '
        . 'ORDER BY tt.TT_id DESC'
    )->fetchAll();
}

$pageTitle = 'Formulario 1 · Actualización de Datos';
require __DIR__ . '/../app/views/header.php';
$estadoLabels = [
    'pendiente_firmas' => 'Pendiente de firmas',
    'en_revision_catt' => 'En revisión CATT',
    'aprobado' => 'Aprobado',
    'rechazado' => 'Rechazado',
];
$estadoClases = [
    'pendiente_firmas' => 'pendiente',
    'en_revision_catt' => 'pendiente',
    'aprobado' => 'aprobado',
    'rechazado' => 'rechazado',
];
?>
<section class="form-page formulario1-page">
    <header class="form-heading">
        <p class="eyebrow">Trabajo Terminal I y II</p>
        <h1>Formulario 1</h1>
        <p>Actualización de Datos del Protocolo/TT</p>
    </header>

    <?php if ($error !== null): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>

    <?php if ($esCatt): ?>
        <section class="form-panel wide-panel formulario1-type-panel">
            <h2>Tipo de TT</h2>
            <p class="form-subtitle">Clasifica los TT existentes como TT1 o TT2. El tipo queda bloqueado cuando ya existe un Formulario 1.</p>
            <?php if (!$ttSinFormulario): ?>
                <p>No hay TT pendientes de clasificar o actualizar.</p>
            <?php else: ?>
                <div class="formulario1-type-list">
                    <?php foreach ($ttSinFormulario as $tt): ?>
                        <form method="post" class="form-grid formulario1-type-form">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="asignar_tipo_tt">
                            <input type="hidden" name="tt_id" value="<?= (int) $tt['TT_id'] ?>">
                            <div><strong><?= e($tt['NumeroTT']) ?></strong><small><?= e($tt['Titulo']) ?></small></div>
                            <label for="tipo-tt-<?= (int) $tt['TT_id'] ?>">Tipo</label>
                            <select id="tipo-tt-<?= (int) $tt['TT_id'] ?>" name="tipo_tt" required>
                                <option value="">Selecciona el tipo</option>
                                <option value="TT1"<?= $tt['TipoTT'] === 'TT1' ? ' selected' : '' ?>>TT1</option>
                                <option value="TT2"<?= $tt['TipoTT'] === 'TT2' ? ' selected' : '' ?>>TT2</option>
                            </select>
                            <button class="button button-dark" type="submit">Guardar tipo</button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="form-panel wide-panel formulario1-list">
            <h2>Formularios para revisión</h2>
            <?php if (!$formularios): ?>
                <p class="form-subtitle">Aún no hay formularios registrados.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($formularios as $item): ?>
                        <li><a href="formulario1.php?id=<?= (int) $item['Formulario1Id'] ?>"><?= e($item['TipoTT'] . ' · ' . $item['NumeroTT'] . ' · ' . FORMULARIO1_MOVIMIENTOS[$item['Movimiento']]) ?></a>
                            <span class="status-chip status-chip--<?= e($estadoClases[$item['Estado']] ?? 'pendiente') ?>"><?= e($estadoLabels[$item['Estado']] ?? $item['Estado']) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($formulario === null && in_array('estudiante', $user['roles'] ?? [], true)): ?>
        <section class="form-panel wide-panel">
            <h2>Crear solicitud</h2>
            <p class="form-subtitle">Elige un TT de tu equipo y registra el movimiento solicitado. El formulario pasará a revisión cuando todos sus actores hayan firmado.</p>
            <?php if (!$trabajosTerminales): ?>
                <p>No tienes un Trabajo Terminal clasificado como TT1 o TT2 para iniciar esta solicitud. Consulta a la CATT si tu TT aún no tiene tipo.</p>
            <?php else: ?>
                <form method="post" class="form-grid" id="formulario1-create">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="crear">
                    <label class="field-full" for="tt_id">Trabajo Terminal</label>
                    <select class="field-full" id="tt_id" name="tt_id" required>
                        <option value="">Selecciona un TT</option>
                        <?php foreach ($trabajosTerminales as $tt): ?>
                            <option value="<?= (int) $tt['TT_id'] ?>" data-numero="<?= e($tt['NumeroTT']) ?>" data-tipo="<?= e($tt['TipoTT']) ?>" data-titulo="<?= e($tt['Titulo']) ?>">
                                <?= e($tt['TipoTT'] . ' · ' . $tt['NumeroTT'] . ' · ' . $tt['Titulo']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label for="fecha">Fecha</label>
                    <input id="fecha" name="fecha" type="date" required>
                    <label for="numero_tt">No. de TT</label>
                    <input id="numero_tt" type="text" readonly>
                    <label class="field-full" for="titulo_actual">Título actual</label>
                    <input class="field-full" id="titulo_actual" type="text" readonly>

                    <label class="field-full" for="movimiento">Movimiento solicitado</label>
                    <select class="field-full" id="movimiento" name="movimiento" required>
                        <option value="">Selecciona un movimiento</option>
                        <?php foreach (FORMULARIO1_MOVIMIENTOS as $codigo => $etiqueta): ?>
                            <option value="<?= e($codigo) ?>"><?= e($codigo . ') ' . $etiqueta) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div class="field-full formulario1-conditional" data-movimientos="A" hidden>
                        <label for="nuevo_titulo">Nuevo título</label>
                        <input id="nuevo_titulo" name="nuevo_titulo" maxlength="100" data-required>
                    </div>
                    <div class="field-full formulario1-conditional" data-movimientos="B,C,D" hidden>
                        <label for="alumno_boleta">No. de boleta</label>
                        <input id="alumno_boleta" name="alumno_boleta" maxlength="10" data-required>
                        <label for="alumno_plan">Plan</label>
                        <select id="alumno_plan" name="alumno_plan" data-required>
                            <option value="">Selecciona el plan</option>
                            <?php foreach (FORMULARIO1_PLANES as $plan): ?><option value="<?= e($plan) ?>"><?= e($plan) ?></option><?php endforeach; ?>
                        </select>
                        <label for="alumno_nombre">Nombre del alumno</label>
                        <input id="alumno_nombre" name="alumno_nombre" maxlength="160" data-required>
                    </div>
                    <div class="field-full formulario1-conditional" data-movimientos="E,F,G" hidden>
                        <label for="director_nombre">Nombre del director</label>
                        <input id="director_nombre" name="director_nombre" maxlength="160" data-required>
                    </div>
                    <div class="field-full formulario1-conditional" data-movimientos="H,I,J" hidden>
                        <label for="ciclo_escolar">Ciclo escolar</label>
                        <input id="ciclo_escolar" name="ciclo_escolar" maxlength="30" placeholder="Ej. 2026-2" data-required>
                    </div>
                    <button class="button button-primary field-full" type="submit">Crear Formulario 1</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($formulario !== null): ?>
        <section class="form-panel wide-panel formulario1-detail">
            <div class="formulario1-detail-head">
                <div>
                    <p class="eyebrow"><?= e($formulario['TipoTT']) ?> · <?= e($formulario['NumeroTT']) ?></p>
                    <h2><?= e(FORMULARIO1_MOVIMIENTOS[$formulario['Movimiento']]) ?></h2>
                </div>
                <span class="status-chip status-chip--<?= e($estadoClases[$formulario['Estado']] ?? 'pendiente') ?>"><?= e($estadoLabels[$formulario['Estado']] ?? $formulario['Estado']) ?></span>
            </div>
            <dl class="formulario1-data">
                <div><dt>Fecha</dt><dd><?= e(date('d/m/Y', strtotime($formulario['Fecha']))) ?></dd></div>
                <div><dt>No. de TT</dt><dd><?= e($formulario['NumeroTT']) ?></dd></div>
                <div><dt>Título actual</dt><dd><?= e($formulario['TituloActual']) ?></dd></div>
                <?php if ($formulario['Movimiento'] === 'A'): ?><div><dt>Nuevo título</dt><dd><?= e($formulario['NuevoTitulo']) ?></dd></div>
                <?php elseif (in_array($formulario['Movimiento'], ['B', 'C', 'D'], true)): ?>
                    <div><dt>No. de boleta</dt><dd><?= e($formulario['AlumnoNoBoleta']) ?></dd></div>
                    <div><dt>Plan</dt><dd><?= e($formulario['AlumnoPlan']) ?></dd></div>
                    <div><dt>Alumno</dt><dd><?= e($formulario['AlumnoNombre']) ?></dd></div>
                <?php elseif (in_array($formulario['Movimiento'], ['E', 'F', 'G'], true)): ?>
                    <div><dt>Director</dt><dd><?= e($formulario['DirectorNombre']) ?></dd></div>
                <?php else: ?><div><dt>Ciclo escolar</dt><dd><?= e($formulario['CicloEscolar']) ?></dd></div><?php endif; ?>
            </dl>

            <?php if (!empty($formulario['Observaciones'])): ?>
                <div class="observaciones-box"><strong>Observaciones de la CATT</strong><p><?= nl2br(e($formulario['Observaciones'])) ?></p></div>
            <?php endif; ?>

            <h3>Firmas autógrafas (PDF, máximo 1 MB)</h3>
            <ul class="formulario1-signers">
                <?php foreach ($formulario['firmantes'] as $firmante): ?>
                    <?php
                    $nombreFirmante = trim(implode(' ', array_filter([
                        $firmante['Nombre'],
                        $firmante['ApellidoPaterno'],
                        $firmante['ApellidoMaterno'],
                    ])));
                    $puedeFirmar = $formulario['Estado'] === 'pendiente_firmas'
                        && (int) $firmante['UserId'] === (int) $user['id']
                        && in_array($firmante['Rol'], $user['roles'] ?? [], true)
                        && $firmante['RutaArchivo'] === null;
                    ?>
                    <li>
                        <div>
                            <strong><?= e(formulario1NombreRol($firmante['Rol'])) ?>: <?= e($nombreFirmante) ?></strong>
                            <?php if ($firmante['Rol'] === 'estudiante' && $firmante['NumeroBoleta']): ?><small>Boleta <?= e($firmante['NumeroBoleta']) ?></small><?php endif; ?>
                            <?php if ($firmante['RutaArchivo'] !== null): ?>
                                <small>Firma cargada el <?= e(date('d/m/Y H:i', strtotime($firmante['FechaCarga']))) ?></small>
                                <?php if ($esCatt || (int) $firmante['UserId'] === (int) $user['id']): ?>
                                    <a href="descargarFirmaFormulario1.php?id=<?= (int) $firmante['FirmaId'] ?>" target="_blank">Ver firma PDF</a>
                                <?php endif; ?>
                            <?php else: ?><small>Pendiente de firma</small><?php endif; ?>
                        </div>
                        <?php if ($puedeFirmar): ?>
                            <form method="post" enctype="multipart/form-data" class="formulario1-sign-upload">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="subir_firma">
                                <input type="hidden" name="formulario_id" value="<?= (int) $formulario['Formulario1Id'] ?>">
                                <input type="hidden" name="rol" value="<?= e($firmante['Rol']) ?>">
                                <input type="hidden" name="MAX_FILE_SIZE" value="<?= FORMULARIO1_MAX_FIRMA_BYTES ?>">
                                <label for="firma-<?= (int) $firmante['FirmaId'] ?>">Firma en PDF</label>
                                <input id="firma-<?= (int) $firmante['FirmaId'] ?>" name="firma" type="file" accept=".pdf,application/pdf" required>
                                <button class="button button-dark" type="submit">Subir firma</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($esCatt && $formulario['Estado'] === 'en_revision_catt'): ?>
                <form method="post" class="formulario1-review">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="revisar">
                    <input type="hidden" name="formulario_id" value="<?= (int) $formulario['Formulario1Id'] ?>">
                    <label for="observaciones">Comentarios u observaciones</label>
                    <textarea id="observaciones" name="observaciones" rows="4"><?= e($formulario['Observaciones']) ?></textarea>
                    <div class="formulario1-review-actions">
                        <button class="button button-primary" type="submit" name="decision" value="aprobado">Aprobar</button>
                        <button class="button button-dark" type="submit" name="decision" value="rechazado">Rechazar</button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if (formulario1PuedeDescargar($db, $user, $formulario)): ?>
                <a class="button button-primary" href="descargarFormulario1.php?id=<?= (int) $formulario['Formulario1Id'] ?>">Generar y descargar documento final (PDF)</a>
            <?php elseif (in_array('estudiante', $user['roles'] ?? [], true) && $formulario['Estado'] === 'aprobado'): ?>
                <?php if (empty($formulario['fecha_presentacion'])): ?>
                    <p class="form-note">La CATT aún no registra la fecha de presentación del TT.</p>
                <?php else: ?>
                    <p class="form-note">El documento final estará disponible desde <?= e(date('d/m/Y', strtotime($formulario['fecha_presentacion'] . ' -7 days'))) ?> (siete días antes de la presentación).</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if (!$esCatt && $formularios && $formulario === null): ?>
        <section class="form-panel wide-panel formulario1-list">
            <h2>Mis Formularios 1</h2>
            <ul>
                <?php foreach ($formularios as $item): ?>
                    <li><a href="formulario1.php?id=<?= (int) $item['Formulario1Id'] ?>"><?= e($item['TipoTT'] . ' · ' . $item['NumeroTT'] . ' · ' . FORMULARIO1_MOVIMIENTOS[$item['Movimiento']]) ?></a>
                        <span class="status-chip status-chip--<?= e($estadoClases[$item['Estado']] ?? 'pendiente') ?>"><?= e($estadoLabels[$item['Estado']] ?? $item['Estado']) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</section>

<script>
const ttSelect = document.getElementById('tt_id');
const numeroTT = document.getElementById('numero_tt');
const tituloActual = document.getElementById('titulo_actual');
function actualizarDatosTT() {
    const option = ttSelect?.selectedOptions[0];
    if (numeroTT) numeroTT.value = option?.dataset.numero || '';
    if (tituloActual) tituloActual.value = option?.dataset.titulo || '';
}
ttSelect?.addEventListener('change', actualizarDatosTT);
actualizarDatosTT();

const movimiento = document.getElementById('movimiento');
function actualizarCamposMovimiento() {
    const tipo = ttSelect?.selectedOptions[0]?.dataset.tipo || '';
    const opcionAlta = movimiento?.querySelector('option[value="B"]');
    if (opcionAlta) opcionAlta.disabled = tipo !== 'TT1';
    if (tipo !== 'TT1' && movimiento?.value === 'B') movimiento.value = '';
    document.querySelectorAll('.formulario1-conditional').forEach((grupo) => {
        const visible = (grupo.dataset.movimientos || '').split(',').includes(movimiento?.value || '');
        grupo.hidden = !visible;
        grupo.querySelectorAll('[data-required]').forEach((campo) => { campo.required = visible; });
    });
}
ttSelect?.addEventListener('change', actualizarCamposMovimiento);
movimiento?.addEventListener('change', actualizarCamposMovimiento);
actualizarCamposMovimiento();
</script>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
