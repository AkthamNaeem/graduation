<?php

namespace App\Models;

use App\Data\Recommendation\RecommendationEngine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecommendationRun extends Model
{
    protected $fillable = [
        'job_seeker_profile_id',
        'request_id',
        'context_hash',
        'context_version',
        'requested_limit',
        'candidate_count',
        'returned_count',
        'engine',
        'fallback_used',
        'fallback_code',
        'model_version',
        'feature_schema_version',
        'explanation_contract_version',
        'generated_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_limit' => 'integer',
            'candidate_count' => 'integer',
            'returned_count' => 'integer',
            'engine' => RecommendationEngine::class,
            'fallback_used' => 'boolean',
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function jobSeekerProfile(): BelongsTo
    {
        return $this->belongsTo(JobSeekerProfile::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecommendationItem::class)->orderBy('rank');
    }
}
