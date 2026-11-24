<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/formulario1.php';

$user = currentUser();
if (!$user) {
    flash('error', 'Inicia sesión para consultar la firma.');
    redirect('login.php');
}

$firmaId = (int) ($_GET['id'] ?? 0);
$query = database()->prepare('SELECT Formulario1Id, RutaArchivo FROM Formulario1_Firma WHERE FirmaId = ?');
$query->execute([$firmaId]);
$firma = $query->fetch();
$formulario = $firma ? formulario1Detalle(database(), (int) $firma['Formulario1Id']) : null;
if (!$firma || !$formulario || !formulario1PuedeAcceder($user, $formulario) || !$firma['RutaArchivo']) {
    flash('error', 'La firma no existe o no tienes permiso para consultarla.');
    redirect('formulario1.php');
}

try {
    $ruta = formulario1RutaFirma((string) $firma['RutaArchivo']);
} catch (RuntimeException $exception) {
    flash('error', 'No se encontró el archivo de firma solicitado.');
    redirect('formulario1.php?id=' . (int) $firma['Formulario1Id']);
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="firma-formulario1-' . $firmaId . '.pdf"');
header('Content-Length: ' . filesize($ruta));
header('X-Content-Type-Options: nosniff');
readfile($ruta);
