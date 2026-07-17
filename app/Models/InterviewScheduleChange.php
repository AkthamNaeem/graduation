<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterviewScheduleChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'interview_id', 'previous_start_at', 'previous_end_at', 'new_start_at', 'new_end_at',
        'previous_mode', 'new_mode', 'previous_meeting_link', 'new_meeting_link',
        'previous_location_text', 'new_location_text', 'changed_by_user_id', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'previous_start_at' => 'datetime', 'previous_end_at' => 'datetime',
            'new_start_at' => 'datetime', 'new_end_at' => 'datetime', 'created_at' => 'datetime',
        ];
    }

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
