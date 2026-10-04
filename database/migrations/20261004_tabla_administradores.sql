CREATE TABLE Administradores (
    UserId INT UNSIGNED PRIMARY KEY,
    CreadoEn TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_administradores_usuario
        FOREIGN KEY (UserId) REFERENCES Usuarios(UserId)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO Administradores (UserId)
SELECT UserId FROM Usuario_Roles WHERE Rol = 'admin';