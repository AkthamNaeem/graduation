<?php

return [
    'parser' => [
        'driver' => env('CV_PARSER_DRIVER', 'rules'),
        'fallback_to_rules' => env('CV_PARSER_FALLBACK_TO_RULES', true),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_CV_MODEL', 'gpt-5-mini'),
        'timeout' => (int) env('OPENAI_CV_TIMEOUT', 60),
        'connect_timeout' => (int) env('OPENAI_CV_CONNECT_TIMEOUT', 10),
    ],
];
