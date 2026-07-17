<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationInformationResponseAttachment extends Model
{
    use HasFactory;

    protected $fillable = ['application_information_response_id', 'original_name', 'stored_path', 'disk', 'mime_type', 'extension', 'size_bytes'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(ApplicationInformationResponse::class, 'application_information_response_id');
    }
}
