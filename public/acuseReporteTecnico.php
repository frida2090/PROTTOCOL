<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/acuses_reporte_tecnico.php';

$user = currentUser();
if (!$user) {
    flash('error', 'Inicia sesión para consultar el Acuse de Recibo.');
    redirect('login.php');
}

$db = database();
$esCatt = acuseEsCatt($user);
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrfValid()) {
            throw new DomainException('La sesión expiró. Vuelve a abrir la página e intenta de nuevo.');
        }
        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action === 'crear') {
            $acuseId = acuseCrear(
                $db,
                $user,
                (int) ($_POST['tt_id'] ?? 0),
                trim((string) ($_POST['tipo_tt'] ?? ''))
            );
            flash('success', 'El acuse fue creado. Los actores asignados ya pueden registrar su recepción y firma.');
            redirect('acuseReporteTecnico.php?id=' . $acuseId);
        }
        if ($action === 'firmar') {
            $acuseId = (int) ($_POST['acuse_id'] ?? 0);
            acuseGuardarEntrega(
                $db,
                (int) ($_POST['entrega_id'] ?? 0),
                $user,
                trim((string) ($_POST['medio_entrega'] ?? '')),
                (string) ($_POST['otro_especificar'] ?? ''),
                $_FILES['firma'] ?? []
            );
            flash('success', 'La recepción y la firma PDF fueron registradas.');
            redirect('acuseReporteTecnico.php?id=' . $acuseId);
        }
        if ($action === 'revisar' && $esCatt) {
            $acuseId = (int) ($_POST['acuse_id'] ?? 0);
            acuseRevisar(
                $db,
                $acuseId,
                trim((string) ($_POST['decision'] ?? '')),
                trim((string) ($_POST['observaciones'] ?? ''))
            );
            flash('success', 'La decisión de la CATT fue guardada.');
            redirect('acuseReporteTecnico.php?id=' . $acuseId);
        }
        throw new DomainException('No tienes permiso para realizar esta acción.');
    } catch (DomainException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $error = 'No fue posible guardar los cambios. Revisa la conexión y vuelve a intentarlo.';
    }
}

$acuses = acuseListar($db, $user);
$acuseId = (int) ($_GET['id'] ?? 0);
$acuse = null;
if ($acuseId > 0) {
    $candidate = acuseDetalle($db, $acuseId);
    if ($candidate && acusePuedeAcceder($user, $candidate)) {
        $acuse = $candidate;
    } else {
        $error = 'El acuse no existe o no tienes permiso para consultarlo.';
    }
}

$trabajosTerminales = [];
if (in_array('estudiante', $user['roles'] ?? [], true)) {
    $query = $db->prepare(
        'SELECT tt.TT_id, tt.NumeroTT, tt.TipoTT, tt.Titulo FROM TT tt '
        . 'JOIN Estudiante e ON e.EquipoId = tt.EquipoId '
        . 'WHERE e.UserId = ? AND tt.TipoTT IS NOT NULL ORDER BY tt.NumeroTT'
    );
    $query->execute([(int) $user['id']]);
    $trabajosTerminales = $query->fetchAll();
}

