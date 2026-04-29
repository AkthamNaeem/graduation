<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_posting_id',
        'job_seeker_profile_id',
        'application_status_id',
    ];

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    public function jobSeekerProfile(): BelongsTo
    {
        return $this->belongsTo(JobSeekerProfile::class);
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
}
