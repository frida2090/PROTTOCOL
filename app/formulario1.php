<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

const FORMULARIO1_MAX_FIRMA_BYTES = 1048576;

const FORMULARIO1_MOVIMIENTOS = [
    'A' => 'Cambio de título',
    'B' => 'Alta de alumno',
    'C' => 'Baja de alumno',
    'D' => 'Sustitución de alumno',
    'E' => 'Alta de director',
    'F' => 'Baja de director',
    'G' => 'Sustitución de director',
    'H' => 'Aplazamiento de TT',
    'I' => 'Reactivación de TT',
    'J' => 'Baja de TT',
];

const FORMULARIO1_PLANES = ['ISC 2009', 'ISC 2020', 'LCD', 'IIA'];

function formulario1EsCatt(array $user): bool
{
    return (bool) array_intersect($user['roles'] ?? [], ['miembroCatt', 'jefeAcademia']);
}

function formulario1NombreRol(string $rol): string
{
    return [
        'estudiante' => 'Alumno',
        'director' => 'Director',
        'sinodal' => 'Sinodal',
        'profesor_seguimiento' => 'Profesor de Seguimiento',
    ][$rol] ?? $rol;
}

function formulario1ObtenerActores(PDO $db, int $equipoId): array
{
    $estudiantesQuery = $db->prepare(
        "SELECT u.UserId, 'estudiante' AS Rol, u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno, e.NumeroBoleta "
        . 'FROM Estudiante e JOIN Usuarios u ON u.UserId = e.UserId '
        . "JOIN Usuario_Roles ur ON ur.UserId = u.UserId AND ur.Rol = 'estudiante' "
        . 'WHERE e.EquipoId = ? ORDER BY u.ApellidoPaterno, u.ApellidoMaterno, u.Nombre'
    );
    $estudiantesQuery->execute([$equipoId]);

    $docentesQuery = $db->prepare(
        "SELECT u.UserId, CASE ea.Rol WHEN 'seguimiento' THEN 'profesor_seguimiento' ELSE ea.Rol END AS Rol, "
        . 'u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno, NULL AS NumeroBoleta '
        . 'FROM Equipo_Asignacion ea JOIN Usuarios u ON u.UserId = ea.UserId '
        . 'JOIN Usuario_Roles ur ON ur.UserId = u.UserId '
        . "AND ur.Rol = CASE ea.Rol WHEN 'seguimiento' THEN 'profesor_seguimiento' ELSE ea.Rol END "
        . "WHERE ea.EquipoId = ? AND ea.Rol IN ('director', 'sinodal', 'seguimiento') "
        . "ORDER BY FIELD(ea.Rol, 'director', 'sinodal', 'seguimiento'), u.ApellidoPaterno, u.ApellidoMaterno, u.Nombre"
    );
    $docentesQuery->execute([$equipoId]);
    $actores = array_merge($estudiantesQuery->fetchAll(), $docentesQuery->fetchAll());
    usort($actores, static function (array $a, array $b): int {
        $orden = ['estudiante' => 0, 'director' => 1, 'sinodal' => 2, 'profesor_seguimiento' => 3];
        return ($orden[$a['Rol']] <=> $orden[$b['Rol']])
            ?: strcasecmp($a['ApellidoPaterno'] . $a['Nombre'], $b['ApellidoPaterno'] . $b['Nombre']);
    });

    $maximos = ['estudiante' => 4, 'director' => 2, 'sinodal' => 3, 'profesor_seguimiento' => 1];
    $conteos = array_count_values(array_column($actores, 'Rol'));
    foreach ($maximos as $rol => $maximo) {
        if (($conteos[$rol] ?? 0) > $maximo) {
            throw new DomainException('El equipo excede el máximo permitido de participantes para el rol ' . formulario1NombreRol($rol) . '.');
        }
    }
    return $actores;
}

