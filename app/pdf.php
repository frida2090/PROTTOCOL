<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

const MAX_PDF_FIRMADO_BYTES = 1048576;

function validarPdfFirmado(string $rutaTemporal, string $nombre, int $maxBytes = MAX_PDF_FIRMADO_BYTES): void
{
    $extension = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    $tamano = filesize($rutaTemporal);
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $rutaTemporal) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    if ($extension !== 'pdf' || $mime !== 'application/pdf') {
        throw new DomainException('La firma debe estar en formato PDF.');
    }
    if ($tamano === false || $tamano > $maxBytes) {
        throw new DomainException('El PDF de firma no puede superar 1 MB.');
    }
    try {
        $paginas = (new \setasign\Fpdi\Fpdi())->setSourceFile($rutaTemporal);
    } catch (Throwable $exception) {
        throw new DomainException('El PDF de firma está dañado, protegido con contraseña o no es legible.', 0, $exception);
    }
    if ($paginas < 1) {
        throw new DomainException('El archivo PDF de firma no contiene páginas legibles.');
    }
}

function guardarPdfFirmado(array $archivo, string $subdirectorio, int $maxBytes = MAX_PDF_FIRMADO_BYTES): string
{
    $errorArchivo = (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorArchivo === UPLOAD_ERR_NO_FILE) {
        throw new DomainException('Selecciona el archivo PDF de firma.');
    }
    if (in_array($errorArchivo, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        throw new DomainException('El PDF de firma no puede superar 1 MB.');
    }
    if ($errorArchivo !== UPLOAD_ERR_OK
        || !is_string($archivo['tmp_name'] ?? null)
        || !is_string($archivo['name'] ?? null)
        || !is_uploaded_file($archivo['tmp_name'])) {
        throw new DomainException('No fue posible recibir el archivo PDF de firma.');
    }

    validarPdfFirmado($archivo['tmp_name'], $archivo['name'], $maxBytes);
    if (!preg_match('#\A[a-z0-9_/-]+\z#', $subdirectorio) || str_contains($subdirectorio, '..')) {
        throw new InvalidArgumentException('El directorio de almacenamiento PDF no es válido.');
    }

    $directorio = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, trim($subdirectorio, '/'));
    if (!is_dir($directorio) && !mkdir($directorio, 0750, true) && !is_dir($directorio)) {
        throw new RuntimeException('No fue posible preparar el almacenamiento privado de firmas.');
    }
    $nombreGuardado = bin2hex(random_bytes(24)) . '.pdf';
    if (!move_uploaded_file($archivo['tmp_name'], $directorio . DIRECTORY_SEPARATOR . $nombreGuardado)) {
        throw new RuntimeException('No fue posible guardar el PDF de firma.');
    }
    return 'storage/' . trim($subdirectorio, '/') . '/' . $nombreGuardado;
}

function convertirFirmaPdfAPng(string $rutaPdfOrigen, string $rutaPngDestino): void
{
    if (!extension_loaded('imagick')) {
        convertirFirmaPdfAPngConGhostscript($rutaPdfOrigen, $rutaPngDestino);
        return;
    }

    $imagen = null;
    try {
        $imagen = new \Imagick();
        $imagen->setResolution(300, 300);
        $imagen->readImage($rutaPdfOrigen . '[0]');
        $imagen->trimImage(0);
        $imagen->setImageFormat('png');
        if (!$imagen->writeImage($rutaPngDestino)
            || !is_file($rutaPngDestino)
            || filesize($rutaPngDestino) <= 0) {
            throw new RuntimeException('No fue posible guardar la firma convertida a PNG.');
        }
    } catch (Throwable $exception) {
        if (is_file($rutaPngDestino) && !unlink($rutaPngDestino)) {
            throw new RuntimeException('Falló la conversión y no fue posible limpiar el PNG parcial.', 0, $exception);
        }
        throw new DomainException(
            'No fue posible procesar el PDF de firma. Verifica que no esté dañado ni protegido con contraseña e inténtalo de nuevo.',
            0,
            $exception
        );
    } finally {
        if ($imagen instanceof \Imagick) {
            try {
                $imagen->clear();
            } finally {
                $imagen->destroy();
            }
        }
    }
}

