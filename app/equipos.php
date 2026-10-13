<?php
declare(strict_types=1);

const MAX_EQUIPOS_POR_DOCENTE = 8;
const ETIQUETAS_ROL_EQUIPO = [
    'director' => 'Director',
    'sinodal' => 'Sinodal',
    'seguimiento' => 'Profesor de seguimiento',
];

function claveAlfabetica(array $usuario): string
{
    $texto = mb_strtolower(trim(implode(' ', [
        $usuario['ApellidoPaterno'] ?? '',
        $usuario['ApellidoMaterno'] ?? '',
        $usuario['Nombre'] ?? '',
    ])), 'UTF-8');

    return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n~']);
}

function elegirRepresentante(array $estudiantes): array
{
    usort($estudiantes, static fn (array $a, array $b): int => strcmp(claveAlfabetica($a), claveAlfabetica($b)));
    return $estudiantes[0];
}

function nombreDeUsuario(array $usuario): string
{
    return trim(implode(' ', array_filter([$usuario['Nombre'] ?? '', $usuario['ApellidoPaterno'] ?? '', $usuario['ApellidoMaterno'] ?? ''])));
}

function enteros(mixed $valores): array
{
    if (!is_array($valores)) {
        return [];
    }
    return array_values(array_unique(array_filter(array_map('intval', $valores), static fn (int $id): bool => $id > 0)));
}

