<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationTestAssignmentDeadlineChange extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_test_assignment_id',
        'previous_deadline_at',
        'new_deadline_at',
        'changed_by_user_id',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'previous_deadline_at' => 'datetime',
            'new_deadline_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ApplicationTestAssignment::class, 'application_test_assignment_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
