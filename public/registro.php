<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';

if (currentUser()) {
    redirect('calendario.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $apellidoPaterno = trim($_POST['apellido_paterno'] ?? '');
    $apellidoMaterno = trim($_POST['apellido_materno'] ?? '');
    $correo = trim($_POST['correo'] ?? '');
    $noBoleta = trim($_POST['noBoleta'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($nombre === '' || $apellidoPaterno === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $error = 'Completa todos los datos con un formato válido.';
    } elseif ($noBoleta === '' || strlen($noBoleta) > 10) {
        $error = 'El número de boleta es obligatorio y debe tener 10 caracteres o menos.';
    } elseif (strlen($password) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        try {
            $connection = database();
            $connection->beginTransaction();
            $query = $connection->prepare('INSERT INTO Usuarios (Nombre, ApellidoPaterno, ApellidoMaterno, Email, PasswordHash) VALUES (?, ?, ?, ?, ?)');
            $query->execute([$nombre, $apellidoPaterno, $apellidoMaterno, $correo, password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int) $connection->lastInsertId();
            $roleQuery = $connection->prepare('INSERT INTO Usuario_Roles (UserId, Rol) VALUES (?, \'estudiante\')');
            $roleQuery->execute([$userId]);
            $studentQuery = $connection->prepare('INSERT INTO Estudiante (UserId, NumeroBoleta, Creditos) VALUES (?, ?, 0)');
            $studentQuery->execute([$userId, $noBoleta]);
            $connection->commit();
            flash('success', 'Tu cuenta fue creada. Ya puedes iniciar sesión.');
            redirect('index.php');
        } catch (PDOException $exception) {
            if (isset($connection) && $connection->inTransaction()) {
                $connection->rollBack();
            }
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
            <div class="field"><label for="nombre">Nombre(s)</label><input id="nombre" name="nombre" required value="<?= e($_POST['nombre'] ?? '') ?>"></div>
            <div class="field"><label for="apellido_paterno">Apellido paterno</label><input id="apellido_paterno" name="apellido_paterno" required value="<?= e($_POST['apellido_paterno'] ?? '') ?>"></div>
            <div class="field"><label for="apellido_materno">Apellido materno</label><input id="apellido_materno" name="apellido_materno" required value="<?= e($_POST['apellido_materno'] ?? '') ?>"></div>
            <div class="field"><label for="correo">Correo electrónico</label><input id="correo" name="correo" type="email" required value="<?= e($_POST['correo'] ?? '') ?>"></div>
            <div class="field"><label for="noBoleta">Número de boleta</label><input id="noBoleta" name="noBoleta" value="<?= e($_POST['noBoleta'] ?? '') ?>"></div>
            <div class="field"><label for="password">Contraseña</label><input id="password" name="password" type="password" minlength="8" required></div>
            <div class="field"><label for="confirm_password">Confirmar contraseña</label><input id="confirm_password" name="confirm_password" type="password" minlength="8" required></div>
            <div class="field-full"><button class="button button-primary" type="submit">Crear mi cuenta <span>→</span></button></div>
        </form>
        <p class="form-note">El registro crea cuentas de estudiante. Los demás roles son asignados por la administración.</p>
        <p class="form-note">¿Ya tienes una cuenta? <a href="index.php">Inicia sesión</a></p>
    </div>
</section>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
