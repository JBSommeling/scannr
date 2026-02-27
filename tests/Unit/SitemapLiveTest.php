<?php

namespace Tests\Unit;

use App\Services\SitemapService;
use PHPUnit\Framework\TestCase;

class SitemapLiveTest extends TestCase
{
    public function test_sommeling_sitemap_can_be_discovered(): void
    {
        $service = new SitemapService();
        $service->setBaseUrl('https://www.sommeling.dev');

        // Test getSitemapsFromRobots
        $sitemaps = $service->getSitemapsFromRobots();
        $this->assertNotEmpty($sitemaps, 'Should find sitemap in robots.txt');
        $this->assertStringContainsString('sitemap.xml', $sitemaps[0]);

        // Test parseSitemap
        $urls = $service->parseSitemap($sitemaps[0]);
        $this->assertNotEmpty($urls, 'Should parse URLs from sitemap');

        // Test that sommeling.dev URLs are considered internal for www.sommeling.dev
        foreach (array_slice($urls, 0, 3) as $url) {
            $this->assertTrue(
                $service->isInternalUrl($url),
                "URL $url should be considered internal for www.sommeling.dev"
            );
        }

        // Test full discoverUrls
        $result = $service->discoverUrls('https://www.sommeling.dev');
        $this->assertGreaterThan(0, $result['count'], 'Should discover URLs from sitemap');
    }
}

