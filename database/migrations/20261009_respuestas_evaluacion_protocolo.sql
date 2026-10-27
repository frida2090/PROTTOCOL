-- Guarda cada respuesta y observacion del formulario de evaluacion de protocolo.
-- Aplicar sobre una base existente despues de 20261008_cambiar_status_formatos_subidos.sql.
-- Ejecutar una sola vez.

CREATE TABLE Sinodal_Evaluacion_Detalle(
    EvalId INT UNSIGNED NOT NULL,
    NumeroPregunta TINYINT UNSIGNED NOT NULL,
    Respuesta ENUM('SI', 'NO') NOT NULL,
    Observaciones TEXT NULL,
    PRIMARY KEY (EvalId, NumeroPregunta),
    CONSTRAINT fk_sed_evaluacion FOREIGN KEY (EvalId) REFERENCES Sinodal_Evaluacion(EvalId)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);
