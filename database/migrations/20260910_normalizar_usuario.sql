-- Ejecute este archivo solo en instalaciones existentes creadas con el esquema anterior.
ALTER TABLE usuario
    ADD COLUMN apellido_paterno VARCHAR(60) NULL AFTER nombre,
    ADD COLUMN apellido_materno VARCHAR(60) NULL AFTER apellido_paterno,
    ADD COLUMN numero_empleado VARCHAR(30) NULL UNIQUE AFTER noBoleta;

-- Separe manualmente los nombres completos existentes antes de aplicar las restricciones NOT NULL.
-- Para el usuario de demostración se conserva el valor anterior como número de empleado.
UPDATE usuario
SET numero_empleado = noBoleta,
    noBoleta = NULL
WHERE rol IN ('profesor', 'miembroCatt');

ALTER TABLE usuario
    MODIFY nombre VARCHAR(60) NOT NULL,
    MODIFY apellido_paterno VARCHAR(60) NOT NULL,
    MODIFY apellido_materno VARCHAR(60) NULL,
    MODIFY noBoleta VARCHAR(30) NULL,
    ADD CONSTRAINT chk_usuario_identificador CHECK (
        (rol = 'estudiante' AND noBoleta IS NOT NULL AND numero_empleado IS NULL)
        OR (rol IN ('profesor', 'miembroCatt') AND numero_empleado IS NOT NULL AND noBoleta IS NULL)
    );