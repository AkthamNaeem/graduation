<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Interview extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_application_id',
        'scheduled_by_user_id',
        'interview_type',
        'status',
        'scheduled_at',
        'scheduled_end_at',
        'duration_minutes',
        'interview_mode',
        'location',
        'meeting_link',
        'candidate_message',
        'internal_note',
        'note',
        'confirmed_at',
        'confirmed_by_user_id',
        'completion_note',
        'completed_at',
        'completed_by_user_id',
        'cancellation_reason',
        'cancellation_message',
        'cancelled_at',
        'cancelled_by_user_id',
        'candidate_attendance_status',
        'interviewer_attendance_status',
        'attendance_recorded_at',
        'attendance_recorded_by_user_id',
        'attendance_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'attendance_recorded_at' => 'datetime',
        ];
    }

    public function jobApplication(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class);
    }

    public function scheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function attendanceRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendance_recorded_by_user_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(InterviewStatusHistory::class)->orderBy('id');
    }

    public function scheduleChanges(): HasMany
    {
        return $this->hasMany(InterviewScheduleChange::class)->orderBy('id');
    }

    public function evaluation(): HasOne
    {
        return $this->hasOne(InterviewEvaluation::class);
    }
}
