<?php

namespace Tests\Unit;

use App\Services\CV\CVParsingPrompt;
use App\Services\CV\CVParsingSchema;
use Tests\TestCase;

class CVParsingSchemaTest extends TestCase
{
    public function test_provider_contract_requires_the_additive_fields(): void
    {
        $definition = (new CVParsingSchema)->definition();

        $this->assertContains('nationality', $definition['required']);
        $this->assertContains('marital_status', $definition['required']);
        $this->assertContains('certifications', $definition['required']);
        $this->assertFalse($definition['additionalProperties']);
        $this->assertFalse($definition['properties']['certifications']['items']['additionalProperties']);
        $this->assertSame(
            ['name', 'issuer', 'issue_year', 'expiration_year', 'description', 'evidence', 'confidence_score'],
            $definition['properties']['certifications']['items']['required'],
        );
    }

    public function test_nullable_sensitive_fields_and_an_empty_certification_array_are_valid(): void
    {
        $schema = new CVParsingSchema;

        $this->assertTrue($schema->matches($this->valid()));
        $this->assertTrue($schema->matches($this->valid(['nationality' => 'Example Nationality', 'marital_status' => 'Single'])));
    }

    public function test_a_complete_certification_is_valid(): void
    {
        $this->assertTrue((new CVParsingSchema)->matches($this->valid(['certifications' => [[
            'name' => 'Emergency Response', 'issuer' => 'Example Institute', 'issue_year' => 2024,
            'expiration_year' => 2026, 'description' => null, 'evidence' => '2024 Emergency Response',
            'confidence_score' => 0.9,
        ]]])));
    }

    public function test_missing_name_unknown_properties_and_reversed_years_are_invalid(): void
    {
        $schema = new CVParsingSchema;
        $certification = [
            'name' => 'Emergency Response', 'issuer' => null, 'issue_year' => 2026,
            'expiration_year' => 2024, 'description' => null, 'evidence' => 'Emergency Response',
            'confidence_score' => 1,
        ];

        $withoutName = $certification;
        unset($withoutName['name']);
        $this->assertFalse($schema->matches($this->valid(['certifications' => [$withoutName]])));
        $this->assertFalse($schema->matches($this->valid(['certifications' => [$certification + ['unexpected' => true]]])));
        $this->assertFalse($schema->matches($this->valid(['certifications' => [$certification]])));
        $this->assertFalse($schema->matches(array_diff_key($this->valid(), ['nationality' => true])));
    }

    public function test_both_prompts_include_non_inference_and_certification_rules(): void
    {
        $prompt = new CVParsingPrompt;

        foreach ([$prompt->text(), $prompt->jsonObjectText()] as $text) {
            $this->assertStringContainsString('Extract every explicitly listed certification', $text);
            $this->assertStringContainsString('Never infer nationality', $text);
            $this->assertStringContainsString('Never infer marital status', $text);
            $this->assertStringContainsString('Do not classify education as certification', $text);
            $this->assertStringContainsString('empty certifications array', $text);
        }
    }

    /** @param array<string, mixed> $overrides */
    private function valid(array $overrides = []): array
    {
        return array_replace([
            'full_name' => null, 'email' => null, 'phone' => null, 'location' => null,
            'birth_date' => null, 'nationality' => null, 'marital_status' => null, 'summary' => null,
            'experience' => [], 'education' => [], 'certifications' => [], 'skills' => [], 'languages' => [],
        ], $overrides);
    }
}
