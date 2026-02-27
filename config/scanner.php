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

];