function formulario1Crear(PDO $db, array $user, array $entrada): int
{
    $fecha = trim((string) ($entrada['fecha'] ?? ''));
    $movimiento = trim((string) ($entrada['movimiento'] ?? ''));
    $nuevoTitulo = trim((string) ($entrada['nuevo_titulo'] ?? ''));
    $boleta = trim((string) ($entrada['alumno_boleta'] ?? ''));
    $plan = trim((string) ($entrada['alumno_plan'] ?? ''));
    $alumnoNombre = trim((string) ($entrada['alumno_nombre'] ?? ''));
    $directorNombre = trim((string) ($entrada['director_nombre'] ?? ''));
    $ciclo = trim((string) ($entrada['ciclo_escolar'] ?? ''));
    $ttId = (int) ($entrada['tt_id'] ?? 0);

    $fechaValida = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) === 1
        && checkdate((int) substr($fecha, 5, 2), (int) substr($fecha, 8, 2), (int) substr($fecha, 0, 4));
    if (!$fechaValida || !isset(FORMULARIO1_MOVIMIENTOS[$movimiento]) || $ttId < 1) {
        throw new DomainException('Completa una fecha válida, un TT y un movimiento válido.');
    }

    if (($movimiento === 'A' && ($nuevoTitulo === '' || mb_strlen($nuevoTitulo) > 100))
        || (in_array($movimiento, ['B', 'C', 'D'], true)
            && ($boleta === '' || mb_strlen($boleta) > 10 || !in_array($plan, FORMULARIO1_PLANES, true) || $alumnoNombre === '' || mb_strlen($alumnoNombre) > 160))
        || (in_array($movimiento, ['E', 'F', 'G'], true) && ($directorNombre === '' || mb_strlen($directorNombre) > 160))
        || (in_array($movimiento, ['H', 'I', 'J'], true) && ($ciclo === '' || mb_strlen($ciclo) > 30))) {
        throw new DomainException('Completa los datos requeridos para el movimiento seleccionado.');
    }

    $db->beginTransaction();
    try {
        $ttQuery = $db->prepare(
            'SELECT tt.TT_id, tt.NumeroTT, tt.TipoTT, tt.Titulo, tt.EquipoId '
            . 'FROM TT tt JOIN Estudiante e ON e.EquipoId = tt.EquipoId '
            . 'WHERE tt.TT_id = ? AND e.UserId = ? FOR UPDATE'
        );
        $ttQuery->execute([$ttId, (int) $user['id']]);
        $tt = $ttQuery->fetch();
        if (!$tt || !in_array($tt['TipoTT'], ['TT1', 'TT2'], true)) {
            throw new DomainException('El TT no está disponible o aún no tiene tipo asignado por la CATT.');
        }
        if ($movimiento === 'B' && $tt['TipoTT'] !== 'TT1') {
            throw new DomainException('El movimiento Alta de alumno solo se permite para TT1.');
        }

        $actores = formulario1ObtenerActores($db, (int) $tt['EquipoId']);
        if (!array_filter($actores, static fn (array $actor): bool => (int) $actor['UserId'] === (int) $user['id'] && $actor['Rol'] === 'estudiante')) {
            throw new DomainException('No se encontró al alumno solicitante entre los integrantes del TT.');
        }

        $insert = $db->prepare(
            'INSERT INTO Formulario1 '
            . '(TT_id, CreadoPorUserId, TipoTT, Fecha, NumeroTT, TituloActual, Movimiento, NuevoTitulo, AlumnoNoBoleta, AlumnoPlan, AlumnoNombre, DirectorNombre, CicloEscolar) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            (int) $tt['TT_id'],
            (int) $user['id'],
            $tt['TipoTT'],
            $fecha,
            $tt['NumeroTT'],
            $tt['Titulo'],
            $movimiento,
            $movimiento === 'A' ? $nuevoTitulo : null,
            in_array($movimiento, ['B', 'C', 'D'], true) ? $boleta : null,
            in_array($movimiento, ['B', 'C', 'D'], true) ? $plan : null,
            in_array($movimiento, ['B', 'C', 'D'], true) ? $alumnoNombre : null,
            in_array($movimiento, ['E', 'F', 'G'], true) ? $directorNombre : null,
            in_array($movimiento, ['H', 'I', 'J'], true) ? $ciclo : null,
        ]);
        $formularioId = (int) $db->lastInsertId();
        $actorInsert = $db->prepare('INSERT INTO Formulario1_Firma (Formulario1Id, UserId, Rol) VALUES (?, ?, ?)');
        foreach ($actores as $actor) {
            $actorInsert->execute([$formularioId, (int) $actor['UserId'], $actor['Rol']]);
        }
        $db->commit();
        return $formularioId;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function formulario1SubirFirma(PDO $db, int $formularioId, array $user, string $rol, array $archivo): void
{
    $rolesPermitidos = ['estudiante', 'director', 'sinodal', 'profesor_seguimiento'];
    if (!in_array($rol, $rolesPermitidos, true) || !in_array($rol, $user['roles'] ?? [], true)) {
        throw new DomainException('No tienes el rol requerido para subir esta firma.');
    }
    $errorArchivo = (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorArchivo === UPLOAD_ERR_NO_FILE) {
        throw new DomainException('Selecciona el archivo PDF de tu firma.');
    }
    if (in_array($errorArchivo, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        throw new DomainException('El PDF de firma no puede superar 1 MB.');
    }
    if ($errorArchivo !== UPLOAD_ERR_OK
        || !is_string($archivo['tmp_name'] ?? null)
        || !is_string($archivo['name'] ?? null)
        || !is_uploaded_file($archivo['tmp_name'])) {
        throw new DomainException('No fue posible recibir el archivo PDF de firma.');
    }

    $nombre = $archivo['name'];
    $rutaTemporal = $archivo['tmp_name'];
    formulario1ValidarArchivoFirma($rutaTemporal, $nombre);

    $directorio = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'formulario1_firmas';
    if (!is_dir($directorio) && !mkdir($directorio, 0750, true) && !is_dir($directorio)) {
        throw new RuntimeException('No fue posible preparar el almacenamiento privado de firmas.');
    }
    $nombreGuardado = bin2hex(random_bytes(24)) . '.pdf';
    $rutaAbsoluta = $directorio . DIRECTORY_SEPARATOR . $nombreGuardado;
    $rutaRelativa = 'storage/formulario1_firmas/' . $nombreGuardado;
    if (!move_uploaded_file($rutaTemporal, $rutaAbsoluta)) {
        throw new RuntimeException('No fue posible guardar el PDF de firma.');
    }

    try {
        $db->beginTransaction();
        $formQuery = $db->prepare('SELECT Estado FROM Formulario1 WHERE Formulario1Id = ? FOR UPDATE');
        $formQuery->execute([$formularioId]);
        if ($formQuery->fetchColumn() !== 'pendiente_firmas') {
            throw new DomainException('El formulario ya no está esperando firmas.');
        }
        $signatureQuery = $db->prepare(
            'SELECT FirmaId, RutaArchivo FROM Formulario1_Firma WHERE Formulario1Id = ? AND UserId = ? AND Rol = ? FOR UPDATE'
        );
        $signatureQuery->execute([$formularioId, (int) $user['id'], $rol]);
        $signature = $signatureQuery->fetch();
        if (!$signature) {
            throw new DomainException('No estás registrado como firmante de este formulario.');
        }
        if ($signature['RutaArchivo'] !== null) {
            throw new DomainException('Tu firma ya fue cargada para este formulario.');
        }
        $db->prepare('UPDATE Formulario1_Firma SET RutaArchivo = ?, FechaCarga = CURRENT_TIMESTAMP WHERE FirmaId = ?')
            ->execute([$rutaRelativa, (int) $signature['FirmaId']]);
        $db->prepare(
            "UPDATE Formulario1 SET Estado = 'en_revision_catt' "
            . "WHERE Formulario1Id = ? AND Estado = 'pendiente_firmas' "
            . 'AND NOT EXISTS (SELECT 1 FROM Formulario1_Firma WHERE Formulario1Id = ? AND RutaArchivo IS NULL)'
        )->execute([$formularioId, $formularioId]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if (is_file($rutaAbsoluta) && !unlink($rutaAbsoluta)) {
            throw new RuntimeException('No fue posible limpiar el PDF tras cancelar la carga.', 0, $exception);
        }
        throw $exception;
    }
}

function formulario1ValidarArchivoFirma(string $rutaTemporal, string $nombre): void
{
    $extension = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    $tamano = filesize($rutaTemporal);
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $rutaTemporal) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    if ($extension !== 'pdf' || $mime !== 'application/pdf') {
        throw new DomainException('La firma debe estar en formato PDF.');
    }
    if ($tamano === false || $tamano > FORMULARIO1_MAX_FIRMA_BYTES) {
        throw new DomainException('El PDF de firma no puede superar 1 MB.');
    }
    try {
        $paginas = (new \setasign\Fpdi\Fpdi())->setSourceFile($rutaTemporal);
    } catch (Throwable $exception) {
        throw new DomainException('El archivo de firma no es un PDF válido o legible.', 0, $exception);
    }
    if ($paginas < 1) {
        throw new DomainException('El archivo PDF de firma no contiene páginas legibles.');
    }
}

function formulario1Detalle(PDO $db, int $formularioId): ?array
{
    $query = $db->prepare(
        'SELECT f.*, tt.fecha_presentacion, tt.EquipoId '
        . 'FROM Formulario1 f JOIN TT tt ON tt.TT_id = f.TT_id WHERE f.Formulario1Id = ?'
    );
    $query->execute([$formularioId]);
    $formulario = $query->fetch();
    if (!$formulario) {
        return null;
    }
    $actorsQuery = $db->prepare(
        'SELECT ff.FirmaId, ff.UserId, ff.Rol, ff.RutaArchivo, ff.FechaCarga, '
        . 'u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno, e.NumeroBoleta '
        . 'FROM Formulario1_Firma ff JOIN Usuarios u ON u.UserId = ff.UserId '
        . 'LEFT JOIN Estudiante e ON e.UserId = ff.UserId '
        . 'WHERE ff.Formulario1Id = ? '
        . "ORDER BY FIELD(ff.Rol, 'estudiante', 'director', 'sinodal', 'profesor_seguimiento'), "
        . 'u.ApellidoPaterno, u.ApellidoMaterno, u.Nombre'
    );
    $actorsQuery->execute([$formularioId]);
    $formulario['firmantes'] = $actorsQuery->fetchAll();
    return $formulario;
}

function formulario1PuedeAcceder(array $user, array $formulario): bool
{
    if (formulario1EsCatt($user)) {
        return true;
    }
    foreach ($formulario['firmantes'] as $firmante) {
        if ((int) $firmante['UserId'] === (int) $user['id'] && in_array($firmante['Rol'], $user['roles'] ?? [], true)) {
            return true;
        }
    }
    return false;
}

function formulario1Listar(PDO $db, array $user): array
{
    if (formulario1EsCatt($user)) {
        $query = $db->query(
            'SELECT f.Formulario1Id FROM Formulario1 f ORDER BY '
            . "FIELD(f.Estado, 'en_revision_catt', 'pendiente_firmas', 'rechazado', 'aprobado'), f.CreadoEn DESC"
        );
    } else {
        $query = $db->prepare(
            'SELECT DISTINCT f.Formulario1Id FROM Formulario1 f '
            . 'JOIN Formulario1_Firma ff ON ff.Formulario1Id = f.Formulario1Id '
            . 'WHERE ff.UserId = ? ORDER BY f.Formulario1Id DESC'
        );
        $query->execute([(int) $user['id']]);
    }
    $formularios = [];
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $formularioId) {
        $formulario = formulario1Detalle($db, (int) $formularioId);
        if ($formulario && formulario1PuedeAcceder($user, $formulario)) {
            $formularios[] = $formulario;
        }
    }
    return $formularios;
}

