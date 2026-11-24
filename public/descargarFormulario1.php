<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/formulario1.php';

$user = currentUser();
if (!$user) {
    flash('error', 'Inicia sesión para descargar el documento.');
    redirect('login.php');
}

$formularioId = (int) ($_GET['id'] ?? 0);
$db = database();
$formulario = formulario1Detalle($db, $formularioId);
if (!$formulario || !formulario1PuedeDescargar($db, $user, $formulario)) {
    flash('error', 'El documento aún no está disponible para tu usuario.');
    redirect('formulario1.php');
}
foreach ($formulario['firmantes'] as $firmante) {
    if (!$firmante['RutaArchivo']) {
        flash('error', 'El formulario aún no tiene todas las firmas requeridas.');
        redirect('formulario1.php?id=' . $formularioId);
    }
}

try {
    $contenido = formulario1GenerarPdf($formulario);
} catch (Throwable $exception) {
    flash('error', 'No fue posible generar el PDF final. Verifica los archivos de firma e inténtalo de nuevo.');
    redirect('formulario1.php?id=' . $formularioId);
}

$identificador = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $formulario['NumeroTT']);
$nombreArchivo = 'formulario1-' . $identificador . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Content-Length: ' . strlen($contenido));
header('X-Content-Type-Options: nosniff');
echo $contenido;
