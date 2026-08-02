<?php

return [
    'provider' => env('CV_SUMMARY_PROVIDER', 'openai'),
    'prompt_version' => '1.0',
    'max_source_characters' => (int) env('CV_SUMMARY_MAX_SOURCE_CHARACTERS', 30000),

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_CV_SUMMARY_MODEL', 'gpt-5-mini'),
        'timeout' => (int) env('OPENAI_CV_SUMMARY_TIMEOUT', 60),
        'connect_timeout' => (int) env('OPENAI_CV_SUMMARY_CONNECT_TIMEOUT', 10),
    ],

    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'model' => env('GROQ_CV_SUMMARY_MODEL', 'openai/gpt-oss-20b'),
        'timeout' => (int) env('GROQ_CV_SUMMARY_TIMEOUT', 60),
        'connect_timeout' => (int) env('GROQ_CV_SUMMARY_CONNECT_TIMEOUT', 10),
        'max_completion_tokens' => (int) env('GROQ_CV_SUMMARY_MAX_COMPLETION_TOKENS', 2048),
        'reasoning_effort' => env('GROQ_CV_SUMMARY_REASONING_EFFORT', 'low'),
        'temperature' => (float) env('GROQ_CV_SUMMARY_TEMPERATURE', 0.2),
    ],
];
