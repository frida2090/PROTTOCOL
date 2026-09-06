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
    $query = database()->prepare('SELECT id, nombre, correo, noBoleta, rol, password_hash FROM usuario WHERE correo = ? LIMIT 1');
    $query->execute([$correo]);
    $user = $query->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

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
