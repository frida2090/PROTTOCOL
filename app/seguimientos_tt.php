<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/pdf.php';

function seguimientoEsCatt(array $user): bool
{
    return (bool) array_intersect($user['roles'] ?? [], ['miembroCatt', 'jefeAcademia']);
}

function seguimientoEstaAsignado(PDO $db, int $userId, int $ttId): bool
{
    $query = $db->prepare(
        "SELECT COUNT(*) FROM TT tt JOIN Equipo_Asignacion ea ON ea.EquipoId = tt.EquipoId "
        . "WHERE tt.TT_id = ? AND ea.UserId = ? AND ea.Rol = 'seguimiento'"
    );
    $query->execute([$ttId, $userId]);
    return (int) $query->fetchColumn() === 1;
}

function seguimientoListar(PDO $db, array $user): array
{
    if (seguimientoEsCatt($user)) {
        $query = $db->query(
            'SELECT tt.TT_id, tt.NumeroTT, tt.TipoTT, tt.Titulo, tt.EquipoId, s.id AS seguimiento_id '
            . 'FROM TT tt LEFT JOIN seguimientos_tt s ON s.tt_id = tt.TT_id ORDER BY tt.NumeroTT'
        );
    } elseif (in_array('profesor_seguimiento', $user['roles'] ?? [], true)) {
        $query = $db->prepare(
            "SELECT tt.TT_id, tt.NumeroTT, tt.TipoTT, tt.Titulo, tt.EquipoId, s.id AS seguimiento_id "
            . 'FROM TT tt JOIN Equipo_Asignacion ea ON ea.EquipoId = tt.EquipoId '
            . 'LEFT JOIN seguimientos_tt s ON s.tt_id = tt.TT_id '
            . "WHERE ea.UserId = ? AND ea.Rol = 'seguimiento' ORDER BY tt.NumeroTT"
        );
        $query->execute([(int) $user['id']]);
    } else {
        $query = $db->prepare(
            'SELECT tt.TT_id, tt.NumeroTT, tt.TipoTT, tt.Titulo, tt.EquipoId, s.id AS seguimiento_id '
            . 'FROM TT tt JOIN Estudiante e ON e.EquipoId = tt.EquipoId '
            . 'LEFT JOIN seguimientos_tt s ON s.tt_id = tt.TT_id '
            . 'WHERE e.UserId = ? ORDER BY tt.NumeroTT'
        );
        $query->execute([(int) $user['id']]);
    }
    return $query->fetchAll();
}

function seguimientoDetalle(PDO $db, int $ttId): ?array
{
    $query = $db->prepare(
        'SELECT tt.TT_id, tt.NumeroTT, tt.TipoTT, tt.Titulo, tt.EquipoId, '
        . 's.id AS seguimiento_id, s.profesor_seguimiento_id, s.unidad_aprendizaje, s.grupo, s.ciclo_escolar, s.lugar_reunion, '
        . 'u.Nombre AS profesor_nombre, u.ApellidoPaterno AS profesor_apellido_paterno, u.ApellidoMaterno AS profesor_apellido_materno '
        . 'FROM TT tt LEFT JOIN seguimientos_tt s ON s.tt_id = tt.TT_id '
        . 'LEFT JOIN Usuarios u ON u.UserId = s.profesor_seguimiento_id WHERE tt.TT_id = ?'
    );
    $query->execute([$ttId]);
    $detalle = $query->fetch();
    if (!$detalle) {
        return null;
    }
    $students = $db->prepare(
        'SELECT u.UserId, u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno, e.NumeroBoleta '
        . 'FROM Estudiante e JOIN Usuarios u ON u.UserId = e.UserId WHERE e.EquipoId = ? '
        . 'ORDER BY u.ApellidoPaterno, u.ApellidoMaterno, u.Nombre'
    );
    $students->execute([(int) $detalle['EquipoId']]);
    $detalle['alumnos'] = $students->fetchAll();
    $detalle['sesiones'] = [];
    if ($detalle['seguimiento_id'] !== null) {
        $sessions = $db->prepare(
            'SELECT * FROM seguimiento_sesiones WHERE seguimiento_id = ? ORDER BY numero_sesion'
        );
        $sessions->execute([(int) $detalle['seguimiento_id']]);
        $detalle['sesiones'] = $sessions->fetchAll();
        $attendance = $db->prepare(
            'SELECT a.*, u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno, e.NumeroBoleta '
            . 'FROM seguimiento_asistencias_alumnos a JOIN Usuarios u ON u.UserId = a.alumno_id '
            . 'LEFT JOIN Estudiante e ON e.UserId = a.alumno_id '
            . 'JOIN seguimiento_sesiones s ON s.id = a.sesion_id WHERE s.seguimiento_id = ? '
            . 'ORDER BY s.numero_sesion, u.ApellidoPaterno, u.Nombre'
        );
        $attendance->execute([(int) $detalle['seguimiento_id']]);
        $bySession = [];
        foreach ($attendance->fetchAll() as $row) {
            $bySession[(int) $row['sesion_id']][] = $row;
        }
        foreach ($detalle['sesiones'] as &$session) {
            $session['asistencias'] = $bySession[(int) $session['id']] ?? [];
        }
        unset($session);
    }
    $detalle['avance_total'] = array_sum(array_map(
        static fn (array $session): float => (float) $session['porcentaje_avance'],
        $detalle['sesiones']
    ));
    return $detalle;
}

