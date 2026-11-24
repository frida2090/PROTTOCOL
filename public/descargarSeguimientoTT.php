<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/seguimientos_tt.php';

$user = currentUser();
if (!$user) {
    flash('error', 'Inicia sesión para exportar el formato de seguimiento.');
    redirect('login.php');
}
$db = database();
$detalle = seguimientoDetalle($db, (int) ($_GET['id'] ?? 0));
if (!$detalle || !$detalle['seguimiento_id'] || !seguimientoPuedeAcceder($db, $user, $detalle)) {
    flash('error', 'El formato de seguimiento no existe o no tienes permiso para exportarlo.');
    redirect('seguimientoTT.php');
}

$pdf = seguimientoGenerarPdf($detalle);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="seguimiento-' . rawurlencode((string) $detalle['NumeroTT']) . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
