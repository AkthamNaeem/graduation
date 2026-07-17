<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_posting_skills', function (Blueprint $table): void {
            $table->string('requirement_type')->default('required')->after('skill_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('job_posting_skills', function (Blueprint $table): void {
            $table->dropIndex(['requirement_type']);
            $table->dropColumn('requirement_type');
        });
    }
};
