<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 100)->unique();
            $table->char('country_code', 2)->default('SY')->index();
            $table->string('name_ar', 150);
            $table->string('name_en', 150);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('job_seeker_profiles', function (Blueprint $table): void {
            $table->foreignId('city_id')
                ->nullable()
                ->after('location')
                ->constrained('cities')
                ->nullOnDelete();
        });

        Schema::table('job_postings', function (Blueprint $table): void {
            $table->foreignId('city_id')
                ->nullable()
                ->after('location')
                ->constrained('cities')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('city_id');
        });

        Schema::table('job_seeker_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('city_id');
        });

        Schema::dropIfExists('cities');
    }
};
