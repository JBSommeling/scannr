<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$log = fopen(__DIR__ . '/sitemap_debug.log', 'w');

fwrite($log, "Starting sitemap test\n");

$service = new \App\Services\SitemapService();
$service->setBaseUrl("https://www.sommeling.dev");

$reflection = new ReflectionClass($service);
$prop = $reflection->getProperty("baseHost");
$prop->setAccessible(true);
fwrite($log, "Base host: " . $prop->getValue($service) . "\n");

try {
    $sitemaps = $service->getSitemapsFromRobots();
    fwrite($log, "Sitemaps from robots.txt: " . json_encode($sitemaps) . "\n");

    if (!empty($sitemaps)) {
        $urls = $service->parseSitemap($sitemaps[0]);
        fwrite($log, "URLs from sitemap: " . count($urls) . "\n");
        if (!empty($urls)) {
            fwrite($log, "First 3 URLs: " . json_encode(array_slice($urls, 0, 3)) . "\n");

            // Test isInternalUrl
            foreach (array_slice($urls, 0, 3) as $url) {
                fwrite($log, "isInternalUrl($url): " . ($service->isInternalUrl($url) ? 'true' : 'false') . "\n");
            }
        }
    } else {
        fwrite($log, "No sitemaps found in robots.txt\n");
    }

    // Test full discoverUrls
    fwrite($log, "\nTesting discoverUrls:\n");
    $result = $service->discoverUrls("https://www.sommeling.dev");
    fwrite($log, "Count: " . $result['count'] . "\n");
    if (!empty($result['urls'])) {
        fwrite($log, "First 3: " . json_encode(array_slice($result['urls'], 0, 3)) . "\n");
    }
} catch (Exception $e) {
    fwrite($log, "Error: " . $e->getMessage() . "\n");
    fwrite($log, $e->getTraceAsString() . "\n");
}

fclose($log);
echo "Done - check sitemap_debug.log\n";
