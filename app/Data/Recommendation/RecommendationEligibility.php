<?php

namespace App\Data\Recommendation;

use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use Illuminate\Support\Carbon;

final readonly class RecommendationEligibility
{
    /**
     * @param  list<JobPosting>  $jobs
     */
    public function __construct(
        public JobSeekerProfile $profile,
        public array $jobs,
        public Carbon $now,
    ) {
        foreach ($jobs as $job) {
            if (! $job instanceof JobPosting) {
                throw new \InvalidArgumentException('Eligibility contains an invalid Job.');
            }
        }
    }

    /**
     * @return list<int>
     */
    public function jobIds(): array
    {
        return array_map(
            static fn (JobPosting $job): int => (int) $job->id,
            $this->jobs,
        );
    }
}
