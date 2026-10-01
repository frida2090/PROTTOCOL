USE catt_prottocol;

START TRANSACTION;

INSERT INTO Usuarios (UserId, Nombre, ApellidoPaterno, ApellidoMaterno, Email, PasswordHash)
SELECT id, nombre, apellido_paterno, COALESCE(apellido_materno, ''), correo, password_hash
FROM usuario;

INSERT INTO Academia (Nombre)
SELECT 'Academia por asignar'
WHERE NOT EXISTS (
    SELECT 1 FROM Academia WHERE Nombre = 'Academia por asignar'
);

INSERT INTO Academia_Departamento (Nombre, AcadId)
SELECT 'Departamento por asignar', a.AcadId
FROM Academia a
WHERE a.Nombre = 'Academia por asignar'
  AND NOT EXISTS (
      SELECT 1
      FROM Academia_Departamento d
      WHERE d.AcadId = a.AcadId
        AND d.Nombre = 'Departamento por asignar'
  );

SET @dept_por_asignar = (
    SELECT DeptId
    FROM Academia_Departamento
    WHERE Nombre = 'Departamento por asignar'
    ORDER BY DeptId
    LIMIT 1
);

INSERT INTO Usuario_Roles (UserId, Rol)
SELECT id,
       CASE rol
           WHEN 'profesor' THEN 'director'
           ELSE rol
       END
FROM usuario;

INSERT INTO Estudiante (UserId, NumeroBoleta, Creditos)
SELECT id, noBoleta, 0
FROM usuario
WHERE rol = 'estudiante';

INSERT INTO Empleado (UserId, NumeroEmpleado, DeptId)
SELECT id, numero_empleado, @dept_por_asignar
FROM usuario
WHERE rol IN ('profesor', 'miembroCatt', 'admin');

INSERT INTO calendario (
    id,
    fecha,
    fecha_fin,
    actividad,
    descripcion,
    tipo_tt,
    creado_por_user_id,
    creado_en
)
SELECT id,
       fecha,
       fecha_fin,
       actividad,
       descripcion,
       NULL,
       profesor_id,
       creado_en
FROM calendario_legacy;

COMMIT;