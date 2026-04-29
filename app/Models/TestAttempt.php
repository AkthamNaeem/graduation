<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_test_assignment_id',
        'answers',
        'started_at',
        'submitted_at',
        'score',
        'feedback',
        'evaluated_by_user_id',
        'evaluated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'score' => 'decimal:2',
            'evaluated_at' => 'datetime',
        ];
    }

    public function applicationTestAssignment(): BelongsTo
    {
        return $this->belongsTo(ApplicationTestAssignment::class);
    }

    public function evaluatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by_user_id');
    }
}
