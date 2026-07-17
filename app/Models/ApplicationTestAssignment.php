<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class ApplicationTestAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_application_id',
        'test_id',
        'assigned_by_user_id',
        'note',
        'assigned_at',
        'deadline_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'deadline_at' => 'datetime',
        ];
    }

    public function jobApplication(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class);
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function testAttempt(): HasOne
    {
        return $this->hasOne(TestAttempt::class);
    }

    public function testAttempts(): HasMany
    {
        return $this->hasMany(TestAttempt::class);
    }

    public function deadlineChanges(): HasMany
    {
        return $this->hasMany(ApplicationTestAssignmentDeadlineChange::class);
    }

    public function hasDeadline(): bool
    {
        return $this->deadline_at !== null;
    }

    public function isExpired(?Carbon $at = null): bool
    {
        if (! $this->hasDeadline()) {
            return false;
        }

        $attempt = $this->relationLoaded('testAttempt')
            ? $this->getRelation('testAttempt')
            : $this->testAttempt()->first();

        return $attempt?->submitted_at === null
            && ($at ?? now())->greaterThan($this->deadline_at);
    }

    public function isAvailable(?Carbon $at = null): bool
    {
        return ! $this->isExpired($at);
    }

    public function remainingSeconds(?Carbon $at = null): ?int
    {
        if (! $this->hasDeadline()) {
            return null;
        }

        return max(0, (int) ($at ?? now())->diffInSeconds($this->deadline_at, false));
    }
}
