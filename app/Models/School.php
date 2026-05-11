<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'dean_id'])]
class School extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function dean(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dean_id');
    }

    /**
     * @return HasMany<Department, $this>
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<QualityReport, $this>
     */
    public function qualityReports(): HasMany
    {
        return $this->hasMany(QualityReport::class);
    }
}
