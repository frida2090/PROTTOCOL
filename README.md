# PROTTOCOL

Sistema web que centraliza todo lo que necesitas saber de la CATT en un solo lugar: procesos académicos, calendario de actividades, fechas de pláticas, formularios y la información de directores y sinodales. Está pensado para que la comunidad de ESCOM encuentre rápidamente los recursos y avisos que necesita durante su trayectoria académica.

## Requisitos

- PHP 8.1 o superior
- MySQL 8.0 o MariaDB 10.5+
- Composer 2
- Extensión PDO MySQL habilitada
- Extensión Imagick de PHP habilitada con soporte para leer PDF
- Python 3.10 o superior y dependencias de `database/requirements-import.txt` para importar el catálogo PDF

## Instalación

1. Cree la base de datos importando `database/PROTTOCOL.sql`, que contiene el modelo normalizado con `Usuarios` y `Usuario_Roles`.
2. Instale las dependencias PHP con `composer install`.
3. Configure las credenciales en `config/database.php` o mediante variables de entorno:
   - `DB_HOST`
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASS`
4. Desde la carpeta `PROTTOCOL`, ejecute:

```bash
php -S localhost:8000 -t public
```

5. Abra `http://localhost:8000`.

El modelo normalizado permite asignar cada evento de calendario a usuarios junto con uno de sus roles (`estudiante`, `director`, `miembroCatt`, `jefeAcademia`, `sinodal` o `profesor_seguimiento`). La relación valida el rol contra `Usuario_Roles`, por lo que un usuario puede recibir una misma fecha en varios roles. Para una base existente con las tablas anteriores `usuario` y `calendario`, respalde primero la base, renombre `calendario` a `calendario_legacy`, ejecute `database/PROTTOCOL.sql` completo y después ejecute `database/migrations/20261001_migrar_datos_a_PROTTOCOL.sql`. Verifique los datos antes de retirar las tablas antiguas; la migración conserva las cuentas y eventos, asigna `profesor` al rol nuevo `director` y usa un departamento provisional para el empleado heredado.

## Catálogo de docentes

`DocentesEscom.pdf` es la fuente para el catálogo docente. `database/migrations/20261002_normalizar_docentes_unidades.sql` crea las tablas `DocentesCatalogo`, `UnidadAprendizaje` y `Docente_UnidadAprendizaje`; `database/migrations/20261003_vincular_docentes_usuarios.sql` agrega el vínculo opcional de cada docente con `Usuarios`. Para una base nueva, las tablas y el vínculo ya están en `database/PROTTOCOL.sql`; en una base existente, ejecuta ambas migraciones en orden, una sola vez. Instala las dependencias de importación con `python -m pip install -r database/requirements-import.txt`, configura `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASS` en el entorno de Python y ejecuta `python database/importar_docentes_pdf.py`. Antes de reemplazar una carga del catálogo, valida el PDF con `python database/importar_docentes_pdf.py --dry-run`; `--replace` sustituye únicamente las tablas normalizadas del catálogo, conservando vínculos a cuentas que ya existan.

Para crear una cuenta de docente, entra al panel administrador, selecciona el nombre importado en “Vincular con docente del catálogo”, registra su correo, número de empleado, departamento y contraseña inicial, y asigna los roles `director` y/o `sinodal`. No se generan credenciales automáticamente porque el PDF no incluye correo ni contraseña. La cuenta inicial de administrador también debe aprovisionarse antes de poder usar el panel.

### Administradores y revisión de roles

`Administradores` es la extensión de cuentas con permiso administrativo; `Usuario_Roles` sigue siendo el catálogo de roles de la aplicación. En instalaciones existentes, ejecuta `database/migrations/20261004_tabla_administradores.sql` una sola vez. En instalaciones nuevas, la tabla está incluida en `database/PROTTOCOL.sql`.

Las instalaciones nuevas incluyen una cuenta de administrador de ejemplo: `admin@prottocol.com.mx`, contraseña `123456789`. Para habilitarla en una base existente, ejecuta una sola vez `database/migrations/20261005_registrar_administrador_ejemplo.sql`; si ese correo ya está registrado, la migración conserva la contraseña existente y solo agrega los registros administrativos que falten. Cambia la contraseña de ejemplo después del primer inicio de sesión. También puedes promover cualquier cuenta existente desde la terminal local con `php database/promover_administrador.php correo@institucion.mx` y confirmar escribiendo `PROMOVER`; ese comando conserva la contraseña actual. Después, el panel permite revisar los roles de cada usuario, asignarlos o retirarlos, sincronizar la tabla `Administradores` y bloquea quitar al último administrador. Los roles `estudiante`, `director`, `sinodal`, `profesor_seguimiento`, `miembroCatt` y `jefeAcademia` requieren que exista la extensión de datos correspondiente antes de ser asignados.

### Equipos de TT y datos de demostración

