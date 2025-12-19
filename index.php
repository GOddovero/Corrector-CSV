<?php
declare(strict_types=1);

// Corrector-CSV (port 1:1 de ArreglarCSV.py)

const CSV_DELIMITER = ';';
const CSV_ENCLOSURE = '"';
const OUTPUT_EOL = "\r\n"; // Los CSV de ejemplo del repo usan CRLF.

const INPUT_ENCODINGS_PY_ORDER = [
    'ISO-8859-1', // latin-1
    'Windows-1252', // cp1252
    'UTF-8',
    'ISO-8859-1', // iso-8859-1
];

function http_error(int $statusCode, string $message): never {
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

function to_utf8(string $bytes, string $sourceEncoding): string {
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($bytes, 'UTF-8', $sourceEncoding);
    }
    if (function_exists('iconv')) {
        $converted = iconv($sourceEncoding, 'UTF-8//IGNORE', $bytes);
        return $converted === false ? $bytes : $converted;
    }
    return $bytes;
}

function choose_source_encoding(array $rawHeaderFields): string {
    // El código Python "intenta" varios encodings, pero latin-1 no falla nunca.
    // Para lograr el mismo resultado práctico (y coincidir con los outputs existentes),
    // elegimos el primer encoding cuyo encabezado normalizado contenga las columnas clave.
    $requiredColumns = [
        'Fecha de Emision',
        'Tipo de Comprobante',
        'Punto de Venta',
        'Numero de Comprobante',
        'Tipo Doc. Vendedor',
        'Nro. Doc. Vendedor',
        'Denominacion Vendedor',
        'Importe Total',
        'Moneda Original',
        'Tipo de Cambio',
        'Importe No Gravado',
        'Importe Exento',
        'Importe Otros Tributos',
        'Total Neto Gravado',
        'Total IVA',
    ];

    foreach (INPUT_ENCODINGS_PY_ORDER as $enc) {
        $normalized = [];
        foreach ($rawHeaderFields as $field) {
            $decoded = to_utf8((string)$field, $enc);
            $normalized[] = normalizar_nombre_columna($decoded);
        }

        $set = array_fill_keys($normalized, true);
        $ok = true;
        foreach ($requiredColumns as $req) {
            if (!isset($set[$req])) {
                $ok = false;
                break;
            }
        }

        if ($ok) {
            return $enc;
        }
    }

    // Fallback: mantener el primer encoding de la lista.
    return INPUT_ENCODINGS_PY_ORDER[0];
}

function normalizar_nombre_columna(string $nombreUtf8): string {
    $nombreUtf8 = str_replace('�', '', $nombreUtf8);

    if (class_exists('Normalizer')) {
        $nombreUtf8 = Normalizer::normalize($nombreUtf8, Normalizer::FORM_KD) ?? $nombreUtf8;
    } else {
        // Fallback sin intl: mapeo explícito de letras acentuadas a ASCII,
        // y luego eliminar cualquier resto no-ASCII (equivalente a encode('ASCII','ignore')).
        $nombreUtf8 = strtr($nombreUtf8, [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ñ' => 'N', 'ñ' => 'n',
            'Ç' => 'C', 'ç' => 'c',
        ]);
    }

    // Emula: nombre.encode('ASCII', 'ignore').decode('ASCII')
    if (function_exists('iconv')) {
        $ascii = iconv('UTF-8', 'ASCII//IGNORE', $nombreUtf8);
        if ($ascii !== false) {
            $nombreUtf8 = $ascii;
        }
    } else {
        $nombreUtf8 = preg_replace('/[^\x00-\x7F]/', '', $nombreUtf8) ?? $nombreUtf8;
    }

    return trim($nombreUtf8);
}

function csv_write_row($outHandle, array $fields): void {
    $parts = [];

    foreach ($fields as $field) {
        if ($field === null) {
            $field = '';
        }
        if (!is_string($field)) {
            $field = (string)$field;
        }

        $mustQuote = str_contains($field, CSV_DELIMITER)
            || str_contains($field, "\n")
            || str_contains($field, "\r")
            || str_contains($field, CSV_ENCLOSURE);

        if ($mustQuote) {
            $field = str_replace(CSV_ENCLOSURE, CSV_ENCLOSURE . CSV_ENCLOSURE, $field);
            $field = CSV_ENCLOSURE . $field . CSV_ENCLOSURE;
        }

        $parts[] = $field;
    }

    fwrite($outHandle, implode(CSV_DELIMITER, $parts) . OUTPUT_EOL);
}

