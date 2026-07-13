<?php

namespace App\Http\Controllers\Quality;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\QualityRolePermission;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CourseAssignmentController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($this->canManageAssignments($request->user()), 403);

        $canManageStructure = $request->user()->canManageQualityStructure();
        $canViewApprovals = $request->user()->canViewApprovalQueue();
        $academicYears = AcademicYear::query()->with('semesters')->orderByDesc('starts_on')->get(['id', 'name', 'status']);
        $academicYearId = (int) ($request->input('academic_year_id') ?: $academicYears->firstWhere('status', 'active')?->id ?: $academicYears->first()?->id);
        $semesterId = (int) ($request->input('semester_id') ?: $academicYears->firstWhere('id', $academicYearId)?->semesters->first()?->id);

        abort_unless($semesterId && $academicYears->firstWhere('id', $academicYearId)?->semesters->contains('id', $semesterId), 422);

        return Inertia::render('quality/course-assignments/Index', [
            'courses' => Course::query()
                ->with([
                    'department.school:id,name',
                    'lecturers' => fn ($query) => $query->wherePivot('semester_id', $semesterId),
                    ...($canViewApprovals ? ['qualityReports' => fn ($query) => $query
                        ->whereIn('status', ['under_review', 'approved'])
                        ->where('semester_id', $semesterId)
                        ->visibleTo($request->user())
                        ->with(['lecturer:id,name,email', 'academicYear:id,name'])
                        ->latest()] : []),
                ])
                ->when(! $canManageStructure, fn ($query) => $query->where('department_id', $request->user()->department_id))
                ->join('departments', 'departments.id', '=', 'courses.department_id')
                ->join('schools', 'schools.id', '=', 'departments.school_id')
                ->orderBy('schools.name')
                ->orderBy('departments.name')
                ->orderBy('courses.code')
                ->select('courses.*')
                ->get()
                ->map(fn (Course $course) => [
                    'id' => $course->id,
                    'code' => $course->code,
                    'title' => $course->title,
                    'courseType' => $course->course_type,
                    'level' => $course->level,
                    'department' => [
                        'name' => $course->department->name,
                        'school' => ['name' => $course->department->school->name],
                    ],
                    'lecturers' => $course->lecturers->map(fn (User $lecturer) => [
                        'id' => $lecturer->id,
                        'name' => $lecturer->name,
                        'email' => $lecturer->email,
                        'studentCount' => $lecturer->pivot->student_count,
                        'allocatedHours' => $lecturer->pivot->allocated_hours,
                    ]),
                    'reports' => $canViewApprovals
                        ? $course->qualityReports->map(fn ($report) => [
                            'id' => $report->id,
                            'title' => $report->title,
                            'status' => $report->status,
                            'lecturer' => $report->lecturer?->name,
                            'academicYear' => $report->academicYear?->name,
                        ])
                        : [],
                ]),
            'lecturers' => User::query()
                ->whereIn('university_role', QualityRolePermission::rolesWith('view_own_reports'))
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $lecturer) => [
                    'id' => $lecturer->id,
                    'name' => $lecturer->name,
                    'email' => $lecturer->email,
                ]),
            'academicYears' => AcademicYear::query()
                ->with('semesters:id,academic_year_id,name,sequence')
                ->orderByDesc('starts_on')
                ->get(['id', 'name', 'status']),
            'filters' => ['academicYearId' => $academicYearId, 'semesterId' => $semesterId],
            'canUploadReports' => $request->user()->canUploadQualityReports(),
            'canViewApprovals' => $canViewApprovals,
            'canApproveReports' => $request->user()->canApproveQualityReports(),
        ]);
    }

    public function sync(Request $request, Course $course): RedirectResponse
    {
        abort_unless($this->canManageAssignments($request->user()), 403);
        abort_if(
            ! $request->user()->canManageQualityStructure()
                && $course->department_id !== $request->user()->department_id,
            403,
        );

        $validated = $request->validate([
            'lecturer_ids' => ['present', 'array'],
            'lecturer_ids.*' => ['integer', Rule::exists('users', 'id')],
            'semester_id' => ['required', 'integer', Rule::exists('semesters', 'id')],
            'assignments' => ['present', 'array'],
            'assignments.*.student_count' => ['nullable', 'integer', 'min:1'],
            'assignments.*.allocated_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ]);

        $lecturerIds = collect($validated['lecturer_ids'])->unique()->values();
        $eligibleLecturers = User::query()
            ->whereIn('id', $lecturerIds)
            ->whereIn('university_role', QualityRolePermission::rolesWith('view_own_reports'))
            ->count();

        abort_unless($eligibleLecturers === $lecturerIds->count(), 422);
        abort_unless($course->course_type === 'module' || $lecturerIds->count() <= 1, 422);

        $assignments = $lecturerIds->mapWithKeys(function (int $lecturerId) use ($validated): array {
            $assignment = $validated['assignments'][$lecturerId] ?? null;

            if (! $assignment || empty($assignment['student_count']) || empty($assignment['allocated_hours'])) {
                throw ValidationException::withMessages([
                    "assignments.{$lecturerId}.student_count" => __('Enter the number of students in the class.'),
                    "assignments.{$lecturerId}.allocated_hours" => __('Enter the teaching hours allocated to this lecturer.'),
                ]);
            }

            return [$lecturerId => [
                'student_count' => (int) $assignment['student_count'],
                'allocated_hours' => (int) $assignment['allocated_hours'],
            ]];
        });

        DB::transaction(function () use ($course, $validated, $assignments): void {
            DB::table('course_lecturer')
                ->where('course_id', $course->id)
                ->where('semester_id', $validated['semester_id'])
                ->delete();

            DB::table('course_lecturer')->insert(
                collect($assignments)->map(fn (array $assignment, int $lecturerId) => [
                    'course_id' => $course->id,
                    'lecturer_id' => $lecturerId,
                    'semester_id' => $validated['semester_id'],
                    ...$assignment,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all(),
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Lecturer assignments saved.')]);

        return to_route('quality.course-assignments.index');
    }

    private function canManageAssignments(User $user): bool
    {
        return $user->canManageQualityStructure() || $user->canManageCourseAssignments();
    }
}
