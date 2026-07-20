<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobApplicationScreeningQuestionOption extends Model
{
    protected $fillable = [
        'application_question_id',
        'source_option_id',
        'option_text',
        'sort_order',
    ];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(JobApplicationScreeningQuestion::class, 'application_question_id');
    }
}
