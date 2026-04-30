<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('status')->default('active')->after('role')->index();
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->string('approval_status')->default('pending')->after('description')->index();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex(['approval_status']);
            $table->dropColumn('approval_status');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }
};
