<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/pdf.php';

const ACUSE_TIPOS_TT = ['TT-I', 'TT-II', 'TT-R'];
const ACUSE_MEDIOS_ENTREGA = [
    'formato_impreso' => 'Formato impreso',
    'correo_electronico' => 'Correo electrónico',
    'disco_usb' => 'Disco / USB',
    'otro' => 'Otro',
];

function acuseEsCatt(array $user): bool
{
    return (bool) array_intersect($user['roles'] ?? [], ['miembroCatt', 'jefeAcademia']);
}

function acuseNombreRol(string $rol): string
{
    return [
        'director' => 'Director',
        'sinodal' => 'Sinodal',
        'profesor_seguimiento' => 'Profesor de Seguimiento',
    ][$rol] ?? $rol;
}

function acuseObtenerActores(PDO $db, int $equipoId): array
{
    $query = $db->prepare(
        "SELECT u.UserId, CASE ea.Rol WHEN 'seguimiento' THEN 'profesor_seguimiento' ELSE ea.Rol END AS Rol, "
        . 'u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno '
        . 'FROM Equipo_Asignacion ea JOIN Usuarios u ON u.UserId = ea.UserId '
        . 'JOIN Usuario_Roles ur ON ur.UserId = u.UserId '
        . "AND ur.Rol = CASE ea.Rol WHEN 'seguimiento' THEN 'profesor_seguimiento' ELSE ea.Rol END "
        . "WHERE ea.EquipoId = ? AND ea.Rol IN ('director', 'sinodal', 'seguimiento') "
        . "ORDER BY FIELD(ea.Rol, 'director', 'sinodal', 'seguimiento'), u.ApellidoPaterno, u.Nombre"
    );
    $query->execute([$equipoId]);
    $actores = $query->fetchAll();
    $conteos = array_count_values(array_column($actores, 'Rol'));
    $maximos = ['director' => 2, 'sinodal' => 3, 'profesor_seguimiento' => 1];
    $etiquetasMaximo = ['director' => 'Directores', 'sinodal' => 'Sinodales', 'profesor_seguimiento' => 'Profesores de Seguimiento'];
    foreach ($maximos as $rol => $maximo) {
        if (($conteos[$rol] ?? 0) > $maximo) {
            throw new DomainException('El TT excede el máximo permitido de ' . $etiquetasMaximo[$rol] . '.');
        }
    }
    if (!$actores) {
        throw new DomainException('El TT no tiene directores, sinodales o profesor de seguimiento asignados para firmar.');
    }
    return $actores;
}

