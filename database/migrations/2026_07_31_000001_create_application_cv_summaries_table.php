<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_cv_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_cv_file_id')->nullable()->constrained('cv_files')->nullOnDelete();
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('locale', 5);
            $table->string('provider', 50);
            $table->string('model', 100);
            $table->string('prompt_version', 20);
            $table->char('input_hash', 64);
            $table->string('headline', 255);
            $table->text('summary');
            $table->json('strengths');
            $table->json('gaps');
            $table->json('evidence');
            $table->string('provider_request_id')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(
                ['job_application_id', 'locale'],
                'application_cv_summaries_application_locale_unique',
            );
            $table->index(['locale', 'input_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_cv_summaries');
    }
};
