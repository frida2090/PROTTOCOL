<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/views/dashboard/sidebar.php';

$user = currentUser();
if(!$user){
    redirect('login.php');
}

if($user['rol'] !== 'admin') {
    flash('error', 'Acceso denegado. Solo los administradores pueden acceder a este panel.');
    redirect('login.php');
}

// Alta de un nuevo usuario por el administrador
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['formulario'] ?? '') === 'alta_usuario') {
    $nombre          = trim($_POST['nombre'] ?? '');
    $apellidoPaterno = trim($_POST['apellido_paterno'] ?? '');
    $apellidoMaterno = trim($_POST['apellido_materno'] ?? '');
    $correo          = trim($_POST['correo'] ?? '');
    $noBoleta        = trim($_POST['noBoleta'] ?? '');
    $numeroEmpleado  = trim($_POST['numero_empleado'] ?? '');
    $departamentoId  = (int) ($_POST['departamento_id'] ?? 0);
    $rolesRaw        = $_POST['roles'] ?? [];
    $password        = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $rolesPermitidos = ['estudiante', 'director', 'miembroCatt', 'jefeAcademia', 'sinodal', 'admin'];
    $roles = is_array($rolesRaw)
        ? array_values(array_unique(array_map('strval', $rolesRaw)))
        : [];
    $rolesInvalidos = array_diff($roles, $rolesPermitidos);
    $esEstudiante = in_array('estudiante', $roles, true);
    $esEmpleado = (bool) array_intersect($roles, ['director', 'miembroCatt', 'jefeAcademia', 'sinodal', 'admin']);

    if ($nombre === '' || $apellidoPaterno === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Completa todos los datos con un formato válido.');
    } elseif (!$roles || $rolesInvalidos) {
        flash('error', 'Selecciona al menos un rol válido.');
    } elseif (($esEstudiante && ($noBoleta === '' || strlen($noBoleta) > 10)) || ($esEmpleado && ($numeroEmpleado === '' || $departamentoId < 1))) {
        flash('error', 'Completa el identificador y los datos académicos requeridos para el rol seleccionado.');
    } elseif (strlen($password) < 8) {
        flash('error', 'La contraseña debe tener al menos 8 caracteres.');
    } elseif ($password !== $confirmPassword) {
        flash('error', 'Las contraseñas no coinciden.');
    } else {
        try {
            $connection = database();
            $connection->beginTransaction();
            $query = $connection->prepare('INSERT INTO Usuarios (Nombre, ApellidoPaterno, ApellidoMaterno, Email, PasswordHash) VALUES (?, ?, ?, ?, ?)');
            $query->execute([$nombre, $apellidoPaterno, $apellidoMaterno, $correo, password_hash($password, PASSWORD_DEFAULT)]);
            $newUserId = (int) $connection->lastInsertId();
            $roleQuery = $connection->prepare('INSERT INTO Usuario_Roles (UserId, Rol) VALUES (?, ?)');
            foreach ($roles as $role) {
                $roleQuery->execute([$newUserId, $role]);
            }
            if ($esEstudiante) {
                $studentQuery = $connection->prepare('INSERT INTO Estudiante (UserId, NumeroBoleta, Creditos) VALUES (?, ?, 0)');
                $studentQuery->execute([$newUserId, $noBoleta]);
            }
            if ($esEmpleado) {
                $employeeQuery = $connection->prepare('INSERT INTO Empleado (UserId, NumeroEmpleado, DeptId) VALUES (?, ?, ?)');
                $employeeQuery->execute([$newUserId, $numeroEmpleado, $departamentoId]);
            }
            $connection->commit();
            flash('success', 'El usuario fue creado correctamente.');
        } catch (PDOException $exception) {
            if (isset($connection) && $connection->inTransaction()) {
                $connection->rollBack();
            }
            flash('error', $exception->getCode() === '23000'
                ? 'El correo o identificador ya está registrado, o el departamento no existe.'
                : 'No fue posible crear el usuario. Revisa la conexión con la base de datos.');
        }
    }
    redirect('panelAdmin.php?modal=usuarios');
}

