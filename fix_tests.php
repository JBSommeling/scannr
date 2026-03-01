<?php
// fix_tests.php - Run with: php fix_tests.php
$file = __DIR__ . '/tests/Unit/ScannerServiceTest.php';
$f = file_get_contents($file);

if ($f === false) {
    echo "ERROR: Cannot read file\n";
    exit(1);
}

$replacements = [
    // UrlNormalizer methods
    '$this->service->normalizeUrl' => '$this->urlNormalizer->normalizeUrl',
    '$this->service->resolveRedirectUrl' => '$this->urlNormalizer->resolveRedirectUrl',
    '$this->service->canonicalUrlKey' => '$this->urlNormalizer->canonicalUrlKey',
    '$this->service->isInternalUrl' => '$this->urlNormalizer->isInternalUrl',
    '$this->service->setBaseUrl' => '$this->urlNormalizer->setBaseUrl',
    '$this->service->getBaseUrl' => '$this->urlNormalizer->getBaseUrl',
    '$this->service->getBaseHost' => '$this->urlNormalizer->getBaseHost',
    '$this->service->setTrackingParams' => '$this->urlNormalizer->setTrackingParams',
    '$this->service->addTrackingParams' => '$this->urlNormalizer->addTrackingParams',
    '$this->service->getTrackingParams' => '$this->urlNormalizer->getTrackingParams',
    '$this->service->stripTrackingParams' => '$this->urlNormalizer->stripTrackingParams',
    // ScanStatistics methods
    '$this->service->filterResults' => '$this->scanStatistics->filterResults',
    '$this->service->filterByElement' => '$this->scanStatistics->filterByElement',
    '$this->service->calculateStats' => '$this->scanStatistics->calculateStats',
    // LinkExtractor methods
    '$this->service->extractLinks' => '$this->linkExtractor->extractLinks',
    // HttpChecker methods
    '$this->service->followRedirects' => '$this->httpChecker->followRedirects',
    '$this->service->setMaxRedirects' => '$this->httpChecker->setMaxRedirects',
    '$this->service->checkUrl' => '$this->httpChecker->checkUrl',
    // setClient goes to httpChecker (ScannerService.setClient delegates anyway)
    '$this->service->setClient' => '$this->httpChecker->setClient',
];

$count = 0;
foreach ($replacements as $old => $new) {
    $c = substr_count($f, $old);
    if ($c > 0) {
        $f = str_replace($old, $new, $f);
        $count += $c;
    }
}

// Fix the user-agent test that creates ScannerService directly
// ScannerService no longer has a 'client' property - HttpChecker does
$f = str_replace(
    '$service = new ScannerService();

        $reflection = new \\ReflectionClass($service);
        $clientProperty = $reflection->getProperty(\'client\');',
    '$service = new HttpChecker(new UrlNormalizer());

        $reflection = new \\ReflectionClass($service);
        $clientProperty = $reflection->getProperty(\'client\');',
    $f
);

file_put_contents($file, $f);
echo "Done: {$count} replacements\n";

// Verify remaining $this->service-> calls
preg_match_all('/\$this->service->(\w+)/', $f, $matches);
$remaining = array_count_values($matches[1]);
ksort($remaining);
echo "Remaining \$this->service-> calls:\n";
foreach ($remaining as $method => $cnt) {
    echo "  {$method}: {$cnt}\n";
}

