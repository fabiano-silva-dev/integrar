<?php

return [
    'fila' => env('DOCUMENTOS_QUEUE', 'documentos'),
    'max_anexo_bytes' => (int) env('DOCUMENTOS_MAX_ANEXO_MB', 80) * 1024 * 1024,
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL', 'http://localhost').'/oauth/google/callback'),
        'scopes' => [
            'https://www.googleapis.com/auth/drive.file',
            'https://www.googleapis.com/auth/drive.metadata.readonly',
            'https://www.googleapis.com/auth/userinfo.email',
        ],
    ],
    'ia' => [
        'gemini_api_key' => env('GEMINI_API_KEY', ''),
        'groq_api_key' => env('GROQ_API_KEY', ''),
        'llama_cloud_api_key' => env('LLAMA_CLOUD_API_KEY', ''),
        'gemini_modelos' => [
            'gemini-2.5-flash',
            'gemini-2.5-flash-lite',
            'gemini-2.0-flash',
        ],
        'groq_modelos' => [
            'meta-llama/llama-4-scout-17b-16e-instruct',
            'llama-3.2-90b-vision-preview',
        ],
        'llama_parse_url' => env('LLAMA_PARSE_URL', 'https://api.cloud.llamaindex.ai/api/v1'),
        'esgotado_ttl_segundos' => 3600,
    ],
];

