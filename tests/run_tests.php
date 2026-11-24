<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/formulario1.php';
require_once __DIR__ . '/../app/acuses_reporte_tecnico.php';
require_once __DIR__ . '/../app/seguimientos_tt.php';

function runTestsRolExiste(PDO $db, string $rol): bool
{
    $query = $db->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS "
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Usuario_Roles' AND COLUMN_NAME = 'Rol'"
    );
    $tipo = $query->fetchColumn();
    return is_string($tipo) && stripos($tipo, $rol) !== false;
}

function runTestsCrearUsuario(PDO $db, string $suffix, string $rol, bool $asignarRol = true): int
{
    $insert = $db->prepare(
        'INSERT INTO Usuarios (Nombre, ApellidoPaterno, ApellidoMaterno, Email, PasswordHash) VALUES (?, ?, ?, ?, ?)'
    );
    $insert->execute([
        'Prueba',
        'Automatica',
        $rol,
        'test-' . strtolower($suffix) . '-' . $rol . '@example.invalid',
        password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
    ]);
    $userId = (int) $db->lastInsertId();
    if ($asignarRol) {
        $db->prepare('INSERT INTO Usuario_Roles (UserId, Rol) VALUES (?, ?)')->execute([$userId, $rol]);
    }
    return $userId;
}

