<?php

namespace Scannr\Services;

use Spatie\Browsershot\Browsershot;

/**
 * Fetches page HTML using a headless browser (Puppeteer) via Browsershot.
 *
 * This enables JavaScript rendering, allowing the scanner to extract
 * links and images from Single Page Applications (React, Vue, etc.)
 * where content is rendered client-side.
 */
class BrowsershotFetcher
{
    protected ?string $nodeBinary = null;

    protected ?string $npmBinary = null;

    protected ?string $chromePath = null;

    protected ?string $nodeModulesPath = null;

    protected int $timeout = 30;

    /**
     * Configure the fetcher with custom binary paths.
     */
    public function configure(array $options = []): self
    {
        $this->nodeBinary = $options['node_binary'] ?? null;
        $this->npmBinary = $options['npm_binary'] ?? null;
        $this->chromePath = $options['chrome_path'] ?? null;
        $this->nodeModulesPath = $options['node_modules_path'] ?? null;
        $this->timeout = $options['timeout'] ?? 30;

        return $this;
    }

    /**
     * Set the request timeout in seconds.
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Fetch the fully rendered HTML of a URL using a headless browser.
     *
     * @param  string  $url  The URL to render.
     * @return array{status: int|string, body: string|null, finalUrl: string}
     */
    public function fetch(string $url): array
    {
        try {
            $defaultUserAgent = 'ScannrBot/1.0 (+https://scannr.io)';
            try {
                $userAgent = config('scannr.user_agent', $defaultUserAgent) ?? $defaultUserAgent;
            } catch (\Throwable) {
                $userAgent = $defaultUserAgent;
            }

            $browsershot = Browsershot::url($url)
                ->noSandbox()
                ->dismissDialogs()
                ->waitUntilNetworkIdle()
                ->timeout($this->timeout)
                ->userAgent($userAgent)
                ->setOption('args', ['--disable-web-security']);

            if ($this->nodeBinary) {
                $browsershot->setNodeBinary($this->nodeBinary);
            }

            if ($this->npmBinary) {
                $browsershot->setNpmBinary($this->npmBinary);
            }

            if ($this->chromePath) {
                $browsershot->setChromePath($this->chromePath);
            }

            if ($this->nodeModulesPath) {
                $browsershot->setNodeModulePath($this->nodeModulesPath);
            }

            $body = $browsershot->bodyHtml();

            return [
                'status' => 200,
                'body' => $body,
                'finalUrl' => $url,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'Error',
                'body' => null,
                'finalUrl' => $url,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if the required dependencies (Node.js + Puppeteer) are available.
     *
     * @return array{available: bool, message: string}
     */
    public static function checkDependencies(): array
    {
        // Check for Node.js
        $nodePath = trim(shell_exec('which node 2>/dev/null') ?? '');
        if (empty($nodePath)) {
            return [
                'available' => false,
                'message' => 'Node.js is not installed. Install Node.js and run: npm install puppeteer',
            ];
        }

        // Check for Puppeteer
        $projectRoot = base_path();
        $puppeteerPath = $projectRoot.'/node_modules/puppeteer';
        if (! is_dir($puppeteerPath)) {
            return [
                'available' => false,
                'message' => 'Puppeteer is not installed. Run: npm install puppeteer',
            ];
        }

        return [
            'available' => true,
            'message' => 'JavaScript rendering is available.',
        ];
    }
}
