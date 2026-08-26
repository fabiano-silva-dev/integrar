<?php

return [
    'url_base' => env('EVOLUTION_URL_BASE', 'http://evolution-api:8080'),
    'api_key' => env('EVOLUTION_API_KEY', ''),
    'webhook_url' => env('EVOLUTION_WEBHOOK_URL', env('APP_URL', 'http://app:8000').'/webhooks/evolution'),
];
