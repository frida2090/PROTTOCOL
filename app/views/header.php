<?php
require_once __DIR__ . '/../auth.php';
$user = currentUser();
$flash = consumeFlash();
$pageTitle = $pageTitle ?? 'CATT';
$userPanelUrl = $user ? match ($user['rol']) {
        'estudiante'   => 'panelAlumno.php',
        'profesor'     => 'panelProfesor.php',
        'miembroCatt'  => 'panelMiembroCatt.php',
        default        => 'calendario.php',
    }
    : 'calendario.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | PROTTOCOL CATT</title>
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
<header class="site-header">
    <a class="brand" href="index.php">
        <img src="assets/prottocol.png" alt="Logo PROTTOCOL">
        <span>CATT <small>Actividades académicas</small></span>
    </a>
    <nav class="main-nav" aria-label="Navegación principal">
        <?php if ($user): ?>
            <a href="calendario.php">Calendario</a>
            <a class="user-chip" href="<?= e($userPanelUrl) ?>" title="Abrir mi panel"><?= e($user['nombre']) ?></a>
            <a class="nav-logout" href="logout.php">Salir</a>
        <?php else: ?>
            <a href="index.php#inicio">Inicio</a>
            <a href="index.php#procesos">Procesos</a>
            <a href="index.php#formularios">Formularios</a>
            <a href="calendario.php">Actividades</a>
            <a href="login.php">Iniciar sesión</a>
            <a class="nav-register" href="registro.php">Crear cuenta</a>
        <?php endif; ?>
    </nav>
</header>
<main class="page-shell">
<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div>
<?php endif; ?>
