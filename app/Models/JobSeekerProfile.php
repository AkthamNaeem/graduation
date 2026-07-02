<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobSeekerProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'headline',
        'summary',
        'phone',
        'location',
        'portfolio_url',
        'linkedin_url',
        'github_url',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function experiences(): HasMany
    {
        return $this->hasMany(Experience::class);
    }

    public function education(): HasMany
    {
        return $this->hasMany(Education::class);
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'job_seeker_skills')
            ->using(JobSeekerSkill::class)
            ->withPivot(['source_type', 'source_cv_file_id', 'user_verified_at'])
            ->withTimestamps();
    }

    public function jobApplications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    public function profileChangeSuggestions(): HasMany
    {
        return $this->hasMany(ProfileChangeSuggestion::class);
    }
}
