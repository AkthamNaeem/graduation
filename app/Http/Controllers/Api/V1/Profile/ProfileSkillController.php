<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Profile\AttachSkillRequest;
use App\Http\Requests\Api\V1\Profile\DetachSkillRequest;
use App\Http\Resources\Api\V1\JobSeekerProfileResource;
use App\Models\Skill;
use App\Services\ProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProfileSkillController extends Controller
{
    public function __construct(
        private readonly ProfileService $profileService,
    ) {
    }

    public function store(AttachSkillRequest $request): JsonResponse
    {
        $skill = Skill::query()->findOrFail($request->integer('skill_id'));

        return ApiResponse::success(
            data: new JobSeekerProfileResource($this->profileService->attachSkill($request->user(), $skill)),
            message: 'Skill attached successfully.',
        );
    }

    public function destroy(DetachSkillRequest $request, Skill $skill): JsonResponse
    {
        return ApiResponse::success(
            data: new JobSeekerProfileResource($this->profileService->detachSkill($request->user(), $skill)),
            message: 'Skill detached successfully.',
        );
    }
}
