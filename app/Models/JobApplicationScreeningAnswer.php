<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobApplicationScreeningAnswer extends Model
{
    protected $fillable = [
        'job_application_id',
        'application_question_id',
        'text_value',
        'number_value',
        'boolean_value',
    ];

    protected function casts(): array
    {
        return [
            'number_value' => 'decimal:6',
            'boolean_value' => 'boolean',
        ];
    }

    public function jobApplication(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(JobApplicationScreeningQuestion::class, 'application_question_id');
    }

    public function selectedOptions(): HasMany
    {
        return $this->hasMany(JobApplicationScreeningAnswerOption::class, 'application_answer_id');
    }
}
