<?php

namespace App\Models;

use App\Enums\ScreeningQuestionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class JobApplicationScreeningQuestion extends Model
{
    protected $fillable = [
        'job_application_id',
        'source_question_id',
        'question_text',
        'question_type',
        'is_required',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'question_type' => ScreeningQuestionType::class,
            'is_required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function jobApplication(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(JobApplicationScreeningQuestionOption::class, 'application_question_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function answer(): HasOne
    {
        return $this->hasOne(JobApplicationScreeningAnswer::class, 'application_question_id');
    }
}
