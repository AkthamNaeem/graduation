<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InterviewVideoSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'interview_id',
        'provider',
        'room_name',
        'enabled',
        'first_joined_at',
        'last_left_at',
        'room_started_at',
        'room_ended_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'first_joined_at' => 'datetime',
            'last_left_at' => 'datetime',
            'room_started_at' => 'datetime',
            'room_ended_at' => 'datetime',
        ];
    }

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(LiveKitWebhookEvent::class);
    }
}
