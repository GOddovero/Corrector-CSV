<?php
declare(strict_types=1);

// Verificación local byte-a-byte contra fixtures del repo.
// Uso (PowerShell): php verify.php

require __DIR__ . '/index.php';

function transform_file_to_string(string $inputPath): string {
    $tmpOut = fopen('php://temp', 'w+b');
    if ($tmpOut === false) {
        throw new RuntimeException('No se pudo abrir php://temp');
    }
    process_uploaded_csv($inputPath, $tmpOut);
    rewind($tmpOut);
    $data = stream_get_contents($tmpOut);
    fclose($tmpOut);
    if ($data === false) {
        throw new RuntimeException('No se pudo leer output.');
    }
    return $data;
}

$cases = [
    [
        'in' => __DIR__ . '/nuevos/comprobantes_periodo_202510_compras_20251218_2001 (montos expresados en pesos)_formato_nuevo.csv',
        'expected' => __DIR__ . '/comprobantes_periodo_202510_compras_20251218_2001 (montos expresados en pesos)_formato_nuevo_arreglado.csv',
    ],
    [
        'in' => __DIR__ . '/estos_andan/comprobantes_periodo_202510_compras_20251107_1027 (montos expresados en pesos)_formato_viejo.csv',
        'expected' => __DIR__ . '/estos_andan/comprobantes_periodo_202510_compras_20251107_1027 (montos expresados en pesos)_arreglado.csv',
    ],
];

$ok = true;
foreach ($cases as $case) {
    $in = $case['in'];
    $expected = $case['expected'];

    if (!is_file($in) || !is_file($expected)) {
        fwrite(STDERR, "Missing fixture: $in or $expected\n");
        $ok = false;
        continue;
    }

    $actual = transform_file_to_string($in);
    $expectedBytes = file_get_contents($expected);
    if ($expectedBytes === false) {
        fwrite(STDERR, "Could not read expected: $expected\n");
        $ok = false;
        continue;
    }

    $ha = hash('sha256', $actual);
    $he = hash('sha256', $expectedBytes);

    if ($ha !== $he) {
        fwrite(STDERR, "FAIL\n  in: $in\n  expected: $expected\n  sha256(actual):   $ha\n  sha256(expected): $he\n\n");
        $ok = false;
    } else {
        fwrite(STDOUT, "OK  $in\n");
    }
}

exit($ok ? 0 : 1);
