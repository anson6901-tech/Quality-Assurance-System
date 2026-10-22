<?php

namespace App\Models;

use App\Enums\Placement;
use App\Enums\UniversityRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'code'])]
class School extends Model
{
    use HasFactory;

    /**
     * The Dean is the user with the Dean role placed at this school.
     *
     * @return HasOne<User, $this>
     */
    public function dean(): HasOne
    {
        return $this->hasOne(User::class)
            ->where('university_role', UniversityRole::Dean->value)
            ->where('placement', Placement::School->value);
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
