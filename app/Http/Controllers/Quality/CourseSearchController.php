<?php

namespace App\Http\Controllers\Quality;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\QualityRolePermission;
use App\Models\User;
use App\Support\Area;
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
        $worksWithArea = $user->placement !== null && (
            $user->canManageQualityStructure()
            || $user->canUploadQualityReports()
            || $user->canViewQualityReports()
            || $user->canApproveQualityReports()
            || $user->canManageCourseAssignments()
            || $user->canViewAreaReports()
        );

        if ($worksWithArea && $user->isPlacedAtUniversity()) {
            return;
        }

        $query->where(function (Builder $query) use ($user, $worksWithArea): void {
            $matched = false;

            if ($worksWithArea) {
                $matched = true;
                $query->orWhere(fn (Builder $query) => Area::courses($query, $user));
            }

            if (QualityRolePermission::roleHas($user->university_role, 'view_own_reports')) {
                $matched = true;
                $query->orWhereHas('lecturers', fn (Builder $query) => $query->whereKey($user->id));
            }

            if (! $matched) {
                $query->whereRaw('1 = 0');
            }
        });
    }
}
