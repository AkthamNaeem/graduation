<?php

namespace App\Models;

use App\Enums\TestAttemptGradingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TestAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_test_assignment_id',
        'answers',
        'started_at',
        'submitted_at',
        'objective_score',
        'objective_max_score',
        'manual_score',
        'manual_max_score',
        'total_score',
        'max_score',
        'percentage',
        'grading_status',
        'auto_graded_at',
        'manually_graded_at',
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
            'objective_score' => 'decimal:2',
            'objective_max_score' => 'decimal:2',
            'manual_score' => 'decimal:2',
            'manual_max_score' => 'decimal:2',
            'total_score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'percentage' => 'decimal:2',
            'grading_status' => TestAttemptGradingStatus::class,
            'auto_graded_at' => 'datetime',
            'manually_graded_at' => 'datetime',
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

    public function testAnswers(): HasMany
    {
        return $this->hasMany(TestAnswer::class)->orderBy('test_question_id');
    }

    public function passingScoreMet(): ?bool
    {
        if (
            ! in_array($this->grading_status, [
                TestAttemptGradingStatus::AUTO_GRADED,
                TestAttemptGradingStatus::FULLY_GRADED,
            ], true)
            || $this->total_score === null
            || (float) $this->max_score <= 0
        ) {
            return null;
        }

        $passingScore = $this->applicationTestAssignment?->test?->passing_score;

        return $passingScore === null ? null : (float) $this->total_score >= (float) $passingScore;
    }
}
