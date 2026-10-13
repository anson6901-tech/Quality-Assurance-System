<?php

namespace App\Http\Controllers\Quality;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\QualityRolePermission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Course lookup behind the global search in the top bar.
 */
class CourseSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $words = collect(preg_split('/\s+/', trim($validated['q'] ?? '')))->filter()->take(5);

        if ($words->isEmpty()) {
            return response()->json(['courses' => []]);
        }

        $courses = Course::query()
            ->with(['department:id,school_id,name', 'department.school:id,name', 'offerings.program:id,code'])
            ->tap(fn (Builder $query) => $this->scopeToUser($query, $request->user()))
            ->where(function (Builder $query) use ($words): void {
                foreach ($words as $word) {
                    $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $word).'%';
                    $query->where(fn (Builder $query) => $query
                        ->where('code', 'like', $like)
                        ->orWhere('title', 'like', $like)
                        ->orWhereHas('department', fn (Builder $query) => $query->where('name', 'like', $like)));
                }
            })
            ->orderBy('code')
            ->limit(12)
            ->get();

        return response()->json([
            'courses' => $courses->map(fn (Course $course) => [
                'id' => $course->id,
                'code' => $course->code,
                'title' => $course->title,
                'courseType' => $course->course_type,
                'level' => $course->level,
                'department' => $course->department?->name,
                'school' => $course->department?->school?->name,
                'programs' => $course->offerings->pluck('program.code')->filter()->unique()->sort()->values(),
            ]),
        ]);
    }

    /**
     * Limits results to the courses the user's role can work with.
     *
     * @param  Builder<Course>  $query
     */
    private function scopeToUser(Builder $query, User $user): void
    {
        $role = $user->university_role;

        if (
            $user->canManageQualityStructure()
            || $user->canUploadQualityReports()
            || $user->canViewQualityReports()
            || $user->canApproveQualityReports()
            || QualityRolePermission::roleHas($role, 'view_all_reports')
        ) {
            return;
        }

        $query->where(function (Builder $query) use ($user, $role): void {
            $matched = false;

            if (QualityRolePermission::roleHas($role, 'view_school_reports') && $user->school_id) {
                $matched = true;
                $query->orWhereHas('department', fn (Builder $query) => $query->where('school_id', $user->school_id))
                    ->orWhereHas('offerings.program.department', fn (Builder $query) => $query->where('school_id', $user->school_id));
            }

            if (
                ($user->canManageCourseAssignments() || QualityRolePermission::roleHas($role, 'view_department_reports'))
                && $user->department_id
            ) {
                $matched = true;
                $query->orWhere('department_id', $user->department_id)
                    ->orWhereHas('offerings.program', fn (Builder $query) => $query->where('department_id', $user->department_id));
            }

            if (QualityRolePermission::roleHas($role, 'view_own_reports')) {
                $matched = true;
                $query->orWhereHas('lecturers', fn (Builder $query) => $query->whereKey($user->id));
            }

            if (! $matched) {
                $query->whereRaw('1 = 0');
            }
        });
    }
}
