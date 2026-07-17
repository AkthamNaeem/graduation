<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApplicationInformationResponse extends Model
{
    use HasFactory;

    protected $fillable = ['application_information_request_id', 'submitted_by_user_id', 'message', 'submitted_at'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    public function informationRequest(): BelongsTo
    {
        return $this->belongsTo(ApplicationInformationRequest::class, 'application_information_request_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ApplicationInformationResponseAttachment::class);
    }
}
