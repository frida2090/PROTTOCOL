-- 2026-10-08: Workflow de evaluacion de protocolos por sinodales.
-- Amplia el estado de un protocolo para cubrir el ciclo completo:
--   * Rechazo de la CATT (blando, con observaciones, reenviable)
--   * Evaluacion por sinodales (v1 / v2)
--   * Aprobado por sinodales (la CATT asigna NumeroTT)
-- Aplicar sobre una base con PROTTOCOL.sql + migraciones 20261001-20261007.
-- NOTA: si se importa dos veces, fallara solo la sentencia MODIFY (columna ya
-- igual); no causa dano. Sin embargo se recomienda aplicarla una sola vez.

ALTER TABLE Formatos_Subidos
    MODIFY COLUMN Status ENUM('Pendiente', 'Rechazado', 'Aprobado',
                              'RechazadoSinodales', 'AprobadoSinodales')
    NOT NULL DEFAULT 'Pendiente';
