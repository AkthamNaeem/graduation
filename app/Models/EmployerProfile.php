<?php

namespace App\Models;

use App\Enums\CompanyMembershipStatus;
use App\Enums\CompanyRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployerProfile extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (EmployerProfile $profile): void {
            $profile->membership_status ??= CompanyMembershipStatus::ACTIVE;
            $profile->joined_at ??= now();

            if ($profile->company_role === null) {
                $hasMember = self::query()
                    ->where('company_id', $profile->company_id)
                    ->exists();
                $profile->company_role = $hasMember
                    ? CompanyRole::COMPANY_ADMIN
                    : CompanyRole::OWNER;
            }
        });
    }

    protected $fillable = [
        'user_id',
        'company_id',
        'company_role',
        'membership_status',
        'invited_by_user_id',
        'joined_at',
        'suspended_at',
        'removed_at',
        'job_title',
        'phone',
        'bio',
    ];

    protected function casts(): array
    {
        return [
            'company_role' => CompanyRole::class,
            'membership_status' => CompanyMembershipStatus::class,
            'joined_at' => 'datetime',
            'suspended_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
