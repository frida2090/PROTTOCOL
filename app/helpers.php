<?php
declare(strict_types=1);

function fullName(array $user): string
{
    return implode(' ', array_filter([
        $user['nombre'] ?? '',
        $user['apellido_paterno'] ?? '',
        $user['apellido_materno'] ?? '',
    ]));
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header("Location: {$path}");
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function consumeFlash(): ?array
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $message;
}
