USE catt_prottocol;
SET NAMES utf8mb4;

ALTER TABLE Formulario1_Firma
    ADD COLUMN RutaArchivoPng VARCHAR(255) NULL AFTER RutaArchivo;

ALTER TABLE acuse_entregas
    ADD COLUMN firma_png_path VARCHAR(255) NULL AFTER firma_pdf_path;

ALTER TABLE seguimiento_sesiones
    ADD COLUMN firma_profesor_png_path VARCHAR(255) NULL AFTER firma_profesor_pdf_path;

ALTER TABLE seguimiento_asistencias_alumnos
    ADD COLUMN firma_alumno_png_path VARCHAR(255) NULL AFTER firma_alumno_pdf_path;
