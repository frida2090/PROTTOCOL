<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/acuses_reporte_tecnico.php';

$user = currentUser();
if (!$user) {
    flash('error', 'Inicia sesión para descargar el acuse.');
    redirect('login.php');
}
$db = database();
$acuse = acuseDetalle($db, (int) ($_GET['id'] ?? 0));
if (!$acuse || !acusePuedeAcceder($user, $acuse) || !acusePuedeDescargar($db, $user, $acuse)) {
    flash('error', 'El acuse no está aprobado o aún no se encuentra en el periodo de descarga.');
    redirect('acuseReporteTecnico.php');
}

$pdf = acuseGenerarPdf($acuse);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="acuse-reporte-tecnico-' . rawurlencode((string) $acuse['NumeroTT']) . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
