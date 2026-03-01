<?php
// fix_crawler_test.php
$file = __DIR__ . '/tests/Unit/CrawlerServiceTest.php';
$f = file_get_contents($file);

// Add imports
$f = str_replace(
    "use App\Services\ScannerService;\nuse App\Services\SitemapService;",
    "use App\Services\HttpChecker;\nuse App\Services\LinkExtractor;\nuse App\Services\ScannerService;\nuse App\Services\ScanStatistics;\nuse App\Services\SitemapService;\nuse App\Services\UrlNormalizer;",
    $f
);

// Replace instantiation pattern:
// $scannerService = new ScannerService();
// $sitemapService = new SitemapService();
// $crawler = new CrawlerService($scannerService, $sitemapService);
$f = str_replace(
    '$scannerService = new ScannerService();' . "\n" . '        $sitemapService = new SitemapService();' . "\n" . '        $crawler = new CrawlerService($scannerService, $sitemapService);',
    '$urlNormalizer = new UrlNormalizer();' . "\n" .
    '        $httpChecker = new HttpChecker($urlNormalizer);' . "\n" .
    '        $linkExtractor = new LinkExtractor($urlNormalizer, $httpChecker);' . "\n" .
    '        $scanStatistics = new ScanStatistics();' . "\n" .
    '        $scannerService = new ScannerService($httpChecker, $linkExtractor, $urlNormalizer, $scanStatistics);' . "\n" .
    '        $sitemapService = new SitemapService(null, $urlNormalizer);' . "\n" .
    '        $crawler = new CrawlerService($scannerService, $urlNormalizer, $httpChecker, $sitemapService);',
    $f
);

file_put_contents($file, $f);

// Verify
$remaining = substr_count($f, 'new ScannerService()');
$crawlerCalls = substr_count($f, 'new CrawlerService($scannerService, $sitemapService)');
echo "Remaining new ScannerService(): {$remaining}\n";
echo "Remaining old CrawlerService constructor: {$crawlerCalls}\n";
echo "New CrawlerService calls: " . substr_count($f, 'new CrawlerService($scannerService, $urlNormalizer') . "\n";