function crearEquipoTT(PDO $db, int $asignadorId, string $titulo, string $descripcion, array $estudianteIds, array $directorIds, array $sinodalIds, int $seguimientoId): int
{
    if ($titulo === '' || mb_strlen($titulo) > 100 || $descripcion === '') {
        throw new DomainException('Captura el título (máximo 100 caracteres) y la descripción del equipo.');
    }
    if (!$estudianteIds) {
        throw new DomainException('Selecciona al menos un estudiante para el equipo.');
    }
    if (count($directorIds) !== 2 || count($sinodalIds) !== 3 || $seguimientoId < 1) {
        throw new DomainException('Selecciona exactamente dos directores, tres sinodales y un profesor de seguimiento.');
    }

    $docentes = array_merge($directorIds, $sinodalIds, [$seguimientoId]);
    if (count(array_unique($docentes)) !== count($docentes)) {
        throw new DomainException('Un docente no puede tener más de un rol dentro del mismo equipo.');
    }

    try {
        $db->beginTransaction();

        $marcas = implode(', ', array_fill(0, count($estudianteIds), '?'));
        $studentQuery = $db->prepare("SELECT u.UserId, u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno, e.EquipoId FROM Estudiante e JOIN Usuarios u ON u.UserId = e.UserId WHERE e.UserId IN ({$marcas}) FOR UPDATE");
        $studentQuery->execute($estudianteIds);
        $estudiantes = $studentQuery->fetchAll();
        if (count($estudiantes) !== count($estudianteIds)) {
            throw new DomainException('Algún estudiante seleccionado no existe.');
        }
        foreach ($estudiantes as $estudiante) {
            if ($estudiante['EquipoId'] !== null) {
                throw new DomainException(nombreDeUsuario($estudiante) . ' ya pertenece a un equipo.');
            }
        }

        $marcasDocentes = implode(', ', array_fill(0, count($docentes), '?'));
        $roleQuery = $db->prepare("SELECT u.UserId, ur.Rol FROM Usuarios u LEFT JOIN Usuario_Roles ur ON ur.UserId = u.UserId WHERE u.UserId IN ({$marcasDocentes}) FOR UPDATE");
        $roleQuery->execute($docentes);
        $roles = [];
        foreach ($roleQuery->fetchAll() as $fila) {
            $roles[(int) $fila['UserId']][] = $fila['Rol'];
        }
        if (count($roles) !== count($docentes)) {
            throw new DomainException('Algún docente seleccionado no existe.');
        }
        $esDocente = static fn (int $id): bool => (bool) array_intersect($roles[$id], ['director', 'sinodal']);
        foreach ($directorIds as $id) {
            if (!in_array('director', $roles[$id], true)) {
                throw new DomainException('Todos los directores deben tener el rol director.');
            }
        }
        foreach ($sinodalIds as $id) {
            if (!in_array('sinodal', $roles[$id], true)) {
                throw new DomainException('Todos los sinodales deben tener el rol sinodal.');
            }
        }
        if (!$esDocente($seguimientoId)) {
            throw new DomainException('El profesor de seguimiento debe ser un docente (director o sinodal).');
        }

        $countQuery = $db->prepare('SELECT COUNT(*) FROM EmpleadoEquipo WHERE UserId = ?');
        foreach ($docentes as $id) {
            $countQuery->execute([$id]);
            if ((int) $countQuery->fetchColumn() >= MAX_EQUIPOS_POR_DOCENTE) {
                throw new DomainException('Un docente seleccionado ya alcanzó el máximo de ' . MAX_EQUIPOS_POR_DOCENTE . ' equipos.');
            }
        }

        $representante = elegirRepresentante($estudiantes);
        $db->prepare('INSERT INTO Equipo_TT (TituloProtocolo, Descripcion, RepresentanteUserId) VALUES (?, ?, ?)')
            ->execute([$titulo, $descripcion, (int) $representante['UserId']]);
        $equipoId = (int) $db->lastInsertId();

        $db->prepare("UPDATE Estudiante SET EquipoId = ? WHERE UserId IN ({$marcas}) AND EquipoId IS NULL")
            ->execute(array_merge([$equipoId], $estudianteIds));

        $assign = $db->prepare('INSERT INTO Equipo_Asignacion (EquipoId, UserId, Rol, AsignadoPorUserId) VALUES (?, ?, ?, ?)');
        $link = $db->prepare('INSERT IGNORE INTO EmpleadoEquipo (UserId, EquipoId) VALUES (?, ?)');
        $porRol = ['director' => $directorIds, 'sinodal' => $sinodalIds, 'seguimiento' => [$seguimientoId]];
        foreach ($porRol as $rol => $ids) {
            foreach ($ids as $id) {
                $assign->execute([$equipoId, $id, $rol, $asignadorId]);
                $link->execute([$id, $equipoId]);
            }
        }

        $db->commit();
        return $equipoId;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function integrantesDeEquipo(PDO $db, int $equipoId): array
{
    $query = $db->prepare('SELECT u.UserId, u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno, u.Email, e.NumeroBoleta, (et.RepresentanteUserId = u.UserId) AS EsRepresentante FROM Estudiante e JOIN Usuarios u ON u.UserId = e.UserId JOIN Equipo_TT et ON et.EquipoId = e.EquipoId WHERE e.EquipoId = ?');
    $query->execute([$equipoId]);
    $integrantes = $query->fetchAll();
    usort($integrantes, static fn (array $a, array $b): int => strcmp(claveAlfabetica($a), claveAlfabetica($b)));
    return $integrantes;
}

function docentesDeEquipo(PDO $db, int $equipoId): array
{
    $query = $db->prepare("SELECT u.UserId, u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno, u.Email, COALESCE(ea.Rol, (SELECT GROUP_CONCAT(ur.Rol) FROM Usuario_Roles ur WHERE ur.UserId = u.UserId AND ur.Rol IN ('director', 'sinodal'))) AS Rol FROM EmpleadoEquipo ee JOIN Usuarios u ON u.UserId = ee.UserId LEFT JOIN Equipo_Asignacion ea ON ea.EquipoId = ee.EquipoId AND ea.UserId = ee.UserId WHERE ee.EquipoId = ? ORDER BY FIELD(ea.Rol, 'director', 'sinodal', 'seguimiento'), u.ApellidoPaterno, u.Nombre");
    $query->execute([$equipoId]);
    return $query->fetchAll();
}

function equipoCompleto(PDO $db, array $equipo): array
{
    $equipoId = (int) $equipo['EquipoId'];
    $equipo['integrantes'] = integrantesDeEquipo($db, $equipoId);
    $equipo['docentes'] = docentesDeEquipo($db, $equipoId);
    return $equipo;
}

function equipoDelEstudiante(PDO $db, int $userId): ?array
{
    $query = $db->prepare('SELECT et.EquipoId, et.TituloProtocolo, et.Descripcion, et.RepresentanteUserId FROM Estudiante e JOIN Equipo_TT et ON et.EquipoId = e.EquipoId WHERE e.UserId = ?');
    $query->execute([$userId]);
    $equipo = $query->fetch();
    return $equipo ? equipoCompleto($db, $equipo) : null;
}

function equiposDelDocente(PDO $db, int $userId): array
{
    $query = $db->prepare("SELECT et.EquipoId, et.TituloProtocolo, et.Descripcion, et.RepresentanteUserId, COALESCE(ea.Rol, (SELECT GROUP_CONCAT(ur.Rol) FROM Usuario_Roles ur WHERE ur.UserId = ee.UserId AND ur.Rol IN ('director', 'sinodal'))) AS MiRol FROM EmpleadoEquipo ee JOIN Equipo_TT et ON et.EquipoId = ee.EquipoId LEFT JOIN Equipo_Asignacion ea ON ea.EquipoId = ee.EquipoId AND ea.UserId = ee.UserId WHERE ee.UserId = ? ORDER BY et.TituloProtocolo");
    $query->execute([$userId]);
    return array_map(static fn (array $equipo): array => equipoCompleto($db, $equipo), $query->fetchAll());
}

function estudiantesSinEquipo(PDO $db): array
{
    $filas = $db->query("SELECT u.UserId, u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno, e.NumeroBoleta FROM Estudiante e JOIN Usuarios u ON u.UserId = e.UserId JOIN Usuario_Roles ur ON ur.UserId = u.UserId AND ur.Rol = 'estudiante' WHERE e.EquipoId IS NULL")->fetchAll();
    usort($filas, static fn (array $a, array $b): int => strcmp(claveAlfabetica($a), claveAlfabetica($b)));
    return $filas;
}

function docentesConCarga(PDO $db): array
{
    $filas = $db->query("SELECT u.UserId, u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno, GROUP_CONCAT(DISTINCT ur.Rol) AS Roles, (SELECT COUNT(*) FROM EmpleadoEquipo ee WHERE ee.UserId = u.UserId) AS Carga FROM Usuarios u JOIN Usuario_Roles ur ON ur.UserId = u.UserId AND ur.Rol IN ('director', 'sinodal') GROUP BY u.UserId, u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno")->fetchAll();
    usort($filas, static fn (array $a, array $b): int => strcmp(claveAlfabetica($a), claveAlfabetica($b)));
    return array_map(static function (array $fila): array {
        $fila['Roles'] = explode(',', $fila['Roles']);
        $fila['Carga'] = (int) $fila['Carga'];
        return $fila;
    }, $filas);
}

function equipoPorId(PDO $db, int $equipoId): array
{
    $query = $db->prepare('SELECT EquipoId, TituloProtocolo, Descripcion, RepresentanteUserId FROM Equipo_TT WHERE EquipoId = ?');
    $query->execute([$equipoId]);
    return equipoCompleto($db, $query->fetch());
}

function renderEquipoDetalleHtml(array $equipo, bool $mostrarMiRol = false): string
{
    ob_start();
    renderEquipoDetalle($equipo, $mostrarMiRol);
    return (string) ob_get_clean();
}

function etiquetaRolEquipo(?string $rol): string
{
    $etiquetas = array_map(static fn (string $r): string => ETIQUETAS_ROL_EQUIPO[$r] ?? ucfirst($r), explode(',', (string) $rol));
    return implode(', ', $etiquetas);
}

function renderEquipoDetalle(array $equipo, bool $mostrarMiRol = false, ?array $protocolo = null): void
{
    ?>
    <article class="summary-item">
        <span class="summary-date">TT</span>
        <div>
            <strong><?= e($equipo['TituloProtocolo']) ?></strong>
            <?php if ($mostrarMiRol): ?><small>Mi rol: <?= e(etiquetaRolEquipo($equipo['MiRol'] ?? '')) ?></small><?php endif; ?>
            <small><?= e($equipo['Descripcion']) ?></small>
            <small>Estudiantes: <?php
                echo e(implode(', ', array_map(static fn (array $i): string => nombreDeUsuario($i) . ($i['EsRepresentante'] ? ' (representante)' : ''), $equipo['integrantes'])) ?: 'Sin estudiantes vinculados');
            ?></small>
            <?php foreach ($equipo['docentes'] as $docente): ?>
                <small><?= e(etiquetaRolEquipo($docente['Rol'])) ?>: <?= e(nombreDeUsuario($docente)) ?></small>
            <?php endforeach; ?>
            <?php if ($protocolo !== null): ?>
                <div class="protocol-status-line">
                    <span class="status-chip status-chip--<?= e(claseEstadoProtocolo((string) $protocolo['Status'])) ?>"><?= e(etiquetaEstadoProtocolo((string) $protocolo['Status'])) ?></span>
                    <?php if (!empty($protocolo['FechaSubida'])): ?><small>Protocolo enviado el <?= e(date('d/m/Y', strtotime((string) $protocolo['FechaSubida']))) ?></small><?php endif; ?>
                    <a class="protocol-doc-link" href="descargarFormato.php?id=<?= (int) $protocolo['FormatoId'] ?>">Ver PDF</a>
                </div>
            <?php endif; ?>
        </div>
    </article>
    <?php
}
