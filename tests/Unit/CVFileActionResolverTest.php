<?php

namespace Tests\Unit;

use App\Enums\CandidateCVStage;
use App\Enums\UserRole;
use App\Models\CVFile;
use App\Models\User;
use App\Services\CV\CVFileActionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CVFileActionResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_current_actions_reflect_file_type_and_pending_workflow(): void
    {
        $resolver = app(CVFileActionResolver::class);
        $pdf = $this->cv();
        $docx = $this->cv([
            'stored_path' => 'cv-files/'.Str::uuid().'.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extension' => 'docx',
        ]);

        $this->assertSame(['preview', 'download', 'update'], $resolver->current($pdf, false));
        $this->assertSame(['preview', 'download'], $resolver->current($pdf, true));
        $this->assertSame(['download', 'update'], $resolver->current($docx, false));
    }

    #[DataProvider('pendingStages')]
    public function test_pending_actions_match_the_actual_stage(CandidateCVStage $stage, array $expected): void
    {
        $this->assertSame($expected, app(CVFileActionResolver::class)->pending($this->cv(), $stage));
    }

    public static function pendingStages(): iterable
    {
        yield 'processing' => [CandidateCVStage::PROCESSING, ['preview', 'download', 'view_status', 'cancel']];
        yield 'first review' => [CandidateCVStage::FIRST_REVIEW, ['preview', 'download', 'review', 'cancel']];
        yield 'differences review' => [CandidateCVStage::DIFFERENCES_REVIEW, ['preview', 'download', 'review', 'cancel']];
        yield 'final confirmation' => [CandidateCVStage::FINAL_CONFIRMATION, ['preview', 'download', 'review', 'confirm', 'cancel']];
        yield 'failed' => [CandidateCVStage::FAILED, ['download', 'cancel']];
    }

    /** @param array<string, mixed> $overrides */
    private function cv(array $overrides = []): CVFile
    {
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        $cv = CVFile::create(array_merge([
            'user_id' => $user->id,
            'original_name' => 'resume.pdf',
            'stored_path' => 'cv-files/'.Str::uuid().'.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 8,
            'status' => 'parsed',
        ], $overrides));
        Storage::disk('local')->put($cv->stored_path, 'cv-bytes');

        return $cv;
    }
}
