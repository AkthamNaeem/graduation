<?php

namespace App\Services;

use App\Jobs\ParseCVFileJob;
use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Models\JobSeekerProfile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CVService
{
    public function upload(User $user, UploadedFile $file): CVFile
    {
        $disk = 'local';
        $storedPath = $file->store("cv-files/{$user->id}", $disk);

        $cvFile = CVFile::query()->create([
            'user_id' => $user->id,
            'original_name' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'disk' => $disk,
            'mime_type' => $file->getClientMimeType(),
            'extension' => strtolower($file->getClientOriginalExtension()),
            'size_bytes' => $file->getSize(),
            'status' => 'uploaded',
        ]);

        ParseCVFileJob::dispatch($cvFile);

        return $cvFile->refresh();
    }

    /**
     * @return Collection<int, CVFile>
     */
    public function list(User $user): Collection
    {
        return $user->cvFiles()
            ->with('parsingResult')
            ->latest()
            ->get();
    }

    public function get(User $user, CVFile $cvFile): CVFile
    {
        return $this->ownedCVFile($user, $cvFile)->load('parsingResult');
    }

    public function getParsedResult(User $user, CVFile $cvFile): CVParsingResult
    {
        $cvFile = $this->ownedCVFile($user, $cvFile);
        $result = $cvFile->parsingResult;

        abort_unless($result instanceof CVParsingResult, 404);

        return $result;
    }

    public function confirm(User $user, CVFile $cvFile): JobSeekerProfile
    {
        $cvFile = $this->ownedCVFile($user, $cvFile)->load('parsingResult');

        if ($cvFile->confirmed_at !== null) {
            throw ValidationException::withMessages([
                'cv' => ['This CV has already been confirmed.'],
            ]);
        }

        if (! $cvFile->parsingResult instanceof CVParsingResult) {
            abort(404);
        }

        return DB::transaction(function () use ($user, $cvFile): JobSeekerProfile {
            $profile = $user->jobSeekerProfile()->firstOrFail();
            $parsed = $cvFile->parsingResult->parsed_json ?? [];

            $this->appendExperiences($profile, $parsed['experience'] ?? []);
            $this->appendEducation($profile, $parsed['education'] ?? []);
            $this->attachSkills($profile, $parsed['skills'] ?? []);

            $cvFile->forceFill(['confirmed_at' => now()])->save();

            return $profile->load(['user', 'experiences', 'education', 'skills']);
        });
    }

    private function ownedCVFile(User $user, CVFile $cvFile): CVFile
    {
        abort_unless($cvFile->user_id === $user->id, 404);

        return $cvFile;
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function appendExperiences(JobSeekerProfile $profile, array $items): void
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = $this->cleanString($item['title'] ?? null);
            $companyName = $this->cleanString($item['company_name'] ?? null);

            if ($title === null || $companyName === null) {
                continue;
            }

            $profile->experiences()->create([
                'title' => $title,
                'company_name' => $companyName,
                'description' => $this->cleanString($item['description'] ?? null),
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function appendEducation(JobSeekerProfile $profile, array $items): void
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $institution = $this->cleanString($item['institution'] ?? null);

            if ($institution === null) {
                continue;
            }

            $profile->education()->create([
                'institution' => $institution,
                'degree' => $this->cleanString($item['degree'] ?? null),
                'field_of_study' => $this->cleanString($item['field_of_study'] ?? null),
                'description' => $this->cleanString($item['description'] ?? null),
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $skillNames
     */
    private function attachSkills(JobSeekerProfile $profile, array $skillNames): void
    {
        $slugs = collect($skillNames)
            ->filter(fn (mixed $skill): bool => is_string($skill))
            ->map(fn (string $skill): string => Str::slug($skill))
            ->filter()
            ->unique()
            ->values();

        if ($slugs->isEmpty()) {
            return;
        }

        $skillIds = Skill::query()
            ->whereIn('slug', $slugs->all())
            ->pluck('id')
            ->all();

        $profile->skills()->syncWithoutDetaching($skillIds);
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
