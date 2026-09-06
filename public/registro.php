<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';

if (currentUser()) {
    redirect('calendario.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $correo = trim($_POST['correo'] ?? '');
    $noBoleta = trim($_POST['noBoleta'] ?? '');
    $rol = $_POST['rol'] ?? 'estudiante';
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($nombre === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL) || $noBoleta === '') {
        $error = 'Completa todos los datos con un formato válido.';
    } elseif (!in_array($rol, ['estudiante', 'profesor', 'miembroCatt'], true)) {
        $error = 'Selecciona un rol válido.';
    } elseif (strlen($password) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        try {
            $query = database()->prepare('INSERT INTO usuario (nombre, correo, noBoleta, rol, password_hash) VALUES (?, ?, ?, ?, ?)');
            $query->execute([$nombre, $correo, $noBoleta, $rol, password_hash($password, PASSWORD_DEFAULT)]);
            flash('success', 'Tu cuenta fue creada. Ya puedes iniciar sesión.');
            redirect('index.php');
        } catch (PDOException $exception) {
            $error = $exception->getCode() === '23000'
                ? 'El correo o número de boleta ya está registrado.'
                : 'No fue posible crear la cuenta. Revisa la conexión con la base de datos.';
        }
    }
}

$pageTitle = 'Registro';
require __DIR__ . '/../app/views/header.php';
?>
<section class="form-page">
    <div class="form-heading">
        <p class="eyebrow">Nueva cuenta</p>
        <h1>Únete a la comunidad CATT</h1>
        <p>Registra tus datos para consultar la agenda académica.</p>
    </div>
    <div class="form-panel wide-panel">
        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="form-grid" novalidate>
            <div class="field field-full"><label for="nombre">Nombre completo</label><input id="nombre" name="nombre" required value="<?= e($_POST['nombre'] ?? '') ?>"></div>
            <div class="field"><label for="correo">Correo electrónico</label><input id="correo" name="correo" type="email" required value="<?= e($_POST['correo'] ?? '') ?>"></div>
            <div class="field"><label for="noBoleta">Número de boleta</label><input id="noBoleta" name="noBoleta" required value="<?= e($_POST['noBoleta'] ?? '') ?>"></div>
            <div class="field"><label for="rol">Rol</label><select id="rol" name="rol"><option value="estudiante">Estudiante</option><option value="profesor">Profesor</option><option value="miembroCatt">Miembro CATT</option></select></div>
            <div class="field"><label for="password">Contraseña</label><input id="password" name="password" type="password" minlength="8" required></div>
            <div class="field"><label for="confirm_password">Confirmar contraseña</label><input id="confirm_password" name="confirm_password" type="password" minlength="8" required></div>
            <div class="field-full"><button class="button button-primary" type="submit">Crear mi cuenta <span>→</span></button></div>
        </form>
        <p class="form-note">¿Ya tienes una cuenta? <a href="index.php">Inicia sesión</a></p>
    </div>
</section>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
