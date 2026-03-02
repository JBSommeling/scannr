<?php

$html = '<html><body><script>const url="https://example.com/plugins/test",n</script></body></html>';

echo "Testing URL extraction:\n";
if (preg_match_all('/(https?:\/\/[^\s"\')<>]+)/i', $html, $matches)) {
    foreach ($matches[1] as $rawUrl) {
        echo "Raw URL: [$rawUrl]\n";

        $hasSuspicious = preg_match('/\$\{|\#\{|`|,\w+$|"\s*,|\n/', $rawUrl);
        echo "Has suspicious syntax: " . ($hasSuspicious ? "YES" : "NO") . "\n";

        $cleaned = rtrim($rawUrl, '.,;:"\')}>]');
        echo "Cleaned URL: [$cleaned]\n";
        echo "\n";
    }
}

