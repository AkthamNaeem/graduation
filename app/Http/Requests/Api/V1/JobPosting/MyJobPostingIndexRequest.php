<?php

namespace App\Http\Requests\Api\V1\JobPosting;

use App\Http\Requests\Api\V1\JobPosting\Concerns\ResolvesJobPostingUser;

class MyJobPostingIndexRequest extends IndexJobPostingRequest
{
    use ResolvesJobPostingUser;

    public function authorize(): bool
    {
        return $this->isEmployerUser();
    }
}
