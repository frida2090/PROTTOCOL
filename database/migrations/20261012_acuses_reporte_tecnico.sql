USE catt_prottocol;
SET NAMES utf8mb4;

CREATE TABLE acuses_reporte_tecnico (
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

CREATE TABLE acuse_entregas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    acuse_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    rol ENUM('director', 'sinodal', 'profesor_seguimiento') NOT NULL,
    medio_entrega ENUM('formato_impreso', 'correo_electronico', 'disco_usb', 'otro') NULL,
    otro_especificar VARCHAR(255) NULL,
    firma_pdf_path VARCHAR(255) NULL,
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
