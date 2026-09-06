CREATE DATABASE IF NOT EXISTS catt_prottocol
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE catt_prottocol;

CREATE TABLE usuario (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    correo VARCHAR(160) NOT NULL UNIQUE,
    noBoleta VARCHAR(30) NOT NULL UNIQUE,
    rol ENUM('estudiante', 'profesor', 'miembroCatt') NOT NULL DEFAULT 'estudiante',
    password_hash VARCHAR(255) NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE calendario (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fecha DATE NOT NULL,
    actividad VARCHAR(180) NOT NULL,
    descripcion TEXT NULL,
    profesor_id INT UNSIGNED NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_calendario_profesor
        FOREIGN KEY (profesor_id) REFERENCES usuario(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE INDEX idx_calendario_fecha ON calendario(fecha);
CREATE INDEX idx_usuario_rol ON usuario(rol);

-- Usuario profesor de demostración. Cambie la contraseña después de instalar.
INSERT INTO usuario (nombre, correo, noBoleta, rol, password_hash)
VALUES ('Profesor CATT', 'profesor@catt.local', 'PROF-001', 'profesor',
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llCq.D7k2lP8bKx9kqZy');

INSERT INTO calendario (fecha, actividad, descripcion, profesor_id)
SELECT '2026-09-15', 'Reunión de bienvenida CATT', 'Presentación de actividades del semestre.', id
FROM usuario WHERE correo = 'profesor@catt.local';

INSERT INTO calendario (fecha, actividad, descripcion, profesor_id)
SELECT '2026-10-02', 'Taller de ingeniería de software', 'Taller abierto para estudiantes y miembros CATT.', id
FROM usuario WHERE correo = 'profesor@catt.local';
