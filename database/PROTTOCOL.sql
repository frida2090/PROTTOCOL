CREATE DATABASE IF NOT EXISTS catt_prottocol
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE catt_prottocol;
SET NAMES utf8mb4;

--Usuarios del sistema
CREATE TABLE Usuarios (
    UserId INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Nombre VARCHAR(60) NOT NULL,
    ApellidoPaterno VARCHAR(60) NOT NULL,
    ApellidoMaterno VARCHAR(60) NOT NULL,
    Email VARCHAR(160) NOT NULL UNIQUE,
    PasswordHash VARCHAR(255) NOT NULL
);

--Extension de la tabla Usuario que contenga todos los roles que un usuario puede tener (Para casos como directores y sinodal al mismo tiempo)
CREATE TABLE Usuario_Roles (
    UserId INT UNSIGNED NOT NULL,
    Rol ENUM('estudiante', 'director', 'miembroCatt', 'jefeAcademia', 'admin', 'sinodal') NOT NULL,
    PRIMARY KEY (UserId, Rol), --Evita roles repetidos para un mismo usuario
    CONSTRAINT fk_usuario_roles FOREIGN KEY (UserId) REFERENCES Usuarios(UserId)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

--Estructura de una Academia
CREATE TABLE Academia (
    AcadId INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Nombre VARCHAR(100) NOT NULL
);

CREATE TABLE Academia_Departamento (
    DeptId INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Nombre VARCHAR(100) NOT NULL,
    AcadId INT UNSIGNED NOT NULL,
    CONSTRAINT fk_academia_departamento FOREIGN KEY (AcadId) REFERENCES Academia(AcadId)
        ON UPDATE CASCADE
        ON DELETE RESTRICT, --Impide borrar una academia por error si ya existen departamentos asociados
    CONSTRAINT nombre_por_academia
        UNIQUE (Nombre, AcadId) --Evita que existan dos departamentos con el mismo nombre en la misma academia
);

CREATE TABLE Empleado(
    UserId INT UNSIGNED PRIMARY KEY,
    NumeroEmpleado VARCHAR(30) NOT NULL UNIQUE,
    DeptId INT UNSIGNED NOT NULL,
    CONSTRAINT fk_empleado_usuario 
        FOREIGN KEY (UserId) REFERENCES Usuarios(UserId)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_empleado_departamento 
        FOREIGN KEY (DeptId) REFERENCES Academia_Departamento(DeptId)
        ON UPDATE CASCADE
        ON DELETE RESTRICT --Impide borrar un departamento por error si ya existen empleados asociados
);

--Se trabaja con la tabla equipo durante el periodo del proceso de Protocolo
CREATE TABLE Equipo_TT(
    EquipoId INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    TituloProtocolo VARCHAR(100) NOT NULL,
    Descripcion TEXT NOT NULL,
    FechaCreacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE TT(
    TT_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    NumeroTT VARCHAR(20) NOT NULL UNIQUE,
    Titulo VARCHAR(100) NOT NULL,
    Descripcion TEXT NOT NULL,
    Status ENUM('En Desarrollo', 'Concluido', 'Baja') NOT NULL DEFAULT 'En Desarrollo',
    EquipoId INT UNSIGNED NOT NULL UNIQUE,
    CONSTRAINT fk_tt_equipo 
        FOREIGN KEY (EquipoId) REFERENCES Equipo_TT(EquipoId)
        ON UPDATE CASCADE
        ON DELETE RESTRICT --Impide borrar un equipo por error si ya existe un TT asociado
);

CREATE TABLE Externo(
    UserId INT UNSIGNED PRIMARY KEY,
    OrgProcedencia VARCHAR(100) NOT NULL,
    EquipoId INT UNSIGNED NULL, 
    CONSTRAINT fk_externo_usuario 
        FOREIGN KEY (UserId) REFERENCES Usuarios(UserId)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_externo_equipo 
        FOREIGN KEY (EquipoId) REFERENCES Equipo_TT(EquipoId)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE TABLE Estudiante(
    UserId INT UNSIGNED PRIMARY KEY,
    NumeroBoleta VARCHAR(10) NOT NULL UNIQUE,
    Creditos INT UNSIGNED NOT NULL,
    EquipoId INT UNSIGNED NULL, --Un alumno solo pertenece a un equipo
    CONSTRAINT fk_estudiante_usuario 
        FOREIGN KEY (UserId) REFERENCES Usuarios(UserId)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_estudiante_equipo 
        FOREIGN KEY (EquipoId) REFERENCES Equipo_TT(EquipoId)
        ON UPDATE CASCADE
        ON DELETE RESTRICT --Impide borrar un equipo por error si ya existen estudiantes asociados
);

--Permite que un empleado pueda pertenecer a varios equipos de TT
CREATE TABLE EmpleadoEquipo(
    UserId INT UNSIGNED NOT NULL,
    EquipoId INT UNSIGNED NOT NULL, 
    PRIMARY KEY(UserId, EquipoId),
    CONSTRAINT fk_empleado_equipo_usuario 
        FOREIGN KEY (UserId) REFERENCES Usuarios(UserId)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_empleado_equipo_equipo 
        FOREIGN KEY (EquipoId) REFERENCES Equipo_TT(EquipoId)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE TABLE Catalogo_Formatos(
    TipoFormatoId INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Clave ENUM('Protocolo', 'Solicitud de Modificacion') NOT NULL UNIQUE,
    Nombre VARCHAR(100) NOT NULL,
    FormatoEjemplo MEDIUMBLOB NOT NULL --Archivos de ejemplo para que los usuarios se puedan guiar
);

CREATE TABLE Formatos_Subidos(
    FormatoId INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    EquipoId INT UNSIGNED NOT NULL,
    TipoFormatoId INT UNSIGNED NOT NULL,
    FormatoSubido MEDIUMBLOB NOT NULL,
    FechaSubida TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Status ENUM('Pendiente', 'Aprobado', 'Rechazado') NOT NULL DEFAULT 'Pendiente',
    Observaciones TEXT NULL,
    UserId INT UNSIGNED, --Usuario que revisa el formato (miembro Catt)
    FechaRevision TIMESTAMP NULL,
    CONSTRAINT fk_formatos_subidos_equipo 
        FOREIGN KEY (EquipoId) REFERENCES Equipo_TT(EquipoId)
        ON UPDATE CASCADE
        ON DELETE RESTRICT, --Impide borrar un equipo por error si ya existen formatos asociados
    CONSTRAINT fk_formatos_subidos_tipo_formato 
        FOREIGN KEY (TipoFormatoId) REFERENCES Catalogo_Formatos(TipoFormatoId)
        ON UPDATE CASCADE
        ON DELETE RESTRICT, --Impide borrar un tipo de formato por error si ya existen formatos asociados
    CONSTRAINT fk_formatos_subidos_usuario 
        FOREIGN KEY (UserId) REFERENCES Usuarios(UserId)
        ON UPDATE CASCADE
        ON DELETE SET NULL --Impide borrar un usuario por error si ya existen formatos asociados
);

CREATE TABLE Formato_Protocolo (
    FormatoId INT UNSIGNED PRIMARY KEY,
    FormatoVersion INT UNSIGNED NOT NULL,
    Constraint fk_formato_protocolo 
        FOREIGN KEY (FormatoId) REFERENCES Formatos_Subidos(FormatoId)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

--Los jefes de academia revisan los formatos asociados a su academia y los pueden enviar al departamento correspondiente
CREATE TABLE Academia_Formato(
    AFormatoId INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    AcadId INT UNSIGNED NOT NULL,
    FormatoId INT UNSIGNED NOT NULL,
    Status ENUM('Pendiente de asignar departamento', 'Departamento asignado'),
    FechaRecibido TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, --Fecha cuando se recibio el formato?
    CONSTRAINT uq_acad_formato UNIQUE (AcadId, FormatoId),
    CONSTRAINT fk_af_academia 
        FOREIGN KEY (AcadId) REFERENCES Academia(AcadId)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_af_formato FOREIGN KEY (FormatoId) REFERENCES Formatos_Subidos(FormatoId)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

CREATE TABLE Departamento_Formato(
    DFormatoId INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    AFormatoId INT UNSIGNED NOT NULL,
    DeptId INT UNSIGNED NOT NULL,
    FechaAsignado TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, 
    CONSTRAINT uq_depto_formato UNIQUE (AFormatoId, DeptId),
    CONSTRAINT fk_df_aformato FOREIGN KEY (AFormatoId) REFERENCES Academia_Formato(AFormatoId)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_df_depto FOREIGN KEY (DeptId) REFERENCES Academia_Departamento(DeptId)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE TABLE Sinodal_Evaluacion(
    EvalId INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    FormatoId INT UNSIGNED NOT NULL,
    UserId INT UNSIGNED NOT NULL,
    Dictamen ENUM('aprobado', 'rechazado') NOT NULL DEFAULT 'aprobado',
    Observaciones TEXT NULL,
    FechaRevision TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_formato_sinodal UNIQUE (FormatoId, UserId),
    CONSTRAINT fk_se_formato FOREIGN KEY (FormatoId) REFERENCES Formatos_Subidos(FormatoId)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_se_sinodal FOREIGN KEY (UserId) REFERENCES Empleado(UserId)
        ON UPDATE CASCADE
        ON DELETE RESTRICT 
);

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



