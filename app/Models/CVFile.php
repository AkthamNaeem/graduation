<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CVFile extends Model
{
    use HasFactory;

    public const REVIEW_MODE_INITIAL_IMPORT = 'initial_import';

    public const REVIEW_MODE_PROFILE_SYNC = 'profile_sync';

    public const REVIEW_STATUS_DRAFT = 'draft';

    public const REVIEW_STATUS_COMPARISON_PENDING = 'comparison_pending';

    public const REVIEW_STATUS_DECISIONS_PENDING = 'decisions_pending';

    public const REVIEW_STATUS_READY_TO_APPLY = 'ready_to_apply';

    public const REVIEW_STATUS_APPLIED = 'applied';

    protected $table = 'cv_files';

    protected $fillable = [
        'user_id',
        'original_name',
        'version_label',
        'stored_path',
        'disk',
        'mime_type',
        'extension',
        'size_bytes',
        'status',
        'review_mode',
        'review_status',
        'error_message',
        'confirmed_at',
        'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parsingResult(): HasOne
    {
        return $this->hasOne(CVParsingResult::class, 'cv_file_id');
    }

    public function profileChangeSuggestions(): HasMany
    {
        return $this->hasMany(ProfileChangeSuggestion::class, 'cv_file_id');
    }

    public function selectedByApplications(): HasMany
    {
        return $this->hasMany(JobApplication::class, 'selected_cv_file_id');
    }

    public function isUsableForApplication(): bool
    {
        return $this->archived_at === null
            && in_array($this->status, ['uploaded', 'processing', 'parsed', 'failed'], true)
            && filled($this->disk)
            && filled($this->stored_path);
    }

    public function nextAction(): string
    {
        if (in_array($this->status, ['uploaded', 'processing'], true)) {
            return 'wait_for_parsing';
        }

        if ($this->status === 'failed') {
            return 'retry_upload';
        }

        if ($this->review_status === self::REVIEW_STATUS_APPLIED) {
            return 'completed';
        }

        if ($this->review_mode === self::REVIEW_MODE_INITIAL_IMPORT) {
            return 'review_draft';
        }

        return match ($this->review_status) {
            self::REVIEW_STATUS_COMPARISON_PENDING => 'generate_suggestions',
            self::REVIEW_STATUS_DECISIONS_PENDING => 'review_suggestions',
            self::REVIEW_STATUS_READY_TO_APPLY => 'apply_suggestions',
            default => 'wait_for_parsing',
        };
    }
}
