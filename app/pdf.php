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
        throw new DomainException('El archivo de firma no es un PDF válido o legible.', 0, $exception);
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
