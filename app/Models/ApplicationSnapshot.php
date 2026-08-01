<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ApplicationSnapshot extends Model
{
    public const SCHEMA_VERSION = 1;

    public const ORIGIN_SUBMISSION = 'captured_at_submission';

    public const ORIGIN_BACKFILL = 'backfilled';

    public const ACCURACY_EXACT = 'exact';

    public const ACCURACY_BEST_AVAILABLE = 'best_available';

    protected $fillable = [
        'job_application_id',
        'schema_version',
        'profile_snapshot',
        'application_answers_snapshot',
        'source_cv_file_id',
        'cv_original_name',
        'cv_mime_type',
        'cv_extension',
        'cv_size_bytes',
        'cv_checksum_sha256',
        'cv_disk',
        'cv_stored_path',
        'origin',
        'accuracy',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'profile_snapshot' => 'array',
            'application_answers_snapshot' => 'array',
            'source_cv_file_id' => 'integer',
            'cv_size_bytes' => 'integer',
            'captured_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Application snapshots are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Application snapshots cannot be deleted directly.');
        });
    }

    public function jobApplication(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class);
    }
}
