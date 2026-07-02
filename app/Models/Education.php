<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Education extends Model
{
    use HasFactory;

    protected $table = 'education';

    protected $fillable = [
        'job_seeker_profile_id',
        'institution',
        'degree',
        'field_of_study',
        'start_date',
        'end_date',
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
