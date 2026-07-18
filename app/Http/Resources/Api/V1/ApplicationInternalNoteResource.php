<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ApplicationInternalNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApplicationInternalNote */
class ApplicationInternalNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $final = in_array($this->jobApplication?->applicationStatus?->slug, ['accepted', 'rejected', 'withdrawn'], true);
        $approved = $this->jobApplication?->jobPosting?->company?->approval_status === 'approved';
        $canMutate = $actor !== null && $actor->id === $this->author_user_id && ! $this->trashed()
            && ! $final && $approved && $this->isWithinEditWindow();

        return [
            'id' => $this->id,
            'application_id' => $this->job_application_id,
            'body' => $this->trashed() ? null : $this->body,
            'version' => $this->version,
            'is_edited' => $this->edited_at !== null,
            'is_deleted' => $this->trashed(),
            'can_edit' => $canMutate,
            'can_delete' => $canMutate,
            'edit_deadline_at' => $this->editDeadline()->toISOString(),
            'author' => ApplicationInternalNoteAuthorResource::make($this->whenLoaded('author')),
            'created_at' => $this->created_at?->toISOString(),
            'edited_at' => $this->edited_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
