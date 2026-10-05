INSERT INTO Usuarios (Nombre, ApellidoPaterno, ApellidoMaterno, Email, PasswordHash)
SELECT
    'Administrador',
    'PROTTOCOL',
    '',
    'admin@prottocol.com.mx',
    '$2y$10$RbWbWNuNjSjYw578WlVjxuDxc0g.NNfmzEmTzM1Fuk9wU2quCaA2y'
WHERE NOT EXISTS (
    SELECT 1 FROM Usuarios WHERE Email = 'admin@prottocol.com.mx'
);

SELECT UserId INTO @admin_user_id
FROM Usuarios
WHERE Email = 'admin@prottocol.com.mx';

INSERT IGNORE INTO Usuario_Roles (UserId, Rol)
VALUES (@admin_user_id, 'admin');

INSERT IGNORE INTO Administradores (UserId)
VALUES (@admin_user_id);
