<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TestAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'test_attempt_id',
        'test_question_id',
        'answer_text',
        'file_path',
        'file_disk',
        'file_original_name',
        'file_mime_type',
        'file_size',
    ];

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }

    public function testAttempt(): BelongsTo
    {
        return $this->belongsTo(TestAttempt::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(TestQuestion::class, 'test_question_id');
    }

    public function selectedOptions(): BelongsToMany
    {
        return $this->belongsToMany(TestOption::class, 'test_answer_options')->withTimestamps();
    }
}
