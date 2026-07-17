<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class JobApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_posting_id',
        'job_seeker_profile_id',
        'selected_cv_file_id',
        'application_status_id',
        'cover_letter',
        'consent_to_share_profile',
        'screening_answers',
    ];

    protected $casts = [
        'consent_to_share_profile' => 'boolean',
        'screening_answers' => 'array',
    ];

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    public function jobSeekerProfile(): BelongsTo
    {
        return $this->belongsTo(JobSeekerProfile::class);
    }

    public function selectedCvFile(): BelongsTo
    {
        return $this->belongsTo(CVFile::class, 'selected_cv_file_id');
    }

    public function applicationStatus(): BelongsTo
    {
        return $this->belongsTo(ApplicationStatus::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ApplicationStatusHistory::class)->orderBy('id');
    }

    public function applicationTestAssignments(): HasMany
    {
        return $this->hasMany(ApplicationTestAssignment::class)->latest();
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class)->latest('scheduled_at')->latest('id');
    }

    public function informationRequests(): HasMany
    {
        return $this->hasMany(ApplicationInformationRequest::class)->latest('id');
    }

    public function latestInformationRequest(): HasOne
    {
        return $this->hasOne(ApplicationInformationRequest::class)->latestOfMany();
    }
}
