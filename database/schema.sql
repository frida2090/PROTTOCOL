CREATE DATABASE IF NOT EXISTS catt_prottocol
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE catt_prottocol;
SET NAMES utf8mb4;

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
    fecha_fin DATE NULL,
    actividad VARCHAR(180) NOT NULL,
    descripcion TEXT NULL,
    profesor_id INT UNSIGNED NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_calendario_profesor
        FOREIGN KEY (profesor_id) REFERENCES usuario(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT chk_calendario_rango
        CHECK (fecha_fin IS NULL OR fecha_fin >= fecha)
) ENGINE=InnoDB;

CREATE INDEX idx_calendario_fecha ON calendario(fecha);
CREATE INDEX idx_usuario_rol ON usuario(rol);

-- Usuario profesor de demostración. Cambie la contraseña después de instalar.
INSERT INTO usuario (nombre, correo, noBoleta, rol, password_hash)
VALUES ('Profesor CATT', 'profesor@catt.local', 'PROF-001', 'profesor',
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llCq.D7k2lP8bKx9kqZy');

INSERT INTO calendario (fecha, fecha_fin, actividad, descripcion, profesor_id)
SELECT datos.fecha, datos.fecha_fin, datos.actividad, datos.descripcion, u.id
FROM (
    SELECT '2026-07-15' fecha, '2026-09-04' fecha_fin, 'Modificaciones al protocolo' actividad, '15 de julio al 4 de septiembre de 2026' descripcion
    UNION ALL SELECT '2026-08-25', NULL, 'Plática informativa (TT-I, TT-II y TTR 2027/1)', NULL
    UNION ALL SELECT '2026-09-14', NULL, 'Publicación de grupos', NULL
    UNION ALL SELECT '2026-10-22', NULL, 'Publicación calendario de presentaciones', 'Reprogramación por formato'
    UNION ALL SELECT '2026-10-29', '2026-11-13', 'Presentación ordinaria del trabajo terminal (TT-I)', '29 de octubre al 13 de noviembre de 2026'
    UNION ALL SELECT '2026-11-17', '2026-12-01', 'Presentación ordinaria del trabajo terminal (TT-II)', '17 de noviembre al 1 de diciembre de 2026'
    UNION ALL SELECT '2026-12-04', '2026-12-08', 'Presentación extraordinaria del trabajo terminal I y II', '4, 7 y 8 de diciembre de 2026'
  UNION ALL SELECT '2026-12-11', NULL, 'Entrega de calificaciones finales ordinarias CATT', NULL
  UNION ALL SELECT '2026-12-11', '2026-12-15', 'Captura de calificaciones finales ordinarias docentes titulares', '11, 14 y 15 de diciembre de 2026'
  UNION ALL SELECT '2026-10-28', NULL, 'Entrega de calificaciones ordinarias de seguimiento TT-I', NULL
  UNION ALL SELECT '2026-11-13', NULL, 'Entrega de calificaciones ordinarias de seguimiento TT-II', NULL
  UNION ALL SELECT '2026-11-13', NULL, 'Entrega de calificaciones extraordinarias de seguimiento TT-I', NULL
  UNION ALL SELECT '2026-12-01', NULL, 'Entrega de calificaciones extraordinarias de seguimiento TT-II', NULL
  UNION ALL SELECT '2026-12-16', NULL, 'Entrega de calificaciones finales extraordinarias CATT', NULL
  UNION ALL SELECT '2026-01-14', '2026-01-16', 'Captura de calificaciones finales extraordinarias docentes titulares', '14 al 16 de enero de 2026'
  UNION ALL SELECT '2026-11-03', '2026-11-30', 'Apertura de Select (plan 2009) para ELECTIVA', '3 al 30 de noviembre de 2026'
  UNION ALL SELECT '2026-09-07', NULL, 'Publicación de propuestas de protocolo (A cursar en 2027-2)', NULL
  UNION ALL SELECT '2026-09-11', NULL, 'Plática informativa (A cursar en 2027-2)', NULL
  UNION ALL SELECT '2026-09-21', '2026-09-25', 'Registro de protocolos', '21 al 25 de septiembre de 2026'
  UNION ALL SELECT '2026-10-08', '2026-10-14', 'Asignación de sinodales', '8 al 14 de octubre de 2026'
  UNION ALL SELECT '2026-10-19', '2026-10-23', 'Primera evaluación de protocolos', '19 al 23 de octubre de 2026'
  UNION ALL SELECT '2026-10-28', NULL, 'Envío de dictámenes de la evaluación', NULL
  UNION ALL SELECT '2026-11-03', '2026-11-09', 'Registro de protocolos reestructurados para segunda evaluación', '3 al 9 de noviembre de 2026'
  UNION ALL SELECT '2026-11-10', '2026-11-17', 'Segunda evaluación de protocolos', '10 al 17 de noviembre de 2026'
  UNION ALL SELECT '2026-11-20', NULL, 'Entrega de dictámenes reestructurados', NULL
  UNION ALL SELECT '2026-12-07', NULL, 'Publicación de propuestas de protocolo (A recursar en 2027-2 TTR)', NULL
  UNION ALL SELECT '2026-12-14', '2026-12-18', 'Registro de protocolos (TTR)', '14 al 18 de diciembre de 2026'
  UNION ALL SELECT '2027-01-08', NULL, 'Junta con la CATT para asignación de sinodales (TTR)', NULL
  UNION ALL SELECT '2027-01-11', '2027-01-15', 'Asignación de sinodales (TTR)', '11 al 15 de enero de 2027'
  UNION ALL SELECT '2027-01-18', '2027-01-22', 'Primera evaluación de protocolos (TTR)', '18 al 22 de enero de 2027'
  UNION ALL SELECT '2027-01-27', NULL, 'Envío de dictámenes de la evaluación (TTR)', NULL
  UNION ALL SELECT '2027-01-28', '2027-02-04', 'Registro de protocolos reestructurados para segunda evaluación (TTR)', '28 de enero al 4 de febrero de 2027'
  UNION ALL SELECT '2027-02-08', '2027-02-12', 'Segunda evaluación de protocolos (TTR)', '8 al 12 de febrero de 2027'
  UNION ALL SELECT '2027-02-15', NULL, 'Entrega de dictámenes reestructurados (TTR)', NULL
) datos
JOIN usuario u ON u.correo = 'profesor@catt.local';
