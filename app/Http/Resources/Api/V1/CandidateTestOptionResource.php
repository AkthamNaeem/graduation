<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TestOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TestOption */
class CandidateTestOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'option_text' => $this->option_text,
            'order_index' => $this->order_index,
        ];
    }
}
