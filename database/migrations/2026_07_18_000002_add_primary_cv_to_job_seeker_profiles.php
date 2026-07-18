<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_seeker_profiles', function (Blueprint $table) {
            $table->foreignId('primary_cv_file_id')
                ->nullable()
                ->after('user_id')
                ->constrained('cv_files')
                ->nullOnDelete();
        });

        DB::table('job_seeker_profiles')->orderBy('id')->eachById(function (object $profile): void {
            $candidates = DB::table('cv_files')
                ->leftJoin('cv_parsing_results', 'cv_parsing_results.cv_file_id', '=', 'cv_files.id')
                ->where('user_id', $profile->user_id)
                ->whereNull('archived_at')
                ->orderByRaw('CASE WHEN confirmed_at IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('confirmed_at')
                ->orderByRaw("CASE WHEN cv_files.status = 'parsed' THEN 0 ELSE 1 END")
                ->orderByDesc('cv_parsing_results.created_at')
                ->orderByDesc('cv_files.created_at')
                ->orderByDesc('cv_files.id')
                ->get(['cv_files.id', 'cv_files.disk', 'cv_files.stored_path']);

            $cvId = $candidates->first(function (object $candidate): bool {
                try {
                    return Storage::disk($candidate->disk)->exists($candidate->stored_path);
                } catch (\Throwable) {
                    return false;
                }
            })?->id;

            if ($cvId !== null) {
                DB::table('job_seeker_profiles')->where('id', $profile->id)->update([
                    'primary_cv_file_id' => $cvId,
                ]);
            }
        }, 'id');
    }

    public function down(): void
    {
        Schema::table('job_seeker_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('primary_cv_file_id');
        });
    }
};
