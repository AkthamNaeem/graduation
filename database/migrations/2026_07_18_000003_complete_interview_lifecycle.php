<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interviews', function (Blueprint $table): void {
            $table->text('location')->nullable()->change();
            $table->text('meeting_link')->nullable()->change();
            $table->string('status')->default('scheduled')->after('interview_type')->index();
            $table->timestamp('scheduled_end_at')->nullable()->after('scheduled_at');
            $table->text('candidate_message')->nullable()->after('meeting_link');
            $table->text('internal_note')->nullable()->after('candidate_message');
            $table->timestamp('confirmed_at')->nullable()->after('internal_note');
            $table->foreignId('confirmed_by_user_id')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable()->after('completion_note');
            $table->text('cancellation_message')->nullable()->after('cancellation_reason');
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_message');
            $table->foreignId('cancelled_by_user_id')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('candidate_attendance_status')->default('pending')->after('cancelled_by_user_id');
            $table->string('interviewer_attendance_status')->default('pending')->after('candidate_attendance_status');
            $table->timestamp('attendance_recorded_at')->nullable()->after('interviewer_attendance_status');
            $table->foreignId('attendance_recorded_by_user_id')->nullable()->after('attendance_recorded_at')->constrained('users')->nullOnDelete();
            $table->text('attendance_note')->nullable()->after('attendance_recorded_by_user_id');
            $table->index(['job_application_id', 'interview_type', 'status'], 'interviews_application_type_status_index');
        });

        DB::table('interviews')->orderBy('id')->each(function (object $interview): void {
            $mode = match ($interview->interview_mode) {
                'in_person' => 'on_site',
                default => 'online',
            };
            $type = str_contains(strtolower((string) $interview->interview_type), 'final')
                ? 'final'
                : (str_contains(strtolower((string) $interview->interview_type), 'hr') ? 'hr' : 'technical');
            $status = $interview->completed_at === null ? 'scheduled' : 'completed';
            $end = $interview->duration_minutes === null
                ? null
                : CarbonImmutable::parse($interview->scheduled_at)->addMinutes((int) $interview->duration_minutes);

            DB::table('interviews')->where('id', $interview->id)->update([
                'interview_type' => $type,
                'interview_mode' => $mode,
                'status' => $status,
                'scheduled_end_at' => $end,
                'internal_note' => $interview->note,
                'candidate_attendance_status' => $status === 'completed' ? 'present' : 'pending',
                'interviewer_attendance_status' => $status === 'completed' ? 'present' : 'pending',
            ]);
        });

        Schema::create('interview_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('interview_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status')->index();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['interview_id', 'created_at']);
            $table->index('changed_by_user_id');
        });

        Schema::create('interview_schedule_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('interview_id')->constrained()->cascadeOnDelete();
            $table->timestamp('previous_start_at');
            $table->timestamp('previous_end_at');
            $table->timestamp('new_start_at');
            $table->timestamp('new_end_at');
            $table->string('previous_mode');
            $table->string('new_mode');
            $table->text('previous_meeting_link')->nullable();
            $table->text('new_meeting_link')->nullable();
            $table->string('previous_location_text', 1000)->nullable();
            $table->string('new_location_text', 1000)->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['interview_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_schedule_changes');
        Schema::dropIfExists('interview_status_histories');

        Schema::table('interviews', function (Blueprint $table): void {
            $table->dropIndex('interviews_application_type_status_index');
            $table->dropIndex('interviews_status_index');
            $table->dropConstrainedForeignId('attendance_recorded_by_user_id');
            $table->dropConstrainedForeignId('cancelled_by_user_id');
            $table->dropConstrainedForeignId('confirmed_by_user_id');
            $table->dropColumn([
                'status', 'scheduled_end_at', 'candidate_message', 'internal_note', 'confirmed_at',
                'cancellation_reason', 'cancellation_message', 'cancelled_at',
                'candidate_attendance_status', 'interviewer_attendance_status', 'attendance_recorded_at',
                'attendance_note',
            ]);
            $table->string('location')->nullable()->change();
            $table->string('meeting_link')->nullable()->change();
        });
    }
};
