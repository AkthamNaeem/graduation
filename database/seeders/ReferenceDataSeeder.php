<?php

namespace Database\Seeders;

use App\Models\Skill;
use Illuminate\Database\Seeder;

class ReferenceDataSeeder extends Seeder
{
    /** @var list<string> */
    public const SKILLS = [
        'PHP',
        'Laravel',
        'MySQL',
        'PostgreSQL',
        'REST APIs',
        'Git',
        'Docker',
        'AWS',
        'JavaScript',
        'TypeScript',
        'React',
        'Vue.js',
        'Python',
        'Machine Learning',
        'Communication',
        'Problem Solving',
        'Testing',
        'Agile',
        'API Design',
    ];

    public function run(): void
    {
        $this->call(ApplicationStatusSeeder::class);

        foreach (self::SKILLS as $name) {
            Skill::query()->updateOrCreate(
                ['slug' => str($name)->slug()->toString()],
                ['name' => $name],
            );
        }
    }
}