// Baja (eliminación definitiva) de un usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['formulario'] ?? '') === 'baja_usuario') {
    $usuarioId = (int) ($_POST['usuario_id'] ?? 0);

    if ($usuarioId <= 0) {
        flash('error', 'Selecciona un usuario válido.');
    } elseif ($usuarioId === (int) $user['id']) {
        flash('error', 'No puedes darte de baja a ti mismo.');
    } else {
        try {
            $query = database()->prepare('DELETE FROM Usuarios WHERE UserId = ?');
            $query->execute([$usuarioId]);
            flash($query->rowCount() > 0 ? 'success' : 'error',
                $query->rowCount() > 0
                    ? 'El usuario fue eliminado correctamente.'
                    : 'El usuario que intentas eliminar no existe.');
        } catch (PDOException $exception) {
            flash('error', $exception->getCode() === '23000'
                ? 'No se puede eliminar: el usuario tiene actividades registradas en el calendario.'
                : 'No fue posible eliminar el usuario. Intenta de nuevo.');
        }
    }
    redirect('panelAdmin.php?modal=usuarios');
}

$usuarios = [];
$departamentos = [];
try {
    $query = database()->query("SELECT u.UserId AS id, u.Nombre AS nombre, u.Email AS correo, e.NumeroBoleta AS noBoleta, em.NumeroEmpleado AS numero_empleado, GROUP_CONCAT(ur.Rol ORDER BY ur.Rol SEPARATOR ', ') AS roles FROM Usuarios u LEFT JOIN Estudiante e ON e.UserId = u.UserId LEFT JOIN Empleado em ON em.UserId = u.UserId LEFT JOIN Usuario_Roles ur ON ur.UserId = u.UserId GROUP BY u.UserId, u.Nombre, u.Email, e.NumeroBoleta, em.NumeroEmpleado ORDER BY u.UserId");
    $usuarios = $query->fetchAll();
    $departamentos = database()->query('SELECT DeptId, Nombre FROM Academia_Departamento ORDER BY Nombre')->fetchAll();
} catch (PDOException $exception) {
    flash('error', 'No fue posible consultar usuarios o departamentos.');
}

$rolLabels = [
    'estudiante' => 'Estudiante',
    'director' => 'Director',
    'miembroCatt' => 'Miembro CATT',
    'jefeAcademia' => 'Jefe de academia',
    'sinodal' => 'Sinodal',
    'admin' => 'Administrador',
];

$conteoRoles = ['estudiante' => 0, 'director' => 0, 'sinodal' => 0, 'miembroCatt' => 0, 'jefeAcademia' => 0, 'admin' => 0];
foreach ($usuarios as $index => $usuario) {
    $userRoles = array_filter(array_map('trim', explode(',', $usuario['roles'] ?? '')));
    foreach ($userRoles as $userRole) {
        $conteoRoles[$userRole] = ($conteoRoles[$userRole] ?? 0) + 1;
    }
    $usuarios[$index]['roles_label'] = implode(', ', array_map(static fn (string $role): string => $rolLabels[$role] ?? $role, $userRoles));
}
$rolesFormulario = is_array($_POST['roles'] ?? null)
    ? array_map('strval', $_POST['roles'])
    : ['estudiante'];

$pageTitle = 'Panel del Administrador';
require __DIR__ . '/../app/views/header.php';
$modal = $_GET['modal'] ?? '';
$modalPermitido = in_array($modal, ['perfil', 'usuarios', 'nuevo_usuario'], true);
?>
<div class="dashboard-container">
    <?php renderSidebar('inicio'); ?>

    <main class="dashboard-main">
        <header class="dashboard-topbar">
            <div>
                <p class="eyebrow">Comisión Académica de Trabajos Terminales</p>
                <h1>Panel del Administrador</h1>
            </div>
            <a class="button button-primary" href="calendario.php">Ver calendario</a>
        </header>

        <section class="dashboard-grid">
            <article class="dashboard-card highlight">
                <p class="dashboard-kicker">Comunidad</p>
                <h2>Total de usuarios</h2>
                <p class="card-copy">La comunidad registrada en la plataforma CATT.</p>
                <div class="stat-row">
                    <strong><?= count($usuarios) ?></strong>
                    <span>usuarios</span>
                </div>
                <a class="card-list-link" href="panelAdmin.php?modal=usuarios">Ver usuarios</a>
            </article>

            <article class="dashboard-card">
                <p class="dashboard-kicker">Académicos</p>
                <h2>Docentes y CATT</h2>
                <p class="card-copy">Profesores, miembros de la comisión y administradores.</p>
                <div class="stat-row">
                    <strong><?= $conteoRoles['director'] + $conteoRoles['sinodal'] + $conteoRoles['miembroCatt'] + $conteoRoles['jefeAcademia'] + $conteoRoles['admin'] ?></strong>
                    <span>cuentas</span>
                </div>
            </article>

            <article class="dashboard-card">
                <p class="dashboard-kicker">Estudiantes</p>
                <h2>Estudiantes</h2>
                <p class="card-copy">Alumnos registrados en la plataforma.</p>
                <div class="stat-row">
                    <strong><?= $conteoRoles['estudiante'] ?></strong>
                    <span>cuentas</span>
                </div>
            </article>
        </section>

        <section class="dashboard-panel">
            <div class="panel-header">
                <h2>Próximas Fechas de la CATT</h2>
                <a href="calendario.php">Ver todas</a>
            </div>

            <div class="activity-summary">
                <div class="summary-item">
                    <span class="summary-date">18</span>
                    <div>
                        <strong>Revisión de protocolos</strong>
                        <small>Del 21 al 25 de septiembre</small>
                    </div>
                </div>
                <div class="summary-item">
                    <span class="summary-date">03</span>
                    <div>
                        <strong>Sesión de evaluación de la comisión</strong>
                        <small>19 al 23 de octubre</small>
                    </div>
                </div>
                <div class="summary-item">
                    <span class="summary-date">14</span>
                    <div>
                        <strong>Publicación de dictámenes</strong>
                        <small>14 de septiembre</small>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>
