#!/usr/bin/env php
<?php

declare(strict_types=1);

$pluginSlug = 'contifico-woocommerce';
$projectRoot = dirname(__DIR__);
$pluginDir = $projectRoot . '/wp-content/plugins/' . $pluginSlug;
$distDir = $projectRoot . '/dist';
$outputFile = $distDir . '/' . $pluginSlug . '.zip';
$excludedPatterns = [
    '#(^|/)\.git(/|$)#',
    '#(^|/)\.github(/|$)#',
    '#(^|/)node_modules(/|$)#',
    '#(^|/)vendor(/|$)#',
    '#(^|/)\.DS_Store$#',
];

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "La extensión de PHP 'zip' es requerida para generar el paquete.\n");
    exit(1);
}

if (!is_dir($pluginDir)) {
    fwrite(STDERR, "No se encontró el directorio del plugin en {$pluginDir}.\n");
    exit(1);
}

if (!is_dir($distDir) && !mkdir($distDir, 0775, true) && !is_dir($distDir)) {
    fwrite(STDERR, "No fue posible crear el directorio dist en {$distDir}.\n");
    exit(1);
}

if (file_exists($outputFile) && !unlink($outputFile)) {
    fwrite(STDERR, "No fue posible eliminar el paquete existente en {$outputFile}.\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($outputFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "No se pudo crear el archivo ZIP en {$outputFile}.\n");
    exit(1);
}

$zip->addEmptyDir($pluginSlug);

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($pluginDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

/** @var \SplFileInfo $file */
foreach ($iterator as $file) {
    $filePath = $file->getPathname();
    $relativePath = substr($filePath, strlen($pluginDir) + 1);

    if ($relativePath === false || $relativePath === '') {
        continue;
    }

    $normalizedPath = str_replace('\\', '/', $relativePath);

    foreach ($excludedPatterns as $pattern) {
        if (preg_match($pattern, $normalizedPath) === 1) {
            if ($file->isDir()) {
                $iterator->skipChildren();
            }
            continue 2;
        }
    }

    $destinationPath = $pluginSlug . '/' . $normalizedPath;

    if ($file->isDir()) {
        $zip->addEmptyDir($destinationPath);
        continue;
    }

    if (!$zip->addFile($filePath, $destinationPath)) {
        fwrite(STDERR, "No se pudo agregar {$filePath} al paquete ZIP.\n");
        $zip->close();
        exit(1);
    }
}

if (!$zip->close()) {
    fwrite(STDERR, "Error al cerrar el archivo ZIP {$outputFile}.\n");
    exit(1);
}

echo "Paquete generado en {$outputFile}\n";
