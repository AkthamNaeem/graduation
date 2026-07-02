<?php

namespace App\Services;

use App\Models\ApplicationTestAssignment;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Notification;
use App\Models\ProfileChangeSuggestion;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AdminReportService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return [
            'users' => [
                'total' => User::query()->count(),
                'by_role' => $this->countsBy(User::query(), 'role'),
                'by_status' => $this->countsBy(User::query(), 'status'),
            ],
            'companies' => [
                'total' => Company::query()->count(),
                'by_approval_status' => $this->countsBy(Company::query(), 'approval_status'),
            ],
            'jobs' => [
                'total' => JobPosting::query()->count(),
                'by_status' => $this->countsBy(JobPosting::query(), 'status'),
            ],
            'applications' => [
                'total' => JobApplication::query()->count(),
                'by_status' => $this->applicationCountsByStatus(JobApplication::query()),
            ],
            'tests' => [
                'total' => Test::query()->count(),
                'assignments' => ApplicationTestAssignment::query()->count(),
                'attempts' => TestAttempt::query()->count(),
            ],
            'interviews' => [
                'total' => Interview::query()->count(),
                'by_status' => [
                    'scheduled' => Interview::query()->whereNull('completed_at')->count(),
                    'completed' => Interview::query()->whereNotNull('completed_at')->count(),
                ],
            ],
            'notifications' => [
                'total' => Notification::query()->count(),
                'unread' => Notification::query()->whereNull('read_at')->count(),
            ],
            'cv_files' => [
                'total' => CVFile::query()->count(),
                'by_status' => $this->countsBy(CVFile::query(), 'status'),
            ],
            'cv_parsing_results' => [
                'success' => CVParsingResult::query()->count(),
                'failed' => CVFile::query()->where('status', 'failed')->count(),
            ],
            'audit_logs' => [
                'count' => AuditLog::query()->count(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function applications(array $filters): array
    {
        $query = JobApplication::query()
            ->join('application_statuses', 'application_statuses.id', '=', 'job_applications.application_status_id')
            ->join('job_postings', 'job_postings.id', '=', 'job_applications.job_posting_id')
            ->select('job_applications.*');

        $this->applyDateFilters($query, $filters, 'job_applications.created_at');

        $query
            ->when($filters['company_id'] ?? null, fn (Builder $builder, int $companyId) => $builder->where('job_postings.company_id', $companyId))
            ->when($filters['job_id'] ?? null, fn (Builder $builder, int $jobId) => $builder->where('job_applications.job_posting_id', $jobId));

        $statusCounts = $this->applicationCountsByStatus(clone $query);
        $finalStatuses = ['accepted', 'rejected', 'withdrawn'];

        return [
            'total' => (clone $query)->count(),
            'by_status' => $statusCounts,
            'accepted' => $statusCounts['accepted'] ?? 0,
            'rejected' => $statusCounts['rejected'] ?? 0,
            'active' => (clone $query)->whereNotIn('application_statuses.slug', $finalStatuses)->count(),
            'final' => (clone $query)->whereIn('application_statuses.slug', $finalStatuses)->count(),
            'per_day' => (clone $query)
                ->selectRaw('DATE(job_applications.created_at) as date, COUNT(*) as total')
                ->groupBy('date')
                ->orderBy('date')
                ->pluck('total', 'date')
                ->map(fn (int $total): int => $total)
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function jobs(array $filters): array
    {
        $query = JobPosting::query();

        $this->applyDateFilters($query, $filters, 'created_at');

        $query
            ->when($filters['company_id'] ?? null, fn (Builder $builder, int $companyId) => $builder->where('company_id', $companyId))
            ->when($filters['status'] ?? null, fn (Builder $builder, string $status) => $builder->where('status', $status));

        $statusCounts = $this->countsBy(clone $query, 'status');
        $totalJobs = (clone $query)->count();
        $totalApplications = JobApplication::query()
            ->whereIn('job_posting_id', (clone $query)->select('id'))
            ->count();

        return [
            'total' => $totalJobs,
            'by_status' => $statusCounts,
            'published' => $statusCounts['open'] ?? 0,
            'closed' => $statusCounts['closed'] ?? 0,
            'draft' => $statusCounts['draft'] ?? 0,
            'average_applications_per_job' => $totalJobs > 0
                ? round($totalApplications / $totalJobs, 2)
                : 0.0,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function cvParsing(array $filters): array
    {
        $cvFiles = CVFile::query();
        $suggestions = ProfileChangeSuggestion::query();

        $this->applyDateFilters($cvFiles, $filters, 'created_at');
        $this->applyDateFilters($suggestions, $filters, 'created_at');

        $cvFileIds = (clone $cvFiles)->select('id');
        $suggestionCounts = $this->countsBy(clone $suggestions, 'status');

        return [
            'total_uploaded_cvs' => (clone $cvFiles)->count(),
            'parsed_count' => CVParsingResult::query()
                ->whereIn('cv_file_id', $cvFileIds)
                ->count(),
            'failed_count' => (clone $cvFiles)->where('status', 'failed')->count(),
            'suggestions_generated_count' => (clone $suggestions)->count(),
            'suggestions_by_status' => $suggestionCounts,
            'suggestions_accepted' => $suggestionCounts['accepted'] ?? 0,
            'suggestions_rejected' => $suggestionCounts['rejected'] ?? 0,
            'suggestions_applied' => $suggestionCounts['applied'] ?? 0,
        ];
    }

    /**
     * @param  Builder<Model>  $query
     * @return array<string, int>
     */
    private function countsBy(Builder $query, string $column): array
    {
        return $query
            ->select($column, DB::raw('COUNT(*) as total'))
            ->groupBy($column)
            ->pluck('total', $column)
            ->map(fn (int $total): int => $total)
            ->all();
    }

    /**
     * @param  Builder<Model>  $query
     * @return array<string, int>
     */
    private function applicationCountsByStatus(Builder $query): array
    {
        return $query
            ->join('application_statuses as status_counts', 'status_counts.id', '=', 'job_applications.application_status_id')
            ->select('status_counts.slug', DB::raw('COUNT(*) as total'))
            ->groupBy('status_counts.slug')
            ->pluck('total', 'status_counts.slug')
            ->map(fn (int $total): int => $total)
            ->all();
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyDateFilters(Builder $query, array $filters, string $column): void
    {
        $query
            ->when($filters['date_from'] ?? null, fn (Builder $builder, string $dateFrom) => $builder->whereDate($column, '>=', $dateFrom))
            ->when($filters['date_to'] ?? null, fn (Builder $builder, string $dateTo) => $builder->whereDate($column, '<=', $dateTo));
    }
}
