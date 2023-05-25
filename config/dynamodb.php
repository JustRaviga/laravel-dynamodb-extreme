<?php

return [
    'region' => env('DYNAMODB_REGION', 'localhost'),
    'version' => env('DYNAMODB_VERSION', 'latest'),
    'credentials' => [
        'key' => env('DYNAMODB_KEY', ''),
        'secret' => env('DYNAMODB_SECRET', ''),
    ],
    'endpoint' => env('DYNAMODB_ENDPOINT', 'http://localhost:8000'),
    'defaults' => [
        'consistent_read' => env('DYNAMODB_CONSISTENT_READ', true),
        'table' => env('DYNAMODB_TABLE', 'default'),
        'partition_key' => 'pk',
        'sort_key' => 'sk',
        'global_secondary_indexes' => [
            'gsi1' => [
                'pk' => 'gsi1_pk',
                'sk' => 'gsi1_sk',
            ]
        ],
        'log_queries' => env('DYNAMODB_LOG_QUERIES', false),
    ],
];
