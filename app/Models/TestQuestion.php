<?php

namespace App\Models;

use App\Enums\TestQuestionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TestQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'test_id',
        'question_text',
        'question_type',
        'order_index',
        'points',
        'is_required',
    ];

    protected function casts(): array
    {
        return [
            'question_type' => TestQuestionType::class,
            'points' => 'decimal:2',
            'is_required' => 'boolean',
        ];
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(TestOption::class)->orderBy('order_index')->orderBy('id');
    }

    public function testAnswers(): HasMany
    {
        return $this->hasMany(TestAnswer::class);
    }
}
