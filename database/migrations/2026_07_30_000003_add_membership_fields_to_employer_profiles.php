<?php

use App\Enums\CompanyMembershipStatus;
use App\Enums\CompanyRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employer_profiles', function (Blueprint $table): void {
            $table->string('company_role')
                ->default(CompanyRole::COMPANY_ADMIN->value)
                ->after('company_id');
            $table->string('membership_status')
                ->default(CompanyMembershipStatus::ACTIVE->value)
                ->after('company_role');
            $table->foreignId('invited_by_user_id')
                ->nullable()
                ->after('membership_status')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('joined_at')->nullable()->after('invited_by_user_id');
            $table->timestamp('suspended_at')->nullable()->after('joined_at');
            $table->timestamp('removed_at')->nullable()->after('suspended_at');

            $table->index('company_role', 'employer_profiles_company_role_index');
            $table->index('membership_status', 'employer_profiles_membership_status_index');
            $table->index(
                ['company_id', 'membership_status'],
                'employer_profiles_company_membership_index',
            );
            $table->index(
                ['company_id', 'company_role'],
                'employer_profiles_company_role_composite_index',
            );
        });

        DB::table('employer_profiles')->update([
            'membership_status' => CompanyMembershipStatus::ACTIVE->value,
            'joined_at' => DB::raw('created_at'),
            'company_role' => CompanyRole::COMPANY_ADMIN->value,
        ]);

        DB::table('employer_profiles')
            ->select('company_id')
            ->distinct()
            ->orderBy('company_id')
            ->get()
            ->each(function (object $row): void {
                $ownerId = DB::table('employer_profiles')
                    ->where('company_id', $row->company_id)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->value('id');

                if ($ownerId !== null) {
                    DB::table('employer_profiles')
                        ->where('id', $ownerId)
                        ->update(['company_role' => CompanyRole::OWNER->value]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('employer_profiles', function (Blueprint $table): void {
            $table->dropForeign(['invited_by_user_id']);
            $table->dropIndex('employer_profiles_company_role_index');
            $table->dropIndex('employer_profiles_membership_status_index');
            $table->dropIndex('employer_profiles_company_membership_index');
            $table->dropIndex('employer_profiles_company_role_composite_index');
            $table->dropColumn([
                'company_role',
                'membership_status',
                'invited_by_user_id',
                'joined_at',
                'suspended_at',
                'removed_at',
            ]);
        });
    }
};
