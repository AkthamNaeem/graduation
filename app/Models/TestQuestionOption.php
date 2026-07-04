<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestQuestionOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'test_question_id',
        'option_text',
        'is_correct',
        'order_index',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'order_index' => 'integer',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(TestQuestion::class, 'test_question_id');
    }
}
