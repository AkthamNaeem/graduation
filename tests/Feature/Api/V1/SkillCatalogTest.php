<?php

namespace Tests\Feature\Api\V1;

use App\Models\Skill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkillCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_users_can_list_skills_with_default_limit_and_name_ordering(): void
    {
        Skill::create(['name' => 'Vue.js', 'slug' => 'vue-js']);
        Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);

        $this->getJson('/api/v1/skills')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Laravel')
            ->assertJsonPath('data.1.name', 'Vue.js');
    }

    public function test_skill_search_matches_name_and_slug_and_limit_is_applied(): void
    {
        Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        Skill::create(['name' => 'REST APIs', 'slug' => 'rest-apis']);
        Skill::create(['name' => 'React', 'slug' => 'react']);

        $this->getJson('/api/v1/skills?search=api&limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'REST APIs');
    }

    public function test_skill_limit_is_validated(): void
    {
        $this->getJson('/api/v1/skills?limit=101')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['limit']);
    }
}
