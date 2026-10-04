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

Como el panel solo lo puede abrir un administrador, la primera cuenta se habilita desde la terminal local: ejecuta `php database/promover_administrador.php correo@institucion.mx` y confirma escribiendo `PROMOVER`. El comando requiere que esa cuenta ya exista y conserva su contraseña actual. Después, el panel permite revisar los roles de cada usuario, asignarlos o retirarlos, sincronizar la tabla `Administradores` y bloquea quitar al último administrador. Los roles `estudiante`, `director`, `sinodal`, `miembroCatt` y `jefeAcademia` requieren que exista la extensión de datos correspondiente antes de ser asignados.

## Estructura

- `app/`: helpers, sesión y componentes compartidos.
- `config/`: conexión a la base de datos.
- `database/`: esquema y modelo entidad-relación.
- `public/`: punto de entrada y vistas públicas.
- `public/assets/`: estilos y logotipo institucional.
