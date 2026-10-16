<?php
declare(strict_types=1);

const MAX_ARCHIVO_PROTOCOLO_BYTES = 10485760; // 10 MB
const MAX_ENTREGAS_PROTOCOLO = 2; // 1a y 2a entrega ante dictamen de sinodales

function asegurarCatalogoProtocolo(PDO $db): int
{
    $existe = $db->prepare("SELECT TipoFormatoId FROM Catalogo_Formatos WHERE Clave = 'Protocolo'");
    $existe->execute();
    $id = $existe->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    $db->prepare("INSERT INTO Catalogo_Formatos (Clave, Nombre, FormatoEjemplo) VALUES ('Protocolo', 'Registro de protocolo', '')")
        ->execute();
    return (int) $db->lastInsertId();
}

// Ultimo formato (version vigente) del equipo.
function protocoloDelEquipo(PDO $db, int $equipoId): ?array
{
    $query = $db->prepare(
        'SELECT fs.FormatoId, fs.EquipoId, fs.Status, fs.FechaSubida, fs.Observaciones, fs.UserId AS RevisorUserId, fs.FechaRevision, cf.Nombre AS TipoFormato '
        . 'FROM Formatos_Subidos fs JOIN Catalogo_Formatos cf ON cf.TipoFormatoId = fs.TipoFormatoId '
        . 'WHERE fs.EquipoId = ? ORDER BY fs.FormatoId DESC LIMIT 1'
    );
    $query->execute([$equipoId]);
    $formato = $query->fetch();
    return $formato ?: null;
}

// Historial completo de formatos subidos por un equipo (version 1, 2, ...).
function historialProtocolosDelEquipo(PDO $db, int $equipoId): array
{
    $query = $db->prepare(
        'SELECT fs.FormatoId, fs.EquipoId, fs.Status, fs.FechaSubida, fs.Observaciones, fs.UserId AS RevisorUserId, fs.FechaRevision, cf.Nombre AS TipoFormato '
        . 'FROM Formatos_Subidos fs JOIN Catalogo_Formatos cf ON cf.TipoFormatoId = fs.TipoFormatoId '
        . 'WHERE fs.EquipoId = ? ORDER BY fs.FormatoId ASC'
    );
    $query->execute([$equipoId]);
    return $query->fetchAll();
}

// Cuantas entregas del equipo llegaron a evaluacion de sinodales (v1, v2, ...).
function numeroEntregaEquipo(PDO $db, int $equipoId): int
{
    $query = $db->prepare(
        "SELECT COUNT(*) FROM Formatos_Subidos "
        . "WHERE EquipoId = ? AND Status IN ('Aprobado','RechazadoSinodales','AprobadoSinodales')"
    );
    $query->execute([$equipoId]);
    return (int) $query->fetchColumn();
}

function etiquetaEstadoProtocolo(string $status): string
{
    return match ($status) {
        'Rechazado' => 'Rechazado por la CATT',
        'Aprobado' => 'En evaluacion de sinodales',
        'RechazadoSinodales' => 'En correccion por sinodales',
        'AprobadoSinodales' => 'Aprobado',
        default => 'Pendiente de autorizacion',
    };
}

function claseEstadoProtocolo(string $status): string
{
    return match ($status) {
        'Rechazado' => 'rechazado',
        'Aprobado' => 'pendiente',
        'RechazadoSinodales' => 'correccion',
        'AprobadoSinodales' => 'aprobado',
        default => 'pendiente',
    };
}

// Protocolos que esperan la revision inicial de la CATT.
function protocolosPendientesDeRevision(PDO $db): array
{
    $query = $db->prepare(
        "SELECT fs.FormatoId, fs.EquipoId, fs.FechaSubida, et.TituloProtocolo, et.Descripcion "
        . 'FROM Formatos_Subidos fs '
        . "JOIN Catalogo_Formatos cf ON cf.TipoFormatoId = fs.TipoFormatoId AND cf.Clave = 'Protocolo' "
        . 'JOIN Equipo_TT et ON et.EquipoId = fs.EquipoId '
        . "WHERE fs.Status = 'Pendiente' ORDER BY fs.FechaSubida ASC, fs.FormatoId ASC"
    );
    $query->execute();
    return $query->fetchAll();
}

