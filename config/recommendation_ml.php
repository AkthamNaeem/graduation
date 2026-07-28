<?php

return [
    'enabled' => filter_var(
        env('ML_RECOMMENDATION_ENABLED', false),
        FILTER_VALIDATE_BOOL,
    ),
    'base_url' => env('ML_RECOMMENDATION_BASE_URL', 'http://127.0.0.1:8100'),
    'service_token' => env('ML_RECOMMENDATION_SERVICE_TOKEN'),
    'connect_timeout_seconds' => (int) env('ML_RECOMMENDATION_CONNECT_TIMEOUT_SECONDS', 2),
    'timeout_seconds' => (int) env('ML_RECOMMENDATION_TIMEOUT_SECONDS', 10),
    'max_jobs_per_request' => (int) env('ML_RECOMMENDATION_MAX_JOBS_PER_REQUEST', 500),
    'max_results' => (int) env('ML_RECOMMENDATION_MAX_RESULTS', 100),
    'api_contract_version' => env(
        'ML_RECOMMENDATION_API_CONTRACT_VERSION',
        'recommendation-ranking-api-v1',
    ),
    'bundle_version' => env(
        'ML_RECOMMENDATION_BUNDLE_VERSION',
        'job-rec-inference-bundle-v1',
    ),
    'model_version' => env(
        'ML_RECOMMENDATION_MODEL_VERSION',
        'xgbranker-tuned-v1',
    ),
    'feature_schema_version' => env(
        'ML_RECOMMENDATION_FEATURE_SCHEMA_VERSION',
        'job-rec-features-v1',
    ),
    'explanation_contract_version' => env(
        'ML_RECOMMENDATION_EXPLANATION_CONTRACT_VERSION',
        'recommendation-explanation-contract-v1',
    ),
    'score_transform_version' => env(
        'ML_RECOMMENDATION_SCORE_TRANSFORM_VERSION',
        'validation-minmax-selected-trial-t06-v1',
    ),
    'persistence' => [
        'cache_enabled' => filter_var(
            env('RECOMMENDATION_CACHE_ENABLED', true),
            FILTER_VALIDATE_BOOL,
        ),
        'cache_ttl_seconds' => (int) env('RECOMMENDATION_CACHE_TTL_SECONDS', 900),
        'fallback_cache_ttl_seconds' => (int) env(
            'RECOMMENDATION_FALLBACK_CACHE_TTL_SECONDS',
            60,
        ),
        'empty_cache_ttl_seconds' => (int) env(
            'RECOMMENDATION_EMPTY_CACHE_TTL_SECONDS',
            60,
        ),
        'run_retention_days' => (int) env('RECOMMENDATION_RUN_RETENTION_DAYS', 30),
        'context_version' => 'recommendation-context-v1',
        'cache_schema_version' => 'recommendation-cache-pointer-v1',
        'ranking_policy_version' => 'raw-score-published-at-job-id-v1',
    ],
];
