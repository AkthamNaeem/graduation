<?php

namespace App\Models;

use App\Enums\JobSkillRequirementType;
use App\Enums\JobWorkMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobPosting extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'title',
        'department',
        'description',
        'responsibilities',
        'requirements',
        'benefits',
        'employment_type',
        'experience_level',
        'education_level',
        'location',
        'work_mode',
        'salary_min',
        'salary_max',
        'status',
        'published_at',
        'application_deadline',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
            'published_at' => 'datetime',
            'application_deadline' => 'datetime',
            'work_mode' => JobWorkMode::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'job_posting_skills')
            ->using(JobPostingSkill::class)
            ->withPivot(['requirement_type', 'weight'])
            ->withTimestamps();
    }

    public function jobApplications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    public function screeningQuestions(): HasMany
    {
        return $this->hasMany(JobScreeningQuestion::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function hasApplicationDeadline(): bool
    {
        return $this->application_deadline !== null;
    }

    public function isApplicationDeadlinePassed(): bool
    {
        return $this->application_deadline !== null && now()->gt($this->application_deadline);
    }

    public function acceptsApplications(): bool
    {
        return $this->isAcceptingApplications()
            && $this->company?->approval_status === 'approved';
    }

    public function isAcceptingApplications(): bool
    {
        return $this->status === 'open' && ! $this->isApplicationDeadlinePassed();
    }

    public function requiredSkillsCount(): int
    {
        return $this->skills()
            ->wherePivot('requirement_type', JobSkillRequirementType::REQUIRED->value)
            ->count();
    }
}
