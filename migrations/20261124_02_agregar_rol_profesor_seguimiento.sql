SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'calendario_participante'
       AND CONSTRAINT_NAME = 'fk_calendario_participante_usuario_rol'
       AND CONSTRAINT_TYPE = 'FOREIGN KEY') > 0,
    'ALTER TABLE calendario_participante DROP FOREIGN KEY fk_calendario_participante_usuario_rol',
    'DO 0'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

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

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'calendario_participante'
       AND CONSTRAINT_NAME = 'fk_calendario_participante_usuario_rol'
       AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0,
    'ALTER TABLE calendario_participante ADD CONSTRAINT fk_calendario_participante_usuario_rol FOREIGN KEY (UserId, Rol) REFERENCES Usuario_Roles(UserId, Rol) ON UPDATE CASCADE ON DELETE CASCADE',
    'DO 0'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

INSERT IGNORE INTO Usuario_Roles (UserId, Rol)
SELECT DISTINCT UserId, 'profesor_seguimiento'
FROM Equipo_Asignacion
WHERE Rol = 'seguimiento';
