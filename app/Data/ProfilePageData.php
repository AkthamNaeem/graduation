<?php

namespace App\Data;

use App\Models\JobSeekerProfile;

final readonly class ProfilePageData
{
    /**
     * @param  list<array{key: string, url: string}>  $professionalLinks
     * @param  list<string>  $allowedActions
     * @param  array<string, mixed>  $profileCompleteness
     * @param  list<array<string, mixed>>  $attentionItems
     */
    public function __construct(
        public JobSeekerProfile $profile,
        public float $yearsOfExperience,
        public array $professionalLinks,
        public array $allowedActions,
        public array $profileCompleteness,
        public array $attentionItems,
    ) {}
}
