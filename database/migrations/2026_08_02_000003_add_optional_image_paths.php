<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('avatar_path')->nullable();
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->string('cover_image_path')->nullable();
        });

        Schema::table('test_questions', function (Blueprint $table): void {
            $table->string('image_path')->nullable();
        });

        Schema::table('skills', function (Blueprint $table): void {
            $table->string('icon_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('avatar_path');
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('cover_image_path');
        });

        Schema::table('test_questions', function (Blueprint $table): void {
            $table->dropColumn('image_path');
        });

        Schema::table('skills', function (Blueprint $table): void {
            $table->dropColumn('icon_path');
        });
    }
};
