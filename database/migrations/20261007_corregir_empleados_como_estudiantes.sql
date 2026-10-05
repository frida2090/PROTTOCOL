USE catt_prottocol;
SET NAMES utf8mb4;

-- Docentes/CATT que quedaron guardados en Estudiante (su número aparecía como boleta)
-- Se conserva el número como NumeroEmpleado y se retira el registro de Estudiante.
INSERT INTO Empleado (UserId, NumeroEmpleado, DeptId)
SELECT e.UserId, e.NumeroBoleta, (SELECT MIN(DeptId) FROM Academia_Departamento)
FROM Estudiante e
WHERE e.EquipoId IS NULL
  AND NOT EXISTS (SELECT 1 FROM Usuario_Roles r WHERE r.UserId = e.UserId AND r.Rol = 'estudiante')
  AND EXISTS (SELECT 1 FROM Usuario_Roles r WHERE r.UserId = e.UserId AND r.Rol IN ('director', 'sinodal', 'miembroCatt', 'jefeAcademia'))
  AND NOT EXISTS (SELECT 1 FROM Empleado em WHERE em.UserId = e.UserId)
  AND NOT EXISTS (SELECT 1 FROM Empleado em WHERE em.NumeroEmpleado = e.NumeroBoleta);

DELETE e FROM Estudiante e
WHERE e.EquipoId IS NULL
  AND NOT EXISTS (SELECT 1 FROM Usuario_Roles r WHERE r.UserId = e.UserId AND r.Rol = 'estudiante')
  AND EXISTS (SELECT 1 FROM Empleado em WHERE em.UserId = e.UserId);
