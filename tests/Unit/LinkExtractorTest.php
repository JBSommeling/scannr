<?php

namespace Tests\Unit;

use App\Services\HttpChecker;
use App\Services\LinkExtractor;
use App\Services\LinkFlagService;
use App\Services\SeverityEvaluator;
use App\Services\UrlNormalizer;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class LinkExtractorTest extends TestCase
{
    private LinkExtractor $linkExtractor;
    private UrlNormalizer $urlNormalizer;
    private HttpChecker $httpChecker;
    private LinkFlagService $linkFlagService;
    private SeverityEvaluator $severityEvaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->urlNormalizer = new UrlNormalizer();
        $this->severityEvaluator = new SeverityEvaluator();
        $this->linkFlagService = new LinkFlagService($this->urlNormalizer, $this->severityEvaluator);
        $this->httpChecker = new HttpChecker($this->urlNormalizer, $this->linkFlagService);
        $this->linkExtractor = new LinkExtractor($this->urlNormalizer, $this->httpChecker, $this->linkFlagService);
    }

    /**
     * Create a mock HTTP client with a predefined response.
     */
    private function createMockClient(int $statusCode, string $body = '', array $headers = []): Client
    {
        $mockStream = $this->createMock(StreamInterface::class);
        $mockStream->method('__toString')->willReturn($body);

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn($statusCode);
        $mockResponse->method('getBody')->willReturn($mockStream);
        $mockResponse->method('getHeaderLine')->willReturnCallback(function ($name) use ($headers) {
            return $headers[$name] ?? '';
        });

        $mockClient = $this->createMock(Client::class);
        $mockClient->method('request')->willReturn($mockResponse);

        return $mockClient;
    }
    // extractLinks tests
    // ======================

    public function test_extract_links_finds_href_links(): void
    {
        $html = '<html><body><a href="https://example.com/page1">Link 1</a><a href="/page2">Link 2</a></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(2, $links);
    }

    public function test_extract_links_skips_javascript_links(): void
    {
        $html = '<html><body><a href="javascript:void(0)">JS Link</a><a href="https://example.com/page">Normal</a></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/page', $links[0]['url']);
    }

    public function test_extract_links_skips_mailto_links(): void
    {
        $html = '<html><body><a href="mailto:test@example.com">Email</a><a href="/page">Page</a></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
    }

    public function test_extract_links_skips_tel_links(): void
    {
        $html = '<html><body><a href="tel:1234567890">Phone</a><a href="/page">Page</a></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
    }

    public function test_extract_links_skips_fragment_only_links(): void
    {
        $html = '<html><body><a href="#section">Anchor</a><a href="/page">Page</a></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
    }

    public function test_extract_links_normalizes_relative_urls(): void
    {
        $html = '<html><body><a href="/page">Link</a></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/page', $links[0]['url']);
    }

    public function test_extract_links_includes_source(): void
    {
        $html = '<html><body><a href="/page">Link</a></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com/source');

        $this->assertEquals('https://example.com/source', $links[0]['source']);
    }

    public function test_extract_links_finds_link_href(): void
    {
        $html = '<html><head><link href="/css/style.css" rel="stylesheet"></head><body></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/css/style.css', $links[0]['url']);
        $this->assertEquals('link', $links[0]['element']);
    }

    public function test_extract_links_finds_script_src(): void
    {
        $html = '<html><head><script src="/js/app.js"></script></head><body></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/js/app.js', $links[0]['url']);
        $this->assertEquals('script', $links[0]['element']);
    }

    public function test_extract_links_finds_img_src(): void
    {
        $html = '<html><body><img src="/images/logo.png" alt="Logo"></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/images/logo.png', $links[0]['url']);
        $this->assertEquals('img', $links[0]['element']);
    }

    public function test_extract_links_finds_img_srcset(): void
    {
        $html = '<html><body><img srcset="/images/logo-320w.jpg 320w, /images/logo-480w.jpg 480w, /images/logo-800w.jpg 800w"></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $urls = array_column($links, 'url');
        $this->assertCount(3, $links);
        $this->assertContains('https://example.com/images/logo-320w.jpg', $urls);
        $this->assertContains('https://example.com/images/logo-480w.jpg', $urls);
        $this->assertContains('https://example.com/images/logo-800w.jpg', $urls);
        $this->assertEquals('img', $links[0]['element']);
    }

    public function test_extract_links_finds_img_srcset_with_pixel_density(): void
    {
        $html = '<html><body><img srcset="/images/logo.jpg 1x, /images/logo@2x.jpg 2x"></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $urls = array_column($links, 'url');
        $this->assertCount(2, $links);
        $this->assertContains('https://example.com/images/logo.jpg', $urls);
        $this->assertContains('https://example.com/images/logo@2x.jpg', $urls);
    }

    public function test_extract_links_finds_img_data_src(): void
    {
        $html = '<html><body><img data-src="/images/lazy-loaded.jpg" class="lazy"></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/images/lazy-loaded.jpg', $links[0]['url']);
        $this->assertEquals('img', $links[0]['element']);
    }

    public function test_extract_links_finds_picture_source_srcset(): void
    {
        $html = '<html><body>
            <picture>
                <source srcset="/images/hero-wide.jpg 1200w, /images/hero-medium.jpg 800w" media="(min-width: 800px)">
                <source srcset="/images/hero-narrow.jpg 400w">
                <img src="/images/hero-fallback.jpg">
            </picture>
        </body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $urls = array_column($links, 'url');
        $this->assertContains('https://example.com/images/hero-wide.jpg', $urls);
        $this->assertContains('https://example.com/images/hero-medium.jpg', $urls);
        $this->assertContains('https://example.com/images/hero-narrow.jpg', $urls);
        $this->assertContains('https://example.com/images/hero-fallback.jpg', $urls);
    }

    public function test_extract_links_avoids_duplicates_in_srcset(): void
    {
        $html = '<html><body><img src="/images/logo.jpg" srcset="/images/logo.jpg 1x, /images/logo@2x.jpg 2x"></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $urls = array_column($links, 'url');
        // Should have 3 images: logo.jpg from src, logo.jpg from srcset (dedupe handled), logo@2x.jpg from srcset
        // Actually src adds one, then srcset checks for duplicates
        $this->assertCount(2, $links);
        $this->assertContains('https://example.com/images/logo.jpg', $urls);
        $this->assertContains('https://example.com/images/logo@2x.jpg', $urls);
    }

    public function test_extract_links_handles_srcset_with_empty_candidates(): void
    {
        $html = '<html><body><img srcset="/images/small.jpg 320w, , /images/large.jpg 800w"></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $urls = array_column($links, 'url');
        $this->assertCount(2, $links);
        $this->assertContains('https://example.com/images/small.jpg', $urls);
        $this->assertContains('https://example.com/images/large.jpg', $urls);
    }

    public function test_extract_links_skips_data_urls(): void
    {
        $html = '<html><body><img src="data:image/png;base64,abc123" alt="Data Image"><img src="/real.png"></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/real.png', $links[0]['url']);
        $this->assertEquals('img', $links[0]['element']);
    }

    public function test_extract_links_finds_all_element_types(): void
    {
        $html = '<html>
            <head>
                <link href="/style.css" rel="stylesheet">
                <script src="/app.js"></script>
            </head>
            <body>
                <a href="/page">Link</a>
                <img src="/image.png">
                <video src="/video.mp4"></video>
            </body>
        </html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $urls = array_column($links, 'url');
        $elements = array_column($links, 'element');
        $this->assertCount(5, $links);
        $this->assertContains('https://example.com/style.css', $urls);
        $this->assertContains('https://example.com/app.js', $urls);
        $this->assertContains('https://example.com/page', $urls);
        $this->assertContains('https://example.com/image.png', $urls);
        $this->assertContains('https://example.com/video.mp4', $urls);
        $this->assertContains('a', $elements);
        $this->assertContains('link', $elements);
        $this->assertContains('script', $elements);
        $this->assertContains('img', $elements);
        $this->assertContains('media', $elements);
    }

    public function test_extract_links_finds_video_src(): void
    {
        $html = '<html><body><video src="/video.mp4"></video></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/video.mp4', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_video_poster(): void
    {
        $html = '<html><body><video poster="/poster.jpg"></video></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/poster.jpg', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_audio_src(): void
    {
        $html = '<html><body><audio src="/audio.mp3"></audio></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/audio.mp3', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_video_source_src(): void
    {
        $html = '<html><body><video><source src="/video.webm" type="video/webm"></video></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/video.webm', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_audio_source_src(): void
    {
        $html = '<html><body><audio><source src="/audio.ogg" type="audio/ogg"></audio></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/audio.ogg', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_object_data(): void
    {
        $html = '<html><body><object data="/document.pdf" type="application/pdf"></object></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/document.pdf', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_embed_src(): void
    {
        $html = '<html><body><embed src="/flash.swf" type="application/x-shockwave-flash"></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/flash.swf', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }


    public function test_extract_links_finds_a_download(): void
    {
        $html = '<html><body><a href="/report.pdf" download>Download Report</a></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        // Should appear as both 'a' (from a[href]) and 'media' (from a[download][href])
        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertNotEmpty($mediaLinks);
        $this->assertEquals('https://example.com/report.pdf', array_values($mediaLinks)[0]['url']);
    }

    public function test_extract_links_finds_button_data_href(): void
    {
        $html = '<html><body><button data-href="/download/file.zip">Download</button></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/download/file.zip', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_data_url(): void
    {
        $html = '<html><body><div data-url="/assets/brochure.pdf" class="download-btn">Get PDF</div></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/assets/brochure.pdf', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_data_download(): void
    {
        $html = '<html><body><button data-download="/files/report.xlsx">Export</button></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/files/report.xlsx', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_data_file(): void
    {
        $html = '<html><body><span data-file="/docs/manual.pdf">Manual</span></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/docs/manual.pdf', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_does_not_double_classify_img_data_src(): void
    {
        $html = '<html><body><img data-src="/images/lazy.jpg" class="lazy"></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        // Should only appear once as 'img', not also as 'media'
        $this->assertCount(1, $links);
        $this->assertEquals('img', $links[0]['element']);
    }

    public function test_extract_links_finds_onclick_window_location_href(): void
    {
        $html = '<html><body><button onclick="window.location.href=\'/downloads/report.pdf\'">Download</button></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/downloads/report.pdf', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_onclick_location_href(): void
    {
        $html = '<html><body><button onclick="location.href=\'/file.zip\'">Download</button></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/file.zip', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_onclick_window_open(): void
    {
        $html = '<html><body><button onclick="window.open(\'/docs/manual.pdf\')">Open</button></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/docs/manual.pdf', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_finds_onclick_download_function(): void
    {
        $html = '<html><body><button onclick="download(\'/assets/cv.pdf\')">Download CV</button></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/assets/cv.pdf', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    public function test_extract_links_onclick_skips_javascript_urls(): void
    {
        $html = '<html><body><button onclick="javascript:void(0)">No-op</button></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(0, $links);
    }

    public function test_extract_links_finds_onclick_with_double_quotes(): void
    {
        $html = "<html><body><button onclick='window.location.href=\"/report.xlsx\"'>Export</button></body></html>";

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertEquals('https://example.com/report.xlsx', $links[0]['url']);
        $this->assertEquals('media', $links[0]['element']);
    }

    // ==========================================
    // extractLinks inline script scanning tests
    // ==========================================

    public function test_extract_links_finds_download_url_in_inline_script_when_enabled(): void
    {
        $html = '<html><body><script>var cv = "/files/cv.pdf";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(1, $mediaLinks);
        $this->assertEquals('https://example.com/files/cv.pdf', array_values($mediaLinks)[0]['url']);
    }

    public function test_extract_links_ignores_inline_script_when_disabled(): void
    {
        $html = '<html><body><script>var cv = "/files/cv.pdf";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', false);

        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(0, $mediaLinks);
    }

    public function test_extract_links_ignores_inline_script_by_default(): void
    {
        $html = '<html><body><script>var cv = "/files/cv.pdf";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(0, $mediaLinks);
    }

    public function test_extract_links_finds_download_url_in_next_data_script(): void
    {
        $html = '<html><body><script id="__NEXT_DATA__" type="application/json">{"props":{"downloadUrl":"/docs/report.xlsx"}}</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(1, $mediaLinks);
        $this->assertEquals('https://example.com/docs/report.xlsx', array_values($mediaLinks)[0]['url']);
    }

    public function test_extract_links_inline_script_ignores_non_download_paths(): void
    {
        $html = '<html><body><script>var api = "/api/users"; var page = "/about";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(0, $mediaLinks);
    }

    public function test_extract_links_inline_script_requires_path_prefix(): void
    {
        $html = '<html><body><script>var x = "version.pdf"; var y = "file.zip";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(0, $mediaLinks);
    }

    public function test_extract_links_inline_script_finds_multiple_files(): void
    {
        $html = '<html><body><script>
            var cv = "/files/cv.pdf";
            var brochure = "/downloads/brochure.docx";
            var archive = "/assets/data.zip";
        </script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $mediaLinks = array_values(array_filter($links, fn($l) => $l['element'] === 'media'));
        $urls = array_column($mediaLinks, 'url');
        $this->assertCount(3, $mediaLinks);
        $this->assertContains('https://example.com/files/cv.pdf', $urls);
        $this->assertContains('https://example.com/downloads/brochure.docx', $urls);
        $this->assertContains('https://example.com/assets/data.zip', $urls);
    }

    public function test_extract_links_inline_script_finds_absolute_urls(): void
    {
        $html = '<html><body><script>var file = "https://cdn.example.com/files/report.pdf";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(1, $mediaLinks);
        $this->assertEquals('https://cdn.example.com/files/report.pdf', array_values($mediaLinks)[0]['url']);
    }

    public function test_extract_links_inline_script_deduplicates_urls(): void
    {
        $html = '<html><body><script>
            var a = "/files/cv.pdf";
            var b = "/files/cv.pdf";
        </script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(1, $mediaLinks);
    }

    public function test_extract_links_inline_script_skips_script_with_src(): void
    {
        $html = '<html><head><script src="/app.js">var cv = "/files/cv.pdf";</script></head><body></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        // Should find the src as 'script' element, but NOT the inline content
        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(0, $mediaLinks);

        $scriptLinks = array_filter($links, fn($l) => $l['element'] === 'script');
        $this->assertCount(1, $scriptLinks);
    }

    public function test_extract_links_inline_script_finds_json_escaped_urls(): void
    {
        // Real-world pattern: JSON with forward-slash escaping (common in JSON-LD and __NEXT_DATA__)
        $html = '<html><body><script type="application/json">{"file":"\/downloads\/report.pdf"}</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(1, $mediaLinks);
        $this->assertEquals('https://example.com/downloads/report.pdf', array_values($mediaLinks)[0]['url']);
    }

    // ============================================
    // extractLinks external JS bundle scan tests
    // ============================================

    public function test_extract_links_scans_external_js_bundle_when_enabled(): void
    {
        // Simulate a React app where the download URL is inside the compiled JS bundle
        $bundleContent = 'onClick:()=>{const e=document.createElement("a");e.href="/cv.pdf",e.download="CV.pdf",e.click()}';

        $mockClient = $this->createMockClient(200, $bundleContent);
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $html = '<html><head><script src="/assets/bundle.js"></script></head><body></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $mediaLinks = array_values(array_filter($links, fn($l) => $l['element'] === 'media'));
        $this->assertNotEmpty($mediaLinks);
        $urls = array_column($mediaLinks, 'url');
        $this->assertContains('https://example.com/cv.pdf', $urls);
    }

    public function test_extract_links_does_not_scan_external_js_bundle_when_disabled(): void
    {
        $bundleContent = 'var file = "/downloads/report.pdf";';

        $mockClient = $this->createMockClient(200, $bundleContent);
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $html = '<html><head><script src="/assets/bundle.js"></script></head><body></body></html>';

        // scanScriptContent = false (default)
        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', false);

        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(0, $mediaLinks);
    }

    public function test_extract_links_skips_external_js_bundle_from_other_domains(): void
    {
        // External CDN script should NOT be fetched
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $html = '<html><head><script src="https://cdn.other.com/vendor.js"></script></head><body></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $mediaLinks = array_filter($links, fn($l) => $l['element'] === 'media');
        $this->assertCount(0, $mediaLinks);
    }

    public function test_extract_links_external_bundle_finds_multiple_download_urls(): void
    {
        $bundleContent = 'var cv="/files/cv.pdf"; var brochure="/docs/brochure.docx"; var api="/api/users";';

        $mockClient = $this->createMockClient(200, $bundleContent);
        $this->httpChecker->setClient($mockClient);
        $this->urlNormalizer->setBaseUrl('https://example.com');

        $html = '<html><head><script src="/app.js"></script></head><body></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $mediaLinks = array_values(array_filter($links, fn($l) => $l['element'] === 'media'));
        $urls = array_column($mediaLinks, 'url');
        $this->assertContains('https://example.com/files/cv.pdf', $urls);
        $this->assertContains('https://example.com/docs/brochure.docx', $urls);
        // /api/users should NOT be found (no download extension)
        $this->assertNotContains('https://example.com/api/users', $urls);
    }

    public function test_extract_links_finds_form_action_urls(): void
    {
        $html = '<html><body><form action="https://formspree.io/f/abc123" method="POST"><input type="text"><button type="submit">Send</button></form></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formLink = array_values($formLinks)[0];
        $this->assertEquals('https://formspree.io/f/abc123', $formLink['url']);
        $this->assertEquals('form', $formLink['element']);
    }

    public function test_extract_links_finds_relative_form_action_urls(): void
    {
        $html = '<html><body><form action="/api/contact" method="POST"><button type="submit">Send</button></form></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formLink = array_values($formLinks)[0];
        $this->assertEquals('https://example.com/api/contact', $formLink['url']);
        $this->assertEquals('form', $formLink['element']);
    }

    public function test_extract_links_skips_forms_without_action(): void
    {
        $html = '<html><body><form method="POST"><button type="submit">Send</button></form><a href="/page">Link</a></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com');

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertEmpty($formLinks);
    }

    // ======================
    // Form endpoint JS extraction tests
    // ======================

    public function test_extract_links_finds_fetch_form_endpoint_in_inline_script(): void
    {
        $html = '<html><body><script>function handleSubmit(data) { fetch("/api/contact", { method: "POST", body: JSON.stringify(data) }); }</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formLink = array_values($formLinks)[0];
        $this->assertEquals('https://example.com/api/contact', $formLink['url']);
    }

    public function test_extract_links_finds_axios_post_form_endpoint(): void
    {
        $html = '<html><body><script>axios.post("/api/submit", formData);</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formLink = array_values($formLinks)[0];
        $this->assertEquals('https://example.com/api/submit', $formLink['url']);
    }

    public function test_extract_links_finds_formspree_url_in_script(): void
    {
        $html = '<html><body><script>fetch("https://formspree.io/f/xpzvqwer", { method: "POST", body: formData });</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        // Should find it via both fetch pattern and formspree pattern, but deduplicated
        $formUrls = array_map(fn($l) => $l['url'], array_values($formLinks));
        $this->assertContains('https://formspree.io/f/xpzvqwer', $formUrls);
    }

    public function test_extract_links_finds_web3forms_url_in_script(): void
    {
        $html = '<html><body><script>fetch("https://api.web3forms.com/submit", { method: "POST" });</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formUrls = array_map(fn($l) => $l['url'], array_values($formLinks));
        $this->assertContains('https://api.web3forms.com/submit', $formUrls);
    }

    public function test_extract_links_finds_jquery_ajax_form_endpoint(): void
    {
        $html = '<html><body><script>$.post("/api/contact", data);</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formLink = array_values($formLinks)[0];
        $this->assertEquals('https://example.com/api/contact', $formLink['url']);
    }

    public function test_extract_links_finds_xhr_open_form_endpoint(): void
    {
        $html = '<html><body><script>var xhr = new XMLHttpRequest(); xhr.open("POST", "/api/contact");</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formLink = array_values($formLinks)[0];
        $this->assertEquals('https://example.com/api/contact', $formLink['url']);
    }

    public function test_extract_links_does_not_find_form_endpoints_without_js_flag(): void
    {
        $html = '<html><body><script>fetch("/api/contact", { method: "POST" });</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', false);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertEmpty($formLinks);
    }

    public function test_extract_links_deduplicates_form_endpoints(): void
    {
        $html = '<html><body><script>fetch("/api/contact", opts); fetch("/api/contact", opts);</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form' && $l['url'] === 'https://example.com/api/contact');
        $this->assertCount(1, $formLinks);
    }

    public function test_extract_links_finds_axios_without_method(): void
    {
        $html = '<html><body><script>axios("/api/submit-form", { data: formData });</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formLink = array_values($formLinks)[0];
        $this->assertEquals('https://example.com/api/submit-form', $formLink['url']);
    }

    public function test_extract_links_ignores_telemetry_fetch_urls(): void
    {
        $html = '<html><body><script>fetch("/_spark/kv"); fetch("/_spark/loaded"); fetch("/_spark/llm"); fetch("/analytics/event");</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertEmpty($formLinks);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('newFormKeywordEndpointsProvider')]
    public function test_extract_links_finds_new_keyword_endpoints(string $endpoint, string $expectedUrl): void
    {
        $html = '<html><body><script>fetch("' . $endpoint . '", { method: "POST", body: JSON.stringify(data) });</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks, "Expected form endpoint for: {$endpoint}");
        $formUrls = array_map(fn($l) => $l['url'], array_values($formLinks));
        $this->assertContains($expectedUrl, $formUrls);
    }

    public static function newFormKeywordEndpointsProvider(): array
    {
        return [
            'send' => ['/api/send', 'https://example.com/api/send'],
            'mail' => ['/api/mail', 'https://example.com/api/mail'],
            'email' => ['/api/email', 'https://example.com/api/email'],
            'checkout' => ['/api/checkout', 'https://example.com/api/checkout'],
            'order' => ['/api/order', 'https://example.com/api/order'],
            'payment' => ['/api/payment', 'https://example.com/api/payment'],
            'donate' => ['/api/donate', 'https://example.com/api/donate'],
            'donation' => ['/api/donation', 'https://example.com/api/donation'],
            'apply' => ['/api/apply', 'https://example.com/api/apply'],
            'application' => ['/api/application', 'https://example.com/api/application'],
            'enroll' => ['/api/enroll', 'https://example.com/api/enroll'],
            'survey' => ['/api/survey', 'https://example.com/api/survey'],
            'rsvp' => ['/api/rsvp', 'https://example.com/api/rsvp'],
            'review' => ['/api/review', 'https://example.com/api/review'],
            'comment' => ['/api/comment', 'https://example.com/api/comment'],
            'reply' => ['/api/reply', 'https://example.com/api/reply'],
            'upload' => ['/api/upload', 'https://example.com/api/upload'],
            'report' => ['/api/report', 'https://example.com/api/report'],
            'claim' => ['/api/claim', 'https://example.com/api/claim'],
            'login' => ['/api/login', 'https://example.com/api/login'],
            'signin' => ['/api/signin', 'https://example.com/api/signin'],
            'sign-in' => ['/api/sign-in', 'https://example.com/api/sign-in'],
            'verify' => ['/api/verify', 'https://example.com/api/verify'],
        ];
    }

    public function test_extract_links_finds_external_contact_endpoint(): void
    {
        $html = '<html><body><script>fetch("https://app.example.com/contacts", { method: "POST", body: JSON.stringify(formData) });</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formLink = array_values($formLinks)[0];
        $this->assertEquals('https://app.example.com/contacts', $formLink['url']);
    }

    public function test_extract_links_finds_subscribe_endpoint(): void
    {
        $html = '<html><body><script>fetch("/api/newsletter/subscribe", { method: "POST" });</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formLink = array_values($formLinks)[0];
        $this->assertEquals('https://example.com/api/newsletter/subscribe', $formLink['url']);
    }

    public function test_extract_links_finds_api_config_baseurl_with_endpoints(): void
    {
        // React pattern: config object with baseUrl + endpoints, URL built via template literal
        $html = '<html><body><script>const config={baseUrl:"https://app.example.com",endpoints:{contacts:"/api/contacts"},headers:{"Content-Type":"application/json"}};function submit(data){return fetch(`${config.baseUrl}${config.endpoints.contacts}`,{method:"POST",body:JSON.stringify(data)})}</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formUrls = array_map(fn($l) => $l['url'], array_values($formLinks));
        $this->assertContains('https://app.example.com/api/contacts', $formUrls);
    }

    public function test_extract_links_finds_minified_api_config(): void
    {
        // Matches the exact minified pattern from sommeling.dev
        $html = '<html><body><script>a))}const mr={baseUrl:"https://app.sommeling.dev",endpoints:{contacts:"/api/contacts"},headers:{"Content-Type":"application/json"}};function submit(){return fetch(`${mr.baseUrl}${mr.endpoints.contacts}`,{method:"POST"})}</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://sommeling.dev', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertNotEmpty($formLinks);
        $formUrls = array_map(fn($l) => $l['url'], array_values($formLinks));
        $this->assertContains('https://app.sommeling.dev/api/contacts', $formUrls);
    }

    public function test_extract_links_api_config_ignores_non_form_endpoints(): void
    {
        // baseUrl with non-form endpoints should not be picked up
        $html = '<html><body><script>const api={baseUrl:"https://api.example.com",endpoints:{users:"/api/users",analytics:"/api/analytics"}};</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertEmpty($formLinks);
    }

    public function test_extract_links_api_config_not_detected_without_js_flag(): void
    {
        $html = '<html><body><script>const config={baseUrl:"https://app.example.com",endpoints:{contacts:"/api/contacts"}};</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', false);

        $formLinks = array_filter($links, fn($l) => $l['element'] === 'form');
        $this->assertEmpty($formLinks);
    }

    public function test_extract_links_finds_urls_in_inline_script_with_js_flag(): void
    {
        $html = '<html><body><script>const projects=[{url:"https://tree-demo.sommeling.dev/"},{url:"https://yoga-demo.sommeling.dev/"}];</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://sommeling.dev', true);

        $urls = array_column($links, 'url');
        $this->assertContains('https://tree-demo.sommeling.dev', $urls);
        $this->assertContains('https://yoga-demo.sommeling.dev', $urls);
    }

    public function test_extract_links_does_not_find_urls_in_inline_script_without_js_flag(): void
    {
        $html = '<html><body><script>const projects=[{url:"https://tree-demo.sommeling.dev/"},{url:"https://yoga-demo.sommeling.dev/"}];</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://sommeling.dev', false);

        $urls = array_column($links, 'url');
        $this->assertNotContains('https://tree-demo.sommeling.dev', $urls);
        $this->assertNotContains('https://yoga-demo.sommeling.dev', $urls);
    }

    public function test_extract_links_js_bundle_scanning_skips_cdn_urls(): void
    {
        $html = '<html><body><script>const cdn="https://cdn.jsdelivr.net/npm/lib";const site="https://myapp.example.com/page";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $urls = array_column($links, 'url');
        $this->assertContains('https://myapp.example.com/page', $urls);
        $this->assertNotContains('https://cdn.jsdelivr.net/npm/lib', $urls);
    }

    public function test_extract_links_js_bundle_flags_indirect_references(): void
    {
        $html = '<html><body><script>const urls=["https://alpinejs.dev/plugins/${r}",n,"https://atomiks.github.io/tippyjs/v6/all-props",`https://example.com/test`];</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        // Find link with template literal
        $suspiciousLink = null;
        foreach ($links as $link) {
            if (strpos($link['url'], 'alpinejs.dev') !== false) {
                $suspiciousLink = $link;
                break;
            }
        }

        $this->assertNotNull($suspiciousLink);
        $this->assertNotEmpty($suspiciousLink['flags'] ?? []);
        $this->assertContains('detected_in_js_bundle', $suspiciousLink['flags'] ?? []);
        $this->assertContains('indirect_reference', $suspiciousLink['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_flags_clean_urls_for_verification(): void
    {
        $html = '<html><body><script>const docs="https://react.dev/reference";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $cleanLink = null;
        foreach ($links as $link) {
            if (strpos($link['url'], 'react.dev') !== false) {
                $cleanLink = $link;
                break;
            }
        }

        $this->assertNotNull($cleanLink);
        $this->assertNotEmpty($cleanLink['flags'] ?? []);
        $this->assertContains('detected_in_js_bundle', $cleanLink['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_detects_backtick_in_url(): void
    {
        $html = '<html><body><script>const url="https://example.com/test`incomplete";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'example.com/test') !== false))[0] ?? null;

        $this->assertNotNull($link);
        $this->assertNotEmpty($link['flags'] ?? []);
        $this->assertContains('indirect_reference', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_comma_suffix_not_flagged_as_indirect(): void
    {
        // URL with comma suffix in post-context should NOT be flagged as indirect_reference
        // because the URL itself is valid - only the context suggests partial URL
        // This avoids false positives for URLs in arrays
        $html = '<html><body><script>const url="https://example.com/plugins/test",n</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'example.com/plugins') !== false))[0] ?? null;

        $this->assertNotNull($link);
        $this->assertContains('detected_in_js_bundle', $link['flags'] ?? []);
        // Post-context patterns should NOT trigger indirect_reference (too many false positives)
        $this->assertNotContains('indirect_reference', $link['flags'] ?? []);
        $this->assertNotContains('malformed_url', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_detects_curly_braces(): void
    {
        $html = '<html><body><script>const url="https://alpinejs.dev/plugins/${r}";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'alpinejs.dev') !== false))[0] ?? null;

        $this->assertNotNull($link);
        $this->assertNotEmpty($link['flags'] ?? []);
        $this->assertContains('detected_in_js_bundle', $link['flags'] ?? []);
        $this->assertContains('indirect_reference', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_detects_standalone_curly_brace(): void
    {
        $html = '<html><body><script>const url="https://example.com/api/{id}/details";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'example.com/api') !== false))[0] ?? null;

        $this->assertNotNull($link);
        $this->assertNotEmpty($link['flags'] ?? []);
        $this->assertContains('indirect_reference', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_does_not_flag_internal_subdomains(): void
    {
        $html = '<html><body><script>const projects=[{url:"https://tree-demo.sommeling.dev/"},{url:"https://yoga-demo.sommeling.dev/"}];</script></body></html>';

        // Set base URL so isInternalUrl knows what domain we're scanning
        $this->urlNormalizer->setBaseUrl('https://sommeling.dev');

        $links = $this->linkExtractor->extractLinks($html, 'https://sommeling.dev', true);

        // Both URLs should be extracted
        $urls = array_column($links, 'url');
        $this->assertContains('https://tree-demo.sommeling.dev', $urls);
        $this->assertContains('https://yoga-demo.sommeling.dev', $urls);

        // But they should NOT be flagged for verification (they're internal subdomains)
        foreach ($links as $link) {
            if (strpos($link['url'], 'tree-demo.sommeling.dev') !== false ||
                strpos($link['url'], 'yoga-demo.sommeling.dev') !== false) {
                $this->assertFalse($link['needsVerification'] ?? false, "Internal subdomain {$link['url']} should not need verification");
            }
        }
    }

    public function test_extract_links_js_bundle_flags_external_clean_urls(): void
    {
        $html = '<html><body><script>const docs="https://react.dev/reference";</script></body></html>';

        $this->urlNormalizer->setBaseUrl('https://example.com');

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'react.dev') !== false))[0] ?? null;

        $this->assertNotNull($link);
        $this->assertNotEmpty($link['flags'] ?? [], 'External URL from JS bundle should have flags');
        $this->assertContains('detected_in_js_bundle', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_flags_internal_suspicious_urls(): void
    {
        $html = '<html><body><script>const api="https://app.example.com/api/{userId}/profile";</script></body></html>';

        $this->urlNormalizer->setBaseUrl('https://example.com');

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'app.example.com') !== false))[0] ?? null;

        $this->assertNotNull($link);
        $this->assertNotEmpty($link['flags'] ?? [], 'Internal URL with suspicious syntax should have flags');
        $this->assertContains('indirect_reference', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_flags_localhost_as_developer_leftover(): void
    {
        $html = '<html><body><script>const api="http://localhost/api/contacts";</script></body></html>';

        $this->urlNormalizer->setBaseUrl('https://example.com');

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'localhost') !== false))[0] ?? null;

        $this->assertNotNull($link);
        // localhost URLs from JS bundles should have detected_in_js_bundle flag
        $this->assertContains('detected_in_js_bundle', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_flags_127_0_0_1_as_developer_leftover(): void
    {
        $html = '<html><body><script>const api="http://127.0.0.1:8000/api/submit";</script></body></html>';

        $this->urlNormalizer->setBaseUrl('https://example.com');

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], '127.0.0.1') !== false))[0] ?? null;

        $this->assertNotNull($link);
        // 127.0.0.1 URLs from JS bundles should have detected_in_js_bundle flag
        $this->assertContains('detected_in_js_bundle', $link['flags'] ?? []);
    }

    // ===================
    // Clean subdomain URL tests (should NOT be flagged as malformed)
    // ===================

    public function test_extract_links_js_bundle_clean_subdomain_not_flagged_as_malformed(): void
    {
        $html = '<html><body><script>const demo="https://yoga-demo.sommeling.dev";</script></body></html>';

        $this->urlNormalizer->setBaseUrl('https://www.sommeling.dev');

        $links = $this->linkExtractor->extractLinks($html, 'https://www.sommeling.dev', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'yoga-demo') !== false))[0] ?? null;

        $this->assertNotNull($link);
        $this->assertContains('detected_in_js_bundle', $link['flags'] ?? []);
        // Should NOT have malformed_url or indirect_reference flags
        $this->assertNotContains('malformed_url', $link['flags'] ?? []);
        $this->assertNotContains('indirect_reference', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_clean_subdomain_with_path_not_flagged_as_malformed(): void
    {
        $html = '<html><body><script>const app="https://app.sommeling.dev/dashboard";</script></body></html>';

        $this->urlNormalizer->setBaseUrl('https://www.sommeling.dev');

        $links = $this->linkExtractor->extractLinks($html, 'https://www.sommeling.dev', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'app.sommeling') !== false))[0] ?? null;

        $this->assertNotNull($link);
        // Should NOT have malformed_url flag
        $this->assertNotContains('malformed_url', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_clean_external_url_not_flagged_as_malformed(): void
    {
        $html = '<html><body><script>const docs="https://docs.laravel.com/10.x/routing";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'laravel.com') !== false))[0] ?? null;

        $this->assertNotNull($link);
        // Should NOT have malformed_url flag
        $this->assertNotContains('malformed_url', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_url_with_query_params_not_flagged_as_malformed(): void
    {
        $html = '<html><body><script>const api="https://api.example.com/search?q=test&limit=10";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'api.example.com') !== false))[0] ?? null;

        $this->assertNotNull($link);
        // Query params should not trigger malformed_url
        $this->assertNotContains('malformed_url', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_url_with_template_literal_is_flagged_as_malformed(): void
    {
        $html = '<html><body><script>const user="https://api.example.com/user/${userId}";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'api.example.com') !== false))[0] ?? null;

        $this->assertNotNull($link);
        // Template literal syntax SHOULD trigger malformed_url
        $this->assertContains('malformed_url', $link['flags'] ?? []);
        $this->assertContains('indirect_reference', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_url_with_vue_interpolation_is_flagged_as_malformed(): void
    {
        $html = '<html><body><script>const profile="https://example.com/user/{userId}/profile";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'user/') !== false))[0] ?? null;

        $this->assertNotNull($link);
        // Vue/Angular interpolation syntax SHOULD trigger malformed_url
        $this->assertContains('malformed_url', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_linkedin_url_in_array_not_flagged_as_malformed(): void
    {
        // URLs in arrays should NOT be flagged as malformed - the comma after the quote is normal array syntax
        $html = '<html><body><script>const socials=["https://www.linkedin.com/in/jesse-sommeling","https://github.com/user"];</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'linkedin.com') !== false))[0] ?? null;

        $this->assertNotNull($link, 'LinkedIn URL should be extracted from JS bundle');
        // Should NOT have malformed_url flag - this is a clean URL in an array
        $this->assertNotContains('malformed_url', $link['flags'] ?? []);
        $this->assertNotContains('indirect_reference', $link['flags'] ?? []);
        // Should still have detected_in_js_bundle since it came from JS
        $this->assertContains('detected_in_js_bundle', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_url_with_string_concatenation_not_flagged_as_malformed(): void
    {
        // URL followed by string concatenation should NOT be flagged as malformed
        // because the URL itself is valid - only the context suggests it might be a partial URL
        // This is a trade-off to avoid false positives like LinkedIn URLs in arrays
        $html = '<html><body><script>const url="https://api.example.com/user/",userId;</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'api.example.com') !== false))[0] ?? null;

        $this->assertNotNull($link);
        // Post-context concatenation should NOT trigger malformed_url (too many false positives)
        $this->assertNotContains('malformed_url', $link['flags'] ?? []);
        // Should still have detected_in_js_bundle
        $this->assertContains('detected_in_js_bundle', $link['flags'] ?? []);
    }

    // ===================
    // JS Bundle extraction - Clean external URLs (from user's actual scan data)
    // ===================

    public function test_extract_links_js_bundle_github_url_not_flagged_as_malformed(): void
    {
        $html = '<html><body><script>const github = "https://github.com/JBSommeling";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://www.sommeling.dev', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'github.com') !== false))[0] ?? null;

        $this->assertNotNull($link, 'GitHub URL should be extracted');
        $this->assertNotContains('malformed_url', $link['flags'] ?? []);
        $this->assertNotContains('indirect_reference', $link['flags'] ?? []);
        $this->assertContains('detected_in_js_bundle', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_pusher_url_not_flagged_as_malformed(): void
    {
        $html = '<html><body><script>const pusher = "https://pusher.com";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://app.sommeling.dev', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'pusher.com') !== false))[0] ?? null;

        $this->assertNotNull($link, 'Pusher URL should be extracted');
        $this->assertNotContains('malformed_url', $link['flags'] ?? []);
        $this->assertNotContains('indirect_reference', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_js_pusher_url_not_flagged_as_malformed(): void
    {
        $html = '<html><body><script>const js = "https://js.pusher.com";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://app.sommeling.dev', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'js.pusher.com') !== false))[0] ?? null;

        $this->assertNotNull($link, 'JS Pusher URL should be extracted');
        $this->assertNotContains('malformed_url', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_http_js_pusher_url_not_flagged_as_malformed(): void
    {
        $html = '<html><body><script>const js = "http://js.pusher.com";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://app.sommeling.dev', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'js.pusher.com') !== false))[0] ?? null;

        $this->assertNotNull($link, 'HTTP JS Pusher URL should be extracted');
        $this->assertNotContains('malformed_url', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_example_com_not_flagged_as_malformed(): void
    {
        $html = '<html><body><script>const example = "https://example.com";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://www.sommeling.dev', true);

        $link = array_values(array_filter($links, fn($l) => $l['url'] === 'https://example.com'))[0] ?? null;

        $this->assertNotNull($link, 'Example.com URL should be extracted');
        $this->assertNotContains('malformed_url', $link['flags'] ?? []);
        $this->assertNotContains('indirect_reference', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_urls_in_array_not_flagged_as_malformed(): void
    {
        // Multiple URLs in an array - common pattern in JS
        $html = '<html><body><script>const urls = ["https://github.com/user", "https://twitter.com/user", "https://linkedin.com/in/user"];</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        foreach ($links as $link) {
            if (strpos($link['url'], 'github.com') !== false ||
                strpos($link['url'], 'twitter.com') !== false ||
                strpos($link['url'], 'linkedin.com') !== false) {
                $this->assertNotContains('malformed_url', $link['flags'] ?? [], "URL {$link['url']} should not be flagged as malformed");
                $this->assertNotContains('indirect_reference', $link['flags'] ?? [], "URL {$link['url']} should not be flagged as indirect_reference");
            }
        }
    }

    public function test_extract_links_js_bundle_url_in_object_not_flagged_as_malformed(): void
    {
        // URL in an object - common pattern in JS config
        $html = '<html><body><script>const config = {social: "https://linkedin.com/in/user", repo: "https://github.com/user"};</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        foreach ($links as $link) {
            if (strpos($link['url'], 'linkedin.com') !== false ||
                strpos($link['url'], 'github.com') !== false) {
                $this->assertNotContains('malformed_url', $link['flags'] ?? [], "URL {$link['url']} should not be flagged as malformed");
            }
        }
    }

    public function test_extract_links_js_bundle_url_with_actual_template_literal_is_flagged(): void
    {
        // URL with actual template literal syntax in the URL itself SHOULD be flagged
        $html = '<html><body><script>const api = "https://api.example.com/users/${userId}";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'api.example.com') !== false))[0] ?? null;

        $this->assertNotNull($link, 'API URL should be extracted');
        // URL itself contains ${userId} so it SHOULD be flagged
        $this->assertContains('malformed_url', $link['flags'] ?? []);
        $this->assertContains('indirect_reference', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_url_with_brace_variable_is_flagged(): void
    {
        // URL with {variable} syntax SHOULD be flagged
        $html = '<html><body><script>const user = "https://api.example.com/users/{id}/profile";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'api.example.com') !== false))[0] ?? null;

        $this->assertNotNull($link, 'API URL should be extracted');
        $this->assertContains('malformed_url', $link['flags'] ?? []);
    }

    // ===================
    // JS Bundle extraction - Localhost URLs (should be flagged)
    // ===================

    public function test_extract_links_js_bundle_localhost_url_is_flagged(): void
    {
        $html = '<html><body><script>const api = "http://localhost:3000/api";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'localhost') !== false))[0] ?? null;

        $this->assertNotNull($link, 'Localhost URL should be extracted');
        $this->assertContains('detected_in_js_bundle', $link['flags'] ?? []);
        $this->assertContains('localhost_url', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_127_0_0_1_url_is_flagged(): void
    {
        $html = '<html><body><script>const api = "http://127.0.0.1:8080/api";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], '127.0.0.1') !== false))[0] ?? null;

        $this->assertNotNull($link, '127.0.0.1 URL should be extracted');
        $this->assertContains('localhost_url', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_dot_local_url_is_flagged(): void
    {
        $html = '<html><body><script>const api = "http://myapp.local/api";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'myapp.local') !== false))[0] ?? null;

        $this->assertNotNull($link, '.local URL should be extracted');
        $this->assertContains('localhost_url', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_dot_test_url_is_flagged(): void
    {
        $html = '<html><body><script>const api = "http://laravel.test/api";</script></body></html>';

        $links = $this->linkExtractor->extractLinks($html, 'https://example.com', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'laravel.test') !== false))[0] ?? null;

        $this->assertNotNull($link, '.test URL should be extracted');
        $this->assertContains('localhost_url', $link['flags'] ?? []);
    }

    public function test_extract_links_js_bundle_production_url_not_flagged_as_localhost(): void
    {
        $html = '<html><body><script>const api = "https://api.sommeling.dev/users";</script></body></html>';

        $this->urlNormalizer->setBaseUrl('https://www.sommeling.dev');

        $links = $this->linkExtractor->extractLinks($html, 'https://www.sommeling.dev', true);

        $link = array_values(array_filter($links, fn($l) => strpos($l['url'], 'api.sommeling.dev') !== false))[0] ?? null;

        $this->assertNotNull($link, 'Production URL should be extracted');
        $this->assertNotContains('localhost_url', $link['flags'] ?? []);
    }
}
