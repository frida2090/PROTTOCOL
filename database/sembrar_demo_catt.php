<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este comando solo se puede ejecutar desde la terminal.\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';

$db = database();

// Aplica la migración de equipos si todavía no existe la tabla de asignaciones
$tabla = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
$tabla->execute(['Equipo_Asignacion']);
if ((int) $tabla->fetchColumn() === 0) {
    $sql = file_get_contents(__DIR__ . '/migrations/20261006_asignacion_equipos_tt.sql');
    $sql = preg_replace('/^(USE|SET NAMES)[^;]*;/mi', '', (string) $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $sentencia) {
        $db->exec($sentencia);
    }
    fwrite(STDOUT, "Migración de equipos aplicada.\n");
}

const PASSWORD_SINODALES = 'Sinodal123';
const PASSWORD_ALUMNOS = 'Alumno123';
const PASSWORD_CATT = 'Catt12345';

$sinodales = [
    ['docente' => 'Cruz Meza María Elena', 'nombre' => 'María Elena', 'paterno' => 'Cruz', 'materno' => 'Meza', 'email' => 'mariacruz@ipn.mx'],
    ['docente' => 'García Mendoza Consuelo Varinia', 'nombre' => 'Consuelo Varinia', 'paterno' => 'García', 'materno' => 'Mendoza', 'email' => 'consuelogarcia@ipn.mx'],
    ['docente' => 'Guzmán Flores Jessie Paulina', 'nombre' => 'Jessie Paulina', 'paterno' => 'Guzmán', 'materno' => 'Flores', 'email' => 'jessieguzman@ipn.mx'],
];

$alumnos = [
    ['nombre' => 'Fatima', 'paterno' => 'Carmona', 'materno' => 'Juarez', 'email' => 'fcarmonaj@alumno.ipn.mx', 'boleta' => '2024630101'],
    ['nombre' => 'Omar', 'paterno' => 'Redondo', 'materno' => 'Gonzalez', 'email' => 'oredondog@alumno.ipn.mx', 'boleta' => '2024630102'],
    ['nombre' => 'Guztavo', 'paterno' => 'Zaragoza', 'materno' => 'Guerrero', 'email' => 'gzaragozag@alumno.ipn.mx', 'boleta' => '2024630103'],
];

$miembrosCatt = [
    ['nombre' => 'Miembro', 'paterno' => 'CATT', 'materno' => 'Uno', 'email' => 'catt@ipn.mx', 'empleado' => 'CATT-001'],
];

$deptId = (int) $db->query('SELECT DeptId FROM Academia_Departamento ORDER BY DeptId LIMIT 1')->fetchColumn();
if ($deptId < 1) {
    fwrite(STDERR, "No existe ningún departamento; importa database/PROTTOCOL.sql primero.\n");
    exit(1);
}

$existe = $db->prepare('SELECT UserId FROM Usuarios WHERE Email = ?');
$crearUsuario = $db->prepare('INSERT INTO Usuarios (Nombre, ApellidoPaterno, ApellidoMaterno, Email, PasswordHash) VALUES (?, ?, ?, ?, ?)');
$crearRol = $db->prepare('INSERT IGNORE INTO Usuario_Roles (UserId, Rol) VALUES (?, ?)');
$crearEmpleado = $db->prepare('INSERT IGNORE INTO Empleado (UserId, NumeroEmpleado, DeptId) VALUES (?, ?, ?)');
$crearEstudiante = $db->prepare('INSERT IGNORE INTO Estudiante (UserId, NumeroBoleta, Creditos) VALUES (?, ?, 0)');
$buscarDocente = $db->prepare('SELECT DocenteId FROM DocentesCatalogo WHERE NombreCompleto = ?');
$vincularDocente = $db->prepare('UPDATE DocentesCatalogo SET UserId = ? WHERE DocenteId = ? AND UserId IS NULL');

$usuarioId = function (array $dato, string $password) use ($existe, $crearUsuario, $db): array {
    $existe->execute([$dato['email']]);
    $id = $existe->fetchColumn();
    if ($id !== false) {
        return [(int) $id, false];
    }
    $crearUsuario->execute([$dato['nombre'], $dato['paterno'], $dato['materno'], $dato['email'], password_hash($password, PASSWORD_DEFAULT)]);
    return [(int) $db->lastInsertId(), true];
};

$resumen = [];

try {
    $db->beginTransaction();

    foreach ($sinodales as $dato) {
        [$id, $nuevo] = $usuarioId($dato, PASSWORD_SINODALES);
        $buscarDocente->execute([$dato['docente']]);
        $docenteId = (int) ($buscarDocente->fetchColumn() ?: 0);
        $crearEmpleado->execute([$id, sprintf('DOC-%04d', $docenteId ?: $id), $deptId]);
        $crearRol->execute([$id, 'sinodal']);
        if ($docenteId > 0) {
            $vincularDocente->execute([$id, $docenteId]);
        }
        $resumen[] = ['Sinodal', $dato['email'], $nuevo ? PASSWORD_SINODALES : '(ya existía)'];
    }

    foreach ($alumnos as $dato) {
        [$id, $nuevo] = $usuarioId($dato, PASSWORD_ALUMNOS);
        $crearEstudiante->execute([$id, $dato['boleta']]);
        $crearRol->execute([$id, 'estudiante']);
        $resumen[] = ['Alumno', $dato['email'], $nuevo ? PASSWORD_ALUMNOS : '(ya existía)'];
    }

    foreach ($miembrosCatt as $dato) {
        [$id, $nuevo] = $usuarioId($dato, PASSWORD_CATT);
        $crearEmpleado->execute([$id, $dato['empleado'], $deptId]);
        $crearRol->execute([$id, 'miembroCatt']);
        $resumen[] = ['Miembro CATT', $dato['email'], $nuevo ? PASSWORD_CATT : '(ya existía)'];
    }

    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "No fue posible inyectar los datos: {$exception->getMessage()}\n");
    exit(1);
}

fwrite(STDOUT, "\nCuentas listas:\n");
foreach ($resumen as [$tipo, $correo, $password]) {
    fwrite(STDOUT, sprintf("  %-13s %-28s %s\n", $tipo, $correo, $password));
}
