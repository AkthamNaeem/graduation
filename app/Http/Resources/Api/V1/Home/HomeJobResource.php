<?php

namespace App\Http\Resources\Api\V1\Home;

use App\Models\JobPosting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HomeJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $recommendation = is_array($this->resource) ? $this->resource : null;
        /** @var JobPosting $job */
        $job = $recommendation['job'] ?? $this->resource;

        $data = [
            'id' => $job->id,
            'title' => $job->title,
            'company' => [
                'id' => $job->company?->id,
                'name' => $job->company?->name,
                'logo_url' => null,
            ],
            'location' => $job->location,
            'work_mode' => $job->work_mode?->value ?? $job->work_mode,
            'employment_type' => $job->employment_type,
            'published_at' => $job->published_at?->toISOString(),
        ];

        if ($recommendation !== null) {
            $data['match'] = [
                'score' => $recommendation['score'] ?? null,
                'matched_skills' => array_values($recommendation['matched_skills'] ?? []),
                'missing_skills' => collect($recommendation['missing_required_skills'] ?? [])
                    ->map(static fn (mixed $skill): string => is_array($skill)
                        ? (string) ($skill['name'] ?? '')
                        : (string) $skill)
                    ->filter()
                    ->values()
                    ->all(),
                'reasons' => collect($recommendation['reasons'] ?? [])
                    ->map(static fn (array $reason): array => [
                        'code' => $reason['code'] ?? null,
                        'message' => $reason['message'] ?? null,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return $data;
    }
}
