# PROTTOCOL

Sistema web que centraliza todo lo que necesitas saber de la CATT en un solo lugar: procesos académicos, calendario de actividades, fechas de pláticas, formularios y la información de directores y sinodales. Está pensado para que la comunidad de ESCOM encuentre rápidamente los recursos y avisos que necesita durante su trayectoria académica.

## Requisitos

- PHP 8.1 o superior
- MySQL 8.0 o MariaDB 10.5+
- Extensión PDO MySQL habilitada
- Python 3.10 o superior y dependencias de `database/requirements-import.txt` para importar el catálogo PDF

## Instalación

1. Cree la base de datos importando `database/PROTTOCOL.sql`, que contiene el modelo normalizado con `Usuarios` y `Usuario_Roles`.
2. Configure las credenciales en `config/database.php` o mediante variables de entorno:
   - `DB_HOST`
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASS`
3. Desde la carpeta `PROTTOCOL`, ejecute:

```bash
php -S localhost:8000 -t public
```

4. Abra `http://localhost:8000`.

El modelo normalizado permite asignar cada evento de calendario a usuarios junto con uno de sus roles (`estudiante`, `director`, `miembroCatt`, `jefeAcademia` o `sinodal`). La relación valida el rol contra `Usuario_Roles`, por lo que un usuario puede recibir una misma fecha en varios roles. Para una base existente con las tablas anteriores `usuario` y `calendario`, respalde primero la base, renombre `calendario` a `calendario_legacy`, ejecute `database/PROTTOCOL.sql` completo y después ejecute `database/migrations/20261001_migrar_datos_a_PROTTOCOL.sql`. Verifique los datos antes de retirar las tablas antiguas; la migración conserva las cuentas y eventos, asigna `profesor` al rol nuevo `director` y usa un departamento provisional para el empleado heredado.

## Catálogo de docentes

`DocentesEscom.pdf` es la fuente para el catálogo docente. `database/migrations/20261002_normalizar_docentes_unidades.sql` crea las tablas `DocentesCatalogo`, `UnidadAprendizaje` y `Docente_UnidadAprendizaje`; `database/migrations/20261003_vincular_docentes_usuarios.sql` agrega el vínculo opcional de cada docente con `Usuarios`. Para una base nueva, las tablas y el vínculo ya están en `database/PROTTOCOL.sql`; en una base existente, ejecuta ambas migraciones en orden, una sola vez. Instala las dependencias de importación con `python -m pip install -r database/requirements-import.txt`, configura `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASS` en el entorno de Python y ejecuta `python database/importar_docentes_pdf.py`. Antes de reemplazar una carga del catálogo, valida el PDF con `python database/importar_docentes_pdf.py --dry-run`; `--replace` sustituye únicamente las tablas normalizadas del catálogo, conservando vínculos a cuentas que ya existan.

Para crear una cuenta de docente, entra al panel administrador, selecciona el nombre importado en “Vincular con docente del catálogo”, registra su correo, número de empleado, departamento y contraseña inicial, y asigna los roles `director` y/o `sinodal`. No se generan credenciales automáticamente porque el PDF no incluye correo ni contraseña. La cuenta inicial de administrador también debe aprovisionarse antes de poder usar el panel.

### Administradores y revisión de roles

`Administradores` es la extensión de cuentas con permiso administrativo; `Usuario_Roles` sigue siendo el catálogo de roles de la aplicación. En instalaciones existentes, ejecuta `database/migrations/20261004_tabla_administradores.sql` una sola vez. En instalaciones nuevas, la tabla está incluida en `database/PROTTOCOL.sql`.

Las instalaciones nuevas incluyen una cuenta de administrador de ejemplo: `admin@prottocol.com.mx`, contraseña `123456789`. Para habilitarla en una base existente, ejecuta una sola vez `database/migrations/20261005_registrar_administrador_ejemplo.sql`; si ese correo ya está registrado, la migración conserva la contraseña existente y solo agrega los registros administrativos que falten. Cambia la contraseña de ejemplo después del primer inicio de sesión. También puedes promover cualquier cuenta existente desde la terminal local con `php database/promover_administrador.php correo@institucion.mx` y confirmar escribiendo `PROMOVER`; ese comando conserva la contraseña actual. Después, el panel permite revisar los roles de cada usuario, asignarlos o retirarlos, sincronizar la tabla `Administradores` y bloquea quitar al último administrador. Los roles `estudiante`, `director`, `sinodal`, `miembroCatt` y `jefeAcademia` requieren que exista la extensión de datos correspondiente antes de ser asignados.

### Equipos de TT y datos de demostración

Los miembros de la CATT asignan equipos desde su panel (**Asignar equipos TT**): estudiantes sin equipo, exactamente dos directores, tres sinodales y un profesor de seguimiento. El representante del equipo es el primer estudiante en orden alfabético y se guarda en `Equipo_TT.RepresentanteUserId`; las asignaciones docentes se guardan en `Equipo_Asignacion`. Los estudiantes ven su equipo y docentes, y cada docente ve sus equipos (máximo 8). En una base existente, `php database/sembrar_demo_catt.php` aplica `database/migrations/20261006_asignacion_equipos_tt.sql` si hace falta e inyecta cuentas de demostración (idempotente): sinodales `mariacruz@ipn.mx`, `consuelogarcia@ipn.mx` y `jessieguzman@ipn.mx` (contraseña `Sinodal123`), alumnos `fcarmonaj@alumno.ipn.mx`, `oredondog@alumno.ipn.mx` y `gzaragozag@alumno.ipn.mx` (`Alumno123`) y el miembro CATT `catt@ipn.mx` (`Catt12345`). Las boletas son ficticias; cambia estas contraseñas fuera de un entorno de pruebas.

### Evaluación de protocolos

Las respuestas Sí/No y las observaciones por pregunta del formulario de evaluación se guardan en `Sinodal_Evaluacion_Detalle`, relacionadas con el dictamen general del sinodal en `Sinodal_Evaluacion`. En una instalación nueva la tabla se crea al importar `database/PROTTOCOL.sql`; en una base existente, aplica una sola vez `database/migrations/20261009_respuestas_evaluacion_protocolo.sql` después de las migraciones anteriores. Los alumnos, sinodales, miembros CATT y administradores autorizados pueden consultar los dictámenes y el estado/observaciones de la revisión CATT desde la vista del protocolo. Las evaluaciones previas a esta migración conservan su dictamen general, pero no tienen respuestas individuales para mostrar.

## Estructura

- `app/`: helpers, sesión y componentes compartidos.
- `config/`: conexión a la base de datos.
- `database/`: esquema y modelo entidad-relación.
- `public/`: punto de entrada y vistas públicas.
- `public/assets/`: estilos y logotipo institucional.
