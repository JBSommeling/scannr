<?php

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://sommeling.dev");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36");
$body = curl_exec($ch);
$info = curl_getinfo($ch);
echo "HTTP Code: " . $info["http_code"] . "\n";
echo "Content-Type: " . $info["content_type"] . "\n";
echo "Body length: " . strlen($body) . "\n\n";

// Check if img tags exist
preg_match_all('/<img[^>]*>/i', $body, $imgMatches);
echo "IMG tags found: " . count($imgMatches[0]) . "\n";
foreach ($imgMatches[0] as $img) {
    echo "  " . $img . "\n";
}

echo "\n--- First 3000 chars of body ---\n";
echo substr($body, 0, 3000) . "\n";

// Check for the broken image URL
if (strpos($body, 'cloudinary') !== false) {
    echo "\n*** Found 'cloudinary' in HTML ***\n";
} else {
    echo "\n*** 'cloudinary' NOT found in raw HTML ***\n";
}

// Check if it's a React/SPA app
if (strpos($body, 'id="root"') !== false || strpos($body, 'id="app"') !== false || strpos($body, '__next') !== false) {
    echo "*** This appears to be a SPA/React app ***\n";
}

curl_close($ch);

