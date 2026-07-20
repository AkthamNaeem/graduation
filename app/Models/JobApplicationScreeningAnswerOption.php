<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobApplicationScreeningAnswerOption extends Model
{
    protected $fillable = [
        'application_answer_id',
        'application_question_option_id',
    ];

    public function answer(): BelongsTo
    {
        return $this->belongsTo(JobApplicationScreeningAnswer::class, 'application_answer_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(JobApplicationScreeningQuestionOption::class, 'application_question_option_id');
    }
}
