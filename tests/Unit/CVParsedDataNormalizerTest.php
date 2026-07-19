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
FutureX
● Platform Engineer
▪ API Developer
◦ “Team Lead”
Damascus University
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

    public function test_layered_evidence_accepts_entities_multiline_freelance_overlap_and_education(): void
    {
        $rawText = <<<'TEXT'
EXPERIENCE
January 2025 - Present
Web Developer
Freelance
CMS customization &amp; plugin development

October 2024 - December 2025
Software Developer
Northwind Labs

EDUCATION
Bachelor&#039;s degree
Information Technology
State University
2020-2026 Expected
TEXT;
        $data = [
            'skills' => [],
            'experience' => [
                [
                    'title' => 'Web Developer', 'company_name' => 'Freelance',
                    'start_date' => '2025-01', 'end_date' => null, 'is_current' => true,
                    'description' => null, 'responsibilities' => ['CMS customization & plugin development'],
                    'evidence' => "Web Developer\nFreelance\nCMS customization and plugin development",
                    'confidence_score' => 1,
                ],
                [
                    'title' => 'Software Developer', 'company_name' => 'Northwind Labs',
                    'start_date' => '2024-10', 'end_date' => '2025-12', 'is_current' => false,
                    'description' => null, 'responsibilities' => [],
                    'evidence' => "October 2024 - December 2025\nSoftware Developer\nNorthwind Labs",
                    'confidence_score' => 1,
                ],
            ],
            'education' => [[
                'institution' => 'State University', 'degree' => "Bachelor's degree",
                'field_of_study' => 'Information Technology', 'start_year' => 2020,
                'graduation_year' => 2026, 'evidence' => "Bachelor's degree State University 2020 - 2026",
                'confidence_score' => 1,
            ]],
        ];

        $normalized = (new CVParsedDataNormalizer)->normalize($data, $rawText);

        $this->assertCount(2, $normalized['experience']);
        $this->assertSame('Freelance', $normalized['experience'][0]['company_name']);
        $this->assertCount(1, $normalized['education']);
        $this->assertSame("Bachelor's degree", $normalized['education'][0]['degree']);
    }

    public function test_identity_anchors_reject_missing_and_hallucinated_experiences_with_safe_diagnostics(): void
    {
        $data = [
            'skills' => ['Laravel'],
            'experience' => [
                ['title' => '', 'company_name' => 'Acme', 'evidence' => 'Acme'],
                ['title' => 'Ghost Developer', 'company_name' => 'Imaginary Inc', 'evidence' => 'Developer'],
            ],
            'education' => [
                ['institution' => '', 'degree' => 'BSc'],
                ['institution' => 'Imaginary University', 'degree' => 'BSc', 'evidence' => 'BSc'],
            ],
        ];

        $normalized = (new CVParsedDataNormalizer)->normalize($data, 'Real Candidate Laravel');
        $diagnostics = $normalized['_meta']['normalization'];

        $this->assertSame([], $normalized['experience']);
        $this->assertSame([], $normalized['education']);
        $this->assertSame(1, $diagnostics['dropped_counts']['experience_missing_identity']);
        $this->assertSame(1, $diagnostics['dropped_counts']['experience_invalid_evidence']);
        $this->assertSame(1, $diagnostics['dropped_counts']['education_missing_institution']);
        $this->assertSame(1, $diagnostics['dropped_counts']['education_invalid_evidence']);
        $this->assertSame(['experience' => 2, 'education' => 2, 'certifications' => 0, 'skills' => 1], $diagnostics['input_counts']);
        $this->assertSame(['experience' => 0, 'education' => 0, 'certifications' => 0, 'skills' => 1], $diagnostics['output_counts']);
        $this->assertStringNotContainsString('Imaginary', json_encode($diagnostics, JSON_THROW_ON_ERROR));
    }

    public function test_description_is_not_duplicated_from_responsibilities_and_confidence_is_evidence_bounded(): void
    {
        $rawText = "Backend Developer\nAcme\nJanuary 2024\nBuild APIs\nReview code";
        $data = ['skills' => [], 'education' => [], 'experience' => [[
            'title' => 'Backend Developer', 'company_name' => 'Acme',
            'start_date' => '2024-01', 'end_date' => null, 'is_current' => false,
            'description' => 'Build APIs',
            'responsibilities' => ['Build APIs', ' Build APIs ', 'Review code'],
            'evidence' => null, 'confidence_score' => 1,
        ]]];

        $normalized = (new CVParsedDataNormalizer)->normalize($data, $rawText);

        $this->assertNull($normalized['experience'][0]['description']);
        $this->assertSame(['Build APIs', 'Review code'], $normalized['experience'][0]['responsibilities']);
        $this->assertSame(0.75, $normalized['experience'][0]['confidence_score']);
    }

    public function test_skills_split_only_on_commas_outside_parentheses_and_deduplicate_case_insensitively(): void
    {
        $data = [
            'skills' => [
                'React, React Native, Expo',
                'react',
                'Angular (components, services, routing, reactive forms)',
                'Git, GitHub, GitLab',
                'UI/UX',
                'Problem solving &amp; debugging',
            ],
            'experience' => [],
            'education' => [],
        ];

        $normalized = (new CVParsedDataNormalizer)->normalize($data, '');

        $this->assertSame([
            'React', 'React Native', 'Expo',
            'Angular (components, services, routing, reactive forms)',
            'Git', 'GitHub', 'GitLab', 'UI/UX', 'Problem solving & debugging',
        ], $normalized['skills']);
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

    public function test_certifications_are_evidence_bounded_normalized_deduplicated_and_diagnosed(): void
    {
        $rawText = <<<'TEXT'
CERTIFICATIONS
2024
First Aid
Example Institute
Advanced Safety &amp; Response — 2023
2025 First Aid
PERSONAL INFORMATION
Nationality: Example Nationality
Marital Status: Single
TEXT;
        $base = ['skills' => [], 'experience' => [], 'education' => []];
        $certification = fn (array $overrides): array => array_replace([
            'name' => 'First Aid', 'issuer' => null, 'issue_year' => 2024,
            'expiration_year' => null, 'description' => null, 'evidence' => '2024 First Aid',
            'confidence_score' => 1,
        ], $overrides);
        $data = $base + [
            'nationality' => ' Example Nationality ',
            'marital_status' => ' Single ',
            'certifications' => [
                $certification([]),
                $certification([]),
                $certification(['issue_year' => 2025, 'evidence' => '2025 First Aid']),
                $certification([
                    'name' => 'Advanced Safety & Response', 'issuer' => 'Example Institute',
                    'issue_year' => 2023, 'evidence' => 'Advanced Safety & Response — 2023',
                ]),
                $certification(['name' => '', 'evidence' => '']),
                $certification(['name' => 'Imaginary Certificate', 'evidence' => 'Imaginary Certificate 2024']),
                $certification(['expiration_year' => 2022]),
            ],
        ];

        $normalized = (new CVParsedDataNormalizer)->normalize($data, $rawText);
        $diagnostics = $normalized['_meta']['normalization'];

        $this->assertSame('Example Nationality', $normalized['nationality']);
        $this->assertSame('Single', $normalized['marital_status']);
        $this->assertCount(3, $normalized['certifications']);
        $this->assertNull($normalized['certifications'][0]['issuer']);
        $this->assertSame([2024, 2025, 2023], array_column($normalized['certifications'], 'issue_year'));
        $this->assertSame('Advanced Safety & Response', $normalized['certifications'][2]['name']);
        $this->assertSame(7, $diagnostics['input_counts']['certifications']);
        $this->assertSame(3, $diagnostics['output_counts']['certifications']);
        $this->assertSame(1, $diagnostics['dropped_counts']['certification_missing_name']);
        $this->assertSame(1, $diagnostics['dropped_counts']['certification_invalid_evidence']);
        $this->assertSame(1, $diagnostics['dropped_counts']['certification_reversed_years']);
        $this->assertSame(1, $diagnostics['dropped_counts']['certification_duplicate']);
        $this->assertStringNotContainsString('First Aid', json_encode($diagnostics, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Example Nationality', json_encode($diagnostics, JSON_THROW_ON_ERROR));
    }

    public function test_missing_sensitive_values_remain_null_and_are_not_inferred_from_location_or_age(): void
    {
        $normalized = (new CVParsedDataNormalizer)->normalize([
            'location' => 'Example Country', 'birth_date' => '2000-01-01',
            'nationality' => null, 'marital_status' => null,
            'skills' => [], 'experience' => [], 'education' => [], 'certifications' => [],
        ], 'Lives in Example Country and is 26 years old');

        $this->assertNull($normalized['nationality']);
        $this->assertNull($normalized['marital_status']);
    }
}
