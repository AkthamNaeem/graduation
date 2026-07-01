<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileChangeSuggestion extends Model
{
    use HasFactory;

    public const ENTITY_PROFILE = 'profile';
    public const ENTITY_EXPERIENCE = 'experience';
    public const ENTITY_EDUCATION = 'education';
    public const ENTITY_SKILL = 'skill';

    public const TYPE_ADD = 'add';
    public const TYPE_UPDATE = 'update';
    public const TYPE_MERGE = 'merge';
    public const TYPE_IGNORE = 'ignore';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_APPLIED = 'applied';

    public const SOURCE_CV_PARSED = 'cv_parsed';

    protected $fillable = [
        'user_id',
        'cv_file_id',
        'job_seeker_profile_id',
        'entity_type',
        'suggestion_type',
        'status',
        'source',
        'old_value',
        'new_value',
        'user_edited_value',
        'confidence_score',
        'reason',
        'applied_at',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_value' => 'array',
            'new_value' => 'array',
            'user_edited_value' => 'array',
            'confidence_score' => 'float',
            'applied_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cvFile(): BelongsTo
    {
        return $this->belongsTo(CVFile::class, 'cv_file_id');
    }

    public function jobSeekerProfile(): BelongsTo
    {
        return $this->belongsTo(JobSeekerProfile::class);
    }
}
