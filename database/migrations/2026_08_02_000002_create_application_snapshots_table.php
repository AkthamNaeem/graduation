<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_application_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('profile_snapshot');
            $table->json('application_answers_snapshot');
            $table->unsignedBigInteger('source_cv_file_id')->nullable()->index();
            $table->string('cv_original_name');
            $table->string('cv_mime_type', 191);
            $table->string('cv_extension', 16);
            $table->unsignedBigInteger('cv_size_bytes');
            $table->char('cv_checksum_sha256', 64);
            $table->string('cv_disk');
            $table->string('cv_stored_path', 2048);
            $table->string('origin', 32)->default('captured_at_submission');
            $table->string('accuracy', 32)->default('exact');
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index(['origin', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_snapshots');
    }
};
