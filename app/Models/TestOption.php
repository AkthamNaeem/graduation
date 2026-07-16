<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TestOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'test_question_id',
        'option_text',
        'order_index',
        'is_correct',
    ];

    protected function casts(): array
    {
        return ['is_correct' => 'boolean'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(TestQuestion::class, 'test_question_id');
    }

    public function testAnswers(): BelongsToMany
    {
        return $this->belongsToMany(TestAnswer::class, 'test_answer_options')->withTimestamps();
    }
}
