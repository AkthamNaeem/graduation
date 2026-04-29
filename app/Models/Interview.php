<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Interview extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_application_id',
        'scheduled_by_user_id',
        'interview_type',
        'scheduled_at',
        'duration_minutes',
        'interview_mode',
        'location',
        'meeting_link',
        'note',
        'completion_note',
        'completed_at',
        'completed_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function evaluation(): HasOne
    {
        return $this->hasOne(InterviewEvaluation::class);
    }
}
