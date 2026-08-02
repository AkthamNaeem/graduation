<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationCVSummary extends Model
{
    use HasFactory;

    protected $table = 'application_cv_summaries';

    protected $fillable = [
        'job_application_id',
        'source_cv_file_id',
        'generated_by_user_id',
        'locale',
        'provider',
        'model',
        'prompt_version',
        'input_hash',
        'headline',
        'summary',
        'strengths',
        'gaps',
        'evidence',
        'provider_request_id',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'strengths' => 'array',
            'gaps' => 'array',
            'evidence' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function jobApplication(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class);
    }

    public function sourceCVFile(): BelongsTo
    {
        return $this->belongsTo(CVFile::class, 'source_cv_file_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }
}
