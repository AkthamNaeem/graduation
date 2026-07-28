<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecommendationItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'recommendation_run_id',
        'job_posting_id',
        'rank',
        'score',
        'raw_score',
        'matching_score_version',
        'breakdown',
        'reasons',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'score' => 'decimal:4',
            'raw_score' => 'decimal:10',
            'breakdown' => 'array',
            'reasons' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function recommendationRun(): BelongsTo
    {
        return $this->belongsTo(RecommendationRun::class);
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }
}
