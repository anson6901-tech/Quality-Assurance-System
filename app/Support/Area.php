<?php

namespace App\Support;

use App\Enums\Placement;
use App\Models\Course;
use App\Models\Department;
use App\Models\Program;
use App\Models\QualityReport;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Narrows queries to the part of the university a user is placed in. University placement
 * sees everything; school and department placement see only their unit; users who are not
 * placed see nothing through these scopes.
 */
class Area
{
    /**
     * Reports belong to their lecturer's unit; reports without a lecturer use their own
     * school and department.
     *
     * @param  Builder<QualityReport>  $query
     * @return Builder<QualityReport>
     */
    public static function reports(Builder $query, User $user): Builder
    {
        [$column, $id] = match ($user->placement) {
            Placement::University => [null, null],
            Placement::School => ['school_id', $user->school_id],
            Placement::Department => ['department_id', $user->department_id],
            default => ['id', 0],
        };

        if ($column === null) {
            return $query;
        }

        return $query->where(fn (Builder $query) => $query
            ->whereHas('lecturer', fn (Builder $query) => $query->where($column, $id))
            ->orWhere(fn (Builder $query) => $query->whereNull('lecturer_id')->where($column, $id)));
    }

    /**
     * @param  Builder<Program>  $query
     * @return Builder<Program>
     */
    public static function programs(Builder $query, User $user): Builder
    {
        return match ($user->placement) {
            Placement::University => $query,
            Placement::School => $query->whereHas('department', fn (Builder $query) => $query->where('school_id', $user->school_id)),
            Placement::Department => $query->where('department_id', $user->department_id),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Courses are shared, so a course is in an area when one of the area's programs mounts it
     * (or, for older catalogue entries, when it still belongs to one of the area's departments).
     *
     * @param  Builder<Course>  $query
     * @return Builder<Course>
     */
    public static function courses(Builder $query, User $user): Builder
    {
        if ($user->placement === Placement::University) {
            return $query;
        }

        if ($user->placement === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $query) => $query
            ->whereHas('offerings.program', fn (Builder $query) => self::programs($query, $user))
            ->orWhereIn('department_id', self::departments(Department::query(), $user)->select('id')));
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public static function users(Builder $query, User $user): Builder
    {
        return match ($user->placement) {
            Placement::University => $query,
            Placement::School => $query->where('school_id', $user->school_id),
            Placement::Department => $query->where('department_id', $user->department_id),
            default => $query->whereKey($user->id),
        };
    }

    /**
     * @param  Builder<School>  $query
     * @return Builder<School>
     */
    public static function schools(Builder $query, User $user): Builder
    {
        return $user->placement === Placement::University
            ? $query
            : $query->whereKey($user->school_id);
    }

    /**
     * @param  Builder<Department>  $query
     * @return Builder<Department>
     */
    public static function departments(Builder $query, User $user): Builder
    {
        return match ($user->placement) {
            Placement::University => $query,
            Placement::School => $query->where('school_id', $user->school_id),
            Placement::Department => $query->whereKey($user->department_id),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Whether a placement (target) lies inside the user's own area.
     */
    public static function contains(User $user, ?Placement $placement, ?int $schoolId, ?int $departmentId): bool
    {
        return match ($user->placement) {
            Placement::University => true,
            Placement::School => $placement !== null && $placement !== Placement::University && $schoolId === $user->school_id,
            Placement::Department => $placement === Placement::Department && $departmentId === $user->department_id,
            default => false,
        };
    }
}
