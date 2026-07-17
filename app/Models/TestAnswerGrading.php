<?php

namespace App\Models;

use App\Enums\TestAnswerGradingType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestAnswerGrading extends Model
{
    use HasFactory;

    protected $fillable = [
        'test_answer_id',
        'grading_type',
        'is_correct',
        'awarded_points',
        'max_points',
        'explanation',
        'graded_by',
        'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'grading_type' => TestAnswerGradingType::class,
            'is_correct' => 'boolean',
            'awarded_points' => 'decimal:2',
            'max_points' => 'decimal:2',
            'graded_at' => 'datetime',
        ];
    }

    public function testAnswer(): BelongsTo
    {
        return $this->belongsTo(TestAnswer::class);
    }

    public function gradedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }
}
