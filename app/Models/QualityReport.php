<?php

namespace App\Models;

use App\Enums\UniversityRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'title',
    'category',
    'status',
    'academic_year_id',
    'summary',
    'file_path',
    'school_id',
    'department_id',
    'lecturer_id',
    'uploaded_by',
])]
class QualityReport extends Model
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
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lecturer_id');
    }

    /**
     * @return BelongsToMany<Course, $this>
     */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_quality_report')
            ->withTimestamps();
    }

    /**
     * @param  Builder<QualityReport>  $query
     * @return Builder<QualityReport>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match ($user->university_role) {
            UniversityRole::QaOfficer, UniversityRole::QaDirector => $query,
            UniversityRole::Dean => $query->where('school_id', $user->school_id),
            UniversityRole::Hod => $query->where('department_id', $user->department_id),
            UniversityRole::Lecturer => $query->where('lecturer_id', $user->id),
            null => $query->whereRaw('1 = 0'),
        };
    }
}
