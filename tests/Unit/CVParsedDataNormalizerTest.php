<?php

namespace Tests\Unit;

use App\Services\CV\CVParsedDataNormalizer;
use PHPUnit\Framework\TestCase;

class CVParsedDataNormalizerTest extends TestCase
{
    public function test_it_rejects_unsafe_experiences_and_invalid_evidence_and_deduplicates_skills(): void
    {
        $rawText = "Laravel Developer\nFutureX\nrouting, guards, and state management.\nValid evidence line";
        $data = [
            'skills' => ['Laravel', ' laravel ', 'React', ''],
            'experience' => [
                ['title' => 'January 2026', 'company_name' => 'Present', 'evidence' => 'January 2026'],
                ['title' => 'routing', 'company_name' => 'guards, and state management.', 'evidence' => 'routing, guards, and state management.'],
                ['title' => 'Laravel Developer', 'company_name' => 'FutureX', 'evidence' => 'Valid evidence line', 'start_date' => '2026-01', 'end_date' => 'Present', 'is_current' => false],
                ['title' => 'Ghost', 'company_name' => 'Company', 'evidence' => 'not in source'],
            ],
            'education' => [
                ['institution' => '', 'evidence' => 'Valid evidence line'],
                ['institution' => 'Damascus University', 'evidence' => 'missing'],
            ],
        ];

        $normalized = (new CVParsedDataNormalizer)->normalize($data, $rawText);

        $this->assertSame(['Laravel', 'React'], $normalized['skills']);
        $this->assertCount(1, $normalized['experience']);
        $this->assertSame('Laravel Developer', $normalized['experience'][0]['title']);
        $this->assertNull($normalized['experience'][0]['end_date']);
        $this->assertTrue($normalized['experience'][0]['is_current']);
        $this->assertSame([], $normalized['education']);
    }

    public function test_it_rejects_reversed_dates(): void
    {
        $data = ['skills' => [], 'education' => [], 'experience' => [[
            'title' => 'Developer', 'company_name' => 'Acme', 'evidence' => 'Developer at Acme',
            'start_date' => '2026-01', 'end_date' => '2025-12',
        ]]];

        $normalized = (new CVParsedDataNormalizer)->normalize($data, 'Developer at Acme');

        $this->assertSame([], $normalized['experience']);
    }
}
