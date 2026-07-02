<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\DeleteAdminRequest;
use App\Http\Requests\Api\V1\Admin\IndexAdminSkillRequest;
use App\Http\Requests\Api\V1\Admin\StoreAdminSkillRequest;
use App\Http\Requests\Api\V1\Admin\UpdateAdminSkillRequest;
use App\Http\Resources\Api\V1\SkillResource;
use App\Models\Skill;
use App\Services\AuditLogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class AdminSkillController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(IndexAdminSkillRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $sortBy = $filters['sort_by'] ?? 'name';
        $sortDirection = $filters['sort_direction'] ?? 'asc';

        $skills = Skill::query()
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($builder) use ($search): void {
                    $builder
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            })
            ->orderBy($sortBy, $sortDirection)
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success(
            data: SkillResource::collection($skills),
            message: 'Skills retrieved successfully.',
        );
    }

    public function store(StoreAdminSkillRequest $request): JsonResponse
    {
        $attributes = $this->skillAttributes($request->validated());
        $skill = Skill::query()->create($attributes);

        $this->auditLogService->record(
            'skill.created',
            $request->user('sanctum'),
            Skill::class,
            $skill->id,
            null,
            $skill->only(['name', 'slug']),
        );

        return ApiResponse::success(
            data: new SkillResource($skill),
            message: 'Skill created successfully.',
            status: 201,
        );
    }

    public function update(UpdateAdminSkillRequest $request, Skill $skill): JsonResponse
    {
        $before = $skill->only(['name', 'slug']);

        $skill->update($this->skillAttributes($request->validated(), $skill));

        $this->auditLogService->record(
            'skill.updated',
            $request->user('sanctum'),
            Skill::class,
            $skill->id,
            $before,
            $skill->only(['name', 'slug']),
        );

        return ApiResponse::success(
            data: new SkillResource($skill->refresh()),
            message: 'Skill updated successfully.',
        );
    }

    public function destroy(DeleteAdminRequest $request, Skill $skill): JsonResponse
    {
        if ($skill->jobSeekerProfiles()->exists() || $skill->jobPostings()->exists()) {
            return ApiResponse::error(
                message: 'Skill is used by profiles or jobs and cannot be hard-deleted.',
                errors: ['skill' => ['Detach the skill from related profiles and jobs before deleting it.']],
                status: 409,
            );
        }

        $before = $skill->only(['name', 'slug']);
        $skill->delete();

        $this->auditLogService->record(
            'skill.deleted',
            $request->user('sanctum'),
            Skill::class,
            $skill->id,
            $before,
        );

        return ApiResponse::success(
            data: null,
            message: 'Skill deleted successfully.',
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function skillAttributes(array $attributes, ?Skill $skill = null): array
    {
        if (! array_key_exists('slug', $attributes) && array_key_exists('name', $attributes)) {
            $attributes['slug'] = Str::slug((string) $attributes['name']);
        }

        if (($attributes['slug'] ?? null) === null && $skill instanceof Skill) {
            unset($attributes['slug']);
        }

        return $attributes;
    }
}
