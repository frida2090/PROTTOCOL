<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/equipos.php';
require_once __DIR__ . '/../app/protocolos.php';

$user = currentUser();
if (!$user) {
    redirect('login.php');
}
if ($user['rol'] !== 'estudiante') {
    flash('error', 'Acceso denegado. Solo los estudiantes pueden solicitar el registro de un protocolo.');
    redirect('login.php');
}

$studentId = (int) $user['id'];
$error = null;

// Estado actual del equipo/protocolo del alumno (para detectar modo reenvío).
$connectionInicial = database();
$miEquipoInicial = equipoDelEstudiante($connectionInicial, $studentId);
$miProtocoloInicial = $miEquipoInicial ? protocoloDelEquipo($connectionInicial, (int) $miEquipoInicial['EquipoId']) : null;
$esReenvio = $miEquipoInicial !== null && $miProtocoloInicial !== null
    && in_array($miProtocoloInicial['Status'], ['Rechazado', 'RechazadoSinodales'], true);
$esReenvioSinodales = $esReenvio && $miProtocoloInicial['Status'] === 'RechazadoSinodales';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValid()) {
        flash('error', 'La sesión expiró. Vuelve a abrir el formulario e intenta de nuevo.');
        redirect('tramiteProtocolo.php');
    }

    $titulo = trim($_POST['titulo'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $companeros = array_values(array_diff(enteros($_POST['companeros'] ?? []), [$studentId]));
    $directores = enteros($_POST['directores'] ?? []);

    $externoIncluido = ($_POST['externo_incluido'] ?? '') === '1';
    $externo = [
        'nombre' => trim($_POST['externo_nombre'] ?? ''),
        'paterno' => trim($_POST['externo_paterno'] ?? ''),
        'materno' => trim($_POST['externo_materno'] ?? ''),
        'email' => trim($_POST['externo_email'] ?? ''),
        'organizacion' => trim($_POST['externo_organizacion'] ?? ''),
    ];

    $archivo = $_FILES['archivo'] ?? null;
    $blob = null;

    if ($titulo === '' || mb_strlen($titulo) > 100) {
        $error = 'Captura el título del Trabajo Terminal (máximo 100 caracteres).';
    } elseif ($descripcion === '') {
        $error = 'Captura la descripción u objetivos del Trabajo Terminal.';
    } elseif (!$esReenvio && (count($directores) < 1 || count($directores) > 2)) {
        $error = 'Selecciona al menos un profesor director empleado (máximo dos).';
    } elseif (!$esReenvio && $externoIncluido && count($directores) > 1) {
        $error = 'Un director externo solo puede integrarse si eliges un solo director empleado.';
    } elseif (!$esReenvio && $externoIncluido && ($externo['nombre'] === '' || $externo['paterno'] === '' || !filter_var($externo['email'], FILTER_VALIDATE_EMAIL) || $externo['organizacion'] === '')) {
        $error = 'Completa todos los datos del director externo (nombre, apellidos, correo y organización).';
    } elseif (!$esReenvio && !$externoIncluido && ($externo['nombre'] !== '' || $externo['paterno'] !== '' || $externo['email'] !== '' || $externo['organizacion'] !== '')) {
        $error = 'Marca la casilla del director externo para capturar sus datos, o bórralos por completo.';
    } elseif (!$archivo || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $error = 'Adjunta el archivo PDF con tu protocolo.';
    } elseif (in_array($archivo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        $error = 'El archivo supera el límite de 10 MB. Revisa upload_max_filesize en tu php.ini.';
    } elseif ($archivo['error'] !== UPLOAD_ERR_OK) {
        $error = 'No fue posible subir el archivo (código de error ' . (int) $archivo['error'] . ').';
    } elseif ((int) $archivo['size'] > MAX_ARCHIVO_PROTOCOLO_BYTES) {
        $error = 'El PDF del protocolo no puede superar los 10 MB.';
    } else {
        $extension = strtolower(pathinfo((string) $archivo['name'], PATHINFO_EXTENSION));
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string) finfo_file($finfo, $archivo['tmp_name']) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        if ($extension !== 'pdf' || $mime !== 'application/pdf') {
            $error = 'Solo se aceptan archivos PDF.';
        } else {
            $blob = file_get_contents($archivo['tmp_name']);
            if ($blob === false) {
                $error = 'No fue posible leer el archivo subido. Intenta de nuevo.';
            }
        }
    }

    if ($blob !== null && $error === null) {
        $connection = database();

        // ---- Reenvío de un protocolo ya rechazado (por CATT o por sinodales) ----
        if ($esReenvio) {
            try {
                $connection->beginTransaction();

                $equipoId = (int) $miEquipoInicial['EquipoId'];

                // Verificar que siga rechazado (puede haber cambiado entre la carga y el envío).
                $vigenteQuery = $connection->prepare('SELECT fs.Status FROM Formatos_Subidos fs WHERE fs.FormatoId = ? FOR UPDATE');
                $vigenteQuery->execute([(int) $miProtocoloInicial['FormatoId']]);
                $statusVigente = (string) $vigenteQuery->fetchColumn();
                if (!in_array($statusVigente, ['Rechazado', 'RechazadoSinodales'], true)) {
                    throw new DomainException('El protocolo ya no está rechazado; no hay nada que reenviar.');
                }

                // Actualizar título y descripción del equipo (si se modificaron).
                $connection->prepare('UPDATE Equipo_TT SET TituloProtocolo = ?, Descripcion = ? WHERE EquipoId = ?')
                    ->execute([$titulo, $descripcion, $equipoId]);

                // Límite de entregas ante dictamen de sinodales (máx. 2).
                if ($statusVigente === 'RechazadoSinodales') {
                    $entregasQuery = $connection->prepare("SELECT COUNT(*) FROM Formatos_Subidos WHERE EquipoId = ? AND Status IN ('Aprobado','RechazadoSinodales','AprobadoSinodales')");
                    $entregasQuery->execute([$equipoId]);
                    if ((int) $entregasQuery->fetchColumn() >= MAX_ENTREGAS_PROTOCOLO) {
                        throw new DomainException('Ya no se permiten más entregas de este protocolo.');
                    }
                }

                // Nueva versión del formato.
                $tipoFormatoId = asegurarCatalogoProtocolo($connection);
                $nuevoStatus = $statusVigente === 'RechazadoSinodales' ? 'Aprobado' : 'Pendiente';
                $insert = $connection->prepare('INSERT INTO Formatos_Subidos (EquipoId, TipoFormatoId, FormatoSubido, Status) VALUES (?, ?, ?, ?)');
                $insert->bindValue(1, $equipoId, PDO::PARAM_INT);
                $insert->bindValue(2, $tipoFormatoId, PDO::PARAM_INT);
                $insert->bindValue(3, $blob, PDO::PARAM_LOB);
                $insert->bindValue(4, $nuevoStatus);
                $insert->execute();

                $connection->commit();
                if ($statusVigente === 'RechazadoSinodales') {
                    flash('success', 'Tu segunda entrega fue enviada. El sinodal que rechazó la versión anterior deberá re-evaluarla.');
                } else {
                    flash('success', 'Tu protocolo corregido fue reenviado. La CATT revisará el documento de nuevo.');
                }
                redirect('panelAlumno.php');
            } catch (DomainException $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                $error = $exception->getMessage();
            } catch (PDOException $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                $error = 'No fue posible reenviar el protocolo. Revisa la conexión con la base de datos.';
            }
        } else {
        try {
            $connection->beginTransaction();

            $ownQuery = $connection->prepare('SELECT EquipoId FROM Estudiante WHERE UserId = ? FOR UPDATE');
            $ownQuery->execute([$studentId]);
            $ownEquipo = $ownQuery->fetchColumn();
            if ($ownEquipo !== false && $ownEquipo !== null) {
                throw new DomainException('Ya formas parte de un equipo de Trabajo Terminal; no puedes enviar otro protocolo.');
            }

            $teamIds = array_merge([$studentId], $companeros);
            $marcas = implode(', ', array_fill(0, count($teamIds), '?'));
            $studentsQuery = $connection->prepare("SELECT UserId, Nombre, ApellidoPaterno, ApellidoMaterno FROM Usuarios WHERE UserId IN ({$marcas}) FOR UPDATE");
            $studentsQuery->execute($teamIds);
            $studentsById = [];
            foreach ($studentsQuery->fetchAll() as $row) {
                $studentsById[(int) $row['UserId']] = $row;
            }
            if (count($studentsById) !== count($teamIds)) {
                throw new DomainException('Algún estudiante seleccionado no existe.');
            }

            $freeQuery = $connection->prepare("SELECT UserId, EquipoId FROM Estudiante WHERE UserId IN ({$marcas}) FOR UPDATE");
            $freeQuery->execute($teamIds);
            $freeRows = $freeQuery->fetchAll();
            if (count($freeRows) !== count($teamIds)) {
                throw new DomainException('Algún integrante seleccionado no tiene registro de estudiante.');
            }
            foreach ($freeRows as $row) {
                if ($row['EquipoId'] !== null) {
                    throw new DomainException('Algún integrante seleccionado ya pertenece a otro equipo.');
                }
            }

            $dirMarcas = implode(', ', array_fill(0, count($directores), '?'));
            $dirQuery = $connection->prepare("SELECT DISTINCT u.UserId FROM Usuarios u JOIN Usuario_Roles ur ON ur.UserId = u.UserId AND ur.Rol = 'director' JOIN Empleado em ON em.UserId = u.UserId WHERE u.UserId IN ({$dirMarcas}) FOR UPDATE");
            $dirQuery->execute($directores);
            if (count($dirQuery->fetchAll()) !== count($directores)) {
                throw new DomainException('Algún director seleccionado no existe o no tiene el rol de director empleado.');
            }

            $limitQuery = $connection->prepare("SELECT UserId, COUNT(*) AS total FROM EmpleadoEquipo WHERE UserId IN ({$dirMarcas}) GROUP BY UserId HAVING total >= " . MAX_EQUIPOS_POR_DOCENTE);
            $limitQuery->execute($directores);
            if ($limitQuery->fetch()) {
                throw new DomainException('Algún director seleccionado ya alcanzó el máximo de ' . MAX_EQUIPOS_POR_DOCENTE . ' equipos.');
            }

            $externoId = null;
            if ($externoIncluido) {
                $emailQuery = $connection->prepare('SELECT UserId FROM Usuarios WHERE Email = ?');
                $emailQuery->execute([$externo['email']]);
                if ($emailQuery->fetchColumn() !== false) {
                    throw new DomainException('El correo del director externo ya está registrado. Captura otro correo o pide a la CATT que vincule la cuenta existente.');
                }
            }

            $representante = elegirRepresentante($studentsById);
            $connection->prepare('INSERT INTO Equipo_TT (TituloProtocolo, Descripcion, RepresentanteUserId) VALUES (?, ?, ?)')
                ->execute([$titulo, $descripcion, (int) $representante['UserId']]);
            $equipoId = (int) $connection->lastInsertId();

            $connection->prepare("UPDATE Estudiante SET EquipoId = ? WHERE UserId IN ({$marcas}) AND EquipoId IS NULL")
                ->execute(array_merge([$equipoId], $teamIds));

            $assign = $connection->prepare('INSERT INTO Equipo_Asignacion (EquipoId, UserId, Rol, AsignadoPorUserId) VALUES (?, ?, ?, ?)');
            $link = $connection->prepare('INSERT IGNORE INTO EmpleadoEquipo (UserId, EquipoId) VALUES (?, ?)');
            foreach ($directores as $directorId) {
                $assign->execute([$equipoId, $directorId, 'director', $studentId]);
                $link->execute([$directorId, $equipoId]);
            }

            if ($externoIncluido) {
                $connection->prepare('INSERT INTO Usuarios (Nombre, ApellidoPaterno, ApellidoMaterno, Email, PasswordHash) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$externo['nombre'], $externo['paterno'], $externo['materno'], $externo['email'], password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
                $externoId = (int) $connection->lastInsertId();
                $connection->prepare('INSERT INTO Externo (UserId, OrgProcedencia, EquipoId) VALUES (?, ?, ?)')
                    ->execute([$externoId, $externo['organizacion'], $equipoId]);
                $assign->execute([$equipoId, $externoId, 'director', $studentId]);
                $link->execute([$externoId, $equipoId]);
            }

            $tipoFormatoId = asegurarCatalogoProtocolo($connection);
            $formatoQuery = $connection->prepare('INSERT INTO Formatos_Subidos (EquipoId, TipoFormatoId, FormatoSubido, Status) VALUES (?, ?, ?, ?)');
            $formatoQuery->bindValue(1, $equipoId, PDO::PARAM_INT);
            $formatoQuery->bindValue(2, $tipoFormatoId, PDO::PARAM_INT);
            $formatoQuery->bindValue(3, $blob, PDO::PARAM_LOB);
            $formatoQuery->bindValue(4, 'Pendiente');
            $formatoQuery->execute();

            $connection->commit();
            flash('success', 'Tu protocolo fue enviado. La CATT revisará el documento y, al autorizarlo, asignará los tres sinodales de tu equipo.');
            redirect('panelAlumno.php');
        } catch (DomainException $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $error = $exception->getCode() === '23000'
                ? 'No fue posible guardar el protocolo: algún dato ya está registrado o falta una relación requerida.'
                : 'No fue posible enviar el protocolo. Revisa la conexión con la base de datos.';
        }
        } // fin modo envío inicial
    }
}

$connection = database();
$miEquipo = equipoDelEstudiante($connection, $studentId);
$miProtocolo = $miEquipo ? protocoloDelEquipo($connection, (int) $miEquipo['EquipoId']) : null;
$companerosSeleccionados = is_array($_POST['companeros'] ?? null) ? array_map('intval', $_POST['companeros']) : [];
$directoresSeleccionados = is_array($_POST['directores'] ?? null) ? array_map('intval', $_POST['directores']) : [];

$companerosLibres = $directoresDisponibles = [];
if (!$miEquipo) {
    $companerosLibres = $connection->prepare(
        "SELECT u.UserId, u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno, e.NumeroBoleta "
        . 'FROM Estudiante e JOIN Usuarios u ON u.UserId = e.UserId '
        . 'WHERE e.EquipoId IS NULL AND e.UserId <> ? ORDER BY u.ApellidoPaterno, u.ApellidoMaterno, u.Nombre'
    );
    $companerosLibres->execute([$studentId]);
    $companerosLibres = $companerosLibres->fetchAll();

    $directoresDisponibles = $connection->query(
        "SELECT DISTINCT u.UserId, u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno, u.Email, em.NumeroEmpleado "
        . 'FROM Usuarios u JOIN Usuario_Roles ur ON ur.UserId = u.UserId AND ur.Rol = \'director\' '
        . 'JOIN Empleado em ON em.UserId = u.UserId '
        . 'ORDER BY u.ApellidoPaterno, u.ApellidoMaterno, u.Nombre'
    )->fetchAll();
}

$pageTitle = 'Registro de protocolo';
require __DIR__ . '/../app/views/header.php';
?>
<section class="form-page protocol-page">
    <div class="form-heading">
        <p class="eyebrow">Trámite de alumno · Paso 1</p>
        <h1>Registro de protocolo</h1>
        <p>Arma tu equipo de Trabajo Terminal, elige a tus directores y envía el documento a la CATT.</p>
    </div>

    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <?php if ($esReenvio): ?>
        <div class="form-panel wide-panel protocol-status-panel">
            <p class="eyebrow">Corrección de protocolo</p>
            <h2><?= e($miEquipoInicial['TituloProtocolo']) ?></h2>
            <p class="protocol-status-line">
                <span class="status-chip status-chip--<?= e(claseEstadoProtocolo((string) $miProtocoloInicial['Status'])) ?>"><?= e(etiquetaEstadoProtocolo((string) $miProtocoloInicial['Status'])) ?></span>
                <small>Última versión enviada el <?= e(date('d/m/Y', strtotime((string) $miProtocoloInicial['FechaSubida']))) ?></small>
                <a class="protocol-doc-link" href="descargarFormato.php?id=<?= (int) $miProtocoloInicial['FormatoId'] ?>">Ver PDF</a>
            </p>

            <?php if (!empty($miProtocoloInicial['Observaciones'])): ?>
                <div class="observaciones-box">
                    <strong>Observaciones de la CATT:</strong>
                    <p><?= nl2br(e((string) $miProtocoloInicial['Observaciones'])) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($esReenvioSinodales): ?>
                <h3>Observaciones de los sinodales que rechazaron</h3>
                <?php $dictamenesRechazo = array_filter(evaluacionesDeFormato(database(), (int) $miProtocoloInicial['FormatoId']), static fn (array $d): bool => $d['Dictamen'] === 'rechazado'); ?>
                <?php if ($dictamenesRechazo): ?>
                    <div class="observaciones-box">
                        <?php foreach ($dictamenesRechazo as $dictamen): ?>
                            <p><strong><?= e($dictamen['ApellidoPaterno'] . ' ' . $dictamen['ApellidoMaterno'] . ' ' . $dictamen['Nombre']) ?>:</strong>
                            <?php if (!empty($dictamen['Observaciones'])): ?><?= nl2br(e((string) $dictamen['Observaciones'])) ?><?php else: ?>Sin observaciones adicionales.<?php endif; ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <p class="form-note">Debes subir una versión corregida de tu protocolo. El sinodal que rechazó deberá volver a evaluarlo.</p>
            <?php else: ?>
                <p class="form-note">Corrige tu protocolo según las observaciones y vuelve a enviarlo. La CATT lo revisará de nuevo.</p>
            <?php endif; ?>

            <form method="post" class="form-grid protocol-form" enctype="multipart/form-data" novalidate>
                <?= csrfField() ?>
                <input type="hidden" name="reenvio" value="1">

                <div class="field field-full"><label for="titulo">Título del Trabajo Terminal</label><input id="titulo" name="titulo" maxlength="100" required value="<?= e($_POST['titulo'] ?? $miEquipoInicial['TituloProtocolo']) ?>"></div>
                <div class="field field-full"><label for="descripcion">Descripción u objetivos</label><textarea id="descripcion" name="descripcion" rows="4" required><?= e($_POST['descripcion'] ?? $miEquipoInicial['Descripcion']) ?></textarea></div>
                <div class="field field-full">
                    <label for="archivo">Nueva versión del PDF <span class="field-hint">(máximo 10 MB)</span></label>
                    <input id="archivo" name="archivo" type="file" accept="application/pdf,.pdf" class="file-input" required>
                </div>
                <div class="field-full"><button class="button button-primary" type="submit"><?= $esReenvioSinodales ? 'Enviar segunda entrega' : 'Reenviar protocolo corregido' ?> <span>→</span></button></div>
            </form>
            <p class="form-note">Integrantes, directores y sinodales permanecen fijos; solo se actualiza el documento, el título y la descripción.</p>
            <p class="form-note"><a href="panelAlumno.php">Cancelar y volver a mi panel</a></p>
        </div>
    <?php elseif ($miEquipo): ?>
        <div class="form-panel wide-panel protocol-status-panel">
            <p class="eyebrow">Estado de tu solicitud</p>
            <h2><?= e($miEquipo['TituloProtocolo']) ?></h2>
            <?php if ($miProtocolo): ?>
                <p class="protocol-status-line">
                    <span class="status-chip status-chip--<?= e(claseEstadoProtocolo($miProtocolo['Status'])) ?>"><?= e(etiquetaEstadoProtocolo($miProtocolo['Status'])) ?></span>
                    <small>Enviado el <?= e(date('d/m/Y', strtotime($miProtocolo['FechaSubida']))) ?></small>
                </p>
            <?php else: ?>
                <p class="protocol-status-line"><span class="status-chip status-chip--pendiente">Equipo registrado sin protocolo</span></p>
                <p class="form-note">Tu equipo fue registrado por la CATT sin documento adjunto. Contacta a un miembro de la comisión para registrar el formato de protocolo.</p>
            <?php endif; ?>
            <?php renderEquipoDetalle($miEquipo); ?>
            <p class="form-note">La CATT asignará los tres sinodales una vez que autorice el documento.</p>
            <p class="form-note"><a href="panelAlumno.php">Volver a mi panel</a></p>
        </div>
    <?php else: ?>
        <div class="form-panel wide-panel">
            <ol class="protocol-steps">
                <li><strong>Tu equipo:</strong> elige a tus compañeros (o forma un equipo individual).</li>
                <li><strong>Directores:</strong> al menos un profesor empleado; opcionalmente uno externo.</li>
                <li><strong>Documento:</strong> adjunta el PDF del protocolo (máximo 10 MB).</li>
            </ol>
            <form method="post" class="form-grid protocol-form" enctype="multipart/form-data" novalidate>
                <?= csrfField() ?>

                <div class="field field-full"><label for="titulo">Título del Trabajo Terminal</label><input id="titulo" name="titulo" maxlength="100" required value="<?= e($_POST['titulo'] ?? '') ?>" placeholder="Ej. Sistema de gestión de protocolos CATT"></div>

                <div class="field field-full"><label for="descripcion">Descripción u objetivos</label><textarea id="descripcion" name="descripcion" rows="4" required placeholder="Describe en qué consiste tu Trabajo Terminal"><?= e($_POST['descripcion'] ?? '') ?></textarea></div>

                <div class="field field-full">
                    <label for="companeros">Compañeros del equipo <span class="field-hint">(opcional; usa Ctrl para elegir varios — si no eliges nadie, el equipo serás tú)</span></label>
                    <select id="companeros" name="companeros[]" multiple size="6" class="multi-select">
                        <?php foreach ($companerosLibres as $companero): ?>
                            <option value="<?= e((string) $companero['UserId']) ?>"<?= in_array((int) $companero['UserId'], $companerosSeleccionados, true) ? ' selected' : '' ?>><?= e(fullName($companero)) ?> · <?= e($companero['NumeroBoleta']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$companerosLibres): ?><p class="field-hint">No hay otros estudiantes disponibles; tu equipo serás tú.</p><?php endif; ?>
                </div>

                <div class="field field-full">
                    <label for="directores">Profesores directores (empleados) <span class="field-hint">(al menos 1, máximo 2 — usa Ctrl para elegir varios)</span></label>
                    <select id="directores" name="directores[]" multiple size="6" class="multi-select" required>
                        <?php foreach ($directoresDisponibles as $director): ?>
                            <option value="<?= e((string) $director['UserId']) ?>"<?= in_array((int) $director['UserId'], $directoresSeleccionados, true) ? ' selected' : '' ?>><?= e(fullName($director)) ?> · <?= e($director['NumeroEmpleado']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$directoresDisponibles): ?><p class="field-hint">Aún no hay directores registrados. Pide a la CATT que registre al menos uno.</p><?php endif; ?>
                </div>

                <fieldset class="protocol-externo">
                    <legend>Director externo <span>(opcional)</span></legend>
                    <label class="protocol-externo-toggle"><input type="checkbox" id="externo_incluido" name="externo_incluido" value="1"<?= ($_POST['externo_incluido'] ?? '') === '1' ? ' checked' : '' ?>> Agregar un director externo de la organización</label>
                    <div class="protocol-externo-fields" id="externo-fields">
                        <div class="field"><label for="externo_nombre">Nombre(s)</label><input id="externo_nombre" name="externo_nombre" value="<?= e($_POST['externo_nombre'] ?? '') ?>"></div>
                        <div class="field"><label for="externo_paterno">Apellido paterno</label><input id="externo_paterno" name="externo_paterno" value="<?= e($_POST['externo_paterno'] ?? '') ?>"></div>
                        <div class="field"><label for="externo_materno">Apellido materno</label><input id="externo_materno" name="externo_materno" value="<?= e($_POST['externo_materno'] ?? '') ?>"></div>
                        <div class="field"><label for="externo_email">Correo electrónico</label><input id="externo_email" name="externo_email" type="email" value="<?= e($_POST['externo_email'] ?? '') ?>"></div>
                        <div class="field field-full"><label for="externo_organizacion">Organización de procedencia</label><input id="externo_organizacion" name="externo_organizacion" value="<?= e($_POST['externo_organizacion'] ?? '') ?>"></div>
                    </div>
                </fieldset>

                <div class="field field-full">
                    <label for="archivo">Archivo del protocolo <span class="field-hint">(PDF, máximo 10 MB)</span></label>
                    <input id="archivo" name="archivo" type="file" accept="application/pdf,.pdf" class="file-input" required>
                </div>

                <div class="field-full"><button class="button button-primary" type="submit">Enviar protocolo a la CATT <span>→</span></button></div>
            </form>
            <p class="form-note">Al enviarlo, tu equipo quedará registrado y la solicitud aparecerá como <strong>pendiente de autorización</strong> para la CATT.</p>
            <p class="form-note"><a href="panelAlumno.php">Cancelar y volver a mi panel</a></p>
        </div>
    <?php endif; ?>
</section>
<script>
(() => {
    const toggle = document.getElementById('externo_incluido');
    const fields = document.getElementById('externo-fields');
    if (!toggle || !fields) return;
    const sync = () => { fields.classList.toggle('is-visible', toggle.checked); };
    toggle.addEventListener('change', sync);
    sync();
})();
</script>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
