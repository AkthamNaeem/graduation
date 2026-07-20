<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table): void {
            $table->string('department')->nullable()->after('title');
            $table->longText('responsibilities')->nullable()->after('description');
            $table->longText('requirements')->nullable()->after('responsibilities');
            $table->longText('benefits')->nullable()->after('requirements');
        });
    }

    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table): void {
            $table->dropColumn([
                'department',
                'responsibilities',
                'requirements',
                'benefits',
            ]);
        });
    }
};