<div class="dashboard-modal <?= $modalPermitido ? 'is-open' : '' ?>" id="modal-<?= e($modalPermitido ? $modal : 'none') ?>" role="dialog" aria-modal="true" aria-hidden="<?= $modalPermitido ? 'false' : 'true' ?>">
    <div class="dashboard-modal-backdrop" data-close-modal></div>
    <?php if ($modal === 'perfil'): ?>
        <section class="dashboard-modal-content" aria-labelledby="modal-perfil-title">
            <button class="modal-close" type="button" data-close-modal aria-label="Cerrar">×</button>
            <p class="eyebrow">Mi cuenta</p><h2 id="modal-perfil-title">Datos personales</h2><p class="modal-intro">Información asociada a tu cuenta de administrador.</p>
            <dl class="profile-details">
                <div><dt>Nombre completo</dt><dd><?= e($user['nombre']) ?></dd></div>
                <div><dt>Correo electrónico</dt><dd><?= e($user['correo']) ?></dd></div>
                <div><dt>Número de boleta</dt><dd><?= e($user['noBoleta']) ?></dd></div>
                <div><dt>Rol</dt><dd>Administrador</dd></div>
            </dl>
        </section>
    <?php elseif ($modal === 'usuarios'): ?>
        <section class="dashboard-modal-content" aria-labelledby="modal-usuarios-title">
            <button class="modal-close" type="button" data-close-modal aria-label="Cerrar">×</button>
            <p class="eyebrow">Administración</p>
            <h2 id="modal-usuarios-title">Usuarios</h2>
            <p class="modal-intro">Lista de la comunidad registrada. Puedes eliminar o agregar usuarios.</p>

            <a class="button button-gold admin-add-top" href="panelAdmin.php?modal=nuevo_usuario">+ Agregar usuario</a>

            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr><th>Nombre</th><th>Correo</th><th>Identificador</th><th>Roles</th><th>Acción</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($usuarios as $usuario): ?>
                        <tr>
                            <td><?= e($usuario['nombre']) ?></td>
                            <td><?= e($usuario['correo']) ?></td>
                            <td><?= e($usuario['noBoleta'] ?: $usuario['numero_empleado']) ?></td>
                            <td><?= e($usuario['roles_label']) ?></td>
                            <td>
                                <?php if ((int) $usuario['id'] !== (int) $user['id']): ?>
                                    <form method="post" class="admin-inline-form" onsubmit="return confirm('¿Eliminar a <?= e($usuario['nombre']) ?>? Esta acción no se puede deshacer.');">
                                        <input type="hidden" name="formulario" value="baja_usuario">
                                        <input type="hidden" name="usuario_id" value="<?= (int) $usuario['id'] ?>">
                                        <button class="admin-delete" type="submit">Eliminar usuario</button>
                                    </form>
                                <?php else: ?>
                                    <em class="admin-self">Tú</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php elseif ($modal === 'nuevo_usuario'): ?>
        <section class="dashboard-modal-content" aria-labelledby="modal-nuevo-usuario-title">
            <button class="modal-close" type="button" data-close-modal aria-label="Cerrar">×</button>
            <p class="eyebrow">Nueva cuenta</p><h2 id="modal-nuevo-usuario-title">Agregar usuario</h2><p class="modal-intro">Crea la cuenta de un nuevo usuario de la comunidad.</p>
            <form method="post" class="modal-form">
                <input type="hidden" name="formulario" value="alta_usuario">

                <label for="nombre">Nombre(s)</label>
                <input id="nombre" name="nombre" required value="<?= e($_POST['nombre'] ?? '') ?>">

                <label for="apellido_paterno">Apellido paterno</label>
                <input id="apellido_paterno" name="apellido_paterno" required value="<?= e($_POST['apellido_paterno'] ?? '') ?>">

                <label for="apellido_materno">Apellido materno</label>
                <input id="apellido_materno" name="apellido_materno" required value="<?= e($_POST['apellido_materno'] ?? '') ?>">

                <label for="correo">Correo electrónico</label>
                <input id="correo" name="correo" type="email" required value="<?= e($_POST['correo'] ?? '') ?>">

                <label for="noBoleta">Número de boleta (estudiante)</label>
                <input id="noBoleta" name="noBoleta" value="<?= e($_POST['noBoleta'] ?? '') ?>">

                <label for="numero_empleado">Número de empleado (personal)</label>
                <input id="numero_empleado" name="numero_empleado" value="<?= e($_POST['numero_empleado'] ?? '') ?>">

                <label for="departamento_id">Departamento (personal)</label>
                <select id="departamento_id" name="departamento_id"><option value="">Selecciona departamento</option><?php foreach ($departamentos as $departamento): ?><option value="<?= e((string) $departamento['DeptId']) ?>"<?= (int) ($_POST['departamento_id'] ?? 0) === (int) $departamento['DeptId'] ? ' selected' : '' ?>><?= e($departamento['Nombre']) ?></option><?php endforeach; ?></select>

                <fieldset class="admin-role-options">
                    <legend>Roles</legend>
                    <?php foreach ($rolLabels as $roleValue => $roleLabel): ?>
                        <label><input type="checkbox" name="roles[]" value="<?= e($roleValue) ?>"<?= in_array($roleValue, $rolesFormulario, true) ? ' checked' : '' ?>> <?= e($roleLabel) ?></label>
                    <?php endforeach; ?>
                </fieldset>

                <label for="password">Contraseña</label>
                <input id="password" name="password" type="password" minlength="8" required>

                <label for="confirm_password">Confirmar contraseña</label>
                <input id="confirm_password" name="confirm_password" type="password" minlength="8" required>

                <button class="button button-primary" type="submit">Agregar usuario <span>→</span></button>
            </form>
        </section>
    <?php endif; ?>
