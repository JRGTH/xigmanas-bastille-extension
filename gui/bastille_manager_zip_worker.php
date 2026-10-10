<?php
if ($argc < 2 || !file_exists($argv[1])) {
    exit(1);
}

[$src, $dst_wip, $dst_done, $base] = json_decode(file_get_contents($argv[1]), true);

unlink($argv[1]);

$zip = new ZipArchive();
if ($zip->open($dst_wip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) exit(1);

if (is_dir($src)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        $fp  = $f->getRealPath();
        $rel = substr($fp, strlen($src) + 1);
        is_dir($fp) ? $zip->addEmptyDir($rel) : $zip->addFile($fp, $rel);
    }
} else {
    $zip->addFile($src, $base);
}

$zip->close();
rename($dst_wip, $dst_done);