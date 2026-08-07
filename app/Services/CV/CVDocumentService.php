<?php

namespace App\Services\CV;

use App\Exceptions\CVLifecycleException;
use App\Models\ApplicationSnapshot;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Throwable;

class CVDocumentService
{
    public function __construct(
        private readonly CurrentCVResolver $currentCVResolver,
        private readonly CVDocumentDataMapper $dataMapper,
        private readonly CVDocumentRenderer $renderer,
    ) {}

    public function currentResponse(User $user, bool $download): Response
    {
        $profile = JobSeekerProfile::query()
            ->where('user_id', $user->id)
            ->with([
                'user',
                'city',
                'experiences' => fn ($query) => $query
                    ->orderByDesc('is_current')
                    ->orderByDesc('end_date')
                    ->orderByDesc('start_date')
                    ->orderByDesc('id'),
                'education' => fn ($query) => $query
                    ->orderByDesc('end_date')
                    ->orderByDesc('start_date')
                    ->orderByDesc('id'),
                'skills' => fn ($query) => $query->orderBy('skills.name')->orderBy('skills.id'),
                'primaryCVFile',
            ])
            ->firstOrFail();

        if ($this->currentCVResolver->resolve($user, $profile) === null) {
            throw new CVLifecycleException(
                __('domain_errors.PRIMARY_CV_REQUIRED'),
                'PRIMARY_CV_REQUIRED',
                422,
            );
        }

        $data = $this->dataMapper->fromProfile($profile);
        $name = Str::slug((string) ($data['name'] ?? '')) ?: 'current';

        return $this->response($data, "{$name}-cv.pdf", $download);
    }

    public function snapshotResponse(ApplicationSnapshot $snapshot, bool $download): Response
    {
        $data = $this->dataMapper->fromSnapshot($snapshot->profile_snapshot ?? []);
        $name = Str::slug((string) ($data['name'] ?? '')) ?: 'candidate';

        return $this->response(
            $data,
            "{$name}-application-{$snapshot->job_application_id}-cv.pdf",
            $download,
        );
    }

    /** @param array<string, mixed> $data */
    private function response(array $data, string $filename, bool $download): Response
    {
        try {
            $pdf = $this->renderer->render($data);
        } catch (Throwable $exception) {
            report($exception);
            throw new CVLifecycleException(
                __('domain_errors.CV_DOCUMENT_GENERATION_FAILED'),
                'CV_DOCUMENT_GENERATION_FAILED',
                500,
            );
        }

        $disposition = HeaderUtils::makeDisposition(
            $download ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE,
            $filename,
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition,
            'Content-Length' => (string) strlen($pdf),
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges' => 'none',
        ]);
    }
}
