<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\DeleteAdminRequest;
use App\Http\Requests\Api\V1\Admin\IndexAdminRequest;
use App\Http\Requests\Api\V1\Admin\StoreAdminSkillRequest;
use App\Http\Requests\Api\V1\Admin\UpdateAdminSkillRequest;
use App\Http\Resources\Api\V1\SkillResource;
use App\Models\Skill;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AdminSkillController extends Controller
{
    public function index(IndexAdminRequest $request): JsonResponse
    {
        $skills = Skill::query()
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success(
            data: SkillResource::collection($skills),
            message: 'Skills retrieved successfully.',
        );
    }

    public function store(StoreAdminSkillRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: new SkillResource(Skill::query()->create($request->validated())),
            message: 'Skill created successfully.',
            status: 201,
        );
    }

    public function update(UpdateAdminSkillRequest $request, Skill $skill): JsonResponse
    {
        $skill->update($request->validated());

        return ApiResponse::success(
            data: new SkillResource($skill->refresh()),
            message: 'Skill updated successfully.',
        );
    }

    public function destroy(DeleteAdminRequest $request, Skill $skill): JsonResponse
    {
        $skill->delete();

        return ApiResponse::success(
            data: null,
            message: 'Skill deleted successfully.',
        );
    }
}
