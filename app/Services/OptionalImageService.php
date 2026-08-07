<?php

namespace App\Services;

use App\Enums\CompanyPermission;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Skill;
use App\Models\Test;
use App\Models\TestQuestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class OptionalImageService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly CompanyPermissionService $companyPermissionService,
        private readonly PrivateFileStorageService $privateStorage,
        private readonly PublicImageOptimizationService $publicImageOptimizer,
        private readonly TestService $testService,
    ) {}

    public function updateAvatar(User $actor, ?UploadedFile $image): User
    {
        if (! $image instanceof UploadedFile) {
            return $actor->refresh();
        }

        $this->replacePublicImage(
            entity: $actor,
            field: 'avatar_path',
            image: $image,
            prefix: "user-avatars/{$actor->id}",
            actor: $actor,
            event: 'user.avatar.updated',
            validationField: 'image',
            maxWidth: 512,
            maxHeight: 512,
        );

        return $actor->refresh();
    }

    public function removeAvatar(User $actor): User
    {
        $this->removePublicImage($actor, 'avatar_path', $actor, 'user.avatar.removed');

        return $actor->refresh();
    }

    public function updateCompanyCover(User $actor, ?UploadedFile $image): Company
    {
        $company = $this->editableCompany($actor);

        if ($image instanceof UploadedFile) {
            $this->replacePublicImage(
                entity: $company,
                field: 'cover_image_path',
                image: $image,
                prefix: "company-covers/{$company->id}",
                actor: $actor,
                event: 'company.cover_image.updated',
                validationField: 'image',
                maxWidth: 1600,
                maxHeight: 1200,
            );
        }

        return $company->refresh()->load(['employerProfiles.user']);
    }

    public function removeCompanyCover(User $actor): Company
    {
        $company = $this->editableCompany($actor);
        $this->removePublicImage($company, 'cover_image_path', $actor, 'company.cover_image.removed');

        return $company->refresh()->load(['employerProfiles.user']);
    }

    public function updateSkillIcon(User $actor, Skill $skill, ?UploadedFile $image): Skill
    {
        $this->assertAdmin($actor);

        if ($image instanceof UploadedFile) {
            $this->replacePublicImage(
                entity: $skill,
                field: 'icon_path',
                image: $image,
                prefix: "skill-icons/{$skill->id}",
                actor: $actor,
                event: 'job_category.icon.updated',
                validationField: 'image',
                maxWidth: 256,
                maxHeight: 256,
            );
        }

        return $skill->refresh();
    }

    public function removeSkillIcon(User $actor, Skill $skill): Skill
    {
        $this->assertAdmin($actor);
        $this->removePublicImage($skill, 'icon_path', $actor, 'job_category.icon.removed');

        return $skill->refresh();
    }

    public function updateQuestionImage(User $actor, Test $test, TestQuestion $question, ?UploadedFile $image): TestQuestion
    {
        $this->assertCanManageQuestion($actor, $test, $question);

        if (! $image instanceof UploadedFile) {
            return $question->refresh()->load('options');
        }

        $stored = $this->privateStorage->storeUploadedFile(
            $image,
            "tests/{$test->id}/questions/{$question->id}",
        );
        $oldPath = $question->image_path;

        try {
            DB::transaction(function () use ($question, $stored, $actor, $oldPath): void {
                $question->update(['image_path' => $stored->path]);
                $this->recordImageAudit(
                    'test_question.image.updated',
                    $actor,
                    $question,
                    $oldPath !== null,
                    true,
                );
            });
        } catch (Throwable $exception) {
            try {
                $this->privateStorage->delete($stored->disk, $stored->path);
            } catch (Throwable $cleanupException) {
                $this->privateStorage->logCleanupFailure(
                    'test_question_image_failed_update_cleanup',
                    $stored->disk,
                    $stored->path,
                    $cleanupException,
                    TestQuestion::class,
                    $question->id,
                );
            }

            throw $exception;
        }

        if ($oldPath !== null && $oldPath !== $stored->path) {
            $this->privateStorage->delete($this->privateStorage->privateDisk(), $oldPath);
        }

        return $question->refresh()->load('options');
    }

    public function removeQuestionImage(User $actor, Test $test, TestQuestion $question): TestQuestion
    {
        $this->assertCanManageQuestion($actor, $test, $question);
        $oldPath = $question->image_path;

        if ($oldPath === null) {
            return $question->refresh()->load('options');
        }

        DB::transaction(function () use ($question, $actor): void {
            $question->update(['image_path' => null]);
            $this->recordImageAudit('test_question.image.removed', $actor, $question, true, false);
        });

        $this->privateStorage->delete($this->privateStorage->privateDisk(), $oldPath);

        return $question->refresh()->load('options');
    }

    public function questionImageResponse(TestQuestion $question): StreamedResponse
    {
        abort_if($question->image_path === null, 404);
        $extension = strtolower(pathinfo($question->image_path, PATHINFO_EXTENSION));
        $mimeType = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        return $this->privateStorage->inlineResponse(
            $this->privateStorage->privateDisk(),
            $question->image_path,
            "question-{$question->id}.{$extension}",
            $mimeType,
        );
    }

    private function editableCompany(User $actor): Company
    {
        $company = $actor->employerProfile()->with('company')->firstOrFail()->company;
        $this->companyPermissionService->assertCan($actor, CompanyPermission::UPDATE_COMPANY, $company);

        return $company;
    }

    private function assertCanManageQuestion(User $actor, Test $test, TestQuestion $question): void
    {
        abort_unless($question->test_id === $test->id, 404);
        abort_unless($actor->can('manageQuestions', $test), 403);
        $this->testService->ensureTestIsMutable($test);
    }

    private function assertAdmin(User $actor): void
    {
        abort_unless($actor->role === UserRole::ADMIN, 403);
    }

    private function replacePublicImage(
        Model $entity,
        string $field,
        UploadedFile $image,
        string $prefix,
        User $actor,
        string $event,
        string $validationField,
        int $maxWidth,
        int $maxHeight,
    ): void {
        $newPath = $this->publicImageOptimizer->store(
            $image,
            $prefix,
            $maxWidth,
            $maxHeight,
            $validationField,
        );

        $oldPath = $entity->getAttribute($field);

        try {
            DB::transaction(function () use ($entity, $field, $newPath, $actor, $event, $oldPath): void {
                $entity->update([$field => $newPath]);
                $this->recordImageAudit($event, $actor, $entity, $oldPath !== null, true);
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($newPath);
            throw $exception;
        }

        if (is_string($oldPath) && $oldPath !== $newPath) {
            Storage::disk('public')->delete($oldPath);
        }
    }

    private function removePublicImage(Model $entity, string $field, User $actor, string $event): void
    {
        $oldPath = $entity->getAttribute($field);
        if (! is_string($oldPath) || $oldPath === '') {
            return;
        }

        DB::transaction(function () use ($entity, $field, $actor, $event): void {
            $entity->update([$field => null]);
            $this->recordImageAudit($event, $actor, $entity, true, false);
        });

        Storage::disk('public')->delete($oldPath);
    }

    private function recordImageAudit(
        string $event,
        User $actor,
        Model $entity,
        bool $wasPresent,
        bool $isPresent,
    ): void {
        $this->auditLogService->record(
            $event,
            $actor,
            $entity::class,
            (int) $entity->getKey(),
            ['image_present' => $wasPresent],
            ['image_present' => $isPresent],
        );
    }
}
