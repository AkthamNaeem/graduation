<?php

namespace App\Data\Recommendation;

final readonly class RecommendationContext
{
    public function __construct(
        public string $hash,
        public string $version,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1 || $version === '') {
            throw new \InvalidArgumentException('Recommendation context is invalid.');
        }
    }
}
