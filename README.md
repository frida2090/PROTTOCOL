# PROTTOCOL CATT

Sistema web en PHP nativo para gestionar usuarios y un calendario de actividades académicas.

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

El registro permite crear estudiantes, profesores y miembros CATT. El calendario requiere una sesión iniciada.

## Estructura

- `app/`: helpers, sesión y componentes compartidos.
- `config/`: conexión a la base de datos.
- `database/`: esquema y modelo entidad-relación.
- `public/`: punto de entrada y vistas públicas.
- `public/assets/`: estilos y logotipo institucional.