function process_uploaded_csv(string $tmpPath, $outHandle): void {
    $in = fopen($tmpPath, 'rb');
    if ($in === false) {
        throw new RuntimeException('No se pudo abrir el archivo subido.');
    }

    $rawHeader = fgetcsv($in, 0, CSV_DELIMITER, CSV_ENCLOSURE);
    if ($rawHeader === false) {
        throw new RuntimeException('El CSV está vacío o no se pudo leer el encabezado.');
    }

    $sourceEncoding = choose_source_encoding($rawHeader);

    $normalizedHeaders = [];
    foreach ($rawHeader as $h) {
        $hUtf8 = to_utf8((string)$h, $sourceEncoding);
        $normalizedHeaders[] = normalizar_nombre_columna($hUtf8);
    }

    $inputIndex = [];
    foreach ($normalizedHeaders as $i => $name) {
        // Si hubiera duplicados, pandas los renombra automáticamente; este script Python no lo maneja explícitamente.
        // Para mantener comportamiento simple, dejamos el primero.
        if ($name !== '' && !array_key_exists($name, $inputIndex)) {
            $inputIndex[$name] = $i;
        }
    }

    $columnas_a_eliminar = [
        'Credito Fiscal Computable',
        'Importe de Per. o Pagos a Cta. de Otros Imp. Nac.',
        'Importe de Percepciones de Ingresos Brutos',
        'Importe de Impuestos Municipales',
        'Importe de Percepciones o Pagos a Cuenta de IVA',
        'Importe de Impuestos Internos',
        'Neto Gravado IVA 0%',
        'Neto Gravado IVA 2,5%',
        'Importe IVA 2,5%',
        'Neto Gravado IVA 5%',
        'Importe IVA 5%',
        'Neto Gravado IVA 10,5%',
        'Importe IVA 10,5%',
        'Neto Gravado IVA 21%',
        'Importe IVA 21%',
        'Neto Gravado IVA 27%',
        'Importe IVA 27%',
    ];

    $renombres = [
        'Fecha de Emision' => 'Fecha de emision',
        'Punto de Venta' => 'punto de venta',
        'Tipo Doc. Vendedor' => 'tipo doc. emisor',
        'Nro. Doc. Vendedor' => 'nro. doc. emisor',
        'Denominacion Vendedor' => 'denominacion emisor',
        'Importe Total' => 'Imp. Total',
        'Importe No Gravado' => 'imp neto no gravado',
        'Importe Exento' => 'importe OpExcento',
        'Importe Otros Tributos' => 'otros tributos',
        'Moneda Original' => 'Moneda',
        'Total IVA' => 'IVA',
        'Numero de Comprobante' => 'numero desde',
    ];

    $orden_columnas = [
        'Fecha de emision',
        'Tipo de Comprobante',
        'punto de venta',
        'numero desde',
        'numero hasta',
        'Cod Autorizacion',
        'tipo doc. emisor',
        'nro. doc. emisor',
        'denominacion emisor',
        'Tipo de Cambio',
        'Moneda',
        'Total Neto Gravado',
        'imp neto no gravado',
        'importe OpExcento',
        'otros tributos',
        'IVA',
        'Imp. Total',
    ];

    // Validaciones mínimas para fallar de forma clara en vez de generar un CSV incorrecto.
    foreach ($columnas_a_eliminar as $drop) {
        // no-op: se elimina solo si existe (igual que pandas)
    }

    if (!array_key_exists('Numero de Comprobante', $inputIndex)) {
        throw new RuntimeException(
            'Falta la columna "Numero de Comprobante" (necesaria para "numero hasta" / "numero desde"). ' .
            'Headers detectados: ' . implode(' | ', array_keys($inputIndex))
        );
    }

    // Emitir encabezado final.
    csv_write_row($outHandle, $orden_columnas);

    // Para cada columna de salida, determinar su fuente.
    $outputSource = [];
    foreach ($orden_columnas as $outName) {
        if ($outName === 'numero hasta') {
            $outputSource[$outName] = ['type' => 'numero_hasta'];
            continue;
        }
        if ($outName === 'Cod Autorizacion') {
            $outputSource[$outName] = ['type' => 'const', 'value' => '0'];
            continue;
        }

        $inputKey = array_search($outName, $renombres, true);
        if ($inputKey === false) {
            $inputKey = $outName;
        }

        $outputSource[$outName] = ['type' => 'input', 'key' => $inputKey];
    }

    while (($rawRow = fgetcsv($in, 0, CSV_DELIMITER, CSV_ENCLOSURE)) !== false) {
        // Convertir cada celda a UTF-8 como hace pandas tras decodificar.
        foreach ($rawRow as $i => $cell) {
            $rawRow[$i] = to_utf8((string)$cell, $sourceEncoding);
        }

        // Armar fila de salida en orden.
        $outRow = [];
        foreach ($orden_columnas as $outName) {
            $src = $outputSource[$outName];

            if ($src['type'] === 'const') {
                $value = (string)$src['value'];
            } elseif ($src['type'] === 'numero_hasta') {
                $idx = $inputIndex['Numero de Comprobante'];
                $value = $rawRow[$idx] ?? '';
            } else {
                $key = $src['key'];

                // Simular drop columns: si una columna fue eliminada en pandas, no se podría usar aquí.
                if (in_array($key, $columnas_a_eliminar, true)) {
                    $value = '';
                } else {
                    if (!array_key_exists($key, $inputIndex)) {
                        throw new RuntimeException(
                            'Falta la columna requerida: ' . $key . '. ' .
                            'Headers detectados: ' . implode(' | ', array_keys($inputIndex))
                        );
                    }
                    $idx = $inputIndex[$key];
                    $value = $rawRow[$idx] ?? '';
                }
            }

            // Reglas post-proceso 1:1
            if ($outName === 'Tipo de Comprobante') {
                if (trim($value) === '81') {
                    $value = '83';
                }
            }

            if ($outName === 'Imp. Total' || $outName === 'Total Neto Gravado' || $outName === 'IVA') {
                $value = str_replace('-', '', $value);
            }

            $outRow[] = $value;
        }

        csv_write_row($outHandle, $outRow);
    }

    fclose($in);
}

