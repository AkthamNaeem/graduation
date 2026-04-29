<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class JobSeekerSkill extends Pivot
{
    protected $table = 'job_seeker_skills';

    protected $fillable = [
        'job_seeker_profile_id',
        'skill_id',
    ];
}
