<?php

namespace App\Models;

use App\Enums\Placement;
use App\Enums\UniversityRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['school_id', 'name', 'code'])]
class Department extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * The Head of Department is the user with the HOD role placed at this department.
     *
     * @return HasOne<User, $this>
     */
    public function hod(): HasOne
    {
        return $this->hasOne(User::class)
            ->where('university_role', UniversityRole::Hod->value)
            ->where('placement', Placement::Department->value);
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

    /**
     * @return HasMany<Course, $this>
     */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    /**
     * @return HasMany<Program, $this>
     */
    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }
}
