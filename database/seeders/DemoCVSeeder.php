<?php

namespace Database\Seeders;

use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Models\JobSeekerProfile;
use App\Models\ProfileChangeSuggestion;
use App\Models\User;
use Database\Seeders\Support\DemoSeederContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class DemoCVSeeder extends Seeder
{
    private const DISK = 'local';

    public function run(): void
    {
        $now = DemoSeederContext::now();
        $backend = User::query()->where('email', 'seeker.backend@workey.test')->firstOrFail();
        $frontend = User::query()->where('email', 'seeker.frontend@workey.test')->firstOrFail();
        $dataUser = User::query()->where('email', 'seeker.data@workey.test')->firstOrFail();
        $junior = User::query()->where('email', 'seeker.junior@workey.test')->firstOrFail();

        $backendPrimary = $this->file($backend, 'backend-primary.pdf', 'parsed', [
            'version_label' => 'Primary 2026',
            'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
            'review_status' => CVFile::REVIEW_STATUS_APPLIED,
            'confirmed_at' => $now->copy()->subDays(7),
            'created_at' => $now->copy()->subDays(20),
        ]);
        $backendOld = $this->file($backend, 'backend-legacy.pdf', 'parsed', [
            'version_label' => 'Archived 2024',
            'review_mode' => CVFile::REVIEW_MODE_INITIAL_IMPORT,
            'review_status' => CVFile::REVIEW_STATUS_APPLIED,
            'confirmed_at' => $now->copy()->subDays(200),
            'archived_at' => $now->copy()->subDays(19),
            'created_at' => $now->copy()->subDays(300),
        ]);
        $processing = $this->file($frontend, 'frontend-processing.pdf', 'processing', [
            'version_label' => 'Frontend refresh',
            'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
            'review_status' => CVFile::REVIEW_STATUS_DRAFT,
            'created_at' => $now->copy()->subHours(3),
        ]);
        $uploaded = $this->file($junior, 'junior-uploaded.pdf', 'uploaded', [
            'version_label' => 'First upload',
            'review_mode' => CVFile::REVIEW_MODE_INITIAL_IMPORT,
            'review_status' => CVFile::REVIEW_STATUS_DRAFT,
            'created_at' => $now->copy()->subHour(),
        ]);
        $failed = $this->file($dataUser, 'data-failed.pdf', 'failed', [
            'version_label' => 'Failed parser sample',
            'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
            'review_status' => CVFile::REVIEW_STATUS_COMPARISON_PENDING,
            'error_message' => 'The demo parser could not extract readable text.',
            'created_at' => $now->copy()->subDays(2),
        ]);
        $comparison = $this->file($dataUser, 'data-reviewed.pdf', 'parsed', [
            'version_label' => 'Data profile review',
            'review_mode' => CVFile::REVIEW_MODE_PROFILE_SYNC,
            'review_status' => CVFile::REVIEW_STATUS_DECISIONS_PENDING,
            'created_at' => $now->copy()->subDays(5),
        ]);

        foreach ([$backendPrimary, $backendOld, $comparison] as $cvFile) {
            $parsed = $this->parsedPayload($cvFile->user);
            CVParsingResult::query()->create([
                'cv_file_id' => $cvFile->id,
                'raw_text' => $this->rawText($cvFile->user),
                'parsed_json' => $parsed,
                'reviewed_json' => $cvFile->id === $comparison->id
                    ? [...$parsed, 'summary' => 'Reviewed ML engineer profile with ranked recommendation experience.']
                    : $parsed,
                'reviewed_at' => $cvFile->id === $comparison->id ? $now->copy()->subDays(3) : $cvFile->confirmed_at,
            ]);
        }

        $backend->jobSeekerProfile()->update(['primary_cv_file_id' => $backendPrimary->id]);
        $dataUser->jobSeekerProfile()->update(['primary_cv_file_id' => $comparison->id]);
        $frontend->jobSeekerProfile()->update(['primary_cv_file_id' => $processing->id]);
        $junior->jobSeekerProfile()->update(['primary_cv_file_id' => $uploaded->id]);

        $this->suggestions($comparison, $dataUser->jobSeekerProfile, $now);

        unset($failed);
    }

    /** @param array<string, mixed> $overrides */
    private function file(User $user, string $name, string $status, array $overrides): CVFile
    {
        $path = 'demo-seed/cvs/'.$name;
        $content = $this->minimalPdf($user->name.' - '.$name);
        Storage::disk(self::DISK)->put($path, $content);

        return CVFile::query()->create([
            'user_id' => $user->id,
            'original_name' => $name,
            'stored_path' => $path,
            'disk' => self::DISK,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => strlen($content),
            'status' => $status,
            'error_message' => null,
            'confirmed_at' => null,
            'archived_at' => null,
            ...$overrides,
        ]);
    }

    /** @return array<string, mixed> */
    private function parsedPayload(User $user): array
    {
        return [
            'full_name' => $user->name,
            'email' => $user->email,
            'phone' => '+963 900 000 001',
            'location' => 'Damascus, Syria',
            'birth_date' => null,
            'nationality' => null,
            'marital_status' => null,
            'summary' => 'Engineer experienced in reliable APIs and recruitment workflows.',
            'experience' => [[
                'title' => 'Software Engineer',
                'company_name' => 'Demo Technology',
                'location' => 'Damascus, Syria',
                'work_mode' => 'hybrid',
                'start_date' => '2021-01',
                'end_date' => null,
                'is_current' => true,
                'description' => 'Builds production services.',
                'responsibilities' => ['Designed REST APIs', 'Maintained automated tests'],
                'evidence' => 'Software Engineer at Demo Technology since January 2021',
                'confidence_score' => 0.94,
            ]],
            'education' => [[
                'degree' => 'Bachelor',
                'field_of_study' => 'Computer Science',
                'institution' => 'Damascus University',
                'start_year' => 2016,
                'graduation_year' => 2020,
                'is_expected' => false,
                'description' => null,
                'evidence' => 'Bachelor in Computer Science, Damascus University, 2020',
                'confidence_score' => 0.92,
            ]],
            'certifications' => [[
                'name' => 'Laravel Developer',
                'issuer' => 'Demo Academy',
                'issue_year' => 2024,
                'expiration_year' => null,
                'description' => 'Backend engineering certification.',
                'evidence' => 'Laravel Developer certification from Demo Academy in 2024',
                'confidence_score' => 0.88,
            ]],
            'skills' => ['PHP', 'Laravel', 'REST APIs', 'Git'],
            'languages' => [
                ['name' => 'Arabic', 'level' => 'Native'],
                ['name' => 'English', 'level' => 'Professional'],
            ],
        ];
    }

    private function rawText(User $user): string
    {
        return "{$user->name}\n{$user->email}\nSoftware Engineer at Demo Technology since January 2021\n"
            .'Designed REST APIs and maintained automated tests.'."\n"
            .'Bachelor in Computer Science, Damascus University, 2020'."\n"
            .'Laravel Developer certification from Demo Academy in 2024';
    }

    private function suggestions(CVFile $cvFile, JobSeekerProfile $profile, Carbon $now): void
    {
        $rows = [
            [ProfileChangeSuggestion::ENTITY_PROFILE, ProfileChangeSuggestion::TYPE_UPDATE, ProfileChangeSuggestion::STATUS_PENDING, ['summary' => 'Updated from CV']],
            [ProfileChangeSuggestion::ENTITY_EXPERIENCE, ProfileChangeSuggestion::TYPE_ADD, ProfileChangeSuggestion::STATUS_ACCEPTED, ['title' => 'ML Engineer']],
            [ProfileChangeSuggestion::ENTITY_EDUCATION, ProfileChangeSuggestion::TYPE_MERGE, ProfileChangeSuggestion::STATUS_REJECTED, ['institution' => 'Damascus University']],
            [ProfileChangeSuggestion::ENTITY_SKILL, ProfileChangeSuggestion::TYPE_IGNORE, ProfileChangeSuggestion::STATUS_APPLIED, ['name' => 'Python']],
        ];

        foreach ($rows as $index => [$entity, $type, $status, $newValue]) {
            ProfileChangeSuggestion::query()->create([
                'user_id' => $profile->user_id,
                'cv_file_id' => $cvFile->id,
                'job_seeker_profile_id' => $profile->id,
                'entity_type' => $entity,
                'suggestion_type' => $type,
                'status' => $status,
                'source' => ProfileChangeSuggestion::SOURCE_CV_PARSED,
                'old_value' => $index === 0 ? ['summary' => $profile->summary] : null,
                'new_value' => $newValue,
                'user_edited_value' => $status === ProfileChangeSuggestion::STATUS_ACCEPTED ? $newValue : null,
                'confidence_score' => 0.90 - ($index * 0.08),
                'reason' => 'Deterministic demo suggestion generated from stored parsed CV data.',
                'decided_at' => $status === ProfileChangeSuggestion::STATUS_PENDING ? null : $now->copy()->subDays(2),
                'applied_at' => $status === ProfileChangeSuggestion::STATUS_APPLIED ? $now->copy()->subDay() : null,
            ]);
        }
    }

    private function minimalPdf(string $text): string
    {
        $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);

        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R>>endobj\n"
            .'4 0 obj<</Length '.(strlen($safe) + 31).">>stream\nBT /F1 12 Tf 72 720 Td ({$safe}) Tj ET\nendstream\nendobj\n"
            ."trailer<</Root 1 0 R>>\n%%EOF\n";
    }
}
