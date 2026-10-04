<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';

$user = currentUser();
$userRoles = $user['roles'] ?? [];
$canManage = (bool) array_intersect($userRoles, ['director', 'miembroCatt', 'jefeAcademia']);
$canAssignPresentations = (bool) array_intersect($userRoles, ['miembroCatt', 'jefeAcademia']);
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['formulario'] ?? '') === 'presentacion' && $canAssignPresentations) {
    $fecha = trim($_POST['fecha_presentacion'] ?? '');
    $tipoTT = $_POST['tipo_tt'] ?? '';
    $alumnoId = (int) ($_POST['alumno_id'] ?? 0);
    $directoresRaw = $_POST['directores'] ?? [];
    $sinodalesRaw = $_POST['sinodales'] ?? [];
    $directorIds = is_array($directoresRaw)
        ? array_values(array_unique(array_filter(array_map('intval', $directoresRaw), static fn (int $id): bool => $id > 0)))
        : [];
    $sinodalIds = is_array($sinodalesRaw)
        ? array_values(array_unique(array_filter(array_map('intval', $sinodalesRaw), static fn (int $id): bool => $id > 0)))
        : [];
    $tituloEquipo = trim($_POST['titulo_equipo'] ?? '');
    $descripcionEquipo = trim($_POST['descripcion_equipo'] ?? '');

    $fechaValida = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) === 1
        && checkdate((int) substr($fecha, 5, 2), (int) substr($fecha, 8, 2), (int) substr($fecha, 0, 4));
    if (!$fechaValida || !in_array($tipoTT, ['TT1', 'TT2'], true) || $alumnoId < 1 || count($directorIds) !== 2 || count($sinodalIds) !== 3) {
        $error = 'Selecciona una fecha, el tipo de TT, un alumno, exactamente dos directores y exactamente tres sinodales.';
    } else {
        $selectedIds = array_values(array_unique(array_merge([$alumnoId], $directorIds, $sinodalIds)));
        $placeholders = implode(', ', array_fill(0, count($selectedIds), '?'));
        $usersQuery = database()->prepare("SELECT UserId AS id, Nombre AS nombre, ApellidoPaterno AS apellido_paterno, ApellidoMaterno AS apellido_materno FROM Usuarios WHERE UserId IN ({$placeholders})");
        $usersQuery->execute($selectedIds);
        $selectedUsers = $usersQuery->fetchAll();
        $usersById = [];
        foreach ($selectedUsers as $selectedUser) {
            $usersById[(int) $selectedUser['id']] = $selectedUser;
        }
        $roleQuery = database()->prepare("SELECT UserId, Rol FROM Usuario_Roles WHERE UserId IN ({$placeholders})");
        $roleQuery->execute($selectedIds);
        $rolesByUser = [];
        foreach ($roleQuery->fetchAll() as $roleRow) {
            $rolesByUser[(int) $roleRow['UserId']][] = $roleRow['Rol'];
        }
        $cattQuery = database()->query("SELECT UserId, Rol FROM Usuario_Roles WHERE Rol IN ('miembroCatt', 'jefeAcademia')");
        $cattParticipants = $cattQuery->fetchAll();
        $rolesValidos = count($usersById) === count($selectedIds)
            && in_array('estudiante', $rolesByUser[$alumnoId] ?? [], true);
        foreach ($directorIds as $directorId) {
            $rolesValidos = $rolesValidos && in_array('director', $rolesByUser[$directorId] ?? [], true);
        }
        foreach ($sinodalIds as $sinodalId) {
            $rolesValidos = $rolesValidos && in_array('sinodal', $rolesByUser[$sinodalId] ?? [], true);
        }
        $teamQuery = database()->prepare('SELECT EquipoId FROM Estudiante WHERE UserId = ?');
        $teamQuery->execute([$alumnoId]);
        $equipoId = (int) ($teamQuery->fetchColumn() ?: 0);

        if (!$rolesValidos) {
            $error = 'El alumno, director y sinodales seleccionados no tienen los roles esperados.';
        } elseif (!$cattParticipants) {
            $error = 'Registra al menos un miembro CATT o jefe de academia antes de programar presentaciones.';
        } elseif ($equipoId === 0 && ($tituloEquipo === '' || $descripcionEquipo === '')) {
            $error = 'Captura título y descripción para crear el equipo de Trabajo Terminal del alumno.';
        } else {
            $alumno = fullName($usersById[$alumnoId]);
            $directores = array_map(static fn (int $id): string => fullName($usersById[$id]), $directorIds);
            $sinodales = array_map(static fn (int $id): string => fullName($usersById[$id]), $sinodalIds);
            $actividad = "Presentación de {$tipoTT} - {$alumno}";
            $descripcion = 'Alumno: ' . $alumno . ' | Directores: ' . implode(', ', $directores) . ' | Sinodales: ' . implode(', ', $sinodales);
            $connection = database();
            try {
                $connection->beginTransaction();
                if ($equipoId === 0) {
                    $teamInsert = $connection->prepare('INSERT INTO Equipo_TT (TituloProtocolo, Descripcion) VALUES (?, ?)');
                    $teamInsert->execute([$tituloEquipo, $descripcionEquipo]);
                    $equipoId = (int) $connection->lastInsertId();
                    $studentTeamUpdate = $connection->prepare('UPDATE Estudiante SET EquipoId = ? WHERE UserId = ? AND EquipoId IS NULL');
                    $studentTeamUpdate->execute([$equipoId, $alumnoId]);
                }
                $teamAssignment = $connection->prepare('INSERT IGNORE INTO EmpleadoEquipo (UserId, EquipoId) VALUES (?, ?)');
                foreach (array_values(array_unique(array_merge($directorIds, $sinodalIds))) as $teacherId) {
                    $teamAssignment->execute([$teacherId, $equipoId]);
                }
                $eventQuery = $connection->prepare('INSERT INTO calendario (fecha, actividad, descripcion, tipo_tt, creado_por_user_id) VALUES (?, ?, ?, ?, ?)');
                $eventQuery->execute([$fecha, $actividad, $descripcion, $tipoTT, $user['id']]);
                $calendarId = (int) $connection->lastInsertId();
                $participantQuery = $connection->prepare('INSERT INTO calendario_participante (calendario_id, UserId, Rol) VALUES (?, ?, ?)');
                $participantQuery->execute([$calendarId, $alumnoId, 'estudiante']);
                foreach ($directorIds as $directorId) {
                    $participantQuery->execute([$calendarId, $directorId, 'director']);
                }
                foreach ($sinodalIds as $sinodalId) {
                    $participantQuery->execute([$calendarId, $sinodalId, 'sinodal']);
                }
                foreach ($cattParticipants as $cattParticipant) {
                    $participantQuery->execute([$calendarId, (int) $cattParticipant['UserId'], $cattParticipant['Rol']]);
                }
                $connection->commit();
                flash('success', "La presentación de {$tipoTT} fue asignada con dos directores y tres sinodales; el equipo quedó vinculado a sus docentes.");
                redirect('calendario.php');
            } catch (Throwable $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                $error = 'No fue posible guardar la fecha de presentación. Intenta de nuevo.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['formulario'] ?? '') === 'actividad' && $canManage) {
    $fecha = $_POST['fecha'] ?? '';
    $fechaFin = $_POST['fecha_fin'] ?? '';
    $actividad = trim($_POST['actividad'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    if ($fecha === '' || $actividad === '') {
        $error = 'La fecha y el nombre de la actividad son obligatorios.';
    } elseif ($fechaFin !== '' && $fechaFin < $fecha) {
        $error = 'La fecha final no puede ser anterior a la fecha de inicio.';
    } else {
        $query = database()->prepare('INSERT INTO calendario (fecha, fecha_fin, actividad, descripcion, creado_por_user_id) VALUES (?, ?, ?, ?, ?)');
        $query->execute([$fecha, $fechaFin ?: null, $actividad, $descripcion ?: null, $user['id']]);
        flash('success', 'La actividad fue agregada al calendario.');
        redirect('calendario.php');
    }
}

$month = $_GET['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$startDate = $month . '-01';
$endDate = date('Y-m-t', strtotime($startDate));
$monthNames = [1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL', 5 => 'MAYO', 6 => 'JUNIO', 7 => 'JULIO', 8 => 'AGOSTO', 9 => 'SEPTIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE'];
$students = $canAssignPresentations ? database()->query("SELECT u.UserId AS id, u.Nombre AS nombre, u.ApellidoPaterno AS apellido_paterno, u.ApellidoMaterno AS apellido_materno, e.NumeroBoleta AS noBoleta FROM Usuarios u JOIN Estudiante e ON e.UserId = u.UserId JOIN Usuario_Roles ur ON ur.UserId = u.UserId AND ur.Rol = 'estudiante' ORDER BY u.ApellidoPaterno, u.ApellidoMaterno, u.Nombre")->fetchAll() : [];
$directors = $canAssignPresentations ? database()->query("SELECT u.UserId AS id, u.Nombre AS nombre, u.ApellidoPaterno AS apellido_paterno, u.ApellidoMaterno AS apellido_materno, em.NumeroEmpleado AS numero_empleado FROM Usuarios u JOIN Empleado em ON em.UserId = u.UserId JOIN Usuario_Roles ur ON ur.UserId = u.UserId AND ur.Rol = 'director' ORDER BY u.ApellidoPaterno, u.ApellidoMaterno, u.Nombre")->fetchAll() : [];
$sinodals = $canAssignPresentations ? database()->query("SELECT u.UserId AS id, u.Nombre AS nombre, u.ApellidoPaterno AS apellido_paterno, u.ApellidoMaterno AS apellido_materno, em.NumeroEmpleado AS numero_empleado FROM Usuarios u JOIN Empleado em ON em.UserId = u.UserId JOIN Usuario_Roles ur ON ur.UserId = u.UserId AND ur.Rol = 'sinodal' ORDER BY u.ApellidoPaterno, u.ApellidoMaterno, u.Nombre")->fetchAll() : [];
$visibilitySql = $user
    ? 'AND (c.tipo_tt IS NULL OR EXISTS (SELECT 1 FROM calendario_participante cp WHERE cp.calendario_id = c.id AND cp.UserId = ?))'
    : 'AND c.tipo_tt IS NULL';
$query = database()->prepare("SELECT c.id, c.fecha, c.fecha_fin, c.actividad, c.descripcion, c.tipo_tt, CONCAT_WS(' ', u.Nombre, u.ApellidoPaterno, u.ApellidoMaterno) AS creador FROM calendario c JOIN Usuarios u ON u.UserId = c.creado_por_user_id WHERE c.fecha <= ? AND (c.fecha_fin IS NULL OR c.fecha_fin >= ?) {$visibilitySql} ORDER BY c.fecha, c.actividad");
$query->execute($user ? [$endDate, $startDate, $user['id']] : [$endDate, $startDate]);
$activities = $query->fetchAll();
$daysInMonth = (int) date('t', strtotime($startDate));
$firstWeekday = (int) date('N', strtotime($startDate));
$activitiesByDay = [];
foreach ($activities as $activity) {
    $activityStart = max($activity['fecha'], $startDate);
    $activityEnd = min($activity['fecha_fin'] ?: $activity['fecha'], $endDate);
    $day = new DateTimeImmutable($activityStart);
    $lastDay = new DateTimeImmutable($activityEnd);
    while ($day <= $lastDay) {
        $activitiesByDay[$day->format('Y-m-d')][] = $activity;
        $day = $day->modify('+1 day');
    }
}
$previousMonth = date('Y-m', strtotime($startDate . ' -1 month'));
$nextMonth = date('Y-m', strtotime($startDate . ' +1 month'));
$today = date('Y-m-d');

$pageTitle = 'Calendario';
require __DIR__ . '/../app/views/header.php';
?>
<section class="calendar-heading">
    <div><p class="eyebrow">Agenda CATT</p><h1>Calendario de actividades</h1><p>Una vista compartida para las fechas que mueven a la comunidad.</p></div>
    <?php if ($canManage): ?><a class="button button-gold" href="#nueva-actividad">+ Nueva actividad</a><?php endif; ?>
</section>
<div class="calendar-toolbar">
    <div class="month-navigation"><a class="button button-dark" href="?mes=<?= e($previousMonth) ?>" aria-label="Mes anterior">←</a><strong><?= e($monthNames[(int) date('n', strtotime($startDate))]) ?> <?= e(date('Y', strtotime($startDate))) ?></strong><a class="button button-dark" href="?mes=<?= e($nextMonth) ?>" aria-label="Mes siguiente">→</a></div>
    <form method="get"><label for="mes">Ir a un mes</label><input id="mes" name="mes" type="month" value="<?= e($month) ?>"><button class="button button-dark" type="submit">Consultar</button></form>
    <span class="activity-count"><?= count($activities) ?> <?= count($activities) === 1 ? 'actividad' : 'actividades' ?></span>
</div>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<section class="calendar-grid" aria-label="Calendario de actividades de <?= e($monthNames[(int) date('n', strtotime($startDate))]) ?> <?= e(date('Y', strtotime($startDate))) ?>">
    <?php foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $weekday): ?><div class="calendar-weekday"><?= e($weekday) ?></div><?php endforeach; ?>
    <?php for ($blank = 1; $blank < $firstWeekday; $blank++): ?><div class="calendar-day calendar-day-empty" aria-hidden="true"></div><?php endfor; ?>
    <?php for ($dayNumber = 1; $dayNumber <= $daysInMonth; $dayNumber++): $dateKey = $month . '-' . str_pad((string) $dayNumber, 2, '0', STR_PAD_LEFT); ?>
        <div class="calendar-day<?= $dateKey === $today ? ' calendar-day-today' : '' ?>">
            <span class="day-number"><?= $dayNumber ?></span>
            <?php foreach ($activitiesByDay[$dateKey] ?? [] as $activity): ?><a class="calendar-event" href="#actividad-<?= e((string) $activity['id']) ?>" title="<?= e($activity['actividad']) ?>"><?= e($activity['actividad']) ?></a><?php endforeach; ?>
        </div>
    <?php endfor; ?>
</section>
<div class="activity-list">
<?php if (!$activities): ?>
    <div class="empty-state"><span>◌</span><h2>Este mes aún está libre</h2><p>No hay actividades registradas para el periodo seleccionado.</p></div>
<?php else: foreach ($activities as $activity): ?>
    <article class="activity-item" id="actividad-<?= e((string) $activity['id']) ?>"><time datetime="<?= e($activity['fecha']) ?>"><strong><?= e(date('d', strtotime($activity['fecha']))) ?></strong><span><?= e(substr($monthNames[(int) date('n', strtotime($activity['fecha']))], 0, 3)) ?></span></time><div><h2><?= e($activity['actividad']) ?></h2><p><?= e($activity['descripcion'] ?: 'Actividad académica CATT') ?></p><?php if ($activity['tipo_tt']): ?><small class="date-range"><?= e($activity['tipo_tt']) ?></small><?php endif; ?><?php if ($activity['fecha_fin']): ?><small class="date-range">Hasta <?= e(date('d/m/Y', strtotime($activity['fecha_fin']))) ?></small><?php endif; ?><small>Registró <?= e($activity['creador']) ?></small></div></article>
<?php endforeach; endif; ?>
</div>
<?php if ($canAssignPresentations): ?>
<section class="new-activity">
    <div><p class="eyebrow">Asignación CATT</p><h2>Programar presentación TT</h2><p>Asigna una fecha, dos directores y tres sinodales al estudiante.</p></div>
    <form method="post" class="activity-form">
        <input type="hidden" name="formulario" value="presentacion">
        <label for="tipo_tt">Trabajo terminal</label>
        <select id="tipo_tt" name="tipo_tt" required><option value="">Selecciona TT1 o TT2</option><option value="TT1">TT1</option><option value="TT2">TT2</option></select>
        <label for="fecha_presentacion">Fecha de presentación</label>
        <input id="fecha_presentacion" name="fecha_presentacion" type="date" required>
        <label for="alumno_id">Estudiante</label>
        <select id="alumno_id" name="alumno_id" required><option value="">Selecciona un estudiante</option><?php foreach ($students as $student): ?><option value="<?= e((string) $student['id']) ?>"><?= e(fullName($student)) ?> · <?= e($student['noBoleta']) ?></option><?php endforeach; ?></select>
        <label for="titulo_equipo">Título del equipo / protocolo</label>
        <input id="titulo_equipo" name="titulo_equipo" maxlength="100" placeholder="Título del Trabajo Terminal">
        <label for="descripcion_equipo">Descripción del equipo</label>
        <textarea id="descripcion_equipo" name="descripcion_equipo" rows="3" placeholder="Descripción del proyecto"></textarea>
        <fieldset class="presentation-people"><legend>Dos directores</legend><?php foreach ($directors as $director): ?><label><input type="checkbox" name="directores[]" value="<?= e((string) $director['id']) ?>"> <?= e(fullName($director)) ?> · <?= e($director['numero_empleado']) ?></label><?php endforeach; ?></fieldset>
        <fieldset class="presentation-people"><legend>Tres sinodales</legend><?php foreach ($sinodals as $sinodal): ?><label><input type="checkbox" name="sinodales[]" value="<?= e((string) $sinodal['id']) ?>"> <?= e(fullName($sinodal)) ?> · <?= e($sinodal['numero_empleado']) ?></label><?php endforeach; ?></fieldset>
        <button class="button button-primary" type="submit">Asignar presentación</button>
    </form>
</section>
<?php endif; ?>
<?php if ($canManage): ?>
<section id="nueva-actividad" class="new-activity"><div><p class="eyebrow">Gestión</p><h2>Agregar una actividad</h2><p>Completa los datos para compartirla con la comunidad.</p></div><form method="post" class="activity-form"><input type="hidden" name="formulario" value="actividad"><label for="fecha">Fecha de inicio</label><input id="fecha" name="fecha" type="date" required><label for="fecha_fin">Fecha final <span>(opcional)</span></label><input id="fecha_fin" name="fecha_fin" type="date"><label for="actividad">Actividad</label><input id="actividad" name="actividad" required placeholder="Nombre de la actividad"><label for="descripcion">Descripción <span>(opcional)</span></label><textarea id="descripcion" name="descripcion" rows="3"></textarea><button class="button button-primary" type="submit">Publicar actividad</button></form></section>
<?php endif; ?>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
