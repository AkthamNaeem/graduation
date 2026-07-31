<?php

return [
    'cv_summary' => [
        'enabled' => env('AI_CV_SUMMARY_ENABLED', true),
        'provider' => env('AI_CV_SUMMARY_PROVIDER', 'openai'),
        'model' => env('OPENAI_CV_SUMMARY_MODEL', 'gpt-5-mini'),
        'timeout' => (int) env('OPENAI_CV_SUMMARY_TIMEOUT', 45),
        'connect_timeout' => (int) env('OPENAI_CV_SUMMARY_CONNECT_TIMEOUT', 10),
        'max_input_characters' => (int) env('AI_CV_SUMMARY_MAX_INPUT_CHARACTERS', 30000),
        'prompt_version' => '1.0',
    ],
];
