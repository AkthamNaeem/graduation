<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationInternalNoteRevision extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['application_internal_note_id', 'version', 'body', 'edited_by_user_id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'created_at' => 'datetime'];
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(ApplicationInternalNote::class, 'application_internal_note_id');
    }

    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by_user_id');
    }
}
