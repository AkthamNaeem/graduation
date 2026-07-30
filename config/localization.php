<?php

return [
    'supported_locales' => array_values(array_filter(array_map(
        static fn (string $locale): string => strtolower(trim($locale)),
        explode(',', (string) env('APP_SUPPORTED_LOCALES', 'en,ar')),
    ))),
];
