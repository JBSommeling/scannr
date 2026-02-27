<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tracking Parameters
    |--------------------------------------------------------------------------
    |
    | These query parameters will be stripped from URLs during normalization.
    | Use '*' suffix for prefix matching (e.g., 'utm_*' matches 'utm_source',
    | 'utm_medium', etc.). Matching is case-insensitive.
    |
    */

    'tracking_params' => [
        'utm_*',
        'fbclid',
        'gclid',
        'ref',
        'source',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Control the delay between HTTP requests to avoid overwhelming servers.
    | Values are in milliseconds. A random delay between min and max will be
    | applied between each request in the main crawl loop.
    |
    */

    'request_delay_min' => 100,
    'request_delay_max' => 300,

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum time in seconds to wait for a response from the server.
    | This acts as a hard cap regardless of what is passed via command options.
    |
    */

    'timeout' => 30,

    /*
    |--------------------------------------------------------------------------
    | Hard Limits
    |--------------------------------------------------------------------------
    |
    | These are absolute maximum values that cannot be exceeded regardless
    | of what the user specifies via command line options. This protects
    | against excessive resource usage and accidental abuse.
    |
    */

    'hard_max_depth' => 10,
    'hard_max_urls' => 2000,

];

