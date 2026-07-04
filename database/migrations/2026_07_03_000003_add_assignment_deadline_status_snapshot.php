<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_test_assignments', function (Blueprint $table) {
            $table->string('status', 30)->default('assigned')->after('note');
            $table->timestamp('deadline_at')->nullable()->after('assigned_at');
            $table->json('test_snapshot')->nullable()->after('deadline_at');
            $table->timestamp('started_at')->nullable()->after('test_snapshot');
            $table->timestamp('submitted_at')->nullable()->after('started_at');
            $table->timestamp('evaluated_at')->nullable()->after('submitted_at');
            $table->timestamp('cancelled_at')->nullable()->after('evaluated_at');

            $table->index(['job_application_id', 'status'], 'ata_application_status_idx');
            $table->index(['deadline_at', 'status'], 'ata_deadline_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('application_test_assignments', function (Blueprint $table) {
            $table->dropIndex('ata_application_status_idx');
            $table->dropIndex('ata_deadline_status_idx');
            $table->dropColumn([
                'status',
                'deadline_at',
                'test_snapshot',
                'started_at',
                'submitted_at',
                'evaluated_at',
                'cancelled_at',
            ]);
        });
    }
};
