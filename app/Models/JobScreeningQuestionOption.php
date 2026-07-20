<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobScreeningQuestionOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_screening_question_id',
        'option_text',
        'sort_order',
    ];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(JobScreeningQuestion::class, 'job_screening_question_id');
    }
}
