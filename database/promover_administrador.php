<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este comando solo se puede ejecutar desde la terminal.\n");
    exit(1);
}

if ($argc !== 2 || !filter_var($argv[1], FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php database/promover_administrador.php correo@institucion.mx\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';

$connection = database();
$query = $connection->prepare('SELECT UserId, Nombre, ApellidoPaterno, Email FROM Usuarios WHERE Email = ?');
$query->execute([$argv[1]]);
$account = $query->fetch();

if (!$account) {
    fwrite(STDERR, "No existe una cuenta con ese correo. Crea la cuenta antes de promoverla.\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Se agregará el rol administrador a %s %s (%s).\nEscribe PROMOVER para confirmar: ",
    $account['Nombre'],
    $account['ApellidoPaterno'],
    $account['Email']
));
$confirmation = trim((string) fgets(STDIN));
if ($confirmation !== 'PROMOVER') {
    fwrite(STDOUT, "Operación cancelada.\n");
    exit(0);
}

try {
    $connection->beginTransaction();
    $connection->prepare("INSERT IGNORE INTO Usuario_Roles (UserId, Rol) VALUES (?, 'admin')")
        ->execute([$account['UserId']]);
    $connection->prepare('INSERT IGNORE INTO Administradores (UserId) VALUES (?)')
        ->execute([$account['UserId']]);
    $connection->commit();
    fwrite(STDOUT, "Rol administrador asignado correctamente. La cuenta conservará su contraseña actual.\n");
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    fwrite(STDERR, "No fue posible promover la cuenta: {$exception->getMessage()}\n");
    exit(1);
}