function runTestsCrearFixture(PDO $db): array
{
    $suffix = strtoupper(bin2hex(random_bytes(5)));
    $alumnoId = runTestsCrearUsuario($db, $suffix, 'estudiante');
    $directorId = runTestsCrearUsuario($db, $suffix, 'director');
    $profesorId = runTestsCrearUsuario(
        $db,
        $suffix,
        'profesor_seguimiento',
        runTestsRolExiste($db, 'profesor_seguimiento')
    );

    $db->prepare('INSERT INTO Equipo_TT (TituloProtocolo, Descripcion) VALUES (?, ?)')
        ->execute(['Fixture de pruebas ' . $suffix, 'Datos temporales para pruebas automatizadas.']);
    $equipoId = (int) $db->lastInsertId();
    $boleta = 'T' . substr($suffix, 0, 9);
    $db->prepare('INSERT INTO Estudiante (UserId, NumeroBoleta, Creditos, EquipoId) VALUES (?, ?, 0, ?)')
        ->execute([$alumnoId, $boleta, $equipoId]);
    $db->prepare('UPDATE Equipo_TT SET RepresentanteUserId = ? WHERE EquipoId = ?')
        ->execute([$alumnoId, $equipoId]);
    $db->prepare("INSERT INTO Equipo_Asignacion (EquipoId, UserId, Rol) VALUES (?, ?, 'seguimiento')")
        ->execute([$equipoId, $profesorId]);

    $db->prepare(
        "INSERT INTO TT (NumeroTT, Titulo, Descripcion, Status, EquipoId, fecha_presentacion, profesor_seguimiento_id) "
        . "VALUES (?, ?, ?, 'En Desarrollo', ?, ?, ?)"
    )->execute([
        'TEST' . $suffix,
        'TT para pruebas automatizadas',
        'Registro temporal de pruebas.',
        $equipoId,
        (new DateTimeImmutable('today'))->modify('+6 days')->format('Y-m-d'),
        $profesorId,
    ]);
    $ttId = (int) $db->lastInsertId();

    $db->prepare(
        "INSERT INTO acuses_reporte_tecnico (tt_id, tipo_tt, fecha_emision, estado) "
        . "VALUES (?, 'TT-I', CURRENT_DATE, 'pendiente_firmas')"
    )->execute([$ttId]);
    $acuseId = (int) $db->lastInsertId();

    $db->prepare(
        'INSERT INTO seguimientos_tt (tt_id, profesor_seguimiento_id, unidad_aprendizaje, grupo, ciclo_escolar, lugar_reunion) '
        . 'VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$ttId, $profesorId, 'Pruebas', 'T-01', '2026-2', 'Laboratorio de pruebas']);
    $seguimientoId = (int) $db->lastInsertId();
    $db->prepare(
        'INSERT INTO seguimiento_sesiones '
        . '(seguimiento_id, numero_sesion, fecha_hora, actividades_programadas, porcentaje_avance, '
        . 'firma_profesor_pdf_path, firma_profesor_png_path, asistio_profesor) '
        . 'VALUES (?, 1, CURRENT_TIMESTAMP, ?, 50.00, ?, ?, TRUE)'
    )->execute([
        $seguimientoId,
        'Sesión temporal de pruebas',
        'storage/tests/firma-profesor.pdf',
        'storage/tests/firma-profesor.png',
    ]);
    $sesionId = (int) $db->lastInsertId();
    $db->prepare(
        'INSERT INTO seguimiento_asistencias_alumnos '
        . '(sesion_id, alumno_id, asistio, firma_alumno_pdf_path, firma_alumno_png_path, fecha_registro) '
        . 'VALUES (?, ?, TRUE, ?, ?, CURRENT_TIMESTAMP)'
    )->execute([
        $sesionId,
        $alumnoId,
        'storage/tests/firma-alumno.pdf',
        'storage/tests/firma-alumno.png',
    ]);

    return [
        'alumno_id' => $alumnoId,
        'director_id' => $directorId,
        'profesor_id' => $profesorId,
        'tt_id' => $ttId,
        'acuse_id' => $acuseId,
        'sesion_id' => $sesionId,
    ];
}

function runTestsFormatoColumna(string $valor, int $ancho): string
{
    $valor = preg_replace('/\s+/', ' ', $valor) ?? $valor;
    if (mb_strlen($valor, 'UTF-8') > $ancho) {
        $valor = mb_substr($valor, 0, $ancho - 3, 'UTF-8') . '...';
    }
    return str_pad($valor, $ancho);
}

$resultados = [];
$archivosTemporales = [];
$ejecutarPrueba = static function (
    string $id,
    string $modulo,
    string $caso,
    string $esperado,
    callable $verificar
) use (&$resultados): void {
    $inicio = microtime(true);
    try {
        $obtenido = $verificar();
        if (!is_string($obtenido)) {
            $obtenido = (string) $obtenido;
        }
    } catch (Throwable $exception) {
        $obtenido = 'ERROR: ' . $exception->getMessage();
    }
    $resultados[] = [
        'id' => $id,
        'modulo' => $modulo,
        'caso' => $caso,
        'esperado' => $esperado,
        'obtenido' => $obtenido,
        'estado' => $obtenido === $esperado ? 'PASS' : 'FAIL',
        'tiempo' => (microtime(true) - $inicio) * 1000,
    ];
};

$db = null;
$fixture = null;
$errorFixture = null;
try {
    $db = database();
    $db->beginTransaction();
    $fixture = runTestsCrearFixture($db);
} catch (Throwable $exception) {
    $errorFixture = $exception->getMessage();
}

$ejecutarPrueba(
    'DB-00',
    'Base de datos',
    'Fixture temporal dentro de transacción',
    'Preparado',
    static fn (): string => $fixture === null ? 'ERROR: ' . ($errorFixture ?? 'sin fixture') : 'Preparado'
);

$obtenerDb = static function () use ($db): PDO {
    if (!$db instanceof PDO) {
        throw new RuntimeException($errorFixture ?? 'No se pudo abrir la conexión PDO.');
    }
    return $db;
};
$obtenerFixture = static function () use ($fixture, $errorFixture): array {
    if ($fixture === null) {
        throw new RuntimeException($errorFixture ?? 'No se pudo preparar el fixture de base de datos.');
    }
    return $fixture;
};

$ejecutarPrueba('DB-01', 'Esquema', 'Rol profesor_seguimiento en Usuario_Roles', 'Existe', static function () use ($obtenerDb): string {
    return runTestsRolExiste($obtenerDb(), 'profesor_seguimiento') ? 'Existe' : 'Falta';
});
$ejecutarPrueba('DB-02', 'Esquema', 'Columna TT.fecha_presentacion', 'DATE', static function () use ($obtenerDb): string {
    $query = $obtenerDb()->query(
        "SELECT DATA_TYPE FROM information_schema.COLUMNS "
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'TT' AND COLUMN_NAME = 'fecha_presentacion'"
    );
    $tipo = $query->fetchColumn();
    return is_string($tipo) ? strtoupper($tipo) : 'Falta';
});

$pdfOrigen = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'DocentesEscom.pdf';
$ejecutarPrueba('PDF-01', 'Firmas PDF/PNG', 'Conversión Ghostscript a PNG no vacío', 'PNG válido', static function () use ($pdfOrigen, &$archivosTemporales): string {
    $gsEsperado = 'C:\\Program Files\\gs\\gs10.08.0\\bin\\gswin64c.exe';
    $gsPattern = 'C:\\Program Files\\gs\\*\\bin\\gswin64c.exe';
    $ejecutables = glob($gsPattern);
    if (!is_file($gsEsperado) || $ejecutables === false || !in_array($gsEsperado, $ejecutables, true)) {
        throw new RuntimeException('No se encontró el Ghostscript especificado para la prueba.');
    }
    if (!is_file($pdfOrigen)) {
        throw new RuntimeException('No se encontró DocentesEscom.pdf para la prueba de conversión.');
    }
    $temporal = tempnam(sys_get_temp_dir(), 'prottocol-test-');
    if ($temporal === false || !unlink($temporal)) {
        throw new RuntimeException('No fue posible preparar el PNG temporal de prueba.');
    }
    $pngDestino = $temporal . '.png';
    $archivosTemporales[] = $pngDestino;
    convertirFirmaPdfAPngConGhostscript($pdfOrigen, $pngDestino);
    $tamano = filesize($pngDestino);
    return $tamano !== false && $tamano > 0 ? 'PNG válido' : 'PNG vacío';
});
$ejecutarPrueba('PDF-02', 'Firmas PDF/PNG', 'Rechaza extensión distinta de PDF', 'Rechazado', static function () use ($pdfOrigen): string {
    try {
        validarPdfFirmado($pdfOrigen, 'firma.txt');
        return 'Aceptado';
    } catch (DomainException $exception) {
        return str_contains($exception->getMessage(), 'formato PDF') ? 'Rechazado' : 'Rechazo inesperado';
    }
});
$ejecutarPrueba('PDF-03', 'Firmas PDF/PNG', 'Rechaza PDF mayor a 1 MB', 'Rechazado', static function () use ($pdfOrigen, &$archivosTemporales): string {
    $contenido = file_get_contents($pdfOrigen);
    if ($contenido === false || strlen($contenido) > MAX_PDF_FIRMADO_BYTES) {
        throw new RuntimeException('El PDF fixture no sirve para preparar la prueba de tamaño.');
    }
    $temporal = tempnam(sys_get_temp_dir(), 'prottocol-test-');
    if ($temporal === false) {
        throw new RuntimeException('No fue posible preparar el PDF temporal de tamaño.');
    }
    $archivosTemporales[] = $temporal;
    $contenido .= str_repeat(' ', MAX_PDF_FIRMADO_BYTES + 1 - strlen($contenido));
    if (file_put_contents($temporal, $contenido) === false) {
        throw new RuntimeException('No fue posible escribir el PDF temporal de tamaño.');
    }
    try {
        validarPdfFirmado($temporal, 'firma-grande.pdf');
        return 'Aceptado';
    } catch (DomainException $exception) {
        return str_contains($exception->getMessage(), '1 MB') ? 'Rechazado' : 'Rechazo inesperado';
    }
});

$ejecutarPrueba('F1-01', 'Formulario 1', 'Bloquea descarga con más de 7 días', 'Bloqueada', static function () use ($obtenerDb, $obtenerFixture): string {
    $formulario = [
        'Estado' => 'aprobado',
        'TT_id' => $obtenerFixture()['tt_id'],
        'fecha_presentacion' => (new DateTimeImmutable('today'))->modify('+8 days')->format('Y-m-d'),
    ];
    $user = ['id' => $obtenerFixture()['alumno_id'], 'roles' => ['estudiante']];
    return formulario1PuedeDescargar($obtenerDb(), $user, $formulario) ? 'Habilitada' : 'Bloqueada';
});
$ejecutarPrueba('F1-02', 'Formulario 1', 'Bloquea si CATT no ha aprobado', 'Bloqueada', static function () use ($obtenerDb, $obtenerFixture): string {
    $formulario = [
        'Estado' => 'en_revision_catt',
        'TT_id' => $obtenerFixture()['tt_id'],
        'fecha_presentacion' => (new DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d'),
    ];
    $user = ['id' => $obtenerFixture()['alumno_id'], 'roles' => ['estudiante']];
    return formulario1PuedeDescargar($obtenerDb(), $user, $formulario) ? 'Habilitada' : 'Bloqueada';
});
$ejecutarPrueba('F1-03', 'Formulario 1', 'Habilita aprobado a 7 días', 'Habilitada', static function () use ($obtenerDb, $obtenerFixture): string {
    $formulario = [
        'Estado' => 'aprobado',
        'TT_id' => $obtenerFixture()['tt_id'],
        'fecha_presentacion' => (new DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d'),
    ];
    $user = ['id' => $obtenerFixture()['alumno_id'], 'roles' => ['estudiante']];
    return formulario1PuedeDescargar($obtenerDb(), $user, $formulario) ? 'Habilitada' : 'Bloqueada';
});

$ejecutarPrueba('F2-01', 'Formulario 2', 'Inserta medio de entrega', 'formato_impreso', static function () use ($obtenerDb, $obtenerFixture): string {
    $db = $obtenerDb();
    $fixture = $obtenerFixture();
    $db->prepare(
        "INSERT INTO acuse_entregas (acuse_id, usuario_id, rol, medio_entrega, firma_pdf_path, fecha_firma) "
        . "VALUES (?, ?, 'director', 'formato_impreso', ?, CURRENT_TIMESTAMP)"
    )->execute([$fixture['acuse_id'], $fixture['director_id'], 'storage/tests/firma-director.pdf']);
    $query = $db->prepare('SELECT medio_entrega FROM acuse_entregas WHERE acuse_id = ? AND usuario_id = ?');
    $query->execute([$fixture['acuse_id'], $fixture['director_id']]);
    return (string) $query->fetchColumn();
});
$ejecutarPrueba('F2-02', 'Formulario 2', 'CATT pendiente bloquea descarga', 'Bloqueada', static function () use ($obtenerDb, $obtenerFixture): string {
    $fixture = $obtenerFixture();
    $acuse = acuseDetalle($obtenerDb(), $fixture['acuse_id']);
    $user = ['id' => $fixture['alumno_id'], 'roles' => ['estudiante']];
    return $acuse && acusePuedeDescargar($obtenerDb(), $user, $acuse) ? 'Habilitada' : 'Bloqueada';
});
$ejecutarPrueba('F2-03', 'Formulario 2', 'CATT aprobado habilita descarga', 'Habilitada', static function () use ($obtenerDb, $obtenerFixture): string {
    $db = $obtenerDb();
    $fixture = $obtenerFixture();
    $db->prepare("UPDATE acuses_reporte_tecnico SET estado = 'aprobado' WHERE id = ?")
        ->execute([$fixture['acuse_id']]);
    $acuse = acuseDetalle($db, $fixture['acuse_id']);
    $user = ['id' => $fixture['alumno_id'], 'roles' => ['estudiante']];
    return $acuse && acusePuedeDescargar($db, $user, $acuse) ? 'Habilitada' : 'Bloqueada';
});

$ejecutarPrueba('F3-01', 'Formulario 3', 'Suma de avance igual a 100%', 'Aceptada', static function (): string {
    return seguimientoSumaAvanceValida(90.0, 10.0) ? 'Aceptada' : 'Rechazada';
});
$ejecutarPrueba('F3-02', 'Formulario 3', 'Suma de avance mayor a 100%', 'Rechazada', static function (): string {
    return seguimientoSumaAvanceValida(90.0, 10.01) ? 'Aceptada' : 'Rechazada';
});
$ejecutarPrueba('F3-03', 'Formulario 3', 'Registra asistencia de alumno y profesor', 'Profesor y alumno', static function () use ($obtenerDb, $obtenerFixture): string {
    $query = $obtenerDb()->prepare(
        'SELECT s.asistio_profesor, s.firma_profesor_pdf_path, a.asistio, a.firma_alumno_pdf_path '
        . 'FROM seguimiento_sesiones s JOIN seguimiento_asistencias_alumnos a ON a.sesion_id = s.id '
        . 'WHERE s.id = ? AND a.alumno_id = ?'
    );
    $fixture = $obtenerFixture();
    $query->execute([$fixture['sesion_id'], $fixture['alumno_id']]);
    $asistencia = $query->fetch();
    $profesorFirmo = $asistencia
        && !empty($asistencia['asistio_profesor'])
        && !empty($asistencia['firma_profesor_pdf_path']);
    $alumnoFirmo = $asistencia
        && !empty($asistencia['asistio'])
        && !empty($asistencia['firma_alumno_pdf_path']);
    return $profesorFirmo && $alumnoFirmo ? 'Profesor y alumno' : 'Registro incompleto';
});

$limpiezaOk = true;
foreach ($archivosTemporales as $archivoTemporal) {
    if (is_file($archivoTemporal) && !unlink($archivoTemporal)) {
        $limpiezaOk = false;
    }
}
$ejecutarPrueba('FS-01', 'Limpieza', 'Elimina archivos temporales', 'Eliminados', static fn (): string => $limpiezaOk ? 'Eliminados' : 'Fallo');

$rollbackObtenido = 'No había transacción';
if ($db instanceof PDO && $db->inTransaction()) {
    try {
        $rollbackObtenido = $db->rollBack() ? 'Revertida' : 'Fallo';
    } catch (Throwable $exception) {
        $rollbackObtenido = 'ERROR: ' . $exception->getMessage();
    }
}
$ejecutarPrueba('TX-01', 'Base de datos', 'Rollback final de fixture', 'Revertida', static fn (): string => $rollbackObtenido);

$columnas = [
    ['ID', 6],
    ['Módulo', 20],
    ['Caso de Prueba', 45],
    ['Esperado', 25],
    ['Obtenido', 30],
    ['Estado', 7],
    ['Tiempo (ms)', 12],
];
$borde = '+' . implode('+', array_map(static fn (array $columna): string => str_repeat('-', $columna[1] + 2), $columnas)) . '+';
echo $borde, PHP_EOL;
echo '|';
foreach ($columnas as [$titulo, $ancho]) {
    echo ' ', runTestsFormatoColumna($titulo, $ancho), ' |';
}
echo PHP_EOL, $borde, PHP_EOL;
foreach ($resultados as $resultado) {
    echo '| ',
        runTestsFormatoColumna($resultado['id'], $columnas[0][1]), ' | ',
        runTestsFormatoColumna($resultado['modulo'], $columnas[1][1]), ' | ',
        runTestsFormatoColumna($resultado['caso'], $columnas[2][1]), ' | ',
        runTestsFormatoColumna($resultado['esperado'], $columnas[3][1]), ' | ',
        runTestsFormatoColumna($resultado['obtenido'], $columnas[4][1]), ' | ',
        runTestsFormatoColumna($resultado['estado'], $columnas[5][1]), ' | ',
        str_pad(number_format($resultado['tiempo'], 2, '.', ''), $columnas[6][1]), ' |',
        PHP_EOL;
}
echo $borde, PHP_EOL;

$aprobadas = count(array_filter($resultados, static fn (array $resultado): bool => $resultado['estado'] === 'PASS'));
$fallidas = count($resultados) - $aprobadas;
echo 'Resumen: ', $aprobadas, ' PASS, ', $fallidas, ' FAIL, ', count($resultados), ' pruebas.', PHP_EOL;
exit($fallidas === 0 ? 0 : 1);
