<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

function migrateSepararSentenciasSql(string $sql): array
{
    $sentencias = [];
    $sentencia = '';
    $comilla = null;
    $comentarioLinea = false;
    $comentarioBloque = false;
    $longitud = strlen($sql);

    for ($i = 0; $i < $longitud; $i++) {
        $caracter = $sql[$i];
        $siguiente = $sql[$i + 1] ?? '';

        if ($comentarioLinea) {
            if ($caracter === "\n") {
                $comentarioLinea = false;
                $sentencia .= "\n";
            }
            continue;
        }
        if ($comentarioBloque) {
            if ($caracter === '*' && $siguiente === '/') {
                $comentarioBloque = false;
                $sentencia .= ' ';
                $i++;
            }
            continue;
        }
        if ($comilla !== null) {
            $sentencia .= $caracter;
            if ($caracter === '\\' && $comilla !== '`' && $i + 1 < $longitud) {
                $sentencia .= $sql[++$i];
            } elseif ($caracter === $comilla) {
                if ($siguiente === $comilla) {
                    $sentencia .= $sql[++$i];
                } else {
                    $comilla = null;
                }
            }
            continue;
        }

        if ($caracter === '#' || ($caracter === '-' && $siguiente === '-' && ctype_space($sql[$i + 2] ?? ' '))) {
            $comentarioLinea = true;
            $i += $caracter === '-' ? 1 : 0;
        } elseif ($caracter === '/' && $siguiente === '*') {
            $comentarioBloque = true;
            $i++;
        } elseif (in_array($caracter, ["'", '"', '`'], true)) {
            $comilla = $caracter;
            $sentencia .= $caracter;
        } elseif ($caracter === ';') {
            if (trim($sentencia) !== '') {
                $sentencias[] = trim($sentencia);
            }
            $sentencia = '';
        } else {
            $sentencia .= $caracter;
        }
    }

    if ($comilla !== null || $comentarioBloque) {
        throw new RuntimeException('El archivo SQL contiene una cadena o comentario sin cerrar.');
    }
    if (trim($sentencia) !== '') {
        $sentencias[] = trim($sentencia);
    }
    return $sentencias;
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo puede ejecutarse desde la terminal.\n");
    exit(1);
}

$directorioMigraciones = __DIR__ . DIRECTORY_SEPARATOR . 'migrations';
if (!is_dir($directorioMigraciones)) {
    fwrite(STDERR, "No existe la carpeta migrations/.\n");
    exit(1);
}

$archivos = glob($directorioMigraciones . DIRECTORY_SEPARATOR . '*.sql');
if ($archivos === false) {
    fwrite(STDERR, "No fue posible leer la carpeta migrations/.\n");
    exit(1);
}
sort($archivos, SORT_STRING);

try {
    $db = database();
    $db->exec(
        'CREATE TABLE IF NOT EXISTS migraciones ('
        . 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, '
        . 'archivo VARCHAR(255) NOT NULL UNIQUE, '
        . 'ejecutada_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
        . ') ENGINE=InnoDB'
    );
    $consultaAplicada = $db->prepare('SELECT 1 FROM migraciones WHERE archivo = ?');
    $registrar = $db->prepare('INSERT INTO migraciones (archivo) VALUES (?)');

    foreach ($archivos as $archivo) {
        $nombreArchivo = basename($archivo);
        $consultaAplicada->execute([$nombreArchivo]);
        if ($consultaAplicada->fetchColumn()) {
            echo "Omitida (ya ejecutada): {$nombreArchivo}\n";
            continue;
        }

        $contenido = file_get_contents($archivo);
        if ($contenido === false) {
            fwrite(STDERR, "Error: no fue posible leer {$nombreArchivo}.\n");
            exit(1);
        }
        if (str_starts_with($contenido, "\xEF\xBB\xBF")) {
            $contenido = substr($contenido, 3);
        }

        try {
            $sentencias = migrateSepararSentenciasSql($contenido);
            if (!$sentencias) {
                throw new RuntimeException('El archivo no contiene sentencias SQL.');
            }
            foreach ($sentencias as $sentencia) {
                $db->exec($sentencia);
            }
            $registrar->execute([$nombreArchivo]);
            echo "Ejecutada: {$nombreArchivo}\n";
        } catch (Throwable $exception) {
            fwrite(STDERR, "Error en {$nombreArchivo}: {$exception->getMessage()}\n");
            exit(1);
        }
    }

    if (!$archivos) {
        echo "No hay migraciones .sql pendientes en migrations/.\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "No fue posible ejecutar las migraciones: {$exception->getMessage()}\n");
    exit(1);
}
