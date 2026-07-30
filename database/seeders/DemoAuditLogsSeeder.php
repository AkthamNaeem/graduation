<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CVFile;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\ProfileChangeSuggestion;
use App\Models\TestAttempt;
use App\Models\User;
use Database\Seeders\Support\DemoSeederContext;
use Illuminate\Database\Seeder;

class DemoAuditLogsSeeder extends Seeder
{
    public function run(): void
    {
        $now = DemoSeederContext::now();
        $admin = User::query()->where('email', 'admin@workey.test')->firstOrFail();
        $employer = User::query()->where('email', 'employer.approved@workey.test')->firstOrFail();
        $candidate = User::query()->where('email', 'seeker.backend@workey.test')->firstOrFail();
        $company = Company::query()->where('name', 'Workey Labs')->firstOrFail();
        $pendingCompany = Company::query()->where('name', 'Pending Ventures')->firstOrFail();
        $job = JobPosting::query()->where('title', 'Senior Laravel Backend Engineer')->firstOrFail();
        $application = JobApplication::query()->whereHas('applicationStatus', fn ($query) => $query->where('slug', 'accepted'))->firstOrFail();
        $attempt = TestAttempt::query()->whereNotNull('evaluated_at')->firstOrFail();
        $interview = Interview::query()->where('status', 'evaluated')->firstOrFail();
        $cv = CVFile::query()->where('user_id', $candidate->id)->where('status', 'parsed')->firstOrFail();
        $suggestion = ProfileChangeSuggestion::query()->firstOrFail();

        $rows = [
            [$admin, 'user.created', User::class, $candidate->id, null, ['role' => 'job_seeker', 'status' => 'active']],
            [$admin, 'user.suspended', User::class, User::query()->where('email', 'seeker.suspended@workey.test')->valueOrFail('id'), ['status' => 'active'], ['status' => 'suspended']],
            [$admin, 'company.approved', Company::class, $company->id, ['approval_status' => 'pending'], ['approval_status' => 'approved']],
            [$admin, 'company.review_pending', Company::class, $pendingCompany->id, null, ['approval_status' => 'pending']],
            [$employer, 'job.created', JobPosting::class, $job->id, null, ['status' => 'draft', 'title' => $job->title]],
            [$employer, 'job.published', JobPosting::class, $job->id, ['status' => 'draft'], ['status' => 'open']],
            [$candidate, 'application.submitted', JobApplication::class, $application->id, null, ['status' => 'submitted']],
            [$employer, 'application.status_changed', JobApplication::class, $application->id, ['status' => 'final_review'], ['status' => 'accepted']],
            [$employer, 'test_attempt.evaluated', TestAttempt::class, $attempt->id, ['grading_status' => 'manual_grading_required'], ['grading_status' => $attempt->grading_status->value, 'total_score' => $attempt->total_score]],
            [$employer, 'interview.scheduled', Interview::class, $interview->id, null, ['status' => 'scheduled']],
            [$employer, 'interview.evaluated', Interview::class, $interview->id, ['status' => 'completed'], ['status' => 'evaluated', 'recommendation' => $interview->evaluation?->recommendation]],
            [$candidate, 'cv.parsed', CVFile::class, $cv->id, ['status' => 'processing'], ['status' => 'parsed']],
            [$candidate, 'cv.reviewed', CVFile::class, $cv->id, ['review_status' => 'comparison_pending'], ['review_status' => $cv->review_status]],
            [$candidate, 'profile_suggestion.decided', ProfileChangeSuggestion::class, $suggestion->id, ['status' => 'pending'], ['status' => $suggestion->status]],
            [$employer, 'application.final_decision', JobApplication::class, $application->id, ['status' => 'final_review'], ['status' => 'accepted']],
        ];

        foreach ($rows as $index => [$actor, $action, $type, $id, $before, $after]) {
            AuditLog::query()->create([
                'actor_user_id' => $actor->id,
                'action' => $action,
                'entity_type' => $type,
                'entity_id' => $id,
                'before_values' => $before,
                'after_values' => $after,
                'metadata' => ['demo' => true, 'scenario_index' => $index + 1],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Workey Demo Seeder',
                'created_at' => $now->copy()->subHours(30 - $index),
            ]);
        }
    }
}
