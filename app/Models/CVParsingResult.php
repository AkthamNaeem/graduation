<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CVParsingResult extends Model
{
    use HasFactory;

    protected $table = 'cv_parsing_results';

    protected $fillable = [
        'cv_file_id',
        'raw_text',
        'parsed_json',
        'reviewed_json',
        'reviewed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parsed_json' => 'array',
            'reviewed_json' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function cvFile(): BelongsTo
    {
        return $this->belongsTo(CVFile::class, 'cv_file_id');
    }
}
