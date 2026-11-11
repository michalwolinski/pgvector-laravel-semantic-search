<?php

declare(strict_types=1);

// Both embedding models run locally in llama.cpp's llama-server, which speaks the OpenAI embeddings API.
return [

    'default' => 'bge',
    'default_for_embeddings' => 'bge',

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
            'individually' => true,
        ],
    ],

    'providers' => [
        'bge' => [
            'driver' => 'openai-compatible',
            'url' => env('BGE_URL', 'http://127.0.0.1:8091/v1'),
            'key' => 'none',
            'models' => [
                'embeddings' => ['default' => 'bge-small-en-v1.5', 'dimensions' => 384],
            ],
        ],

        'nomic' => [
            'driver' => 'openai-compatible',
            'url' => env('NOMIC_URL', 'http://127.0.0.1:8092/v1'),
            'key' => 'none',
            'models' => [
                'embeddings' => ['default' => 'nomic-embed-text-v1.5', 'dimensions' => 768],
            ],
        ],
    ],

];