Los miembros de la CATT asignan equipos desde su panel (**Asignar equipos TT**): estudiantes sin equipo, exactamente dos directores, tres sinodales y un profesor con rol `profesor_seguimiento`. El representante del equipo es el primer estudiante en orden alfabético y se guarda en `Equipo_TT.RepresentanteUserId`; las asignaciones docentes se guardan en `Equipo_Asignacion`, vinculada al único TT de cada equipo. `TT.TipoTT` identifica TT1 o TT2 y `TT.fecha_presentacion` registra la fecha de presentación; al programar una presentación en el calendario se actualizan ambos campos para el TT existente. En una base existente, ejecuta en orden `database/migrations/20261010_profesor_seguimiento_y_fecha_presentacion.sql` y `database/migrations/20261011_formulario1_firmas.sql` después de crear `Equipo_Asignacion`; la primera conserva los roles de seguimiento y agrega la fecha, y la segunda agrega el tipo y el Formulario 1 con sus firmas. Clasifica los TT existentes desde la página Formulario 1 del miembro CATT antes de que los alumnos inicien solicitudes. El almacenamiento `storage/formulario1_firmas` debe ser escribible por PHP y está fuera del directorio público. `composer install` instala FPDF y FPDI para generar el documento final con las páginas de firmas anexas. `php database/sembrar_demo_catt.php` aplica `database/migrations/20261006_asignacion_equipos_tt.sql` si hace falta e inyecta cuentas de demostración (idempotente): sinodales `mariacruz@ipn.mx`, `consuelogarcia@ipn.mx` y `jessieguzman@ipn.mx` (contraseña `Sinodal123`), alumnos `fcarmonaj@alumno.ipn.mx`, `oredondog@alumno.ipn.mx` y `gzaragozag@alumno.ipn.mx` (`Alumno123`) y el miembro CATT `catt@ipn.mx` (`Catt12345`). Las boletas son ficticias; cambia estas contraseñas fuera de un entorno de pruebas.

### Formulario 1 y firmas

Los alumnos con TT clasificado crean solicitudes de actualización. Cada solicitud toma una instantánea de los alumnos, directores, sinodales y profesor de seguimiento asignados al equipo; esos participantes pueden cargar una firma PDF de hasta 1 MB. El servidor conserva el PDF original y genera una imagen PNG de su primera página a 300 DPI, recortada con Imagick; se guardan ambas rutas. Al completar las firmas el estado pasa a revisión CATT. Después de aprobarse, el PDF final (formulario más las páginas originales de las firmas) se habilita al alumno desde siete días antes de `TT.fecha_presentacion`.

### Acuse del reporte técnico y seguimiento de TT

En bases nuevas, `database/PROTTOCOL.sql` incluye las tablas para el Acuse de Recibo y los formatos de seguimiento. En bases existentes, después de las migraciones anteriores, ejecuta una sola vez y en orden `database/migrations/20261012_acuses_reporte_tecnico.sql`, `database/migrations/20261013_seguimientos_tt.sql` y `database/migrations/20261124_firmas_pdf_a_png.sql`. El alumno inicia un acuse TT-I/TT-II/TT-R; sus directores, sinodales y profesor de seguimiento asignados indican el medio de recepción y cargan su firma PDF (máximo 1 MB). Tras completar todas las firmas, la CATT puede aprobar o rechazar el acuse. El alumno descarga el PDF aprobado únicamente durante la semana previa a la fecha de presentación, hasta ese día. El profesor de seguimiento asignado configura los datos generales y sesiones del TT, con avance acumulado máximo de 100%; alumnos y profesor registran su asistencia por sesión con firma PDF. Las cargas conservan el PDF original y guardan la imagen PNG procesada. Las firmas se guardan bajo `storage/acuses_reporte_tecnico/firmas` y `storage/seguimientos_tt/firmas`, fuera del directorio público. Los PDFs del seguimiento incluyen las sesiones y anexan las firmas cargadas.

### Evaluación de protocolos

Las respuestas Sí/No y las observaciones por pregunta del formulario de evaluación se guardan en `Sinodal_Evaluacion_Detalle`, relacionadas con el dictamen general del sinodal en `Sinodal_Evaluacion`. En una instalación nueva la tabla se crea al importar `database/PROTTOCOL.sql`; en una base existente, aplica una sola vez `database/migrations/20261009_respuestas_evaluacion_protocolo.sql` después de las migraciones anteriores. Los alumnos, sinodales, miembros CATT y administradores autorizados pueden consultar los dictámenes y el estado/observaciones de la revisión CATT desde la vista del protocolo. Las evaluaciones previas a esta migración conservan su dictamen general, pero no tienen respuestas individuales para mostrar.

## Estructura

- `app/`: helpers, sesión y componentes compartidos.
- `config/`: conexión a la base de datos.
- `database/`: esquema y modelo entidad-relación.
- `public/`: punto de entrada y vistas públicas.
- `public/assets/`: estilos y logotipo institucional.
