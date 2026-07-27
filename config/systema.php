<?php

return [
    'base_url' => env('SYSTEM_A_BASE_URL', 'https://dash.oacl.co.tz'),
    'username' => env('SYSTEM_A_USERNAME'),
    'password' => env('SYSTEM_A_PASSWORD'),
    'headless' => env('SYSTEM_A_HEADLESS', true),
    'driver_url' => env('DUSK_DRIVER_URL', 'http://localhost:9515'),
];
