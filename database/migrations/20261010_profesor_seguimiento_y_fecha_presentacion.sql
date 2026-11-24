USE catt_prottocol;
SET NAMES utf8mb4;

ALTER TABLE calendario_participante
    DROP FOREIGN KEY fk_calendario_participante_usuario_rol;

ALTER TABLE Usuario_Roles
    MODIFY COLUMN Rol ENUM(
        'estudiante',
        'director',
        'miembroCatt',
        'jefeAcademia',
        'admin',
        'sinodal',
        'profesor_seguimiento'
    ) NOT NULL;

ALTER TABLE calendario_participante
    MODIFY COLUMN Rol ENUM(
        'estudiante',
        'director',
        'miembroCatt',
        'jefeAcademia',
        'admin',
        'sinodal',
        'profesor_seguimiento'
    ) NOT NULL;

ALTER TABLE calendario_participante
    ADD CONSTRAINT fk_calendario_participante_usuario_rol
        FOREIGN KEY (UserId, Rol) REFERENCES Usuario_Roles(UserId, Rol)
        ON UPDATE CASCADE
        ON DELETE CASCADE;

INSERT IGNORE INTO Usuario_Roles (UserId, Rol)
SELECT DISTINCT UserId, 'profesor_seguimiento'
FROM Equipo_Asignacion
WHERE Rol = 'seguimiento';

ALTER TABLE TT
    ADD COLUMN fecha_presentacion DATE NULL AFTER EquipoId;
