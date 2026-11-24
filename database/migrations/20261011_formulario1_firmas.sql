USE catt_prottocol;
SET NAMES utf8mb4;

ALTER TABLE TT
    ADD COLUMN TipoTT ENUM('TT1', 'TT2') NULL AFTER NumeroTT;

CREATE TABLE Formulario1 (
    Formulario1Id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    TT_id INT UNSIGNED NOT NULL,
    CreadoPorUserId INT UNSIGNED NOT NULL,
    TipoTT ENUM('TT1', 'TT2') NOT NULL,
    Fecha DATE NOT NULL,
    NumeroTT VARCHAR(20) NOT NULL,
    TituloActual VARCHAR(100) NOT NULL,
    Movimiento ENUM('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J') NOT NULL,
    NuevoTitulo VARCHAR(100) NULL,
    AlumnoNoBoleta VARCHAR(10) NULL,
    AlumnoPlan ENUM('ISC 2009', 'ISC 2020', 'LCD', 'IIA') NULL,
    AlumnoNombre VARCHAR(160) NULL,
    DirectorNombre VARCHAR(160) NULL,
    CicloEscolar VARCHAR(30) NULL,
    Estado ENUM('pendiente_firmas', 'en_revision_catt', 'aprobado', 'rechazado') NOT NULL DEFAULT 'pendiente_firmas',
    Observaciones TEXT NULL,
    CreadoEn TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ActualizadoEn TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_formulario1_tt_estado (TT_id, Estado),
    CONSTRAINT fk_formulario1_tt
        FOREIGN KEY (TT_id) REFERENCES TT(TT_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_formulario1_creador
        FOREIGN KEY (CreadoPorUserId) REFERENCES Usuarios(UserId)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE Formulario1_Firma (
    FirmaId INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Formulario1Id INT UNSIGNED NOT NULL,
    UserId INT UNSIGNED NOT NULL,
    Rol ENUM('estudiante', 'director', 'sinodal', 'profesor_seguimiento') NOT NULL,
    RutaArchivo VARCHAR(255) NULL,
    FechaCarga DATETIME NULL,
    UNIQUE KEY uq_formulario1_firma_actor (Formulario1Id, UserId, Rol),
    INDEX idx_formulario1_firma_usuario (UserId, Formulario1Id),
    CONSTRAINT fk_formulario1_firma_formulario
        FOREIGN KEY (Formulario1Id) REFERENCES Formulario1(Formulario1Id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_formulario1_firma_usuario
        FOREIGN KEY (UserId) REFERENCES Usuarios(UserId)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB;
