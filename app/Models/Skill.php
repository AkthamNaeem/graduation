<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Skill extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
    ];

    public function jobSeekerProfiles(): BelongsToMany
    {
        return $this->belongsToMany(JobSeekerProfile::class, 'job_seeker_skills')
            ->using(JobSeekerSkill::class)
            ->withTimestamps();
    }

    public function jobPostings(): BelongsToMany
    {
        return $this->belongsToMany(JobPosting::class, 'job_posting_skills')
            ->using(JobPostingSkill::class)
            ->withPivot('requirement_type')
            ->withTimestamps();
    }
}