function convertirFirmaPdfAPngConGhostscript(string $rutaPdfOrigen, string $rutaPngDestino): void
{
    if (!function_exists('exec')) {
        throw new DomainException('Ghostscript requiere que la función exec() de PHP esté habilitada.');
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $ejecutables = glob('C:\\Program Files\\gs\\*\\bin\\gswin64c.exe');
        if ($ejecutables === false) {
            $ejecutables = [];
        }
        $ejecutables = array_values(array_filter($ejecutables, 'is_file'));
        rsort($ejecutables, SORT_NATURAL);
        if (!$ejecutables) {
            throw new DomainException('No se encontró Ghostscript en C:\\Program Files\\gs\\*\\bin\\gswin64c.exe.');
        }
        $gsBin = $ejecutables[0];
    } else {
        $gsBin = 'gs';
    }

    $comando = escapeshellarg($gsBin)
        . ' -dSAFER -dBATCH -dNOPAUSE -sDEVICE=png16m -r300 -dFirstPage=1 -dLastPage=1'
        . ' -sOutputFile=' . escapeshellarg($rutaPngDestino)
        . ' ' . escapeshellarg($rutaPdfOrigen)
        . ' 2>&1';
    $salida = [];
    $codigoSalida = 0;
    exec($comando, $salida, $codigoSalida);

    if ($codigoSalida !== 0) {
        throw new DomainException(
            'No fue posible convertir el PDF de firma con Ghostscript. Comprueba que no esté dañado ni protegido con contraseña.'
        );
    }
    $tamanoPng = is_file($rutaPngDestino) ? filesize($rutaPngDestino) : false;
    if ($tamanoPng === false || $tamanoPng <= 0) {
        throw new DomainException('Ghostscript no generó un archivo PNG válido para la firma.');
    }
}

function guardarFirmaPdfYConvertirPng(
    array $archivo,
    string $subdirectorio,
    int $maxBytes = MAX_PDF_FIRMADO_BYTES
): array {
    $rutaPdfRelativa = guardarPdfFirmado($archivo, $subdirectorio, $maxBytes);
    $rutaPngRelativa = substr($rutaPdfRelativa, 0, -4) . '.png';
    $rutaPdfAbsoluta = null;
    $rutaPngAbsoluta = null;

    try {
        $rutaPdfAbsoluta = rutaPdfFirmado($rutaPdfRelativa, $subdirectorio);
        $rutaPngAbsoluta = dirname($rutaPdfAbsoluta) . DIRECTORY_SEPARATOR . basename($rutaPngRelativa);
        convertirFirmaPdfAPng($rutaPdfAbsoluta, $rutaPngAbsoluta);
    } catch (Throwable $exception) {
        foreach ([$rutaPngAbsoluta, $rutaPdfAbsoluta] as $ruta) {
            if (is_string($ruta) && is_file($ruta) && !unlink($ruta)) {
                throw new RuntimeException('No fue posible limpiar los archivos de firma tras cancelar el procesamiento.', 0, $exception);
            }
        }
        throw $exception;
    }

    return [
        'pdf_relativa' => $rutaPdfRelativa,
        'png_relativa' => $rutaPngRelativa,
        'pdf_absoluta' => $rutaPdfAbsoluta,
        'png_absoluta' => $rutaPngAbsoluta,
    ];
}

function rutaPdfFirmado(string $rutaRelativa, string $subdirectorio): string
{
    $prefijo = 'storage/' . trim($subdirectorio, '/') . '/';
    if (!str_starts_with($rutaRelativa, $prefijo) || str_contains($rutaRelativa, '..')) {
        throw new RuntimeException('La ruta de firma guardada no es válida.');
    }
    $raizStorage = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
    $directorio = realpath($raizStorage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, trim($subdirectorio, '/')));
    $ruta = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rutaRelativa));
    if ($ruta === false || $directorio === false || !str_starts_with($ruta, $directorio . DIRECTORY_SEPARATOR) || !is_file($ruta)) {
        throw new RuntimeException('No se encontró un archivo de firma requerido.');
    }
    return $ruta;
}

function pdfTexto(string $texto): string
{
    $convertido = iconv('UTF-8', 'windows-1252//TRANSLIT', $texto);
    if ($convertido === false) {
        throw new RuntimeException('No fue posible convertir el texto para el PDF.');
    }
    return $convertido;
}

function pdfAnexarFirmado(\setasign\Fpdi\Fpdi $pdf, string $ruta, string $etiqueta): void
{
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->MultiCell(0, 8, pdfTexto($etiqueta), 0, 'C');
    $paginas = $pdf->setSourceFile($ruta);
    for ($pagina = 1; $pagina <= $paginas; $pagina++) {
        $plantilla = $pdf->importPage($pagina);
        $tamano = $pdf->getTemplateSize($plantilla);
        $pdf->AddPage($tamano['orientation'], [$tamano['width'], $tamano['height']]);
        $pdf->useTemplate($plantilla, 0, 0, $tamano['width'], $tamano['height']);
    }
}
