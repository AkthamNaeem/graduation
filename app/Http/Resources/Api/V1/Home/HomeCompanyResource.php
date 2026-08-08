<?php

namespace App\Http\Resources\Api\V1\Home;

use App\Support\CompanyMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HomeCompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            ...CompanyMedia::urls($this->resource),
            'industry' => $this->industry,
            'location' => $this->location,
            'open_jobs_count' => (int) $this->open_jobs_count,
        ];
    }
}
