<?php

namespace App\Models;

use App\Support\Area;
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
    'section_id',
    'lecturer_id',
    'uploaded_by',
    'assigned_supervisor_id',
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

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
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

    /** @return BelongsTo<User, $this> */
    public function assignedSupervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_supervisor_id');
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
        $seesOwn = QualityRolePermission::roleHas($user->university_role, 'view_own_reports');

        if ($user->canViewAreaReports() && $user->isPlacedAtUniversity()) {
            return $query;
        }

        if ($user->canViewAreaReports()) {
            return $query->where(function (Builder $query) use ($user, $seesOwn): void {
                $query->where(fn (Builder $query) => Area::reports($query, $user));

                if ($seesOwn) {
                    $query->orWhere(fn (Builder $query) => self::ownedBy($query, $user));
                }
            });
        }

        if ($seesOwn) {
            return self::ownedBy($query, $user);
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * Reports written about the lecturer, or about courses they teach when no lecturer is set.
     *
     * @param  Builder<QualityReport>  $query
     * @return Builder<QualityReport>
     */
    private static function ownedBy(Builder $query, User $user): Builder
    {
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
}
