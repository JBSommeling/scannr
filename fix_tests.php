<?php

$file = file_get_contents("tests/Unit/CrawlerServiceTest.php");

// Pattern matches the 7-line service instantiation block
$pattern = '/\$urlNormalizer = new UrlNormalizer\(\);\s+\$httpChecker = new HttpChecker\(\$urlNormalizer\);\s+\$linkExtractor = new LinkExtractor\(\$urlNormalizer, \$httpChecker\);\s+\$scanStatistics = new ScanStatistics\(\);\s+\$scannerService = new ScannerService\(\$httpChecker, \$linkExtractor, \$urlNormalizer, \$scanStatistics\);\s+\$sitemapService = new SitemapService\(null, \$urlNormalizer\);\s+\$crawler = new CrawlerService\(\$scannerService, \$urlNormalizer, \$httpChecker, \$sitemapService\);/';

$replacement = '$services = $this->createServices();
        $crawler = new CrawlerService($services[\'scannerService\'], $services[\'urlNormalizer\'], $services[\'httpChecker\'], $services[\'sitemapService\']);';

$count = 0;
$result = preg_replace($pattern, $replacement, $file, -1, $count);

echo "Replacements made: $count\n";

if ($count > 0) {
    file_put_contents("tests/Unit/CrawlerServiceTest.php", $result);
    echo "File updated.\n";
} else {
    echo "No replacements made.\n";
}

