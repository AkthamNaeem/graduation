<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Test extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'instructions',
        'duration_minutes',
        'max_score',
        'passing_score',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_score' => 'decimal:2',
            'passing_score' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function applicationTestAssignments(): HasMany
    {
        return $this->hasMany(ApplicationTestAssignment::class);
    }
}
