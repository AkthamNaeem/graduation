<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->foreignId('selected_cv_file_id')
                ->nullable()
                ->after('job_seeker_profile_id')
                ->constrained('cv_files')
                ->nullOnDelete();

            $table->text('cover_letter')->nullable()->after('selected_cv_file_id');
            $table->boolean('consent_to_share_profile')->default(false)->after('cover_letter');
            $table->json('screening_answers')->nullable()->after('consent_to_share_profile');
        });
    }

    public function down(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('selected_cv_file_id');
            $table->dropColumn(['cover_letter', 'consent_to_share_profile', 'screening_answers']);
        });
    }
};
