<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveKitWebhookEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'interview_video_session_id',
        'event_id',
        'event_type',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }

    public function videoSession(): BelongsTo
    {
        return $this->belongsTo(InterviewVideoSession::class, 'interview_video_session_id');
    }
}