// Protocolos aprobados por los sinodales y todavia sin NumeroTT asignado.
function protocolosListosParaNumeroTT(PDO $db): array
{
    $query = $db->prepare(
        "SELECT fs.FormatoId, fs.EquipoId, fs.FechaRevision, et.TituloProtocolo, et.Descripcion "
        . 'FROM Formatos_Subidos fs '
        . "JOIN Catalogo_Formatos cf ON cf.TipoFormatoId = fs.TipoFormatoId AND cf.Clave = 'Protocolo' "
        . 'JOIN Equipo_TT et ON et.EquipoId = fs.EquipoId '
        . "LEFT JOIN TT tt ON tt.EquipoId = et.EquipoId "
        . "WHERE fs.Status = 'AprobadoSinodales' AND tt.TT_id IS NULL "
        . 'ORDER BY fs.FechaRevision ASC, fs.FormatoId ASC'
    );
    $query->execute();
    return $query->fetchAll();
}

// Sinodales que pueden asignarse a un equipo: con rol sinodal y que aún no
// estén vinculados al equipo (evita ofrecer como sinodal a un director del
// mismo protocolo o a cualquier docente ya asignado).
function sinodalesElegiblesParaEquipo(PDO $db, int $equipoId): array
{
    $query = $db->prepare(
        "SELECT u.UserId, u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno "
        . 'FROM Usuarios u '
        . "JOIN Usuario_Roles ur ON ur.UserId = u.UserId AND ur.Rol = 'sinodal' "
        . 'WHERE NOT EXISTS (SELECT 1 FROM Equipo_Asignacion ea WHERE ea.EquipoId = ? AND ea.UserId = u.UserId) '
        . 'ORDER BY u.ApellidoPaterno, u.ApellidoMaterno, u.Nombre'
    );
    $query->execute([$equipoId]);
    return $query->fetchAll();
}

// Evaluaciones de sinodales de un formato (dictamen, observaciones, fecha).
function evaluacionesDeFormato(PDO $db, int $formatoId): array
{
    $query = $db->prepare(
        'SELECT se.EvalId, se.FormatoId, se.UserId, se.Dictamen, se.Observaciones, se.FechaRevision, '
        . 'u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno '
        . 'FROM Sinodal_Evaluacion se '
        . 'JOIN Usuarios u ON u.UserId = se.UserId '
        . 'WHERE se.FormatoId = ? '
        . 'ORDER BY se.FechaRevision ASC, se.EvalId ASC'
    );
    $query->execute([$formatoId]);
    return $query->fetchAll();
}

// Evaluaciones de todos los formatos de un equipo (por version).
function evaluacionesDeEquipo(PDO $db, int $equipoId): array
{
    $query = $db->prepare(
        'SELECT se.EvalId, se.FormatoId, se.UserId, se.Dictamen, se.Observaciones, se.FechaRevision, '
        . 'u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno '
        . 'FROM Sinodal_Evaluacion se '
        . 'JOIN Usuarios u ON u.UserId = se.UserId '
        . 'JOIN Formatos_Subidos fs ON fs.FormatoId = se.FormatoId '
        . 'WHERE fs.EquipoId = ? '
        . 'ORDER BY se.FormatoId ASC, se.FechaRevision ASC, se.EvalId ASC'
    );
    $query->execute([$equipoId]);
    return $query->fetchAll();
}

// Sinodales asignados a un equipo (vía Equipo_Asignacion).
function sinodalesAsignados(PDO $db, int $equipoId): array
{
    $query = $db->prepare(
        'SELECT ea.UserId, u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno '
        . 'FROM Equipo_Asignacion ea '
        . 'JOIN Usuarios u ON u.UserId = ea.UserId '
        . "WHERE ea.EquipoId = ? AND ea.Rol = 'sinodal' "
        . 'ORDER BY u.ApellidoPaterno, u.ApellidoMaterno, u.Nombre'
    );
    $query->execute([$equipoId]);
    return $query->fetchAll();
}

