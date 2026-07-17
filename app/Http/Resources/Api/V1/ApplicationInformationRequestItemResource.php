<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationInformationRequestItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'description' => $this->description, 'is_required' => $this->is_required, 'order_index' => $this->order_index];
    }
}
