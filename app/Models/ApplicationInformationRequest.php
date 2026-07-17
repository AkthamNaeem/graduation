<?php

namespace App\Models;

use App\Enums\ApplicationInformationRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ApplicationInformationRequest extends Model
{
    use HasFactory;

    protected $fillable = ['job_application_id', 'requested_by_user_id', 'message', 'due_at', 'status', 'previous_application_status', 'responded_at', 'cancelled_at', 'cancelled_by_user_id'];

    protected function casts(): array
    {
        return [
            'status' => ApplicationInformationRequestStatus::class,
            'due_at' => 'datetime',
            'responded_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function jobApplication(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ApplicationInformationRequestItem::class)->orderBy('order_index');
    }

    public function response(): HasOne
    {
        return $this->hasOne(ApplicationInformationResponse::class);
    }

    public function isPending(): bool
    {
        return $this->status === ApplicationInformationRequestStatus::PENDING;
    }

    public function isResponded(): bool
    {
        return $this->status === ApplicationInformationRequestStatus::RESPONDED;
    }

    public function isCancelled(): bool
    {
        return $this->status === ApplicationInformationRequestStatus::CANCELLED;
    }

    public function isExpired(): bool
    {
        return $this->isPending() && $this->due_at !== null && now()->gt($this->due_at);
    }

    public function canBeRespondedTo(): bool
    {
        return $this->isPending() && ! $this->isExpired() && $this->response === null;
    }
}
