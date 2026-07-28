<?php

namespace App\Services\Recommendation;

use App\Contracts\Recommendation\RecommendationMlClientFactoryContract;
use App\Data\Recommendation\RecommendationMlClientHandle;
use App\Data\RecommendationMl\MlClientConfiguration;
use App\Services\RecommendationMl\RecommendationMlClient;
use App\Support\RecommendationMl\MlOutboundPayloadGuard;
use Illuminate\Http\Client\Factory;

final readonly class RecommendationMlClientFactory implements RecommendationMlClientFactoryContract
{
    /**
     * @param  array<string, mixed>  $configuration
     */
    public function __construct(
        private Factory $http,
        private MlOutboundPayloadGuard $payloadGuard,
        private array $configuration,
    ) {}

    public function make(): RecommendationMlClientHandle
    {
        $configuration = MlClientConfiguration::fromArray($this->configuration);

        return new RecommendationMlClientHandle(
            new RecommendationMlClient(
                $this->http,
                $configuration,
                $this->payloadGuard,
            ),
            $configuration,
        );
    }
}
