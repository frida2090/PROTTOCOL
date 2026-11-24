USE catt_prottocol;
SET NAMES utf8mb4;

CREATE TABLE seguimientos_tt (
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

CREATE TABLE seguimiento_sesiones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seguimiento_id INT UNSIGNED NOT NULL,
    numero_sesion SMALLINT UNSIGNED NOT NULL,
    fecha_hora DATETIME NOT NULL,
    actividades_programadas TEXT NOT NULL,
    porcentaje_avance DECIMAL(5, 2) NOT NULL,
    observaciones TEXT NULL,
    firma_profesor_pdf_path VARCHAR(255) NULL,
    asistio_profesor BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE KEY uq_seguimiento_numero_sesion (seguimiento_id, numero_sesion),
    CONSTRAINT chk_seguimiento_porcentaje
        CHECK (porcentaje_avance >= 0 AND porcentaje_avance <= 100),
    CONSTRAINT fk_seguimiento_sesiones_seguimiento
        FOREIGN KEY (seguimiento_id) REFERENCES seguimientos_tt(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE seguimiento_asistencias_alumnos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sesion_id INT UNSIGNED NOT NULL,
    alumno_id INT UNSIGNED NOT NULL,
    asistio BOOLEAN NOT NULL DEFAULT FALSE,
    firma_alumno_pdf_path VARCHAR(255) NULL,
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
