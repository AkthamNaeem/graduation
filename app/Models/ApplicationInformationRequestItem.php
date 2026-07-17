<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationInformationRequestItem extends Model
{
    use HasFactory;

    protected $fillable = ['application_information_request_id', 'label', 'description', 'is_required', 'order_index'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'order_index' => 'integer'];
    }

    public function informationRequest(): BelongsTo
    {
        return $this->belongsTo(ApplicationInformationRequest::class, 'application_information_request_id');
    }
}
