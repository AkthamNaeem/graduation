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

    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'model' => env('GROQ_CV_MODEL', 'openai/gpt-oss-20b'),
        'timeout' => (int) env('GROQ_CV_TIMEOUT', 60),
        'connect_timeout' => (int) env('GROQ_CV_CONNECT_TIMEOUT', 10),
        'max_completion_tokens' => (int) env('GROQ_CV_MAX_COMPLETION_TOKENS', 4096),
        'reasoning_effort' => env('GROQ_CV_REASONING_EFFORT', 'low'),
        'temperature' => (float) env('GROQ_CV_TEMPERATURE', 0.5),
    ],
];
