<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\CVParsingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $parsed = (new CVParsingService())->parseText($text);

        $this->assertSame('jane.applicant@example.com', $parsed['email']);
        $this->assertSame('+1 555 0100', $parsed['phone']);
        $this->assertSame(['Laravel', 'MySQL'], $parsed['skills']);
        $this->assertSame('Backend Developer', $parsed['experience'][0]['title']);
        $this->assertSame('Northwind Software', $parsed['experience'][0]['company_name']);
        $this->assertSame('State University', $parsed['education'][0]['institution']);
        $this->assertSame('Bachelor of Science', $parsed['education'][0]['degree']);
        $this->assertSame('Computer Science', $parsed['education'][0]['field_of_study']);
    }
}
