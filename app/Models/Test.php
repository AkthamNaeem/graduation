<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Test extends Model
{
    use HasFactory;

    public const VISIBILITY_COMPANY = 'company';
    public const VISIBILITY_GLOBAL = 'global';

    protected $fillable = [
        'company_id',
        'created_by_user_id',
        'visibility',
        'version',
        'parent_test_id',
        'locked_at',
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
            'locked_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function parentTest(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_test_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(TestQuestion::class)->orderBy('order_index');
    }

    public function activeQuestions(): HasMany
    {
        return $this->questions()->where('is_active', true);
    }

    public function applicationTestAssignments(): HasMany
    {
        return $this->hasMany(ApplicationTestAssignment::class);
    }
}
