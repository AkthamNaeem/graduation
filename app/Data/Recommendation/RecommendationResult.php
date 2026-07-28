<?php

namespace App\Data\Recommendation;

final readonly class RecommendationResult
{
    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function __construct(
        public array $items,
        public RecommendationEngine $engine,
        public int $requestedLimit,
        public int $candidateCount,
        public int $returnedCount,
        public bool $fallbackUsed,
        public ?string $safeFallbackCode = null,
        public ?string $modelVersion = null,
        public ?string $featureSchemaVersion = null,
        public ?string $explanationContractVersion = null,
        public ?string $requestId = null,
        public bool $cacheHit = false,
        public bool $persistenceHit = false,
    ) {
        if ($requestedLimit < 1
            || $candidateCount < 0
            || $returnedCount < 0
            || $returnedCount !== count($items)
            || $returnedCount > $requestedLimit
            || $returnedCount > $candidateCount) {
            throw new \InvalidArgumentException('Recommendation result counts are invalid.');
        }
    }

    public function withLookupMetadata(
        bool $cacheHit,
        bool $persistenceHit,
    ): self {
        return new self(
            items: $this->items,
            engine: $this->engine,
            requestedLimit: $this->requestedLimit,
            candidateCount: $this->candidateCount,
            returnedCount: $this->returnedCount,
            fallbackUsed: $this->fallbackUsed,
            safeFallbackCode: $this->safeFallbackCode,
            modelVersion: $this->modelVersion,
            featureSchemaVersion: $this->featureSchemaVersion,
            explanationContractVersion: $this->explanationContractVersion,
            requestId: $this->requestId,
            cacheHit: $cacheHit,
            persistenceHit: $persistenceHit,
        );
    }
}
