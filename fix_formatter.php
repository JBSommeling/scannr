<?php
// fix_formatter.php
$file = __DIR__ . '/app/Services/ResultFormatterService.php';
$f = file_get_contents($file);
$f = str_replace('$this->scannerService->', '$this->scanStatistics->', $f);
file_put_contents($file, $f);
echo "Done: " . substr_count($f, 'scanStatistics') . " refs\n";
echo "Remaining scannerService: " . substr_count($f, 'scannerService') . "\n";

