<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApplicationStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
    ];

    public function jobApplications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    public function statusChangesFrom(): HasMany
    {
        return $this->hasMany(ApplicationStatusHistory::class, 'from_application_status_id');
    }

    public function statusChangesTo(): HasMany
    {
        return $this->hasMany(ApplicationStatusHistory::class, 'to_application_status_id');
    }
}
