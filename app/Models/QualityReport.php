<?php

namespace App\Models;

use App\Enums\UniversityRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'title',
    'category',
    'status',
    'academic_year_id',
    'semester_id',
    'summary',
    'file_path',
    'school_id',
    'department_id',
    'program_id',
    'lecturer_id',
    'uploaded_by',
])]
class QualityReport extends Model
{
    use HasFactory, SoftDeletes;

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

    /** @return BelongsTo<Semester, $this> */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
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

    /** @return HasMany<QualityReportReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(QualityReportReview::class)->latest();
    }

    /**
     * @param  Builder<QualityReport>  $query
     * @return Builder<QualityReport>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (
            $user->university_role === UniversityRole::IctAdmin->value
            && QualityRolePermission::roleHas($user->university_role, 'view_all_reports')
        ) {
            return $query;
        }

        if (
            in_array($user->university_role, [
                UniversityRole::Hod->value,
                UniversityRole::Lecturer->value,
            ], true)
            && QualityRolePermission::roleHas($user->university_role, 'view_own_reports')
        ) {
            return $query->where(function (Builder $query) use ($user): void {
                $query
                    ->where('lecturer_id', $user->id)
                    ->orWhere(function (Builder $query) use ($user): void {
                        $query
                            ->whereNull('lecturer_id')
                            ->whereHas('courses', fn (Builder $query) => $query
                                ->whereHas('lecturers', fn (Builder $query) => $query->whereKey($user->id)));
                    });
            });
        }

        if (QualityRolePermission::roleHas($user->university_role, 'view_department_reports')) {
            return $query->where(function (Builder $query) use ($user): void {
                $query
                    ->whereHas('lecturer', fn (Builder $query) => $query->where('department_id', $user->department_id))
                    ->orWhere(function (Builder $query) use ($user): void {
                        $query
                            ->whereNull('lecturer_id')
                            ->where('department_id', $user->department_id);
                    });
            });
        }

        if (QualityRolePermission::roleHas($user->university_role, 'view_school_reports')) {
            return $query->where(function (Builder $query) use ($user): void {
                $query
                    ->whereHas('lecturer', fn (Builder $query) => $query->where('school_id', $user->school_id))
                    ->orWhere(function (Builder $query) use ($user): void {
                        $query
                            ->whereNull('lecturer_id')
                            ->where('school_id', $user->school_id);
                    });
            });
        }

        if (QualityRolePermission::roleHas($user->university_role, 'view_all_reports')) {
            return $query;
        }

        if (QualityRolePermission::roleHas($user->university_role, 'view_own_reports')) {
            return $query->where(function (Builder $query) use ($user): void {
                $query
                    ->where('lecturer_id', $user->id)
                    ->orWhere(function (Builder $query) use ($user): void {
                        $query
                            ->whereNull('lecturer_id')
                            ->whereHas('courses', fn (Builder $query) => $query
                                ->whereHas('lecturers', fn (Builder $query) => $query->whereKey($user->id)));
                    });
            });
        }

        return $query->whereRaw('1 = 0');
    }
}
