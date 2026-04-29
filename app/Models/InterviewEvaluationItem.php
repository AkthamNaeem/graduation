<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterviewEvaluationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'interview_evaluation_id',
        'criterion',
        'score',
        'comment',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(InterviewEvaluation::class, 'interview_evaluation_id');
    }
}
