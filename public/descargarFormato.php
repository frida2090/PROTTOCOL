<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/protocolos.php';

$user = currentUser();
if (!$user) {
    flash('error', 'Inicia sesión para descargar el documento.');
    redirect('login.php');
}

$formatoId = (int) ($_GET['id'] ?? 0);
if ($formatoId < 1) {
    flash('error', 'Documento no encontrado.');
    redirect('panelAlumno.php');
}

$query = database()->prepare('SELECT FormatoId, EquipoId, Status FROM Formatos_Subidos WHERE FormatoId = ?');
$query->execute([$formatoId]);
$formato = $query->fetch();

if (!$formato) {
    flash('error', 'El documento solicitado no existe.');
    redirect('panelAlumno.php');
}

if (!puedeVerFormato(database(), $user, $formato)) {
    flash('error', 'No tienes permiso para descargar este documento.');
    $destino = match ($user['rol']) {
        'admin' => 'panelAdmin.php',
        'miembroCatt' => 'panelMiembroCatt.php?modal=revisiones',
        'profesor' => 'panelProfesor.php',
        default => 'panelAlumno.php',
    };
    redirect($destino);
}

$contenidoQuery = database()->prepare('SELECT FormatoSubido FROM Formatos_Subidos WHERE FormatoId = ?');
$contenidoQuery->execute([$formatoId]);
$contenido = $contenidoQuery->fetchColumn();
if (is_resource($contenido)) {
    $contenido = stream_get_contents($contenido);
}
if (!is_string($contenido) || $contenido === '') {
    flash('error', 'El documento está vacío o no pudo leerse.');
    redirect('panelAlumno.php');
}

$nombreArchivo = 'protocolo_equipo' . (int) $formato['EquipoId'] . '_formato' . $formatoId . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $nombreArchivo . '"');
header('Content-Length: ' . strlen($contenido));
header('X-Content-Type-Options: nosniff');
echo $contenido;
