<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'country_code',
        'name_ar',
        'name_en',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function jobPostings(): HasMany
    {
        return $this->hasMany(JobPosting::class);
    }

    public function jobSeekerProfiles(): HasMany
    {
        return $this->hasMany(JobSeekerProfile::class);
    }

    public function localizedName(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar'
            ? $this->name_ar
            : $this->name_en;
    }
}
