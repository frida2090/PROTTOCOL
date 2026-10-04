ALTER TABLE DocentesCatalogo
    ADD COLUMN UserId INT UNSIGNED NULL UNIQUE AFTER NombreCompleto,
    ADD INDEX idx_docentes_catalogo_user (UserId),
    ADD CONSTRAINT fk_docentes_catalogo_user
        FOREIGN KEY (UserId) REFERENCES Usuarios(UserId)
        ON UPDATE CASCADE
        ON DELETE SET NULL;