// Evaluaciones que un sinodal tiene pendientes ahora mismo.
//  * v1 (primera entrega): soy sinodal del equipo y aún no he dictado sobre el formato vigente.
//  * v2 (segunda entrega): rechacé la versión anterior y aún no he dictado sobre el formato vigente.
function pendientesEvaluacionParaSinodal(PDO $db, int $userId): array
{
    $query = $db->prepare(
        'SELECT fs.FormatoId, fs.EquipoId, fs.Status, et.TituloProtocolo, et.Descripcion '
        . 'FROM Formatos_Subidos fs '
        . 'JOIN Equipo_TT et ON et.EquipoId = fs.EquipoId '
        . 'JOIN Equipo_Asignacion ea ON ea.EquipoId = fs.EquipoId AND ea.Rol = \'sinodal\' AND ea.UserId = ? '
        . "WHERE fs.Status IN ('Aprobado','AprobadoSinodales') "
        . 'AND NOT EXISTS (SELECT 1 FROM Sinodal_Evaluacion se WHERE se.FormatoId = fs.FormatoId AND se.UserId = ?) '
        . 'AND ( '
        . '    NOT EXISTS (SELECT 1 FROM Formatos_Subidos fs2 WHERE fs2.EquipoId = fs.EquipoId AND fs2.FormatoId < fs.FormatoId '
        . "              AND fs2.Status IN ('Aprobado','RechazadoSinodales','AprobadoSinodales')) "
        . '    OR EXISTS (SELECT 1 FROM Sinodal_Evaluacion se2 JOIN Formatos_Subidos fs3 ON fs3.FormatoId = se2.FormatoId '
        . '               WHERE se2.UserId = ? AND fs3.EquipoId = fs.EquipoId AND se2.Dictamen = \'rechazado\' '
        . '               AND fs3.FormatoId < fs.FormatoId) '
        . ') '
        . 'ORDER BY fs.FormatoId ASC'
    );
    $query->execute([$userId, $userId, $userId]);
    return $query->fetchAll();
}

// Guarda el dictamen de un sinodal sobre el formato vigente de un equipo y
// recalcula el estado del formato. Devuelve un array con el resultado:
//   ['ok' => bool, 'mensaje' => string, 'baja' => bool, 'estado' => string]
function guardarDictamen(PDO $db, int $formatoId, int $sinodalId, string $dictamen, string $observaciones, string $fechaRevision): array
{
    $dictamen = strtolower(trim($dictamen));
    if (!in_array($dictamen, ['aprobado', 'rechazado'], true)) {
        return ['ok' => false, 'mensaje' => 'Selecciona un dictamen valido (aprobado o rechazado).', 'baja' => false, 'estado' => ''];
    }

    try {
        $db->beginTransaction();

        $formatoQuery = $db->prepare('SELECT fs.FormatoId, fs.EquipoId, fs.Status FROM Formatos_Subidos fs WHERE fs.FormatoId = ? FOR UPDATE');
        $formatoQuery->execute([$formatoId]);
        $formato = $formatoQuery->fetch();
        if (!$formato) {
            throw new DomainException('El formato ya no existe.');
        }
        if (!in_array($formato['Status'], ['Aprobado', 'AprobadoSinodales'], true)) {
            throw new DomainException('Este formato ya no esta en evaluacion.');
        }
        $equipoId = (int) $formato['EquipoId'];

        // Soy sinodal asignado del equipo y todavia no he dictado sobre este formato?
        $miAsignacion = $db->prepare("SELECT COUNT(*) FROM Equipo_Asignacion WHERE EquipoId = ? AND UserId = ? AND Rol = 'sinodal'");
        $miAsignacion->execute([$equipoId, $sinodalId]);
        if ((int) $miAsignacion->fetchColumn() === 0) {
            throw new DomainException('No formas parte de la comision de sinodales de este equipo.');
        }

        $yaDicto = $db->prepare('SELECT COUNT(*) FROM Sinodal_Evaluacion WHERE FormatoId = ? AND UserId = ?');
        $yaDicto->execute([$formatoId, $sinodalId]);
        if ((int) $yaDicto->fetchColumn() > 0) {
            throw new DomainException('Ya emitiste tu dictamen sobre este formato.');
        }

        $db->prepare('INSERT INTO Sinodal_Evaluacion (FormatoId, UserId, Dictamen, Observaciones, FechaRevision) VALUES (?, ?, ?, ?, ?)')
            ->execute([$formatoId, $sinodalId, $dictamen, $observaciones !== '' ? $observaciones : null, $fechaRevision]);

        // Version vigente: cuenta de entregas de sinodales (v1, v2).
        $versionQuery = $db->prepare(
            "SELECT COUNT(*) FROM Formatos_Subidos WHERE EquipoId = ? AND Status IN ('Aprobado','RechazadoSinodales','AprobadoSinodales')"
        );
        $versionQuery->execute([$equipoId]);
        $version = (int) $versionQuery->fetchColumn();

        if ($dictamen === 'rechazado') {
            if ($version >= MAX_ENTREGAS_PROTOCOLO) {
                // Segunda entrega rechazada: baja total del equipo y protocolo.
                borrarEquipoYPertenencias($db, $equipoId);
                $db->commit();
                return [
                    'ok' => true,
                    'mensaje' => 'Emitiste un dictamen de rechazo sobre la segunda entrega. El equipo y su protocolo fueron dados de baja; los alumnos deberan iniciar el proceso de nuevo.',
                    'baja' => true,
                    'estado' => '',
                ];
            }
            $db->prepare("UPDATE Formatos_Subidos SET Status = 'RechazadoSinodales' WHERE FormatoId = ?")->execute([$formatoId]);
            $db->commit();
            return [
                'ok' => true,
                'mensaje' => 'Tu dictamen de rechazo quedo registrado. El alumno debera subir una segunda entrega corregida.',
                'baja' => false,
                'estado' => 'RechazadoSinodales',
            ];
        }

        // Dictamen aprobado: si todos los que deben dictar ya lo hicieron, se aprueba.
        $sinodalesPendientes = sinodalesPendientesDeFormato($db, (int) $equipoId, (int) $formatoId, $version);
        if (count($sinodalesPendientes) === 0) {
            $db->prepare("UPDATE Formatos_Subidos SET Status = 'AprobadoSinodales' WHERE FormatoId = ?")->execute([$formatoId]);
            $db->commit();
            return [
                'ok' => true,
                'mensaje' => 'Tu dictamen de aprobado quedo registrado. El protocolo fue aprobado por la comision de sinodales.',
                'baja' => false,
                'estado' => 'AprobadoSinodales',
            ];
        }

        $db->commit();
        return [
            'ok' => true,
            'mensaje' => 'Tu dictamen de aprobado quedo registrado. Falta la evaluacion de otros sinodales.',
            'baja' => false,
            'estado' => 'Aprobado',
        ];
    } catch (DomainException $exception) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'mensaje' => $exception->getMessage(), 'baja' => false, 'estado' => ''];
    } catch (Throwable $exception) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'mensaje' => 'No fue posible registrar el dictamen. Intenta de nuevo.', 'baja' => false, 'estado' => ''];
    }
}

