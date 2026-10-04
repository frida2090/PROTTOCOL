CREATE TABLE DocentesCatalogo (
    DocenteId INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    NombreCompleto VARCHAR(160) NOT NULL UNIQUE,
    UserId INT UNSIGNED NULL UNIQUE,
    INDEX idx_docentes_catalogo_user (UserId),
    CONSTRAINT fk_docentes_catalogo_user
        FOREIGN KEY (UserId) REFERENCES Usuarios(UserId)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE UnidadAprendizaje (
    UnidadId INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Nombre VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE Docente_UnidadAprendizaje (
    DocenteId INT UNSIGNED NOT NULL,
    UnidadId INT UNSIGNED NOT NULL,
    PRIMARY KEY (DocenteId, UnidadId),
    INDEX idx_docente_unidad_unidad (UnidadId, DocenteId),
    CONSTRAINT fk_docente_unidad_docente
        FOREIGN KEY (DocenteId) REFERENCES DocentesCatalogo(DocenteId)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_docente_unidad_unidad
        FOREIGN KEY (UnidadId) REFERENCES UnidadAprendizaje(UnidadId)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB;