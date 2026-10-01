<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function requireAuth(): void
{
    if (!currentUser()) {
        flash('error', 'Inicia sesión para consultar el calendario.');
        redirect('index.php');
    }
}

function attemptLogin(string $correo, string $password): bool
{
    $query = database()->prepare('SELECT u.UserId AS id, u.Nombre AS nombre, u.ApellidoPaterno AS apellido_paterno, u.ApellidoMaterno AS apellido_materno, u.Email AS correo, e.NumeroBoleta AS noBoleta, em.NumeroEmpleado AS numero_empleado, u.PasswordHash AS password_hash FROM Usuarios u LEFT JOIN Estudiante e ON e.UserId = u.UserId LEFT JOIN Empleado em ON em.UserId = u.UserId WHERE u.Email = ? LIMIT 1');
    $query->execute([$correo]);
    $user = $query->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    $rolesQuery = database()->prepare('SELECT Rol FROM Usuario_Roles WHERE UserId = ? ORDER BY FIELD(Rol, \'admin\', \'miembroCatt\', \'jefeAcademia\', \'director\', \'sinodal\', \'estudiante\')');
    $rolesQuery->execute([$user['id']]);
    $roles = $rolesQuery->fetchAll(PDO::FETCH_COLUMN);
    if (!$roles) {
        return false;
    }

    $user['roles'] = $roles;
    $user['rol'] = match ($roles[0]) {
        'director', 'sinodal' => 'profesor',
        'jefeAcademia' => 'miembroCatt',
        default => $roles[0],
    };
    unset($user['password_hash']);
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    return true;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
