<?php

return [
    'bunny_storage_zone' => env('BUNNYCDN_STORAGE_ZONE'),
    'bunny_access_key'   => env('BUNNYCDN_API_KEY'),
    'bunny_region'       => env('BUNNYCDN_REGION'),
    'bunny_host'         => env('BUNNYCDN_HOST', 'storage.bunnycdn.com'),
    'bunny_pull_zone_url'=> env('BUNNYCDN_PULL_ZONE_URL'),
];