<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ApplicationTestAssignment extends Model
{
    use HasFactory;

    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_STARTED = 'started';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_EVALUATED = 'evaluated';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'job_application_id',
        'test_id',
        'assigned_by_user_id',
        'note',
        'status',
        'assigned_at',
        'deadline_at',
        'test_snapshot',
        'started_at',
        'submitted_at',
        'evaluated_at',
        'cancelled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'deadline_at' => 'datetime',
            'test_snapshot' => 'array',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'evaluated_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
}
