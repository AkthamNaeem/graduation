<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'industry',
        'website',
        'location',
        'description',
        'logo_path',
        'cover_image_path',
        'approval_status',
        'owner_setup_required',
    ];

    protected function casts(): array
    {
        return [
            'owner_setup_required' => 'boolean',
        ];
    }

    public function employerProfiles(): HasMany
    {
        return $this->hasMany(EmployerProfile::class);
    }

    public function jobPostings(): HasMany
    {
        return $this->hasMany(JobPosting::class);
    }

    public function tests(): HasMany
    {
        return $this->hasMany(Test::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(CompanyInvitation::class);
    }
}