function acuseCrear(PDO $db, array $user, int $ttId, string $tipoTT): int
{
    if (!in_array('estudiante', $user['roles'] ?? [], true)) {
        throw new DomainException('Solo un alumno puede iniciar el acuse.');
    }
    if (!in_array($tipoTT, ACUSE_TIPOS_TT, true) || $ttId < 1) {
        throw new DomainException('Selecciona el tipo y el número de TT.');
    }

    $db->beginTransaction();
    try {
        $ttQuery = $db->prepare(
            'SELECT tt.TT_id, tt.TipoTT, tt.EquipoId FROM TT tt '
            . 'JOIN Estudiante e ON e.EquipoId = tt.EquipoId '
            . 'WHERE tt.TT_id = ? AND e.UserId = ? FOR UPDATE'
        );
        $ttQuery->execute([$ttId, (int) $user['id']]);
        $tt = $ttQuery->fetch();
        if (!$tt || !in_array($tt['TipoTT'], ['TT1', 'TT2'], true)) {
            throw new DomainException('El TT seleccionado no existe o no tiene tipo asignado por la CATT.');
        }
        if (($tipoTT === 'TT-I' && $tt['TipoTT'] !== 'TT1') || ($tipoTT === 'TT-II' && $tt['TipoTT'] !== 'TT2')) {
            throw new DomainException('El tipo de acuse no coincide con el tipo asignado al TT.');
        }
        $actores = acuseObtenerActores($db, (int) $tt['EquipoId']);
        $db->prepare(
            "INSERT INTO acuses_reporte_tecnico (tt_id, tipo_tt, fecha_emision, estado) "
            . "VALUES (?, ?, CURRENT_DATE, 'pendiente_firmas')"
        )->execute([$ttId, $tipoTT]);
        $acuseId = (int) $db->lastInsertId();
        $insertEntrega = $db->prepare('INSERT INTO acuse_entregas (acuse_id, usuario_id, rol) VALUES (?, ?, ?)');
        foreach ($actores as $actor) {
            $insertEntrega->execute([$acuseId, (int) $actor['UserId'], $actor['Rol']]);
        }
        $db->commit();
        return $acuseId;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function acuseDetalle(PDO $db, int $acuseId): ?array
{
    $query = $db->prepare(
        'SELECT a.*, tt.NumeroTT, tt.Titulo, tt.fecha_presentacion, tt.EquipoId '
        . 'FROM acuses_reporte_tecnico a JOIN TT tt ON tt.TT_id = a.tt_id WHERE a.id = ?'
    );
    $query->execute([$acuseId]);
    $acuse = $query->fetch();
    if (!$acuse) {
        return null;
    }
    $entregasQuery = $db->prepare(
        'SELECT e.id, e.usuario_id, e.rol, e.medio_entrega, e.otro_especificar, e.firma_pdf_path, e.firma_png_path, e.fecha_firma, '
        . 'u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno '
        . 'FROM acuse_entregas e JOIN Usuarios u ON u.UserId = e.usuario_id '
        . 'WHERE e.acuse_id = ? '
        . "ORDER BY FIELD(e.rol, 'director', 'sinodal', 'profesor_seguimiento'), u.ApellidoPaterno, u.Nombre"
    );
    $entregasQuery->execute([$acuseId]);
    $acuse['entregas'] = $entregasQuery->fetchAll();
    $alumnosQuery = $db->prepare(
        'SELECT e.UserId FROM Estudiante e JOIN TT tt ON tt.EquipoId = e.EquipoId '
        . 'WHERE tt.TT_id = ?'
    );
    $alumnosQuery->execute([(int) $acuse['tt_id']]);
    $acuse['alumnos'] = array_map('intval', $alumnosQuery->fetchAll(PDO::FETCH_COLUMN));
    return $acuse;
}

function acusePuedeAcceder(array $user, array $acuse): bool
{
    if (acuseEsCatt($user) || in_array((int) $user['id'], $acuse['alumnos'], true)) {
        return true;
    }
    foreach ($acuse['entregas'] as $entrega) {
        if ((int) $entrega['usuario_id'] === (int) $user['id'] && in_array($entrega['rol'], $user['roles'] ?? [], true)) {
            return true;
        }
    }
    return false;
}

function acuseListar(PDO $db, array $user): array
{
    if (acuseEsCatt($user)) {
        $query = $db->query(
            'SELECT id FROM acuses_reporte_tecnico '
            . "ORDER BY FIELD(estado, 'en_revision_catt', 'pendiente_firmas', 'rechazado', 'aprobado'), created_at DESC"
        );
    } else {
        $query = $db->prepare(
            'SELECT DISTINCT a.id FROM acuses_reporte_tecnico a JOIN TT tt ON tt.TT_id = a.tt_id '
            . 'WHERE EXISTS (SELECT 1 FROM Estudiante e WHERE e.EquipoId = tt.EquipoId AND e.UserId = ?) '
            . 'OR EXISTS (SELECT 1 FROM acuse_entregas d WHERE d.acuse_id = a.id AND d.usuario_id = ?) '
            . 'ORDER BY a.id DESC'
        );
        $query->execute([(int) $user['id'], (int) $user['id']]);
    }
    $acuses = [];
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $acuseId) {
        $acuse = acuseDetalle($db, (int) $acuseId);
        if ($acuse && acusePuedeAcceder($user, $acuse)) {
            $acuses[] = $acuse;
        }
    }
    return $acuses;
}

function acuseGuardarEntrega(PDO $db, int $entregaId, array $user, string $medio, string $otro, array $archivo): void
{
    if (!isset(ACUSE_MEDIOS_ENTREGA[$medio])) {
        throw new DomainException('Selecciona un medio de entrega válido.');
    }
    $otro = trim($otro);
    if (($medio === 'otro' && ($otro === '' || mb_strlen($otro) > 255)) || ($medio !== 'otro' && $otro !== '')) {
        throw new DomainException('Indica el medio de entrega en Otro o borra ese dato si elegiste otro medio.');
    }
    $rolesValidos = ['director', 'sinodal', 'profesor_seguimiento'];
    $marcas = implode(', ', array_fill(0, count($rolesValidos), '?'));
    $entregaQuery = $db->prepare(
        'SELECT e.id, e.acuse_id, e.usuario_id, e.rol, e.firma_pdf_path, a.estado '
        . 'FROM acuse_entregas e JOIN acuses_reporte_tecnico a ON a.id = e.acuse_id '
        . "WHERE e.id = ? AND e.usuario_id = ? AND e.rol IN ({$marcas})"
    );
    $entregaQuery->execute(array_merge([$entregaId, (int) $user['id']], $rolesValidos));
    $entregaActual = $entregaQuery->fetch();
    if (!$entregaActual || !in_array($entregaActual['rol'], $user['roles'] ?? [], true)) {
        throw new DomainException('No estás registrado como firmante de este acuse.');
    }
    if ($entregaActual['estado'] !== 'pendiente_firmas' || $entregaActual['firma_pdf_path'] !== null) {
        throw new DomainException('Este acuse ya no está esperando tu firma.');
    }

    $rutasFirma = guardarFirmaPdfYConvertirPng($archivo, 'acuses_reporte_tecnico/firmas');
    try {
        $db->beginTransaction();
        $acuseQuery = $db->prepare('SELECT estado FROM acuses_reporte_tecnico WHERE id = ? FOR UPDATE');
        $acuseQuery->execute([(int) $entregaActual['acuse_id']]);
        if ($acuseQuery->fetchColumn() !== 'pendiente_firmas') {
            throw new DomainException('El acuse ya no está esperando firmas.');
        }
        $lockEntrega = $db->prepare(
            'SELECT firma_pdf_path FROM acuse_entregas WHERE id = ? AND usuario_id = ? AND rol = ? FOR UPDATE'
        );
        $lockEntrega->execute([$entregaId, (int) $user['id'], $entregaActual['rol']]);
        if ($lockEntrega->fetchColumn() !== null) {
            throw new DomainException('Tu firma ya fue cargada para este acuse.');
        }
        $db->prepare(
            'UPDATE acuse_entregas SET medio_entrega = ?, otro_especificar = ?, firma_pdf_path = ?, firma_png_path = ?, fecha_firma = CURRENT_TIMESTAMP WHERE id = ?'
        )->execute([
            $medio,
            $medio === 'otro' ? $otro : null,
            $rutasFirma['pdf_relativa'],
            $rutasFirma['png_relativa'],
            $entregaId,
        ]);
        $db->prepare(
            "UPDATE acuses_reporte_tecnico SET estado = 'en_revision_catt' "
            . "WHERE id = ? AND estado = 'pendiente_firmas' "
            . 'AND NOT EXISTS (SELECT 1 FROM acuse_entregas WHERE acuse_id = ? AND firma_pdf_path IS NULL)'
        )->execute([(int) $entregaActual['acuse_id'], (int) $entregaActual['acuse_id']]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        foreach ([$rutasFirma['png_absoluta'], $rutasFirma['pdf_absoluta']] as $ruta) {
            if (is_file($ruta) && !unlink($ruta)) {
                throw new RuntimeException('No fue posible limpiar los archivos de firma tras cancelar la carga.', 0, $exception);
            }
        }
        throw $exception;
    }
}

function acuseRevisar(PDO $db, int $acuseId, string $decision, string $observaciones): void
{
    if (!in_array($decision, ['aprobado', 'rechazado'], true)) {
        throw new DomainException('Selecciona una decisión válida.');
    }
    if ($decision === 'rechazado' && trim($observaciones) === '') {
        throw new DomainException('Captura el motivo del rechazo.');
    }
    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT estado FROM acuses_reporte_tecnico WHERE id = ? FOR UPDATE');
        $query->execute([$acuseId]);
        if ($query->fetchColumn() !== 'en_revision_catt') {
            throw new DomainException('El acuse no está listo para revisión de la CATT.');
        }
        $db->prepare(
            "UPDATE acuses_reporte_tecnico SET estado = ?, observaciones_catt = ? WHERE id = ? AND estado = 'en_revision_catt'"
        )->execute([$decision, trim($observaciones) ?: null, $acuseId]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function acuseEnVentanaPresentacion(?string $fechaPresentacion, ?DateTimeImmutable $hoy = null): bool
{
    if ($fechaPresentacion === null || $fechaPresentacion === '') {
        return false;
    }
    $hoy ??= new DateTimeImmutable('today');
    $presentacion = new DateTimeImmutable($fechaPresentacion);
    return $hoy >= $presentacion->modify('-7 days') && $hoy <= $presentacion;
}

function acusePuedeDescargar(PDO $db, array $user, array $acuse): bool
{
    if (!in_array('estudiante', $user['roles'] ?? [], true)
        || $acuse['estado'] !== 'aprobado'
        || !in_array((int) $user['id'], $acuse['alumnos'], true)
        || !acuseEnVentanaPresentacion($acuse['fecha_presentacion'] ?? null)) {
        return false;
    }
    foreach ($acuse['entregas'] as $entrega) {
        if (!$entrega['firma_pdf_path']) {
            return false;
        }
    }
    return true;
}

function acuseGenerarPdf(array $acuse): string
{
    $pdf = new \setasign\Fpdi\Fpdi();
    $pdf->SetTitle(pdfTexto('Acuse de Recibo del Reporte Técnico Final'));
    $pdf->SetAuthor('PROTTOCOL CATT');
    $pdf->SetMargins(18, 18, 18);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->MultiCell(0, 9, pdfTexto('Acuse de Recibo del Reporte Técnico Final'), 0, 'C');
    $pdf->Ln(5);

    $bloque = static function (string $etiqueta, string $valor) use ($pdf): void {
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->MultiCell(0, 6, pdfTexto($etiqueta));
        $pdf->SetFont('Arial', '', 11);
        $pdf->MultiCell(0, 7, pdfTexto($valor !== '' ? $valor : '—'));
        $pdf->Ln(2);
    };
    $bloque('Fecha de emisión', date('d/m/Y', strtotime((string) $acuse['fecha_emision'])));
    $bloque('Tipo de TT', (string) $acuse['tipo_tt']);
    $bloque('Número de TT', (string) $acuse['NumeroTT']);
    $bloque('Título', (string) $acuse['Titulo']);
    $bloque('Estado', (string) $acuse['estado']);
    if (!empty($acuse['observaciones_catt'])) {
        $bloque('Observaciones de la CATT', (string) $acuse['observaciones_catt']);
    }

    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, pdfTexto('Recepciones y firmas anexas'), 0, 1);
    foreach ($acuse['entregas'] as $entrega) {
        $nombre = trim(implode(' ', array_filter([
            $entrega['Nombre'],
            $entrega['ApellidoPaterno'],
            $entrega['ApellidoMaterno'],
        ])));
        $medio = ACUSE_MEDIOS_ENTREGA[$entrega['medio_entrega']] ?? 'Pendiente';
        if ($entrega['medio_entrega'] === 'otro') {
            $medio .= ': ' . $entrega['otro_especificar'];
        }
        $bloque(
            acuseNombreRol((string) $entrega['rol']) . ' - ' . $nombre,
            $medio . ' · ' . (string) $entrega['fecha_firma']
        );
    }
    foreach ($acuse['entregas'] as $entrega) {
        $nombre = trim(implode(' ', array_filter([
            $entrega['Nombre'],
            $entrega['ApellidoPaterno'],
            $entrega['ApellidoMaterno'],
        ])));
        pdfAnexarFirmado(
            $pdf,
            rutaPdfFirmado((string) $entrega['firma_pdf_path'], 'acuses_reporte_tecnico/firmas'),
            acuseNombreRol((string) $entrega['rol']) . ' - ' . $nombre
        );
    }
    return $pdf->Output('S');
}
