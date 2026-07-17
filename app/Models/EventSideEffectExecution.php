<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventSideEffectExecution extends Model
{
    protected $fillable = [
        'event_name',
        'listener_name',
        'aggregate_type',
        'aggregate_id',
        'recipient_user_id',
        'executed_at',
    ];

    protected $guarded = [
        'effect_key',
    ];

    protected function casts(): array
    {
        return [
            'executed_at' => 'datetime',
        ];
    }
}
