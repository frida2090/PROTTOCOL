<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';

$user = currentUser();
$canManage = $user && in_array($user['rol'], ['profesor', 'miembroCatt'], true);
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $fecha = $_POST['fecha'] ?? '';
    $fechaFin = $_POST['fecha_fin'] ?? '';
    $actividad = trim($_POST['actividad'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    if ($fecha === '' || $actividad === '') {
        $error = 'La fecha y el nombre de la actividad son obligatorios.';
    } elseif ($fechaFin !== '' && $fechaFin < $fecha) {
        $error = 'La fecha final no puede ser anterior a la fecha de inicio.';
    } else {
        $query = database()->prepare('INSERT INTO calendario (fecha, fecha_fin, actividad, descripcion, profesor_id) VALUES (?, ?, ?, ?, ?)');
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
$query = database()->prepare("SELECT c.fecha, c.fecha_fin, c.actividad, c.descripcion, CONCAT_WS(' ', u.nombre, u.apellido_paterno, u.apellido_materno) AS profesor FROM calendario c JOIN usuario u ON u.id = c.profesor_id WHERE c.fecha <= ? AND (c.fecha_fin IS NULL OR c.fecha_fin >= ?) ORDER BY c.fecha, c.actividad");
$query->execute([$endDate, $startDate]);
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
            <?php foreach ($activitiesByDay[$dateKey] ?? [] as $activity): ?><a class="calendar-event" href="#actividad-<?= e($activity['fecha']) ?>-<?= e((string) $dayNumber) ?>" title="<?= e($activity['actividad']) ?>"><?= e($activity['actividad']) ?></a><?php endforeach; ?>
        </div>
    <?php endfor; ?>
</section>
<div class="activity-list">
<?php if (!$activities): ?>
    <div class="empty-state"><span>◌</span><h2>Este mes aún está libre</h2><p>No hay actividades registradas para el periodo seleccionado.</p></div>
<?php else: foreach ($activities as $activity): ?>
    <article class="activity-item" id="actividad-<?= e($activity['fecha']) ?>-<?= e((string) date('d', strtotime($activity['fecha']))) ?>"><time datetime="<?= e($activity['fecha']) ?>"><strong><?= e(date('d', strtotime($activity['fecha']))) ?></strong><span><?= e(substr($monthNames[(int) date('n', strtotime($activity['fecha']))], 0, 3)) ?></span></time><div><h2><?= e($activity['actividad']) ?></h2><p><?= e($activity['descripcion'] ?: 'Actividad académica CATT') ?></p><?php if ($activity['fecha_fin']): ?><small class="date-range">Hasta <?= e(date('d/m/Y', strtotime($activity['fecha_fin']))) ?></small><?php endif; ?><small>Imparte <?= e($activity['profesor']) ?></small></div></article>
<?php endforeach; endif; ?>
</div>
<?php if ($canManage): ?>
<section id="nueva-actividad" class="new-activity"><div><p class="eyebrow">Gestión</p><h2>Agregar una actividad</h2><p>Completa los datos para compartirla con la comunidad.</p></div><form method="post" class="activity-form"><label for="fecha">Fecha de inicio</label><input id="fecha" name="fecha" type="date" required><label for="fecha_fin">Fecha final <span>(opcional)</span></label><input id="fecha_fin" name="fecha_fin" type="date"><label for="actividad">Actividad</label><input id="actividad" name="actividad" required placeholder="Nombre de la actividad"><label for="descripcion">Descripción <span>(opcional)</span></label><textarea id="descripcion" name="descripcion" rows="3"></textarea><button class="button button-primary" type="submit">Publicar actividad</button></form></section>
<?php endif; ?>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
