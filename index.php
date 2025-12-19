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
  <title>Corrector CSV | GOH</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #4f46e5; /* Indigo 600 */
      --primary-hover: #4338ca; /* Indigo 700 */
      --bg-gradient: linear-gradient(135deg, #e0e7ff 0%, #f3f4f6 100%);
      --card-bg: rgba(255, 255, 255, 0.95);
      --text-main: #111827;
      --text-secondary: #6b7280;
      --border-color: #e5e7eb;
      --error-bg: #fef2f2;
      --error-text: #991b1b;
      --error-border: #f87171;
    }

    body {
      font-family: 'Inter', sans-serif;
      background: var(--bg-gradient);
      color: var(--text-main);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      margin: 0;
      padding: 20px;
    }

    .container {
      width: 100%;
      max-width: 500px;
      display: flex;
      flex-direction: column;
      align-items: center;
    }

    .card {
      background: var(--card-bg);
      padding: 3rem 2rem;
      border-radius: 1.5rem;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15);
      width: 100%;
      text-align: center;
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.5);
      transition: transform 0.3s ease;
    }

    h1 {
      font-size: 1.75rem;
      font-weight: 700;
      color: var(--text-main);
      margin-bottom: 0.5rem;
      margin-top: 0;
      letter-spacing: -0.025em;
    }

    .subtitle {
      color: var(--text-secondary);
      font-size: 0.95rem;
      margin-bottom: 2rem;
    }

    /* Drag & Drop Zone */
    .upload-area {
      border: 2px dashed #cbd5e1;
      border-radius: 1rem;
      padding: 2.5rem 1.5rem;
      cursor: pointer;
      transition: all 0.2s ease;
      background: #f8fafc;
      position: relative;
      overflow: hidden;
    }

    .upload-area:hover, .upload-area.dragover {
      border-color: var(--primary);
      background: #eef2ff;
    }

    .upload-area input[type="file"] {
      position: absolute;
      width: 100%;
      height: 100%;
      top: 0;
      left: 0;
      opacity: 0;
      cursor: pointer;
    }

    .upload-icon {
      width: 48px;
      height: 48px;
      color: #94a3b8;
      margin-bottom: 1rem;
      transition: color 0.2s;
    }

    .upload-area:hover .upload-icon {
      color: var(--primary);
    }

    .upload-text {
      font-weight: 500;
      color: var(--text-main);
      margin-bottom: 0.25rem;
    }

    .upload-hint {
      font-size: 0.85rem;
      color: var(--text-secondary);
    }

    /* Button (Hidden by default if auto-submit, but good to have for fallback or status) */
    .status-btn {
      margin-top: 1.5rem;
      background-color: var(--primary);
      color: white;
      border: none;
      padding: 0.75rem 1.5rem;
      border-radius: 0.5rem;
      font-weight: 600;
      width: 100%;
      cursor: default;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.3s;
    }
    
    .status-btn.loading {
      opacity: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
    }

    /* Error Box */
    .error-message {
      background-color: var(--error-bg);
      border: 1px solid var(--error-border);
      color: var(--error-text);
      padding: 1rem;
      border-radius: 0.75rem;
      margin-bottom: 1.5rem;
      text-align: left;
      font-size: 0.9rem;
      display: none;
      animation: slideDown 0.3s ease-out;
    }

    @keyframes slideDown {
      from { opacity: 0; transform: translateY(-10px); }
      to { opacity: 1; transform: translateY(0); }
    }

    /* Footer & Credits */
    .footer-note {
      margin-top: 2rem;
      font-size: 0.8rem;
      color: var(--text-secondary);
      text-align: center;
      line-height: 1.5;
      max-width: 400px;
    }

    .credits {
      position: fixed;
      bottom: 1.5rem;
      left: 0;
      width: 100%;
      display: flex;
      justify-content: center;
      z-index: 50;
      pointer-events: none;
    }

    .credits a {
      pointer-events: auto;
      text-decoration: none;
      color: var(--text-main);
      font-weight: 600;
      font-size: 0.9rem;
      display: flex;
      flex-direction: row;
      align-items: center;
      gap: 0.75rem;
      transition: all 0.2s ease;
      background: rgba(255, 255, 255, 0.6);
      padding: 0.5rem 1.25rem;
      border-radius: 2rem;
      backdrop-filter: blur(8px);
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.5);
    }

    .credits a:hover {
      transform: translateY(-2px);
      background: rgba(255, 255, 255, 0.9);
      box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    }

    .credits img {
      height: 28px;
      width: auto;
      object-fit: contain;
    }
    
    /* Spinner */
    .spinner {
      width: 18px;
      height: 18px;
      border: 2px solid #ffffff;
      border-bottom-color: transparent;
      border-radius: 50%;
      display: inline-block;
      box-sizing: border-box;
      animation: rotation 1s linear infinite;
    }

    @keyframes rotation {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
  </style>
</head>
<body>

<div class="container">
  <div class="card">
    <h1>Corrector CSV</h1>
    <p class="subtitle">Sube tu archivo para corregir el formato automáticamente</p>
    
    <div id="error-box" class="error-message">
      <strong>⚠️ Error detectado:</strong>
      <span id="error-text"></span>
    </div>

    <form id="uploadForm" method="post" enctype="multipart/form-data">
      <div class="upload-area" id="dropZone">
        <svg class="upload-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
        </svg>
        <div class="upload-text">Haz clic o arrastra tu archivo aquí</div>
        <div class="upload-hint">Soporta archivos .CSV</div>
        <input type="file" name="csv" id="csvInput" accept=".csv,text/csv" required />
      </div>
      
      <button type="button" class="status-btn" id="statusBtn">
        <span class="spinner"></span> Procesando...
      </button>
    </form>
  </div>

  <div class="footer-note">
    Por seguridad y transparencia ningún archivo se guarda en el servidor.
    El procesamiento se realiza en tiempo real y el resultado se descarga automáticamente.
  </div>
  
  <div class="credits">
    <a href="https://goh-dev.com.ar/" target="_blank" rel="noopener noreferrer">
      <span>Desarrollado por GOH</span>
      <img src="png1.png" alt="Logo GOH" />
    </a>
  </div>
</div>

<script>
  const dropZone = document.getElementById('dropZone');
  const fileInput = document.getElementById('csvInput');
  const form = document.getElementById('uploadForm');
  const errorBox = document.getElementById('error-box');
  const errorText = document.getElementById('error-text');
  const statusBtn = document.getElementById('statusBtn');

  // Drag & Drop visual effects
  ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
    dropZone.addEventListener(eventName, preventDefaults, false);
  });

  function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
  }

  ['dragenter', 'dragover'].forEach(eventName => {
    dropZone.addEventListener(eventName, highlight, false);
  });

  ['dragleave', 'drop'].forEach(eventName => {
    dropZone.addEventListener(eventName, unhighlight, false);
  });

  function highlight(e) {
    dropZone.classList.add('dragover');
  }

  function unhighlight(e) {
    dropZone.classList.remove('dragover');
  }

  dropZone.addEventListener('drop', handleDrop, false);

  function handleDrop(e) {
    const dt = e.dataTransfer;
    const files = dt.files;
    fileInput.files = files;
    handleFiles(files);
  }

  fileInput.addEventListener('change', function() {
    handleFiles(this.files);
  });

  function handleFiles(files) {
    if (files.length > 0) {
      uploadFile(files[0]);
    }
  }

  function uploadFile(file) {
    // UI Loading State
    statusBtn.classList.add('loading');
    errorBox.style.display = 'none';
    
    const formData = new FormData(form);
    // Ensure the file from drag/drop is in the formData if not set by input
    if (!fileInput.value && file) {
        formData.set('csv', file);
    }

    fetch('', {
      method: 'POST',
      body: formData
    })
    .then(async response => {
      if (!response.ok) {
        const text = await response.text();
        throw new Error(text || `Error ${response.status}`);
      }
      return response.blob().then(blob => ({ blob, filename: getFilename(response) }));
    })
    .then(({ blob, filename }) => {
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename || 'archivo_arreglado.csv';
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
      
      // Reset UI after short delay
      setTimeout(() => {
        statusBtn.classList.remove('loading');
        fileInput.value = ''; 
      }, 1000);
    })
    .catch(err => {
      console.error(err);
      errorText.textContent = err.message;
      errorBox.style.display = 'block';
      statusBtn.classList.remove('loading');
      fileInput.value = '';
    });
  }

  function getFilename(response) {
    const disposition = response.headers.get('Content-Disposition');
    if (disposition && disposition.indexOf('attachment') !== -1) {
      const filenameRegex = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/;
      const matches = filenameRegex.exec(disposition);
      if (matches != null && matches[1]) { 
        return matches[1].replace(/['"]/g, '');
      }
    }
    return 'archivo_arreglado.csv';
  }
</script>

</body>
</html>
