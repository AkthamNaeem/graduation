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

    protected $table = 'cv_files';

    protected $fillable = [
        'user_id',
        'original_name',
        'stored_path',
        'disk',
        'mime_type',
        'extension',
        'size_bytes',
        'status',
        'error_message',
        'confirmed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
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
}