function seguimientoPuedeAcceder(PDO $db, array $user, array $detalle): bool
{
    if (seguimientoEsCatt($user)) {
        return true;
    }
    if (in_array('profesor_seguimiento', $user['roles'] ?? [], true)
        && seguimientoEstaAsignado($db, (int) $user['id'], (int) $detalle['TT_id'])) {
        return true;
    }
    foreach ($detalle['alumnos'] as $alumno) {
        if ((int) $alumno['UserId'] === (int) $user['id'] && in_array('estudiante', $user['roles'] ?? [], true)) {
            return true;
        }
    }
    return false;
}

function seguimientoGuardarDatos(PDO $db, int $ttId, array $user, array $entrada): void
{
    if (!in_array('profesor_seguimiento', $user['roles'] ?? [], true)
        || !seguimientoEstaAsignado($db, (int) $user['id'], $ttId)) {
        throw new DomainException('Solo el profesor de seguimiento asignado puede actualizar este formato.');
    }
    $unidad = trim((string) ($entrada['unidad_aprendizaje'] ?? ''));
    $grupo = trim((string) ($entrada['grupo'] ?? ''));
    $ciclo = trim((string) ($entrada['ciclo_escolar'] ?? ''));
    $lugar = trim((string) ($entrada['lugar_reunion'] ?? ''));
    if ($unidad === '' || mb_strlen($unidad) > 200
        || $grupo === '' || mb_strlen($grupo) > 30
        || $ciclo === '' || mb_strlen($ciclo) > 30
        || $lugar === '' || mb_strlen($lugar) > 200) {
        throw new DomainException('Completa Unidad de Aprendizaje, grupo, ciclo escolar y lugar de reunión.');
    }
    $db->prepare(
        'INSERT INTO seguimientos_tt (tt_id, profesor_seguimiento_id, unidad_aprendizaje, grupo, ciclo_escolar, lugar_reunion) '
        . 'VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE '
        . 'profesor_seguimiento_id = VALUES(profesor_seguimiento_id), unidad_aprendizaje = VALUES(unidad_aprendizaje), '
        . 'grupo = VALUES(grupo), ciclo_escolar = VALUES(ciclo_escolar), lugar_reunion = VALUES(lugar_reunion)'
    )->execute([$ttId, (int) $user['id'], $unidad, $grupo, $ciclo, $lugar]);
}