</div>
<style>
.admin-table-wrap { max-height: 260px; overflow-y: auto; border: 1px solid var(--line); margin: 18px 0 6px; }
.admin-table { border-collapse: collapse; font-size: .82rem; width: 100%; }
.admin-table th, .admin-table td { border-bottom: 1px solid var(--line); padding: 8px 10px; text-align: left; vertical-align: middle; }
.admin-table th { background: #f9f7f5; color: var(--muted); font-size: .68rem; letter-spacing: .08em; position: sticky; text-transform: uppercase; top: 0; }
.admin-table td:first-child { font-weight: 600; }
.admin-table td:nth-child(2) { color: var(--muted); }
.admin-inline-form { display: inline-block; margin: 0; }
.admin-delete { background: transparent; border: 1px solid #c0392b; border-radius: 999px; color: #c0392b; cursor: pointer; font: inherit; font-size: .72rem; font-weight: 700; padding: 4px 12px; }
.admin-delete:hover { background: #c0392b; color: #fff; }
.admin-self { color: var(--muted); font-size: .72rem; font-style: normal; }
.admin-add-top { font-size: .82rem; margin: 0; padding: 8px 14px; position: absolute; right: 52px; top: 18px; }
</style>
<script>
document.querySelectorAll('[data-close-modal]').forEach((element) => {
    element.addEventListener('click', () => { window.location.href = 'panelAdmin.php'; });
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && document.querySelector('.dashboard-modal.is-open')) { window.location.href = 'panelAdmin.php'; }
});
</script>
<?php require __DIR__ . '/../app/views/footer.php'; ?>