// Sinodales que aun deben dictar sobre un formato dado.
function sinodalesPendientesDeFormato(PDO $db, int $equipoId, int $formatoId, int $version): array
{
    // En la v1 dictan todos los sinodales asignados.
    // En la v2 (o posteriores) solo re-evaluan los que rechazaron la version anterior.
    if ($version <= 1) {
        return sinodalesAsignados($db, $equipoId);
    }

    $query = $db->prepare(
        'SELECT u.UserId, u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno '
        . 'FROM Equipo_Asignacion ea '
        . 'JOIN Usuarios u ON u.UserId = ea.UserId '
        . "WHERE ea.EquipoId = ? AND ea.Rol = 'sinodal' "
        . 'AND EXISTS (SELECT 1 FROM Sinodal_Evaluacion se JOIN Formatos_Subidos fs ON fs.FormatoId = se.FormatoId '
        . '           WHERE se.UserId = ea.UserId AND fs.EquipoId = ? AND se.Dictamen = \'rechazado\' '
        . '           AND fs.FormatoId < ?) '
        . 'AND NOT EXISTS (SELECT 1 FROM Sinodal_Evaluacion se2 WHERE se2.FormatoId = ? AND se2.UserId = ea.UserId) '
        . 'ORDER BY u.ApellidoPaterno, u.ApellidoMaterno, u.Nombre'
    );
    $query->execute([$equipoId, $equipoId, $formatoId, $formatoId]);
    return $query->fetchAll();
}

// Elimina un equipo y todo lo vinculado, sin depender de ON DELETE CASCADE (MyISAM).
function borrarEquipoYPertenencias(PDO $db, int $equipoId): void
{
    // Dictámenes de sinodales sobre los formatos del equipo.
    $db->prepare('DELETE se FROM Sinodal_Evaluacion se JOIN Formatos_Subidos fs ON fs.FormatoId = se.FormatoId WHERE fs.EquipoId = ?')->execute([$equipoId]);
    // Vínculos de docentes y roles del equipo.
    $db->prepare('DELETE FROM EmpleadoEquipo WHERE EquipoId = ?')->execute([$equipoId]);
    $db->prepare('DELETE FROM Equipo_Asignacion WHERE EquipoId = ?')->execute([$equipoId]);
    // Formatos subidos (el PDF).
    $db->prepare('DELETE FROM Formatos_Subidos WHERE EquipoId = ?')->execute([$equipoId]);
    // Director externo vinculado.
    $db->prepare('DELETE FROM Externo WHERE EquipoId = ?')->execute([$equipoId]);
    // Liberar a los alumnos del equipo.
    $db->prepare('UPDATE Estudiante SET EquipoId = NULL WHERE EquipoId = ?')->execute([$equipoId]);
    // Borrar el equipo.
    $db->prepare('DELETE FROM Equipo_TT WHERE EquipoId = ?')->execute([$equipoId]);
}

