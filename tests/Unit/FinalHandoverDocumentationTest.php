<?php

namespace Tests\Unit;

use Tests\TestCase;

class FinalHandoverDocumentationTest extends TestCase
{
    private const PHASE_16_EXCEPTION_SHA256 =
        'B53A3950A53552542C10A6B2DDA2FF5B3BC3C5B25870D8C5310507515514E422';

    private const PHASE_17_BASELINE_SHA256 =
        'CB3959FF2550064CA4F7D82953A1E6E0A539A1C32A2BC97ABB1E64F09FDBCAC0';

    private const PHASE_17_MATRIX_SHA256 =
        '039755B90097B2A0AFE34E444F49E143FF9466789247222DBA81FED82FA0006C';

    private const PHASE_17_REPORT_SHA256 =
        '6FB97968B6FD59890308CC16BDAD7BF7E69E0382BC4A1B20BF7C5F245D223C10';

    private const PHASE_18_BASELINE_SHA256 =
        'F425D51C0094D2D2AAAFC220C02FE3DA4AA1796DDC59534F0F5E471A27995521';

    public function test_final_handover_documentation_contract(): void
    {
        $phase18Files = [
            'docs/ml-job-recommendation/PHASE_18_PROTECTED_BASELINE.json',
            'docs/ml-job-recommendation/phase18/FINAL_HANDOVER.md',
            'docs/ml-job-recommendation/phase18/FINAL_VERIFICATION_REPORT.md',
            'docs/ml-job-recommendation/phase18/DEMO_RUNBOOK.md',
            'docs/ml-job-recommendation/phase18/REQUIREMENTS_TRACEABILITY_MATRIX.json',
            'docs/ml-job-recommendation/phase18/PROJECT_COMPLETION_CHECKLIST.md',
            'docs/ml-job-recommendation/phase18/GRADUATION_DEFENSE_GUIDE_AR.md',
            'docs/ml-job-recommendation/phase18/FINAL_HANDOVER_MANIFEST.json',
        ];
        foreach ($phase18Files as $path) {
            $this->assertRepositoryFileExists($path);
        }

        $manifest = $this->readJsonArtifact(
            'docs/ml-job-recommendation/phase18/FINAL_HANDOVER_MANIFEST.json',
        );
        $matrix = $this->readJsonArtifact(
            'docs/ml-job-recommendation/phase18/REQUIREMENTS_TRACEABILITY_MATRIX.json',
        );

        $this->assertManifestContract($manifest);
        $this->assertTraceabilityContract($matrix);
        $this->assertProtectedBaselines($manifest);
        $this->assertManifestSourcesAndOutputs($manifest);
        $this->assertReadmeLinks();
        $this->assertDocumentationPrivacy($phase18Files);
        $this->assertPortabilityRemediation();
        $this->assertDemoAndArabicGuide();
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function assertManifestContract(array $manifest): void
    {
        foreach ([
            'manifest_version',
            'source_revision',
            'branch',
            'handover_release_date',
            'completion_scope',
            'implementation_status',
            'production_deployment_status',
            'phase_count',
            'completed_phases',
            'final_progress_percent',
            'model_version',
            'bundle_version',
            'feature_schema_version',
            'api_contract_version',
            'explanation_contract_version',
            'matching_fallback_version',
            'container_image_tag',
            'protected_baseline',
            'source_documents',
            'source_hashes',
            'verification_summary',
            'known_limitations',
            'output_files',
        ] as $key) {
            $this->assertArrayHasKey($key, $manifest);
        }

        $this->assertSame(
            'smart-recruitment-ml-handover-v1',
            $manifest['manifest_version'],
        );
        $this->assertSame(
            'complete_with_documented_production_limitations',
            $manifest['implementation_status'],
        );
        $this->assertSame(
            'not_deployed',
            $manifest['production_deployment_status'],
        );
        $this->assertSame(18, $manifest['phase_count']);
        $this->assertSame(18, $manifest['completed_phases']);
        $this->assertSame(100, $manifest['final_progress_percent']);
        $this->assertSame('xgbranker-tuned-v1', $manifest['model_version']);
        $this->assertSame(
            'job-rec-inference-bundle-v1',
            $manifest['bundle_version'],
        );
        $this->assertSame(
            'job-rec-features-v1',
            $manifest['feature_schema_version'],
        );
        $this->assertSame(
            'recommendation-ranking-api-v1',
            $manifest['api_contract_version'],
        );
        $this->assertSame(
            'recommendation-explanation-contract-v1',
            $manifest['explanation_contract_version'],
        );
        $this->assertSame(
            'MatchingService 2.0',
            $manifest['matching_fallback_version'],
        );
    }

    /**
     * @param  array<string, mixed>  $matrix
     */
    private function assertTraceabilityContract(array $matrix): void
    {
        $this->assertSame(
            'recommendation-requirements-traceability-v1',
            $matrix['matrix_version'],
        );
        $this->assertSame($matrix['requirement_count'], count($matrix['requirements']));
        $this->assertGreaterThanOrEqual(25, $matrix['requirement_count']);

        $ids = [];
        $statuses = [
            'completed',
            'completed_with_limitation',
            'out_of_scope',
            'future_work',
        ];
        foreach ($matrix['requirements'] as $requirement) {
            $this->assertSame([
                'requirement_id',
                'category',
                'requirement',
                'implementation_status',
                'implementation_files',
                'test_files',
                'evidence_artifacts',
                'limitations',
                'phase_completed',
            ], array_keys($requirement));
            $this->assertContains($requirement['implementation_status'], $statuses);
            $this->assertNotEmpty($requirement['evidence_artifacts']);
            $ids[] = $requirement['requirement_id'];

            foreach (array_merge(
                $requirement['implementation_files'],
                $requirement['test_files'],
                $requirement['evidence_artifacts'],
            ) as $path) {
                $this->assertRelativeRepositoryPath($path);
                $this->assertRepositoryFileExists($path);
            }
        }

        $sortedIds = $ids;
        sort($sortedIds, SORT_STRING);
        $this->assertSame($sortedIds, $ids);
        $this->assertSame(count($ids), count(array_unique($ids)));
        $this->assertSame(
            count(array_filter(
                $matrix['requirements'],
                fn (array $requirement): bool => $requirement['implementation_status']
                    === 'completed',
            )),
            $matrix['status_counts']['completed'],
        );
        $this->assertSame(
            count(array_filter(
                $matrix['requirements'],
                fn (array $requirement): bool => $requirement['implementation_status']
                    === 'completed_with_limitation',
            )),
            $matrix['status_counts']['completed_with_limitation'],
        );
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function assertProtectedBaselines(array $manifest): void
    {
        $this->assertSame(
            self::PHASE_18_BASELINE_SHA256,
            $this->sha256(
                'docs/ml-job-recommendation/PHASE_18_PROTECTED_BASELINE.json',
            ),
        );
        $this->assertSame(
            self::PHASE_17_BASELINE_SHA256,
            $this->sha256(
                'docs/ml-job-recommendation/PHASE_17_PROTECTED_BASELINE.json',
            ),
        );
        $this->assertSame(
            self::PHASE_16_EXCEPTION_SHA256,
            $this->sha256(
                'docs/ml-job-recommendation/PHASE_16_INTEGRITY_EXCEPTION.md',
            ),
        );
        $this->assertSame(
            self::PHASE_17_MATRIX_SHA256,
            $this->sha256(
                'docs/ml-job-recommendation/phase17/E2E_TEST_MATRIX.json',
            ),
        );
        $this->assertSame(
            self::PHASE_17_REPORT_SHA256,
            $this->sha256(
                'docs/ml-job-recommendation/phase17/PHASE_17_E2E_REPORT.md',
            ),
        );

        $baseline = $this->readJsonArtifact(
            'docs/ml-job-recommendation/PHASE_18_PROTECTED_BASELINE.json',
        );
        $this->assertSame('phase-18-protected-baseline-v1', $baseline['baseline_version']);
        $this->assertSame(873, $baseline['file_count']);
        $this->assertCount(873, $baseline['files']);
        $this->assertSame(
            '5CC89EC6F445E1DB25CABED4666314890186080D0799B1954CB8F0AF59D233A4',
            $baseline['aggregate_sha256'],
        );
        $this->assertFalse(collect($baseline['files'])->contains(
            fn (array $entry): bool => $entry['path']
                === 'docs/ml-job-recommendation/PHASE_18_PROTECTED_BASELINE.json',
        ));

        $records = [];
        $paths = [];
        $approvedDocumentation = [
            'README.md',
            'services/ml-recommendation/README.md',
            'BACKEND_IMPLEMENTATION_REPORT.md',
            // Approved Phase 18 final-gate test-maintenance change.
            'tests/Feature/Api/V1/RecommendationEndToEndTest.php',
            // Approved post-handover commit-safety portability remediations.
            'services/ml-recommendation/data/baselines/v1/BASELINE_REPORT.md',
            'services/ml-recommendation/data/baselines/v1/manifest.json',
            'services/ml-recommendation/src/smart_recruitment_ml/baselines/evaluator.py',
            // Approved post-handover provenance-integrity remediation.
            'services/ml-recommendation/src/smart_recruitment_ml/training/trainer.py',
            'services/ml-recommendation/tests/test_model_artifacts.py',
        ];
        foreach ($baseline['files'] as $entry) {
            $this->assertRelativeRepositoryPath($entry['path']);
            $this->assertRepositoryFileExists($entry['path']);
            if (! in_array($entry['path'], $approvedDocumentation, true)) {
                $this->assertSame(
                    $entry['size_bytes'],
                    filesize(base_path($entry['path'])),
                    $entry['path'],
                );
                $this->assertSame(
                    $entry['sha256'],
                    $this->sha256($entry['path']),
                    $entry['path'],
                );
            }
            $records[] = implode('|', [
                $entry['path'],
                $entry['size_bytes'],
                $entry['sha256'],
            ]);
            $paths[] = $entry['path'];
        }
        $sortedPaths = $paths;
        sort($sortedPaths, SORT_STRING);
        $this->assertSame($sortedPaths, $paths);
        $this->assertSame(count($paths), count(array_unique($paths)));
        $this->assertSame(
            $baseline['aggregate_sha256'],
            strtoupper(hash('sha256', implode("\n", $records)."\n")),
        );

        $this->assertSame(
            self::PHASE_18_BASELINE_SHA256,
            $manifest['protected_baseline']['sha256'],
        );
        $this->assertSame(873, $manifest['protected_baseline']['file_count']);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function assertManifestSourcesAndOutputs(array $manifest): void
    {
        $expectedFrozen = [
            'docs/ml-job-recommendation/ARCHITECTURE.md' => '60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F',
            'services/ml-recommendation/data/bundles/recommendation/v1/model.json' => '3ABD74137BC8881667643F31A658C790EF6712359D7802EA7FCFFA0C4CF9E26E',
            'services/ml-recommendation/data/bundles/recommendation/v1/feature_schema.json' => 'AEB260B25F34B55B7164B215E613A0B4327DF33EE65AF95ABC904045849CE4A0',
            'services/ml-recommendation/data/bundles/recommendation/v1/bundle_manifest.json' => '1D566E4516724FAE0C08CD6131214C0722DFFCD589A370CF2405B8B0450DFB00',
            'services/ml-recommendation/data/contracts/inference/v1/openapi.json' => 'B73B11B5FA67C40927E5A05AB72E2D2F7B292FA3149F0D945AE74BE08F7CA96D',
            'services/ml-recommendation/data/contracts/inference/v1/contract_manifest.json' => 'A51E8F4E74189CCB086BDB7FE32816C6E56953533F3C77243E50650BE0BF9CB2',
            'services/ml-recommendation/deployment/container/v1/container_manifest.json' => '1DD6BB89D805544266F04B6EA1C5BD4E71C00DF7AC97E78473DDE6A19B431FD1',
        ];
        $actualSources = [];
        foreach ($manifest['source_hashes'] as $source) {
            $this->assertSame(
                ['name', 'path', 'sha256'],
                array_keys($source),
            );
            $this->assertRelativeRepositoryPath($source['path']);
            $this->assertRepositoryFileExists($source['path']);
            $this->assertSame($source['sha256'], $this->sha256($source['path']));
            $actualSources[$source['path']] = $source['sha256'];
        }
        foreach ($expectedFrozen as $path => $hash) {
            $this->assertSame($hash, $actualSources[$path] ?? null, $path);
        }

        $manifestPath =
            'docs/ml-job-recommendation/phase18/FINAL_HANDOVER_MANIFEST.json';
        $outputPaths = [];
        foreach ($manifest['output_files'] as $output) {
            $this->assertSame(
                ['path', 'size_bytes', 'sha256'],
                array_keys($output),
            );
            $this->assertRelativeRepositoryPath($output['path']);
            $this->assertNotSame($manifestPath, $output['path']);
            $this->assertRepositoryFileExists($output['path']);
            $this->assertSame(
                $output['size_bytes'],
                filesize(base_path($output['path'])),
                $output['path'],
            );
            $this->assertSame(
                $output['sha256'],
                $this->sha256($output['path']),
                $output['path'],
            );
            $outputPaths[] = $output['path'];
        }
        $this->assertSame(count($outputPaths), count(array_unique($outputPaths)));

        foreach ($manifest['source_documents'] as $path) {
            $this->assertRelativeRepositoryPath($path);
            $this->assertRepositoryFileExists($path);
        }
    }

    private function assertReadmeLinks(): void
    {
        $root = file_get_contents(base_path('README.md'));
        $service = file_get_contents(
            base_path('services/ml-recommendation/README.md'),
        );
        foreach ([
            'docs/ml-job-recommendation/phase18/FINAL_HANDOVER.md',
            'docs/ml-job-recommendation/phase18/DEMO_RUNBOOK.md',
            'services/ml-recommendation/DEPLOYMENT.md',
            'docs/ml-job-recommendation/phase17/PHASE_17_E2E_REPORT.md',
        ] as $path) {
            $this->assertStringContainsString("]({$path})", $root);
            $this->assertRepositoryFileExists($path);
        }
        foreach ([
            '../../docs/ml-job-recommendation/phase18/FINAL_HANDOVER.md',
            '../../docs/ml-job-recommendation/phase18/DEMO_RUNBOOK.md',
            '../../docs/ml-job-recommendation/phase18/FINAL_HANDOVER_MANIFEST.json',
        ] as $link) {
            $this->assertStringContainsString("]({$link})", $service);
            $resolved = realpath(base_path('services/ml-recommendation/'.$link));
            $this->assertNotFalse($resolved, $link);
        }
    }

    /**
     * @param  list<string>  $phase18Files
     */
    private function assertDocumentationPrivacy(array $phase18Files): void
    {
        $text = '';
        foreach ($phase18Files as $path) {
            $text .= file_get_contents(base_path($path))."\n";
        }
        $this->assertDoesNotMatchRegularExpression(
            '/(?:^|[\s("`])[A-Za-z]:[\\\\\/]/m',
            $text,
        );
        $this->assertStringNotContainsString('/home/', $text);
        $this->assertStringNotContainsString('file://', strtolower($text));
        $this->assertDoesNotMatchRegularExpression(
            '/Bearer\s+(?!<sanctum-token>)[A-Za-z0-9._-]{16,}/i',
            $text,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
            $text,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/production deployment (?:was|is|has been) (?:completed|performed|deployed)/i',
            $text,
        );
    }

    private function assertPortabilityRemediation(): void
    {
        $text = '';
        foreach ([
            'services/ml-recommendation/README.md',
            'services/ml-recommendation/data/baselines/v1/BASELINE_REPORT.md',
            'services/ml-recommendation/src/smart_recruitment_ml/baselines/evaluator.py',
        ] as $path) {
            $text .= file_get_contents(base_path($path))."\n";
        }

        $manifestPath =
            'services/ml-recommendation/data/baselines/v1/manifest.json';
        $manifestText = file_get_contents(base_path($manifestPath));
        $this->assertIsString($manifestText);
        $text .= $manifestText."\n";
        $manifest = json_decode(
            $manifestText,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $reportPath =
            'services/ml-recommendation/data/baselines/v1/BASELINE_REPORT.md';
        $reportRecord = collect($manifest['output_files'])->firstWhere(
            'path',
            $reportPath,
        );
        $this->assertIsArray($reportRecord);
        $this->assertSame(
            filesize(base_path($reportPath)),
            $reportRecord['size_bytes'],
        );
        $this->assertSame(
            strtolower($this->sha256($reportPath)),
            $reportRecord['sha256'],
        );
        $currentBaselineHash = $this->sha256($manifestPath);
        $this->assertSame(
            'C591708A58AE66941BB004CE08522EAADC90F476105F7BED08B5E2DB477046BF',
            $currentBaselineHash,
        );

        $trainer = file_get_contents(base_path(
            'services/ml-recommendation/src/smart_recruitment_ml/training/trainer.py',
        ));
        $this->assertIsString($trainer);
        $this->assertStringContainsString(
            "\"baseline_manifest\": \"{$currentBaselineHash}\".lower()",
            $trainer,
        );

        $artifactTest = file_get_contents(base_path(
            'services/ml-recommendation/tests/test_model_artifacts.py',
        ));
        $this->assertIsString($artifactTest);
        $this->assertStringContainsString(
            'frozen_manifest = json.loads(frozen_bytes["manifest.json"])',
            $artifactTest,
        );
        $this->assertStringContainsString(
            'historical_locked_test = _frozen_source_artifact(',
            $artifactTest,
        );
        $this->assertStringContainsString(
            '"services/ml-recommendation/data/splits/v1/test.jsonl"',
            $artifactTest,
        );
        $this->assertStringContainsString(
            'historical_locked_test_sha256 = str(historical_locked_test["sha256"])',
            $artifactTest,
        );
        $this->assertStringContainsString(
            'monkeypatch.setattr(Path, "open", reject_locked_test_open)',
            $artifactTest,
        );
        $this->assertStringContainsString(
            'pytest.fail("The historical reproduction test must not open the Locked Test.")',
            $artifactTest,
        );

        foreach ([
            'C:/xampp/htdocs/workeyx',
            'C:\\xampp\\htdocs\\workeyx',
            'C:\\Users\\2025',
            'AppData\\Local\\Programs\\Python',
        ] as $machineLocalValue) {
            $this->assertStringNotContainsString($machineLocalValue, $text);
        }
        $this->assertDoesNotMatchRegularExpression(
            '/(?:^|[\s("`])[A-Za-z]:[\\\\\/][^\r\n`]*Python[^\r\n`]*/mi',
            $text,
        );
    }

    private function assertDemoAndArabicGuide(): void
    {
        $demo = file_get_contents(
            base_path('docs/ml-job-recommendation/phase18/DEMO_RUNBOOK.md'),
        );
        foreach ([
            '<temporary-token>',
            '<local-database>',
            '<sanctum-token>',
        ] as $placeholder) {
            $this->assertStringContainsString($placeholder, $demo);
        }
        $this->assertStringContainsString(
            'scripts/phase17/run-e2e.ps1',
            $demo,
        );

        $arabic = file_get_contents(
            base_path(
                'docs/ml-job-recommendation/phase18/GRADUATION_DEFENSE_GUIDE_AR.md',
            ),
        );
        $this->assertGreaterThan(5_000, strlen($arabic));
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $arabic);
        $this->assertStringContainsString('AI', $arabic);
        $this->assertStringContainsString('Probability', $arabic);
        $this->assertStringContainsString('SHAP', $arabic);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonArtifact(string $path): array
    {
        return json_decode(
            file_get_contents(base_path($path)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function sha256(string $path): string
    {
        return strtoupper(hash_file('sha256', base_path($path)));
    }

    private function assertRelativeRepositoryPath(string $path): void
    {
        $this->assertNotSame('', $path);
        $this->assertFalse(str_starts_with($path, '/'));
        $this->assertDoesNotMatchRegularExpression('/^[A-Za-z]:[\\\\\/]/', $path);
        $this->assertStringNotContainsString('\\', $path);
        $this->assertStringNotContainsString('../', $path);
    }

    private function assertRepositoryFileExists(string $path): void
    {
        $this->assertFileExists(base_path($path), $path);
    }
}