function formulario1Revisar(PDO $db, int $formularioId, string $decision, string $observaciones): void
{
    if (!in_array($decision, ['aprobado', 'rechazado'], true)) {
        throw new DomainException('Selecciona una decisión válida.');
    }
    if ($decision === 'rechazado' && trim($observaciones) === '') {
        throw new DomainException('Captura el motivo del rechazo en observaciones.');
    }
    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT Estado FROM Formulario1 WHERE Formulario1Id = ? FOR UPDATE');
        $query->execute([$formularioId]);
        if ($query->fetchColumn() !== 'en_revision_catt') {
            throw new DomainException('El formulario no está listo para revisión de la CATT.');
        }
        $update = $db->prepare(
            "UPDATE Formulario1 SET Estado = ?, Observaciones = ? WHERE Formulario1Id = ? AND Estado = 'en_revision_catt'"
        );
        $update->execute([$decision, trim($observaciones) ?: null, $formularioId]);
        if ($update->rowCount() !== 1) {
            throw new DomainException('El formulario cambió de estado. Actualiza la página e intenta de nuevo.');
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function formulario1AsignarTipoTT(PDO $db, int $ttId, string $tipoTT): void
{
    if (!in_array($tipoTT, ['TT1', 'TT2'], true)) {
        throw new DomainException('Selecciona TT1 o TT2.');
    }
    $db->beginTransaction();
    try {
        $exists = $db->prepare(
            'SELECT TT_id FROM TT WHERE TT_id = ? '
            . 'AND NOT EXISTS (SELECT 1 FROM Formulario1 WHERE TT_id = ?) FOR UPDATE'
        );
        $exists->execute([$ttId, $ttId]);
        if ($exists->fetchColumn() === false) {
            throw new DomainException('El TT no existe o su tipo ya no se puede modificar porque tiene un formulario asociado.');
        }
        $db->prepare('UPDATE TT SET TipoTT = ? WHERE TT_id = ?')->execute([$tipoTT, $ttId]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function formulario1AlumnoPerteneceATt(PDO $db, int $userId, int $ttId): bool
{
    $query = $db->prepare(
        'SELECT COUNT(*) FROM TT tt JOIN Estudiante e ON e.EquipoId = tt.EquipoId '
        . 'WHERE tt.TT_id = ? AND e.UserId = ?'
    );
    $query->execute([$ttId, $userId]);
    return (int) $query->fetchColumn() > 0;
}

function formulario1PuedeDescargar(PDO $db, array $user, array $formulario): bool
{
    if (!in_array('estudiante', $user['roles'] ?? [], true)
        || $formulario['Estado'] !== 'aprobado'
        || empty($formulario['fecha_presentacion'])
        || !formulario1AlumnoPerteneceATt($db, (int) $user['id'], (int) $formulario['TT_id'])) {
        return false;
    }

    return formulario1FechaDisponible((string) $formulario['fecha_presentacion']);
}

function formulario1FechaDisponible(?string $fechaPresentacion, ?DateTimeImmutable $hoy = null): bool
{
    if ($fechaPresentacion === null || $fechaPresentacion === '') {
        return false;
    }
    $fechaDisponible = (new DateTimeImmutable($fechaPresentacion))->modify('-7 days');
    return ($hoy ?? new DateTimeImmutable('today')) >= $fechaDisponible;
}

function formulario1RutaFirma(string $rutaRelativa): string
{
    if (!str_starts_with($rutaRelativa, 'storage/formulario1_firmas/') || str_contains($rutaRelativa, '..')) {
        throw new RuntimeException('La ruta de firma guardada no es válida.');
    }
    $ruta = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rutaRelativa));
    $directorio = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'formulario1_firmas');
    if ($ruta === false || $directorio === false || !str_starts_with($ruta, $directorio . DIRECTORY_SEPARATOR) || !is_file($ruta)) {
        throw new RuntimeException('No se encontró un archivo de firma requerido.');
    }
    return $ruta;
}

function formulario1TextoPdf(string $texto): string
{
    $convertido = iconv('UTF-8', 'windows-1252//TRANSLIT', $texto);
    if ($convertido === false) {
        throw new RuntimeException('No fue posible convertir el texto del formulario para el PDF.');
    }
    return $convertido;
}

function formulario1GenerarPdf(array $formulario): string
{
    $pdf = new \setasign\Fpdi\Fpdi();
    $pdf->SetTitle(formulario1TextoPdf('Formulario 1 - Actualización de Datos del Protocolo/TT'));
    $pdf->SetAuthor('PROTTOCOL CATT');
    $pdf->SetMargins(18, 18, 18);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->MultiCell(0, 9, formulario1TextoPdf('Formulario 1: Actualización de Datos del Protocolo/TT'), 0, 'C');
    $pdf->Ln(5);

    $bloque = static function (string $etiqueta, string $valor) use ($pdf): void {
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->MultiCell(0, 6, formulario1TextoPdf($etiqueta));
        $pdf->SetFont('Arial', '', 11);
        $pdf->MultiCell(0, 7, formulario1TextoPdf($valor !== '' ? $valor : '—'));
        $pdf->Ln(2);
    };

    $bloque('Fecha', date('d/m/Y', strtotime((string) $formulario['Fecha'])));
    $bloque('Tipo de Trabajo Terminal', (string) $formulario['TipoTT']);
    $bloque('No. de TT', (string) $formulario['NumeroTT']);
    $bloque('Título actual', (string) $formulario['TituloActual']);
    $bloque('Movimiento', FORMULARIO1_MOVIMIENTOS[$formulario['Movimiento']]);
    if ($formulario['Movimiento'] === 'A') {
        $bloque('Nuevo título', (string) $formulario['NuevoTitulo']);
    } elseif (in_array($formulario['Movimiento'], ['B', 'C', 'D'], true)) {
        $bloque('No. de boleta', (string) $formulario['AlumnoNoBoleta']);
        $bloque('Plan', (string) $formulario['AlumnoPlan']);
        $bloque('Nombre del alumno', (string) $formulario['AlumnoNombre']);
    } elseif (in_array($formulario['Movimiento'], ['E', 'F', 'G'], true)) {
        $bloque('Nombre del director', (string) $formulario['DirectorNombre']);
    } else {
        $bloque('Ciclo escolar', (string) $formulario['CicloEscolar']);
    }
    $bloque('Estado', (string) $formulario['Estado']);
    $bloque('Observaciones de la CATT', (string) ($formulario['Observaciones'] ?? ''));
    $pdf->Ln(4);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, formulario1TextoPdf('Firmas autógrafas anexas'), 0, 1);
    $pdf->SetFont('Arial', '', 10);
    foreach ($formulario['firmantes'] as $firmante) {
        $nombre = trim(implode(' ', array_filter([
            $firmante['Nombre'],
            $firmante['ApellidoPaterno'],
            $firmante['ApellidoMaterno'],
        ])));
        $pdf->MultiCell(0, 6, formulario1TextoPdf(
            formulario1NombreRol((string) $firmante['Rol']) . ': ' . $nombre . ' — ' . (string) $firmante['FechaCarga']
        ));
    }

    foreach ($formulario['firmantes'] as $firmante) {
        $rutaFirma = formulario1RutaFirma((string) $firmante['RutaArchivo']);
        $nombre = trim(implode(' ', array_filter([
            $firmante['Nombre'],
            $firmante['ApellidoPaterno'],
            $firmante['ApellidoMaterno'],
        ])));
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->MultiCell(0, 8, formulario1TextoPdf(formulario1NombreRol((string) $firmante['Rol']) . ' - ' . $nombre), 0, 'C');
        $paginas = $pdf->setSourceFile($rutaFirma);
        for ($pagina = 1; $pagina <= $paginas; $pagina++) {
            $plantilla = $pdf->importPage($pagina);
            $tamano = $pdf->getTemplateSize($plantilla);
            $pdf->AddPage($tamano['orientation'], [$tamano['width'], $tamano['height']]);
            $pdf->useTemplate($plantilla, 0, 0, $tamano['width'], $tamano['height']);
        }
    }

    return $pdf->Output('S');
}