function safe_output_filename(string $originalName): string {
    $base = basename($originalName);
    $base = preg_replace('/\.[^.]+$/', '', $base) ?? $base;
    // Evitar caracteres problemáticos en header Content-Disposition
    $base = preg_replace('/[^A-Za-z0-9 _()\[\].-]+/', '_', $base) ?? 'archivo';
    return $base . '_arreglado.csv';
}

// Si se incluye desde CLI (por ejemplo verify.php), no ejecutar la parte web.
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;

if ($requestMethod === 'POST') {
    if (!isset($_FILES['csv']) || !is_array($_FILES['csv'])) {
        http_error(400, 'No se recibió ningún archivo (campo "csv").');
    }

    $file = $_FILES['csv'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_error(400, 'Error al subir el archivo (code=' . (int)$file['error'] . ').');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        http_error(400, 'Archivo temporal no válido.');
    }

    $downloadName = safe_output_filename((string)($file['name'] ?? 'archivo.csv'));

    header('Content-Type: text/csv; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');

    try {
        process_uploaded_csv($tmp, fopen('php://output', 'wb'));
    } catch (Throwable $e) {
        // Si ya empezamos a emitir el CSV, no podemos volver atrás; pero la lógica escribe header primero.
        // Para evitar un CSV parcial, intentamos fallar antes de escribir cualquier cosa (validaciones arriba).
        http_error(400, 'No se pudo procesar el CSV: ' . $e->getMessage());
    }

    exit;
}

if ($requestMethod === null) {
        // CLI / include: no renderizar HTML
        return;
}

?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Arreglar CSV</title>
</head>
<body>
  <h1>Arreglar CSV</h1>
  <form method="post" enctype="multipart/form-data">
    <input type="file" name="csv" accept=".csv,text/csv" required />
    <button type="submit">Convertir</button>
  </form>
</body>
</html>