$pageTitle = 'Acuse de Recibo del Reporte Técnico Final';
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
<section class="form-page acuse-page">
    <header class="form-heading">
        <p class="eyebrow">Trabajo Terminal I, II y Recuperación</p>
        <h1>Acuse de Recibo del Reporte Técnico Final</h1>
    </header>
    <?php if ($error !== null): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>

    <?php if (in_array('estudiante', $user['roles'] ?? [], true) && $acuse === null): ?>
        <section class="form-panel wide-panel">
            <h2>Iniciar acuse</h2>
            <p class="form-subtitle">Selecciona el tipo de entrega y el TT. Los directores, sinodales y el profesor de seguimiento asignados completarán la recepción y su firma.</p>
            <?php if (!$trabajosTerminales): ?>
                <p>No tienes un TT clasificado. Consulta a la CATT para asignar el tipo y el número de TT.</p>
            <?php else: ?>
                <form method="post" class="form-grid">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="crear">
                    <label for="tipo_tt">Tipo de acuse</label>
                    <select id="tipo_tt" name="tipo_tt" required>
                        <option value="">Selecciona el tipo</option>
                        <option value="TT-I">TT-I</option>
                        <option value="TT-II">TT-II</option>
                        <option value="TT-R">TT-R</option>
                    </select>
                    <label for="tt_id">Número de TT</label>
                    <select id="tt_id" name="tt_id" required>
                        <option value="">Selecciona un TT</option>
                        <?php foreach ($trabajosTerminales as $tt): ?>
                            <option value="<?= (int) $tt['TT_id'] ?>"><?= e($tt['NumeroTT'] . ' · ' . $tt['TipoTT'] . ' · ' . $tt['Titulo']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button button-primary field-full" type="submit">Crear acuse</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($acuse !== null): ?>
        <?php $puedeDescargar = acusePuedeDescargar($db, $user, $acuse); ?>
        <section class="form-panel wide-panel">
            <div class="formulario1-detail-head">
                <div>
                    <p class="eyebrow"><?= e($acuse['tipo_tt']) ?> · <?= e($acuse['NumeroTT']) ?></p>
                    <h2><?= e($acuse['Titulo']) ?></h2>
                </div>
                <span class="status-chip status-chip--<?= e($estadoClases[$acuse['estado']] ?? 'pendiente') ?>"><?= e($estadoLabels[$acuse['estado']] ?? $acuse['estado']) ?></span>
            </div>
            <dl class="formulario1-data">
                <div><dt>Fecha de emisión</dt><dd><?= e(date('d/m/Y', strtotime($acuse['fecha_emision']))) ?></dd></div>
                <div><dt>Tipo de TT</dt><dd><?= e($acuse['tipo_tt']) ?></dd></div>
                <div><dt>Número de TT</dt><dd><?= e($acuse['NumeroTT']) ?></dd></div>
                <div><dt>Fecha de presentación</dt><dd><?= !empty($acuse['fecha_presentacion']) ? e(date('d/m/Y', strtotime($acuse['fecha_presentacion']))) : 'No registrada' ?></dd></div>
            </dl>
            <?php if (!empty($acuse['observaciones_catt'])): ?>
                <div class="observaciones-box"><strong>Observaciones de la CATT</strong><p><?= nl2br(e($acuse['observaciones_catt'])) ?></p></div>
            <?php endif; ?>

            <h3>Recepción y firmas autógrafas (PDF, máximo 1 MB)</h3>
            <ul class="formulario1-signers">
                <?php foreach ($acuse['entregas'] as $entrega): ?>
                    <?php
                    $nombre = trim(implode(' ', array_filter([$entrega['Nombre'], $entrega['ApellidoPaterno'], $entrega['ApellidoMaterno']])));
                    $puedeFirmar = $acuse['estado'] === 'pendiente_firmas'
                        && (int) $entrega['usuario_id'] === (int) $user['id']
                        && in_array($entrega['rol'], $user['roles'] ?? [], true)
                        && $entrega['firma_pdf_path'] === null;
                    ?>
                    <li>
                        <div>
                            <strong><?= e(acuseNombreRol($entrega['rol'])) ?>: <?= e($nombre) ?></strong>
                            <?php if ($entrega['firma_pdf_path'] !== null): ?>
                                <small>Recibió por <?= e(ACUSE_MEDIOS_ENTREGA[$entrega['medio_entrega']] ?? 'Otro') ?><?= $entrega['medio_entrega'] === 'otro' ? ': ' . e($entrega['otro_especificar']) : '' ?> · <?= e(date('d/m/Y H:i', strtotime($entrega['fecha_firma']))) ?></small>
                            <?php else: ?><small>Pendiente de recepción y firma</small><?php endif; ?>
                        </div>
                        <?php if ($puedeFirmar): ?>
                            <form method="post" enctype="multipart/form-data" class="formulario1-sign-upload">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="firmar">
                                <input type="hidden" name="acuse_id" value="<?= (int) $acuse['id'] ?>">
                                <input type="hidden" name="entrega_id" value="<?= (int) $entrega['id'] ?>">
                                <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_PDF_FIRMADO_BYTES ?>">
                                <label for="medio-<?= (int) $entrega['id'] ?>">Medio de entrega</label>
                                <select id="medio-<?= (int) $entrega['id'] ?>" name="medio_entrega" required data-medio-select>
                                    <option value="">Selecciona</option>
                                    <?php foreach (ACUSE_MEDIOS_ENTREGA as $valor => $label): ?>
                                        <option value="<?= e($valor) ?>"><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label for="otro-<?= (int) $entrega['id'] ?>">Especificar otro medio</label>
                                <input id="otro-<?= (int) $entrega['id'] ?>" name="otro_especificar" maxlength="255" data-otro-input>
                                <label for="firma-<?= (int) $entrega['id'] ?>">Firma en PDF</label>
                                <input id="firma-<?= (int) $entrega['id'] ?>" name="firma" type="file" accept=".pdf,application/pdf" required>
                                <button class="button button-dark" type="submit">Registrar recepción y firmar</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($esCatt && $acuse['estado'] === 'en_revision_catt'): ?>
                <form method="post" class="formulario1-review">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="revisar">
                    <input type="hidden" name="acuse_id" value="<?= (int) $acuse['id'] ?>">
                    <label for="observaciones">Comentarios u observaciones</label>
                    <textarea id="observaciones" name="observaciones" rows="4"><?= e($acuse['observaciones_catt']) ?></textarea>
                    <div class="formulario1-review-actions">
                        <button class="button button-primary" type="submit" name="decision" value="aprobado">Aprobar</button>
                        <button class="button button-dark" type="submit" name="decision" value="rechazado">Rechazar</button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($puedeDescargar): ?>
                <a class="button button-primary" href="descargarAcuseReporteTecnico.php?id=<?= (int) $acuse['id'] ?>">Generar y descargar PDF final</a>
            <?php elseif (in_array('estudiante', $user['roles'] ?? [], true) && $acuse['estado'] === 'aprobado'): ?>
                <?php if (empty($acuse['fecha_presentacion'])): ?>
                    <p class="form-note">La CATT aún no registra la fecha de presentación del TT.</p>
                <?php else: ?>
                    <p class="form-note">El PDF estará disponible durante la semana previa a la presentación, hasta el <?= e(date('d/m/Y', strtotime($acuse['fecha_presentacion']))) ?>.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($acuses && $acuse === null): ?>
        <section class="form-panel wide-panel formulario1-list">
            <h2><?= $esCatt ? 'Acuses para revisión' : 'Mis acuses' ?></h2>
            <ul>
                <?php foreach ($acuses as $item): ?>
                    <li>
                        <a href="acuseReporteTecnico.php?id=<?= (int) $item['id'] ?>"><?= e($item['tipo_tt'] . ' · ' . $item['NumeroTT'] . ' · ' . $item['Titulo']) ?></a>
                        <span class="status-chip status-chip--<?= e($estadoClases[$item['estado']] ?? 'pendiente') ?>"><?= e($estadoLabels[$item['estado']] ?? $item['estado']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</section>
<script>
document.querySelectorAll('[data-medio-select]').forEach((select) => {
    const otherInput = select.closest('form').querySelector('[data-otro-input]');
    const updateOther = () => {
        otherInput.disabled = select.value !== 'otro';
        otherInput.required = select.value === 'otro';
        if (otherInput.disabled) otherInput.value = '';
    };
    select.addEventListener('change', updateOther);
    updateOther();
});
</script>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
