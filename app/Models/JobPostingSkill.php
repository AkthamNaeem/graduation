<?php

namespace App\Models;

use App\Enums\JobSkillRequirementType;
use Illuminate\Database\Eloquent\Relations\Pivot;

class JobPostingSkill extends Pivot
{
    protected $table = 'job_posting_skills';

    protected $fillable = [
        'job_posting_id',
        'skill_id',
        'requirement_type',
    ];

    protected function casts(): array
    {
        return [
            'requirement_type' => JobSkillRequirementType::class,
        ];
    }
}
