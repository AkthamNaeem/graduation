<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\JobSeekerAvailabilityStatus;
use App\Models\JobSeekerProfile;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin JobSeekerProfile */
class ProfileAvailabilityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $status = $this->availability_status;
        if (is_string($status)) {
            $status = JobSeekerAvailabilityStatus::tryFrom($status);
        }

        $availableFrom = $this->available_from;
        $isoDate = $availableFrom instanceof CarbonInterface
            ? $availableFrom->format('Y-m-d')
            : (is_string($availableFrom) ? $availableFrom : null);

        return [
            'status' => $status instanceof JobSeekerAvailabilityStatus ? [
                'key' => $status->value,
                'label' => __("profile.availability.statuses.{$status->value}"),
            ] : null,
            'available_from' => $isoDate,
            'display_label' => $this->displayLabel($status, $availableFrom),
        ];
    }

    private function displayLabel(mixed $status, mixed $availableFrom): ?string
    {
        if (! $status instanceof JobSeekerAvailabilityStatus) {
            return null;
        }

        if ($status === JobSeekerAvailabilityStatus::AVAILABLE_FROM_DATE) {
            if (! $availableFrom instanceof CarbonInterface) {
                return null;
            }

            return __('profile.availability.display.available_from_date', [
                'date' => $availableFrom->copy()->locale(app()->getLocale())->translatedFormat('j F Y'),
            ]);
        }

        return __("profile.availability.display.{$status->value}");
    }
}
