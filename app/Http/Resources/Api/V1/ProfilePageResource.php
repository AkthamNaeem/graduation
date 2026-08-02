<?php

namespace App\Http\Resources\Api\V1;

use App\Data\ProfilePageData;
use App\Support\NameInitials;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin ProfilePageData */
class ProfilePageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $profile = $this->profile;
        $user = $profile->user;

        return [
            // Preserve the editable scalar fields used by existing profile forms.
            'id' => $profile->id,
            'user_id' => $profile->user_id,
            'headline' => $profile->headline,
            'summary' => $profile->summary,
            'phone' => $profile->phone,
            'location' => $profile->location,
            'city' => CityResource::make($profile->city),
            'availability_status' => $profile->availability_status?->value,
            'available_from' => $profile->available_from?->format('Y-m-d'),
            'portfolio_url' => $profile->portfolio_url,
            'linkedin_url' => $profile->linkedin_url,
            'github_url' => $profile->github_url,
            'user' => UserResource::make($user),
            'identity' => [
                'id' => $user->id,
                'profile_id' => $profile->id,
                'name' => $user->name,
                'email' => $user->email,
                'headline' => $profile->headline,
                'summary' => $profile->summary,
                'phone' => $profile->phone,
                'location' => $profile->location,
                'city' => CityResource::make($profile->city),
                'avatar' => [
                    'type' => $user->avatar_path === null ? 'initials' : 'image',
                    'initials' => NameInitials::from($user->name),
                    'url' => $user->avatar_path === null ? null : Storage::disk('public')->url($user->avatar_path),
                ],
            ],
            'career_summary' => [
                'years_of_experience' => $this->yearsOfExperience,
                'experiences_count' => $profile->experiences->count(),
                'education_count' => $profile->education->count(),
                'skills_count' => $profile->skills->count(),
                'professional_links_count' => count($this->professionalLinks),
                'availability' => ProfileAvailabilityResource::make($profile),
            ],
            'professional_profile' => [
                'summary' => $profile->summary,
                'phone' => $profile->phone,
                'portfolio_url' => $profile->portfolio_url,
                'linkedin_url' => $profile->linkedin_url,
                'github_url' => $profile->github_url,
            ],
            'experiences' => ProfilePageExperienceResource::collection($profile->experiences),
            'education' => ProfilePageEducationResource::collection($profile->education),
            'skills' => ProfilePageSkillResource::collection($profile->skills),
            'professional_links' => array_map(
                static fn (array $link): array => [
                    'type' => [
                        'key' => $link['key'],
                        'label' => __("profile.links.{$link['key']}"),
                    ],
                    'url' => $link['url'],
                ],
                $this->professionalLinks,
            ),
            'profile_completeness' => $this->profileCompleteness,
            'attention_items' => ProfileAttentionItemResource::collection($this->attentionItems),
            'current_cv' => $this->currentCV !== null
                ? CurrentCVResource::make($this->currentCV)
                : null,
            'pending_cv_update' => $this->pendingCVUpdate !== null
                ? PendingCVUpdateResource::make($this->pendingCVUpdate)
                : null,
            'allowed_actions' => $this->allowedActions,
            'created_at' => $profile->created_at?->toISOString(),
            'updated_at' => $profile->updated_at?->toISOString(),
        ];
    }
}
