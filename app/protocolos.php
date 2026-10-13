<?php
declare(strict_types=1);

const MAX_ARCHIVO_PROTOCOLO_BYTES = 10485760; // 10 MB

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

function protocoloDelEquipo(PDO $db, int $equipoId): ?array
{
    $query = $db->prepare(
        'SELECT fs.FormatoId, fs.EquipoId, fs.Status, fs.FechaSubida, fs.Observaciones, cf.Nombre AS TipoFormato '
        . 'FROM Formatos_Subidos fs JOIN Catalogo_Formatos cf ON cf.TipoFormatoId = fs.TipoFormatoId '
        . 'WHERE fs.EquipoId = ? ORDER BY fs.FormatoId DESC LIMIT 1'
    );
    $query->execute([$equipoId]);
    $formato = $query->fetch();
    return $formato ?: null;
}

function etiquetaEstadoProtocolo(string $status): string
{
    return match ($status) {
        'Aprobado' => 'Protocolo autorizado',
        'Rechazado' => 'Protocolo rechazado',
        default => 'Pendiente de autorización',
    };
}

function claseEstadoProtocolo(string $status): string
{
    return match ($status) {
        'Aprobado' => 'aprobado',
        'Rechazado' => 'rechazado',
        default => 'pendiente',
    };
}

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
