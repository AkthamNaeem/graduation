<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class CVFile extends Model
{
    use HasFactory;

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
            && Storage::disk($this->disk)->exists($this->stored_path);
    }
}
