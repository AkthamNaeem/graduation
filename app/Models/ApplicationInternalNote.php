<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class ApplicationInternalNote extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['job_application_id', 'author_user_id', 'body', 'version', 'edited_at', 'deleted_by_user_id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'edited_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    public function jobApplication(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ApplicationInternalNoteRevision::class);
    }

    public function editDeadline(): Carbon
    {
        return $this->created_at->copy()->addMinutes((int) config('application.internal_note_edit_window_minutes', 15));
    }

    public function isWithinEditWindow(): bool
    {
        return now()->lte($this->editDeadline());
    }
}
