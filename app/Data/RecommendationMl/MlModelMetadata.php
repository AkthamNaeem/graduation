<?php

namespace App\Data\RecommendationMl;

use App\Support\RecommendationMl\MlDataValidator;

final readonly class MlModelMetadata
{
    private function __construct(
        public string $apiContractVersion,
        public string $bundleVersion,
        public string $modelVersion,
        public string $modelFormat,
        public string $modelSha256,
        public string $datasetVersion,
        public string $featureSchemaVersion,
        public string $featureSchemaSha256,
        public int $featureCount,
        public string $modelSourceRevision,
        public string $scoreTransformVersion,
        public string $explanationContractVersion,
        public string $reasonCodeMappingVersion,
        public bool $ready,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(
        array $data,
        MlClientConfiguration $configuration,
    ): self {
        $keys = [
            'api_contract_version',
            'bundle_version',
            'model_version',
            'model_format',
            'model_sha256',
            'dataset_version',
            'feature_schema_version',
            'feature_schema_sha256',
            'feature_count',
            'model_source_revision',
            'score_transform_version',
            'explanation_contract_version',
            'reason_code_mapping_version',
            'ready',
        ];
        MlDataValidator::responseKeys($data, $keys, $keys);

        if ($data['api_contract_version'] !== $configuration->apiContractVersion
            || $data['bundle_version'] !== $configuration->bundleVersion
            || $data['model_version'] !== $configuration->modelVersion
            || $data['feature_schema_version'] !== $configuration->featureSchemaVersion
            || $data['score_transform_version'] !== $configuration->scoreTransformVersion
            || $data['explanation_contract_version']
                !== $configuration->explanationContractVersion
            || $data['reason_code_mapping_version'] !== 'recommendation-reason-codes-v1'
            || ! is_string($data['model_sha256'])
            || preg_match('/^[a-f0-9]{64}$/D', $data['model_sha256']) !== 1
            || ! is_string($data['feature_schema_sha256'])
            || preg_match('/^[a-f0-9]{64}$/D', $data['feature_schema_sha256']) !== 1
            || ! is_int($data['feature_count'])
            || $data['feature_count'] < 1
            || ! is_bool($data['ready'])
            || $data['ready'] !== true) {
            MlDataValidator::contractFailure(operation: 'metadata');
        }

        return new self(
            apiContractVersion: MlDataValidator::responseString(
                $data['api_contract_version'],
                operation: 'metadata',
            ),
            bundleVersion: MlDataValidator::responseString(
                $data['bundle_version'],
                operation: 'metadata',
            ),
            modelVersion: MlDataValidator::responseString(
                $data['model_version'],
                operation: 'metadata',
            ),
            modelFormat: MlDataValidator::responseString(
                $data['model_format'],
                operation: 'metadata',
            ),
            modelSha256: $data['model_sha256'],
            datasetVersion: MlDataValidator::responseString(
                $data['dataset_version'],
                operation: 'metadata',
            ),
            featureSchemaVersion: MlDataValidator::responseString(
                $data['feature_schema_version'],
                operation: 'metadata',
            ),
            featureSchemaSha256: $data['feature_schema_sha256'],
            featureCount: $data['feature_count'],
            modelSourceRevision: MlDataValidator::responseString(
                $data['model_source_revision'],
                operation: 'metadata',
            ),
            scoreTransformVersion: MlDataValidator::responseString(
                $data['score_transform_version'],
                operation: 'metadata',
            ),
            explanationContractVersion: MlDataValidator::responseString(
                $data['explanation_contract_version'],
                operation: 'metadata',
            ),
            reasonCodeMappingVersion: $data['reason_code_mapping_version'],
            ready: true,
        );
    }

    /**
     * @return array<string, bool|int|string>
     */
    public function toArray(): array
    {
        return [
            'api_contract_version' => $this->apiContractVersion,
            'bundle_version' => $this->bundleVersion,
            'model_version' => $this->modelVersion,
            'model_format' => $this->modelFormat,
            'model_sha256' => $this->modelSha256,
            'dataset_version' => $this->datasetVersion,
            'feature_schema_version' => $this->featureSchemaVersion,
            'feature_schema_sha256' => $this->featureSchemaSha256,
            'feature_count' => $this->featureCount,
            'model_source_revision' => $this->modelSourceRevision,
            'score_transform_version' => $this->scoreTransformVersion,
            'explanation_contract_version' => $this->explanationContractVersion,
            'reason_code_mapping_version' => $this->reasonCodeMappingVersion,
            'ready' => $this->ready,
        ];
    }
}