function seguimientoGuardarSesion(PDO $db, int $ttId, array $user, array $entrada): void
{
    if (!in_array('profesor_seguimiento', $user['roles'] ?? [], true)
        || !seguimientoEstaAsignado($db, (int) $user['id'], $ttId)) {
        throw new DomainException('Solo el profesor de seguimiento asignado puede registrar sesiones.');
    }
    $numero = (int) ($entrada['numero_sesion'] ?? 0);
    $fechaRaw = trim((string) ($entrada['fecha_hora'] ?? ''));
    $actividades = trim((string) ($entrada['actividades_programadas'] ?? ''));
    $observaciones = trim((string) ($entrada['observaciones'] ?? ''));
    $porcentajeRaw = trim((string) ($entrada['porcentaje_avance'] ?? ''));
    if ($numero < 1 || $numero > 65535 || mb_strlen($fechaRaw) > 20 || $actividades === '' || mb_strlen($actividades) > 5000) {
        throw new DomainException('Completa número, fecha y hora y actividades de la sesión.');
    }
    $fecha = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $fechaRaw);
    $fechaErrors = DateTimeImmutable::getLastErrors();
    if (!$fecha || ($fechaErrors !== false && ($fechaErrors['warning_count'] || $fechaErrors['error_count']))) {
        throw new DomainException('La fecha y hora de la sesión no son válidas.');
    }
    if (!is_numeric($porcentajeRaw)) {
        throw new DomainException('El porcentaje de avance debe ser un número entre 0 y 100.');
    }
    $porcentaje = (float) $porcentajeRaw;
    if (!is_finite($porcentaje) || $porcentaje < 0 || $porcentaje > 100 || mb_strlen($observaciones) > 5000) {
        throw new DomainException('El porcentaje debe estar entre 0 y 100 y las observaciones no deben exceder 5000 caracteres.');
    }

    $db->beginTransaction();
    try {
        $tracking = $db->prepare('SELECT id FROM seguimientos_tt WHERE tt_id = ? AND profesor_seguimiento_id = ? FOR UPDATE');
        $tracking->execute([$ttId, (int) $user['id']]);
        $seguimientoId = $tracking->fetchColumn();
        if (!$seguimientoId) {
            throw new DomainException('Primero guarda los datos generales del formato de seguimiento.');
        }
        $existing = $db->prepare(
            'SELECT id, porcentaje_avance, firma_profesor_pdf_path FROM seguimiento_sesiones WHERE seguimiento_id = ? AND numero_sesion = ? FOR UPDATE'
        );
        $existing->execute([(int) $seguimientoId, $numero]);
        $session = $existing->fetch();
        if ($session && $session['firma_profesor_pdf_path'] !== null) {
            throw new DomainException('No se puede editar una sesión cuya asistencia del profesor ya fue firmada.');
        }
        if ($session) {
            $firmasAlumnos = $db->prepare(
                'SELECT COUNT(*) FROM seguimiento_asistencias_alumnos WHERE sesion_id = ? AND firma_alumno_pdf_path IS NOT NULL'
            );
            $firmasAlumnos->execute([(int) $session['id']]);
            if ((int) $firmasAlumnos->fetchColumn() > 0) {
                throw new DomainException('No se puede editar una sesión cuya asistencia de alumnos ya fue firmada.');
            }
        }
        $sumQuery = $db->prepare(
            'SELECT COALESCE(SUM(porcentaje_avance), 0) FROM seguimiento_sesiones WHERE seguimiento_id = ? AND numero_sesion <> ?'
        );
        $sumQuery->execute([(int) $seguimientoId, $numero]);
        if ((float) $sumQuery->fetchColumn() + $porcentaje > 100.00001) {
            throw new DomainException('La suma del porcentaje de avance de las sesiones no puede exceder 100%.');
        }
        if ($session) {
            $db->prepare(
                'UPDATE seguimiento_sesiones SET fecha_hora = ?, actividades_programadas = ?, porcentaje_avance = ?, observaciones = ? WHERE id = ?'
            )->execute([
                $fecha->format('Y-m-d H:i:s'),
                $actividades,
                number_format($porcentaje, 2, '.', ''),
                $observaciones ?: null,
                (int) $session['id'],
            ]);
        } else {
            $db->prepare(
                'INSERT INTO seguimiento_sesiones (seguimiento_id, numero_sesion, fecha_hora, actividades_programadas, porcentaje_avance, observaciones) '
                . 'VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                (int) $seguimientoId,
                $numero,
                $fecha->format('Y-m-d H:i:s'),
                $actividades,
                number_format($porcentaje, 2, '.', ''),
                $observaciones ?: null,
            ]);
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function seguimientoSubirFirmaAsistencia(PDO $db, int $sesionId, array $user, array $archivo): void
{
    if (!in_array('estudiante', $user['roles'] ?? [], true)
        && !in_array('profesor_seguimiento', $user['roles'] ?? [], true)) {
        throw new DomainException('Solo el alumno o el profesor de seguimiento pueden registrar asistencia.');
    }
    $rutaRelativa = guardarPdfFirmado($archivo, 'seguimientos_tt/firmas');
    $rutaAbsoluta = '';
    try {
        $rutaAbsoluta = rutaPdfFirmado($rutaRelativa, 'seguimientos_tt/firmas');
        $db->beginTransaction();
        $query = $db->prepare(
            'SELECT s.id, s.asistio_profesor, s.firma_profesor_pdf_path, st.profesor_seguimiento_id '
            . 'FROM seguimiento_sesiones s JOIN seguimientos_tt st ON st.id = s.seguimiento_id WHERE s.id = ? FOR UPDATE'
        );
        $query->execute([$sesionId]);
        $sesion = $query->fetch();
        if (!$sesion) {
            throw new DomainException('No se encontró la sesión seleccionada.');
        }
        if (in_array('profesor_seguimiento', $user['roles'] ?? [], true)
            && (int) $sesion['profesor_seguimiento_id'] === (int) $user['id']) {
            if ($sesion['firma_profesor_pdf_path'] !== null) {
                throw new DomainException('La asistencia del profesor ya fue firmada para esta sesión.');
            }
            $db->prepare(
                'UPDATE seguimiento_sesiones SET firma_profesor_pdf_path = ?, asistio_profesor = TRUE WHERE id = ?'
            )->execute([$rutaRelativa, $sesionId]);
        } elseif (in_array('estudiante', $user['roles'] ?? [], true)) {
            $studentQuery = $db->prepare(
                'SELECT e.UserId FROM seguimiento_sesiones s JOIN seguimientos_tt st ON st.id = s.seguimiento_id '
                . 'JOIN TT tt ON tt.TT_id = st.tt_id JOIN Estudiante e ON e.EquipoId = tt.EquipoId '
                . 'WHERE s.id = ? AND e.UserId = ?'
            );
            $studentQuery->execute([$sesionId, (int) $user['id']]);
            if (!$studentQuery->fetchColumn()) {
                throw new DomainException('No perteneces al equipo de este Trabajo Terminal.');
            }
            $attendance = $db->prepare(
                'SELECT id, firma_alumno_pdf_path FROM seguimiento_asistencias_alumnos WHERE sesion_id = ? AND alumno_id = ? FOR UPDATE'
            );
            $attendance->execute([$sesionId, (int) $user['id']]);
            $row = $attendance->fetch();
            if ($row && $row['firma_alumno_pdf_path'] !== null) {
                throw new DomainException('Tu asistencia ya fue firmada para esta sesión.');
            }
            if ($row) {
                $db->prepare(
                    'UPDATE seguimiento_asistencias_alumnos SET asistio = TRUE, firma_alumno_pdf_path = ?, fecha_registro = CURRENT_TIMESTAMP WHERE id = ?'
                )->execute([$rutaRelativa, (int) $row['id']]);
            } else {
                $db->prepare(
                    'INSERT INTO seguimiento_asistencias_alumnos (sesion_id, alumno_id, asistio, firma_alumno_pdf_path, fecha_registro) '
                    . 'VALUES (?, ?, TRUE, ?, CURRENT_TIMESTAMP)'
                )->execute([$sesionId, (int) $user['id'], $rutaRelativa]);
            }
        } else {
            throw new DomainException('No tienes permiso para registrar asistencia en esta sesión.');
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($rutaAbsoluta === '') {
            $rutaAbsoluta = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rutaRelativa);
        }
        if (is_file($rutaAbsoluta) && !unlink($rutaAbsoluta)) {
            throw new RuntimeException('No fue posible limpiar el PDF tras cancelar la carga.', 0, $exception);
        }
        throw $exception;
    }
}

function seguimientoGenerarPdf(array $detalle): string
{
    $pdf = new \setasign\Fpdi\Fpdi();
    $pdf->SetTitle(pdfTexto('Formato de Seguimiento de Trabajo Terminal'));
    $pdf->SetAuthor('PROTTOCOL CATT');
    $pdf->SetMargins(14, 14, 14);
    $pdf->SetAutoPageBreak(true, 16);
    $pdf->AddPage('L');
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, pdfTexto('Formato de Seguimiento de Trabajo Terminal'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 10);
    $pdf->MultiCell(0, 7, pdfTexto(
        'Unidad de Aprendizaje: ' . ($detalle['unidad_aprendizaje'] ?? '—')
        . ' | Grupo: ' . ($detalle['grupo'] ?? '—')
        . ' | Ciclo escolar: ' . ($detalle['ciclo_escolar'] ?? '—')
        . ' | Lugar: ' . ($detalle['lugar_reunion'] ?? '—')
    ));
    $pdf->MultiCell(0, 7, pdfTexto(
        'TT: ' . $detalle['NumeroTT'] . ' (' . $detalle['TipoTT'] . ')'
        . ' | Título: ' . $detalle['Titulo']
        . ' | Profesor de Seguimiento: ' . trim(($detalle['profesor_nombre'] ?? '') . ' '
            . ($detalle['profesor_apellido_paterno'] ?? '') . ' ' . ($detalle['profesor_apellido_materno'] ?? ''))
    ));
    $pdf->MultiCell(0, 7, pdfTexto(
        'Alumnos: ' . implode(', ', array_map(
            static fn (array $alumno): string => trim($alumno['Nombre'] . ' ' . $alumno['ApellidoPaterno'] . ' ' . $alumno['ApellidoMaterno']),
            $detalle['alumnos']
        ))
    ));
    $pdf->MultiCell(0, 7, pdfTexto('Porcentaje de avance total: ' . number_format((float) $detalle['avance_total'], 2) . '%'));
    $pdf->Ln(2);
    $widths = [13, 30, 75, 20, 66, 27, 27];
    $headers = ['Sesión', 'Fecha y hora', 'Actividades programadas', '% avance', 'Observaciones', 'Profesor', 'Alumnos'];
    $dibujarEncabezados = static function () use ($pdf, $headers, $widths): void {
        $pdf->SetFont('Arial', 'B', 8);
        foreach ($headers as $index => $header) {
            $pdf->Cell($widths[$index], 9, pdfTexto($header), 1, 0, 'C');
        }
        $pdf->Ln();
        $pdf->SetFont('Arial', '', 8);
    };
    $dibujarEncabezados();
    $pdf->SetFont('Arial', '', 8);
    $contarLineas = static function (string $texto, float $ancho) use ($pdf): int {
        $lineas = 1;
        $linea = '';
        foreach (preg_split('/\s+/', trim($texto)) ?: [] as $palabra) {
            $candidata = $linea === '' ? $palabra : $linea . ' ' . $palabra;
            if ($pdf->GetStringWidth($candidata) > $ancho && $linea !== '') {
                $lineas++;
                $linea = $palabra;
            } else {
                $linea = $candidata;
            }
        }
        return $lineas;
    };
    foreach ($detalle['sesiones'] as $session) {
        $alumnosFirmaron = count(array_filter(
            $session['asistencias'],
            static fn (array $attendance): bool => !empty($attendance['firma_alumno_pdf_path'])
        ));
        $alumnosTotal = count($detalle['alumnos']);
        $values = [
            (string) $session['numero_sesion'],
            date('d/m/Y H:i', strtotime($session['fecha_hora'])),
            (string) $session['actividades_programadas'],
            number_format((float) $session['porcentaje_avance'], 2) . '%',
            (string) ($session['observaciones'] ?? ''),
            !empty($session['firma_profesor_pdf_path']) ? 'Firmó' : 'Pendiente',
            $alumnosFirmaron . '/' . $alumnosTotal . ' firmaron',
        ];
        $altoFila = 8;
        foreach ($values as $index => $value) {
            $altoFila = max($altoFila, $contarLineas(pdfTexto($value), $widths[$index] - 3) * 4 + 3);
        }
        if ($pdf->GetY() + $altoFila > $pdf->GetPageHeight() - 14) {
            $pdf->AddPage('L');
            $dibujarEncabezados();
        }
        $inicioX = $pdf->GetX();
        $inicioY = $pdf->GetY();
        $x = $inicioX;
        foreach ($values as $index => $value) {
            $pdf->Rect($x, $inicioY, $widths[$index], $altoFila);
            $pdf->SetXY($x + 1.5, $inicioY + 1.5);
            $pdf->MultiCell($widths[$index] - 3, 4, pdfTexto($value));
            $x += $widths[$index];
        }
        $pdf->SetXY($inicioX, $inicioY + $altoFila);
    }
    $pdf->SetFont('Arial', '', 8);
    foreach ($detalle['sesiones'] as $session) {
        if (!empty($session['firma_profesor_pdf_path'])) {
            pdfAnexarFirmado(
                $pdf,
                rutaPdfFirmado((string) $session['firma_profesor_pdf_path'], 'seguimientos_tt/firmas'),
                'Firma del profesor - Sesión ' . (int) $session['numero_sesion']
            );
        }
        foreach ($session['asistencias'] as $attendance) {
            if (empty($attendance['firma_alumno_pdf_path'])) {
                continue;
            }
            pdfAnexarFirmado(
                $pdf,
                rutaPdfFirmado((string) $attendance['firma_alumno_pdf_path'], 'seguimientos_tt/firmas'),
                'Firma del alumno ' . trim($attendance['Nombre'] . ' ' . $attendance['ApellidoPaterno'])
                    . ' - Sesión ' . (int) $session['numero_sesion']
            );
        }
    }
    return $pdf->Output('S');
}
