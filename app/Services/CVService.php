<?php

namespace App\Services;

use App\Jobs\ParseCVFileJob;
use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Models\JobSeekerProfile;
use App\Models\ProfileChangeSuggestion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CVService
{
    public function __construct(
        private readonly ProfileSyncService $profileSyncService,
    ) {
    }

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
     * @return LengthAwarePaginator<int, CVFile>
     */
    public function list(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return $user->cvFiles()
            ->with('parsingResult')
            ->latest()
            ->paginate($perPage);
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

    /**
     * @return array{profile: JobSeekerProfile, suggestions: Collection<int, ProfileChangeSuggestion>}
     */
    public function confirm(User $user, CVFile $cvFile): array
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

        return DB::transaction(function () use ($user, $cvFile): array {
            $profile = $user->jobSeekerProfile()->firstOrFail();
            $suggestions = $this->profileSyncService->generateSuggestionsFromParsedCV($user, $cvFile);

            return [
                'profile' => $profile->load(['user', 'experiences', 'education', 'skills']),
                'suggestions' => $suggestions,
            ];
        });
    }

    private function ownedCVFile(User $user, CVFile $cvFile): CVFile
    {
        abort_unless($cvFile->user_id === $user->id, 404);

        return $cvFile;
    }

}
