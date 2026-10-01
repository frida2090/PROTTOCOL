# PROTTOCOL

Sistema web que centraliza todo lo que necesitas saber de la CATT en un solo lugar: procesos académicos, calendario de actividades, fechas de pláticas, formularios y la información de directores y sinodales. Está pensado para que la comunidad de ESCOM encuentre rápidamente los recursos y avisos que necesita durante su trayectoria académica.

## Requisitos

- PHP 8.1 o superior
- MySQL 8.0 o MariaDB 10.5+
- Extensión PDO MySQL habilitada

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

## Estructura

- `app/`: helpers, sesión y componentes compartidos.
- `config/`: conexión a la base de datos.
- `database/`: esquema y modelo entidad-relación.
- `public/`: punto de entrada y vistas públicas.
- `public/assets/`: estilos y logotipo institucional.
