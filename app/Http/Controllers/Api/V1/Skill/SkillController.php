<?php

namespace App\Http\Controllers\Api\V1\Skill;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Skill\IndexSkillRequest;
use App\Http\Resources\Api\V1\SkillResource;
use App\Models\Skill;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class SkillController extends Controller
{
    public function index(IndexSkillRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $search = $validated['search'] ?? null;
        $limit = (int) ($validated['limit'] ?? 50);

        $skills = Skill::query()
            ->when(filled($search), function (Builder $query) use ($search): void {
                $query->where(function (Builder $builder) use ($search): void {
                    $builder->where('name', 'like', '%'.$search.'%')
                        ->orWhere('slug', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return ApiResponse::success(
            data: SkillResource::collection($skills),
            message: 'Skills retrieved successfully.',
        );
    }
}
