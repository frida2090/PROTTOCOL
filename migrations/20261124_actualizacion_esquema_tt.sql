CREATE TABLE IF NOT EXISTS Formulario1 (
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

CREATE TABLE IF NOT EXISTS Formulario1_Firma (
    FirmaId INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Formulario1Id INT UNSIGNED NOT NULL,
    UserId INT UNSIGNED NOT NULL,
    Rol ENUM('estudiante', 'director', 'sinodal', 'profesor_seguimiento') NOT NULL,
    RutaArchivo VARCHAR(255) NULL,
    RutaArchivoPng VARCHAR(255) NULL,
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

CREATE TABLE IF NOT EXISTS firmas_documentos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    rol ENUM('estudiante', 'director', 'sinodal', 'profesor_seguimiento') NOT NULL,
    tipo_formulario VARCHAR(50) NOT NULL,
    formulario_id INT UNSIGNED NOT NULL,
    firma_pdf_path VARCHAR(255) NOT NULL,
    firma_png_path VARCHAR(255) NOT NULL,
    fecha_carga DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_firmas_documentos_formulario (tipo_formulario, formulario_id),
    INDEX idx_firmas_documentos_usuario (usuario_id),
    CONSTRAINT fk_firmas_documentos_usuario
        FOREIGN KEY (usuario_id) REFERENCES Usuarios(UserId)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS solicitudes_actualizacion_tt (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tt_id INT UNSIGNED NOT NULL,
    solicitante_user_id INT UNSIGNED NOT NULL,
    fecha_solicitud DATE NOT NULL,
    titulo_actual VARCHAR(100) NOT NULL,
    titulo_nuevo VARCHAR(100) NULL,
    movimientos JSON NOT NULL,
    alumno_no_boleta VARCHAR(10) NULL,
    alumno_plan ENUM('ISC 2009', 'ISC 2020', 'LCD', 'IIA') NULL,
    alumno_nombre VARCHAR(160) NULL,
    director_user_id INT UNSIGNED NULL,
    director_nombre VARCHAR(160) NULL,
    ciclo_aplazamiento VARCHAR(30) NULL,
    ciclo_reactivacion VARCHAR(30) NULL,
    ciclo_baja VARCHAR(30) NULL,
    estado ENUM('pendiente_firmas', 'en_revision_catt', 'aprobado', 'rechazado') NOT NULL DEFAULT 'pendiente_firmas',
    observaciones_catt TEXT NULL,
    creada_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizada_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_solicitudes_actualizacion_tt_estado (tt_id, estado),
    CONSTRAINT fk_solicitudes_actualizacion_tt
        FOREIGN KEY (tt_id) REFERENCES TT(TT_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_solicitudes_actualizacion_solicitante
        FOREIGN KEY (solicitante_user_id) REFERENCES Usuarios(UserId)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_solicitudes_actualizacion_director
        FOREIGN KEY (director_user_id) REFERENCES Usuarios(UserId)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS acuses_reporte_tecnico (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tt_id INT UNSIGNED NOT NULL,
    tipo_tt ENUM('TT-I', 'TT-II', 'TT-R') NOT NULL,
    fecha_emision DATE NOT NULL,
    estado ENUM('pendiente_firmas', 'en_revision_catt', 'aprobado', 'rechazado') NOT NULL DEFAULT 'pendiente_firmas',
    observaciones_catt TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_acuses_reporte_tt_estado (tt_id, estado),
    CONSTRAINT fk_acuses_reporte_tt
        FOREIGN KEY (tt_id) REFERENCES TT(TT_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS acuse_entregas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    acuse_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    rol ENUM('director', 'sinodal', 'profesor_seguimiento') NOT NULL,
    medio_entrega ENUM('formato_impreso', 'correo_electronico', 'disco_usb', 'otro') NULL,
    otro_especificar VARCHAR(255) NULL,
    firma_pdf_path VARCHAR(255) NULL,
    firma_png_path VARCHAR(255) NULL,
    fecha_firma DATETIME NULL,
    UNIQUE KEY uq_acuse_entrega_actor (acuse_id, usuario_id, rol),
    INDEX idx_acuse_entregas_usuario (usuario_id, acuse_id),
    CONSTRAINT fk_acuse_entregas_acuse
        FOREIGN KEY (acuse_id) REFERENCES acuses_reporte_tecnico(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_acuse_entregas_usuario
        FOREIGN KEY (usuario_id) REFERENCES Usuarios(UserId)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS seguimientos_tt (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tt_id INT UNSIGNED NOT NULL UNIQUE,
    profesor_seguimiento_id INT UNSIGNED NOT NULL,
    unidad_aprendizaje VARCHAR(200) NOT NULL,
    grupo VARCHAR(30) NOT NULL,
    ciclo_escolar VARCHAR(30) NOT NULL,
    lugar_reunion VARCHAR(200) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_seguimientos_tt_tt
        FOREIGN KEY (tt_id) REFERENCES TT(TT_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_seguimientos_tt_profesor
        FOREIGN KEY (profesor_seguimiento_id) REFERENCES Usuarios(UserId)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS seguimiento_sesiones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seguimiento_id INT UNSIGNED NOT NULL,
    numero_sesion SMALLINT UNSIGNED NOT NULL,
    fecha_hora DATETIME NOT NULL,
    actividades_programadas TEXT NOT NULL,
    porcentaje_avance DECIMAL(5, 2) NOT NULL,
    observaciones TEXT NULL,
    firma_profesor_pdf_path VARCHAR(255) NULL,
    firma_profesor_png_path VARCHAR(255) NULL,
    asistio_profesor BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE KEY uq_seguimiento_numero_sesion (seguimiento_id, numero_sesion),
    CONSTRAINT chk_seguimiento_porcentaje
        CHECK (porcentaje_avance >= 0 AND porcentaje_avance <= 100),
    CONSTRAINT fk_seguimiento_sesiones_seguimiento
        FOREIGN KEY (seguimiento_id) REFERENCES seguimientos_tt(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS seguimiento_asistencias_alumnos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sesion_id INT UNSIGNED NOT NULL,
    alumno_id INT UNSIGNED NOT NULL,
    asistio BOOLEAN NOT NULL DEFAULT FALSE,
    firma_alumno_pdf_path VARCHAR(255) NULL,
    firma_alumno_png_path VARCHAR(255) NULL,
    fecha_registro DATETIME NULL,
    UNIQUE KEY uq_seguimiento_asistencia_alumno (sesion_id, alumno_id),
    INDEX idx_seguimiento_asistencia_alumno (alumno_id, sesion_id),
    CONSTRAINT fk_seguimiento_asistencia_sesion
        FOREIGN KEY (sesion_id) REFERENCES seguimiento_sesiones(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_seguimiento_asistencia_alumno
        FOREIGN KEY (alumno_id) REFERENCES Usuarios(UserId)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'TT' AND COLUMN_NAME = 'fecha_presentacion') = 0,
    'ALTER TABLE TT ADD COLUMN fecha_presentacion DATE NULL AFTER EquipoId',
    'DO 0'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'TT' AND COLUMN_NAME = 'profesor_seguimiento_id') = 0,
    'ALTER TABLE TT ADD COLUMN profesor_seguimiento_id INT UNSIGNED NULL AFTER EquipoId',
    'DO 0'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'TT'
       AND CONSTRAINT_NAME = 'fk_tt_profesor_seguimiento') = 0,
    'ALTER TABLE TT ADD CONSTRAINT fk_tt_profesor_seguimiento FOREIGN KEY (profesor_seguimiento_id) REFERENCES Usuarios(UserId) ON UPDATE CASCADE ON DELETE SET NULL',
    'DO 0'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

UPDATE TT tt
JOIN Equipo_Asignacion ea ON ea.EquipoId = tt.EquipoId AND ea.Rol = 'seguimiento'
SET tt.profesor_seguimiento_id = ea.UserId
WHERE tt.profesor_seguimiento_id IS NULL;

INSERT IGNORE INTO Usuario_Roles (UserId, Rol)
SELECT DISTINCT UserId, 'profesor_seguimiento'
FROM Equipo_Asignacion
WHERE Rol = 'seguimiento';

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Formulario1_Firma' AND COLUMN_NAME = 'RutaArchivoPng') = 0,
    'ALTER TABLE Formulario1_Firma ADD COLUMN RutaArchivoPng VARCHAR(255) NULL AFTER RutaArchivo',
    'DO 0'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acuse_entregas' AND COLUMN_NAME = 'firma_png_path') = 0,
    'ALTER TABLE acuse_entregas ADD COLUMN firma_png_path VARCHAR(255) NULL AFTER firma_pdf_path',
    'DO 0'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seguimiento_sesiones' AND COLUMN_NAME = 'firma_profesor_png_path') = 0,
    'ALTER TABLE seguimiento_sesiones ADD COLUMN firma_profesor_png_path VARCHAR(255) NULL AFTER firma_profesor_pdf_path',
    'DO 0'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seguimiento_asistencias_alumnos' AND COLUMN_NAME = 'firma_alumno_png_path') = 0,
    'ALTER TABLE seguimiento_asistencias_alumnos ADD COLUMN firma_alumno_png_path VARCHAR(255) NULL AFTER firma_alumno_pdf_path',
    'DO 0'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
