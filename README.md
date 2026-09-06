# PROTTOCOL

Sistema web que centraliza todo lo que necesitas saber de la CATT en un solo lugar: procesos académicos, calendario de actividades, fechas de pláticas, formularios y la información de directores y sinodales. Está pensado para que la comunidad de ESCOM encuentre rápidamente los recursos y avisos que necesita durante su trayectoria académica.

## Requisitos

- PHP 8.1 o superior
- MySQL 8.0 o MariaDB 10.5+
- Extensión PDO MySQL habilitada

## Instalación

1. Cree la base de datos importando `database/schema.sql`.
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

El registro permite crear estudiantes, profesores y miembros CATT. El calendario de actividades es público; los profesores y miembros CATT pueden iniciar sesión para publicar nuevas actividades.

## Estructura

- `app/`: helpers, sesión y componentes compartidos.
- `config/`: conexión a la base de datos.
- `database/`: esquema y modelo entidad-relación.
- `public/`: punto de entrada y vistas públicas.
- `public/assets/`: estilos y logotipo institucional.
