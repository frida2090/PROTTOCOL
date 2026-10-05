USE catt_prottocol;
SET NAMES utf8mb4;

-- Representante del equipo: primer integrante en orden alfabético (lo calcula la aplicación)
ALTER TABLE Equipo_TT
    ADD COLUMN RepresentanteUserId INT UNSIGNED NULL UNIQUE AFTER Descripcion,
    ADD CONSTRAINT fk_equipo_representante
        FOREIGN KEY (RepresentanteUserId) REFERENCES Estudiante(UserId)
        ON UPDATE CASCADE
        ON DELETE SET NULL;

-- Directores, sinodales y profesor de seguimiento de cada equipo (un rol por docente y equipo)
CREATE TABLE Equipo_Asignacion (
    EquipoId INT UNSIGNED NOT NULL,
    UserId INT UNSIGNED NOT NULL,
    Rol ENUM('director', 'sinodal', 'seguimiento') NOT NULL,
    AsignadoPorUserId INT UNSIGNED NULL,
    FechaAsignacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (EquipoId, UserId),
    INDEX idx_equipo_asignacion_usuario (UserId, EquipoId),
    CONSTRAINT fk_equipo_asignacion_equipo
        FOREIGN KEY (EquipoId) REFERENCES Equipo_TT(EquipoId)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_equipo_asignacion_usuario
        FOREIGN KEY (UserId) REFERENCES Usuarios(UserId)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_equipo_asignacion_asignador
        FOREIGN KEY (AsignadoPorUserId) REFERENCES Usuarios(UserId)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB;
