<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table): void {
            $table->string('work_mode')->default('on_site')->after('location')->index();
            $table->timestamp('application_deadline')->nullable()->after('published_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table): void {
            $table->dropIndex(['work_mode']);
            $table->dropIndex(['application_deadline']);
            $table->dropColumn(['work_mode', 'application_deadline']);
        });
    }
};
