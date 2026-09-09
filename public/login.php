<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';

if (currentUser()) {
    redirect('calendario.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = trim($_POST['correo'] ?? '');
    $password = $_POST['password'] ?? '';
    if (attemptLogin($correo, $password)) {
        redirect(currentUser()['rol'] === 'estudiante' ? 'panelAlumno.php': (currentUser()['rol'] === 'profesor' ? 'panelProfesor.php' : (currentUser()['rol'] === 'miembroCatt' ? 'panelMiembroCatt.php' : 'calendario.php')));
    }
    $error = 'El correo o la contraseña no son correctos.';
}

$pageTitle = 'Iniciar sesión';
require __DIR__ . '/../app/views/header.php';
?>
<section class="auth-layout">
    <div class="auth-intro">
        <p class="eyebrow">Comunidad académica</p>
        <h1>Organiza el semestre con claridad.</h1>
        <p>Consulta las actividades de CATT, encuentra tus próximas fechas y mantén a la comunidad conectada.</p>
        <div class="intro-mark">CATT <span>2026</span></div>
    </div>
    <div class="form-panel">
        <p class="eyebrow">Acceso</p>
        <h2>Bienvenido de vuelta</h2>
        <p class="form-subtitle">Ingresa con tu cuenta institucional.</p>
        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" novalidate>
            <label for="correo">Correo electrónico</label>
            <input id="correo" name="correo" type="email" autocomplete="email" required placeholder="tu@instituto.mx" value="<?= e($_POST['correo'] ?? '') ?>">
            <label for="password">Contraseña</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required placeholder="••••••••">
            <button class="button button-primary" type="submit">Entrar al calendario <span>→</span></button>
        </form>
        <p class="form-note">¿Aún no tienes cuenta? <a href="registro.php">Regístrate aquí</a></p>
    </div>
</section>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
