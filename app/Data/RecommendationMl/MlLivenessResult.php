<?php

namespace App\Data\RecommendationMl;

use App\Support\RecommendationMl\MlDataValidator;

final readonly class MlLivenessResult
{
    private function __construct(
        public string $status,
        public string $service,
        public string $serviceVersion,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        MlDataValidator::responseKeys(
            $data,
            ['status', 'service', 'service_version'],
            ['status', 'service', 'service_version'],
        );
        if ($data['status'] !== 'live'
            || $data['service'] !== 'ml-recommendation'
            || $data['service_version'] !== '0.2.0') {
            MlDataValidator::contractFailure(operation: 'live');
        }

        return new self('live', 'ml-recommendation', '0.2.0');
    }

    /**
     * @return array{status: string, service: string, service_version: string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'service' => $this->service,
            'service_version' => $this->serviceVersion,
        ];
    }
}
