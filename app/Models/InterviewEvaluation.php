<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InterviewEvaluation extends Model
{
    use HasFactory;

    protected $fillable = [
        'interview_id',
        'evaluated_by_user_id',
        'recommendation',
        'overall_comment',
        'evaluated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'evaluated_at' => 'datetime',
        ];
    }

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    public function evaluatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InterviewEvaluationItem::class)->orderBy('sort_order')->orderBy('id');
    }
}
