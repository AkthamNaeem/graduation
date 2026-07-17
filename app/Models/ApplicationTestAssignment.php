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
        'series_root_assignment_id',
        'previous_assignment_id',
        'attempt_number',
        'max_attempts',
        'test_id',
        'assigned_by_user_id',
        'retake_granted_by_user_id',
        'note',
        'retake_reason',
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
            'attempt_number' => 'integer',
            'max_attempts' => 'integer',
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

    public function retakeGrantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retake_granted_by_user_id');
    }

    public function seriesRoot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'series_root_assignment_id');
    }

    public function previousAssignment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_assignment_id');
    }

    public function nextAssignment(): HasOne
    {
        return $this->hasOne(self::class, 'previous_assignment_id');
    }

    public function seriesAssignments(): HasMany
    {
        return $this->hasMany(self::class, 'series_root_assignment_id');
    }

    public function seriesRootId(): int
    {
        return $this->series_root_assignment_id ?? $this->id;
    }

    public function isLatestAssignment(): bool
    {
        return ! $this->nextAssignment()->exists();
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
