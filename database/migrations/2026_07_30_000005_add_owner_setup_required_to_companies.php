<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('owner_setup_required')->default(false)->after('approval_status')->index();
        });

        DB::table('companies')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('employer_profiles')
                    ->whereColumn('employer_profiles.company_id', 'companies.id');
            })
            ->update(['owner_setup_required' => true]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex(['owner_setup_required']);
            $table->dropColumn('owner_setup_required');
        });
    }
};
