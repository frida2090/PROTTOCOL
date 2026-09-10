-- Ejecute este archivo solo en instalaciones existentes creadas con el esquema anterior.
ALTER TABLE usuario
    ADD COLUMN apellido_paterno VARCHAR(60) NULL AFTER nombre,
    ADD COLUMN apellido_materno VARCHAR(60) NULL AFTER apellido_paterno,
    ADD COLUMN numero_empleado VARCHAR(30) NULL UNIQUE AFTER noBoleta,
    MODIFY noBoleta VARCHAR(30) NULL;

-- Conserva el primer término como nombre y el resto como apellido paterno.
-- Revise y ajuste manualmente los apellidos compuestos después de migrar.
UPDATE usuario
SET apellido_paterno = TRIM(SUBSTRING(nombre, LENGTH(SUBSTRING_INDEX(nombre, ' ', 1)) + 1)),
    nombre = SUBSTRING_INDEX(nombre, ' ', 1)
WHERE apellido_paterno IS NULL;

-- Para profesores y miembros CATT, el identificador anterior se convierte
-- en número de empleado.
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