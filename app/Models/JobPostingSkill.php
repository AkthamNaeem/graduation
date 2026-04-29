<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class JobPostingSkill extends Pivot
{
    protected $table = 'job_posting_skills';

    protected $fillable = [
        'job_posting_id',
        'skill_id',
    ];
}
