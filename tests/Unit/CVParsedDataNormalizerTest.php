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

    public function test_evidence_matching_canonicalizes_dashes_quotes_and_bullets(): void
    {
        $rawText = <<<'TEXT'
October 2024 – December 2025
Bachelor’s degree
• Laravel Developer
● Platform Engineer
▪ API Developer
◦ “Team Lead”
TEXT;
        $data = [
            'skills' => [],
            'experience' => [[
                'title' => 'Laravel Developer',
                'company_name' => 'FutureX',
                'evidence' => "October 2024-December 2025\nBachelor's degree\nLaravel Developer\nPlatform Engineer\nAPI Developer\n\"Team Lead\"",
            ]],
            'education' => [[
                'institution' => 'Damascus University',
                'evidence' => "Bachelor's degree",
            ]],
        ];

        $normalized = (new CVParsedDataNormalizer)->normalize($data, $rawText);

        $this->assertCount(1, $normalized['experience']);
        $this->assertCount(1, $normalized['education']);
    }

    public function test_birth_date_normalization_is_deterministic_and_rejects_partial_or_invalid_dates(): void
    {
        $normalizer = new CVParsedDataNormalizer;
        $base = ['skills' => [], 'experience' => [], 'education' => []];

        $this->assertSame('2002-04-21', $normalizer->normalize($base + ['birth_date' => '2002-04-21'], '')['birth_date']);
        $this->assertSame('2002-04-21', $normalizer->normalize($base + ['birth_date' => '21 April 2002'], '')['birth_date']);
        $this->assertSame('2002-04-21', $normalizer->normalize($base + ['birth_date' => 'April 21, 2002'], '')['birth_date']);
        $this->assertNull($normalizer->normalize($base + ['birth_date' => '2002-04'], '')['birth_date']);
        $this->assertNull($normalizer->normalize($base + ['birth_date' => '31 April 2002'], '')['birth_date']);
        $this->assertNull($normalizer->normalize($base + ['birth_date' => 'next Tuesday'], '')['birth_date']);
    }
}
