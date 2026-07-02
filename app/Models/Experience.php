<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Experience extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_seeker_profile_id',
        'title',
        'company_name',
        'location',
        'start_date',
        'end_date',
        'is_current',
        'description',
        'source_type',
        'source_cv_file_id',
        'user_verified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_current' => 'boolean',
            'user_verified_at' => 'datetime',
        ];
    }

    public function jobSeekerProfile(): BelongsTo
    {
        return $this->belongsTo(JobSeekerProfile::class);
    }

    public function sourceCvFile(): BelongsTo
    {
        return $this->belongsTo(CVFile::class, 'source_cv_file_id');
    }
}
