<?php

namespace Tests\Unit;

use App\Contracts\CV\CVTextParser;
use App\Models\Skill;
use App\Services\CV\GroqCVTextParser;
use App\Services\CV\OpenAICVTextParser;
use App\Services\CV\RuleBasedCVTextParser;
use App\Services\CVParsingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CVParsingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_parse_text_extracts_core_cv_fields(): void
    {
        Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        Skill::create(['name' => 'MySQL', 'slug' => 'mysql']);
        Skill::create(['name' => 'Redis', 'slug' => 'redis']);

        $text = <<<'TEXT'
Jane Applicant
jane.applicant@example.com
+1 555 0100

Skills
Laravel, MySQL

Experience
Backend Developer at Northwind Software

Education
Bachelor of Science in Computer Science, State University
TEXT;

        $parsed = $this->app->make(CVParsingService::class)->parseText($text);

        $this->assertSame('jane.applicant@example.com', $parsed['email']);
        $this->assertSame('+1 555 0100', $parsed['phone']);
        $this->assertSame(['Laravel', 'MySQL'], $parsed['skills']);
        $this->assertSame('Backend Developer', $parsed['experience'][0]['title']);
        $this->assertSame('Northwind Software', $parsed['experience'][0]['company_name']);
        $this->assertSame('State University', $parsed['education'][0]['institution']);
        $this->assertSame('Bachelor of Science', $parsed['education'][0]['degree']);
        $this->assertSame('Computer Science', $parsed['education'][0]['field_of_study']);
    }

    public function test_unknown_driver_fails_with_clear_configuration_error(): void
    {
        config()->set('cv.parser.driver', 'unknown');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid CV parser driver. Supported drivers: openai, groq, rules.');

        $this->app->make(CVTextParser::class);
    }

    public function test_all_supported_drivers_resolve_independently(): void
    {
        foreach ([
            'rules' => RuleBasedCVTextParser::class,
            'openai' => OpenAICVTextParser::class,
            'groq' => GroqCVTextParser::class,
        ] as $driver => $parserClass) {
            config()->set('cv.parser.driver', $driver);

            $this->assertInstanceOf($parserClass, $this->app->make(CVTextParser::class));
        }
    }
}
