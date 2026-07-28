<?php

namespace App\Data\RecommendationMl;

use App\Support\RecommendationMl\MlDataValidator;

final readonly class MlRankResponse
{
    public const EXPLANATION_NOTE =
        'Model attribution only; not a probability or hiring decision.';

    /**
     * @param  list<MlRankPrediction>  $predictions
     */
    private function __construct(
        public string $requestId,
        public string $apiContractVersion,
        public string $bundleVersion,
        public string $modelVersion,
        public string $datasetVersion,
        public string $featureSchemaVersion,
        public string $modelSourceRevision,
        public string $scoreTransformVersion,
        public string $explanationContractVersion,
        public int $requestedLimit,
        public int $predictionCount,
        public array $predictions,
        public string $explanationNote,
        public float $latencyMs,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(
        array $data,
        MlRankRequest $request,
        MlClientConfiguration $configuration,
    ): self {
        $required = [
            'request_id',
            'api_contract_version',
            'bundle_version',
            'model_version',
            'dataset_version',
            'feature_schema_version',
            'model_source_revision',
            'score_transform_version',
            'explanation_contract_version',
            'requested_limit',
            'prediction_count',
            'predictions',
            'explanation_note',
            'latency_ms',
        ];
        MlDataValidator::responseKeys($data, $required, $required);

        $requestId = MlDataValidator::responseString(
            $data['request_id'],
            requestId: $request->requestId,
            operation: 'rank',
        );
        if ($requestId !== $request->requestId
            || $data['api_contract_version'] !== $configuration->apiContractVersion
            || $data['bundle_version'] !== $configuration->bundleVersion
            || $data['model_version'] !== $configuration->modelVersion
            || $data['feature_schema_version'] !== $configuration->featureSchemaVersion
            || $data['explanation_contract_version']
                !== $configuration->explanationContractVersion
            || $data['score_transform_version'] !== $configuration->scoreTransformVersion
            || $data['explanation_note'] !== self::EXPLANATION_NOTE
            || ! is_int($data['requested_limit'])
            || $data['requested_limit'] !== $request->limit
            || ! is_int($data['prediction_count'])
            || $data['prediction_count'] !== count($request->jobs)
            || ! is_array($data['predictions'])
            || ! array_is_list($data['predictions'])
            || count($data['predictions']) !== $data['prediction_count']) {
            MlDataValidator::contractFailure(requestId: $request->requestId, operation: 'rank');
        }

        $predictions = array_map(function (mixed $prediction) use ($request): MlRankPrediction {
            if (! is_array($prediction)) {
                MlDataValidator::contractFailure(
                    requestId: $request->requestId,
                    operation: 'rank',
                );
            }

            return MlRankPrediction::fromArray($prediction, $request->requestId);
        }, $data['predictions']);

        self::reconcile($request, $predictions);
        self::assertRanksAndOrdering($predictions, $request->requestId);

        return new self(
            requestId: $requestId,
            apiContractVersion: MlDataValidator::responseString(
                $data['api_contract_version'],
                requestId: $requestId,
                operation: 'rank',
            ),
            bundleVersion: MlDataValidator::responseString(
                $data['bundle_version'],
                requestId: $requestId,
                operation: 'rank',
            ),
            modelVersion: MlDataValidator::responseString(
                $data['model_version'],
                requestId: $requestId,
                operation: 'rank',
            ),
            datasetVersion: MlDataValidator::responseString(
                $data['dataset_version'],
                requestId: $requestId,
                operation: 'rank',
            ),
            featureSchemaVersion: MlDataValidator::responseString(
                $data['feature_schema_version'],
                requestId: $requestId,
                operation: 'rank',
            ),
            modelSourceRevision: MlDataValidator::responseString(
                $data['model_source_revision'],
                requestId: $requestId,
                operation: 'rank',
            ),
            scoreTransformVersion: MlDataValidator::responseString(
                $data['score_transform_version'],
                requestId: $requestId,
                operation: 'rank',
            ),
            explanationContractVersion: MlDataValidator::responseString(
                $data['explanation_contract_version'],
                requestId: $requestId,
                operation: 'rank',
            ),
            requestedLimit: $data['requested_limit'],
            predictionCount: $data['prediction_count'],
            predictions: $predictions,
            explanationNote: self::EXPLANATION_NOTE,
            latencyMs: MlDataValidator::finiteResponseNumber(
                $data['latency_ms'],
                0,
                INF,
                $requestId,
                'rank',
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'api_contract_version' => $this->apiContractVersion,
            'bundle_version' => $this->bundleVersion,
            'model_version' => $this->modelVersion,
            'dataset_version' => $this->datasetVersion,
            'feature_schema_version' => $this->featureSchemaVersion,
            'model_source_revision' => $this->modelSourceRevision,
            'score_transform_version' => $this->scoreTransformVersion,
            'explanation_contract_version' => $this->explanationContractVersion,
            'requested_limit' => $this->requestedLimit,
            'prediction_count' => $this->predictionCount,
            'predictions' => array_map(
                fn (MlRankPrediction $prediction): array => $prediction->toArray(),
                $this->predictions,
            ),
            'explanation_note' => $this->explanationNote,
            'latency_ms' => $this->latencyMs,
        ];
    }

    /**
     * @param  list<MlRankPrediction>  $predictions
     */
    private static function reconcile(MlRankRequest $request, array $predictions): void
    {
        $expected = $request->jobIds();
        $returned = array_map(fn (MlRankPrediction $prediction): int => $prediction->jobId, $predictions);
        $uniqueReturned = array_values(array_unique($returned, SORT_REGULAR));
        $missing = array_diff($expected, $uniqueReturned);
        $extra = array_diff($uniqueReturned, $expected);

        if (count($returned) !== count($uniqueReturned) || $missing !== [] || $extra !== []) {
            MlDataValidator::contractFailure(
                'ML_RESPONSE_JOB_RECONCILIATION_FAILED',
                $request->requestId,
                'rank',
            );
        }
    }

    /**
     * @param  list<MlRankPrediction>  $predictions
     */
    private static function assertRanksAndOrdering(array $predictions, string $requestId): void
    {
        foreach ($predictions as $index => $prediction) {
            if ($prediction->rank !== $index + 1) {
                MlDataValidator::contractFailure(
                    'ML_RESPONSE_RANK_INVALID',
                    $requestId,
                    'rank',
                );
            }

            if ($index === 0) {
                continue;
            }

            $previous = $predictions[$index - 1];
            if ($previous->rawScore < $prediction->rawScore
                || ($previous->rawScore === $prediction->rawScore
                    && $previous->jobId > $prediction->jobId)) {
                MlDataValidator::contractFailure(
                    'ML_RESPONSE_ORDER_INVALID',
                    $requestId,
                    'rank',
                );
            }
        }
    }
}
