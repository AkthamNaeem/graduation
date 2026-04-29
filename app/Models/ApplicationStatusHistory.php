<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationStatusHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_application_id',
        'from_application_status_id',
        'to_application_status_id',
        'changed_by_user_id',
        'note',
    ];

    public function jobApplication(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class);
    }

    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(ApplicationStatus::class, 'from_application_status_id');
    }

    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(ApplicationStatus::class, 'to_application_status_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
