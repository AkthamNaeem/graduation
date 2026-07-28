<?php

namespace App\Data\RecommendationMl;

use App\Support\RecommendationMl\MlDataValidator;

final readonly class MlReadinessResult
{
    private function __construct(
        public string $status,
        public string $service,
        public string $serviceVersion,
        public string $bundleVersion,
        public string $modelVersion,
        public string $featureSchemaVersion,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(
        array $data,
        MlClientConfiguration $configuration,
    ): self {
        $keys = [
            'status',
            'service',
            'service_version',
            'bundle_version',
            'model_version',
            'feature_schema_version',
        ];
        MlDataValidator::responseKeys($data, $keys, $keys);
        if ($data['status'] !== 'ready'
            || $data['service'] !== 'ml-recommendation'
            || $data['service_version'] !== '0.2.0'
            || $data['bundle_version'] !== $configuration->bundleVersion
            || $data['model_version'] !== $configuration->modelVersion
            || $data['feature_schema_version'] !== $configuration->featureSchemaVersion) {
            MlDataValidator::contractFailure(operation: 'ready');
        }

        return new self(
            'ready',
            'ml-recommendation',
            '0.2.0',
            $configuration->bundleVersion,
            $configuration->modelVersion,
            $configuration->featureSchemaVersion,
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'service' => $this->service,
            'service_version' => $this->serviceVersion,
            'bundle_version' => $this->bundleVersion,
            'model_version' => $this->modelVersion,
            'feature_schema_version' => $this->featureSchemaVersion,
        ];
    }
}