// Número de TT registrado para un equipo (o null).
function ttDeEquipo(PDO $db, int $equipoId): ?array
{
    $query = $db->prepare('SELECT TT_id, NumeroTT, Titulo, Descripcion, Status, EquipoId FROM TT WHERE EquipoId = ?');
    $query->execute([$equipoId]);
    $tt = $query->fetch();
    return $tt ?: null;
}

// Asigna el Número de TT a un equipo aprobado por los sinodales.
function asignarNumeroTT(PDO $db, int $equipoId, string $numeroTT, int $cattUserId): array
{
    $numeroTT = strtoupper(trim($numeroTT));
    if ($numeroTT === '' || mb_strlen($numeroTT) > 20) {
        return ['ok' => false, 'mensaje' => 'Captura un número de TT válido (máximo 20 caracteres).'];
    }

    try {
        $db->beginTransaction();

        // El equipo debe estar aprobado por sinodales y sin TT previo.
        $pendiente = $db->prepare(
            "SELECT fs.FormatoId FROM Formatos_Subidos fs WHERE fs.EquipoId = ? AND fs.Status = 'AprobadoSinodales' "
            . 'AND NOT EXISTS (SELECT 1 FROM TT tt WHERE tt.EquipoId = fs.EquipoId) LIMIT 1'
        );
        $pendiente->execute([$equipoId]);
        if ($pendiente->fetchColumn() === false) {
            throw new DomainException('Este equipo no está aprobado por los sinodales o ya tiene un número de TT asignado.');
        }

        $duplicado = $db->prepare('SELECT COUNT(*) FROM TT WHERE NumeroTT = ?');
        $duplicado->execute([$numeroTT]);
        if ((int) $duplicado->fetchColumn() > 0) {
            throw new DomainException('Ese número de TT ya está registrado. Captura uno distinto.');
        }

        $equipo = $db->prepare('SELECT TituloProtocolo, Descripcion FROM Equipo_TT WHERE EquipoId = ?');
        $equipo->execute([$equipoId]);
        $datos = $equipo->fetch();
        if (!$datos) {
            throw new DomainException('El equipo ya no existe.');
        }

        $db->prepare('INSERT INTO TT (NumeroTT, Titulo, Descripcion, EquipoId) VALUES (?, ?, ?, ?)')
            ->execute([$numeroTT, $datos['TituloProtocolo'], $datos['Descripcion'], $equipoId]);

        $db->commit();
        return ['ok' => true, 'mensaje' => "Número de TT {$numeroTT} asignado correctamente."];
    } catch (DomainException $exception) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'mensaje' => $exception->getMessage()];
    } catch (Throwable $exception) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'mensaje' => 'No fue posible asignar el número de TT. Intenta de nuevo.'];
    }
}

function puedeVerFormato(PDO $db, array $user, array $formato): bool
{
    $roles = $user['roles'] ?? [];
    if (array_intersect($roles, ['admin', 'miembroCatt', 'jefeAcademia'])) {
        return true;
    }

    $equipoId = (int) ($formato['EquipoId'] ?? 0);
    if ($equipoId < 1) {
        return false;
    }

    if (in_array('estudiante', $roles, true)) {
        $query = $db->prepare('SELECT COUNT(*) FROM Estudiante WHERE UserId = ? AND EquipoId = ?');
        $query->execute([(int) $user['id'], $equipoId]);
        return (int) $query->fetchColumn() > 0;
    }

    if (array_intersect($roles, ['director', 'sinodal'])) {
        $query = $db->prepare('SELECT COUNT(*) FROM EmpleadoEquipo WHERE UserId = ? AND EquipoId = ?');
        $query->execute([(int) $user['id'], $equipoId]);
        return (int) $query->fetchColumn() > 0;
    }

    return false;
}
