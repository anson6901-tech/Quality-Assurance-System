<?php

namespace App\Http\Controllers\Quality;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Program;
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
        abort_unless($this->canViewAssignments($request->user()), 403);

        $canManageStructure = $request->user()->canManageQualityStructure();
        $canViewApprovals = $request->user()->canViewApprovalQueue();
        $academicYears = AcademicYear::query()->with('semesters')->orderByDesc('starts_on')->get(['id', 'name', 'status']);
        $academicYearId = $request->integer('academic_year_id')
            ?: $academicYears->firstWhere('status', 'active')?->id
            ?: $academicYears->first()?->id;
        $selectedAcademicYear = $academicYears->firstWhere('id', $academicYearId);
        $semesterId = $request->integer('semester_id')
            ?: $selectedAcademicYear?->semesters->first()?->id;

        if ($request->filled('academic_year_id') || $request->filled('semester_id')) {
            abort_unless(
                $academicYearId
                    && $semesterId
                    && $selectedAcademicYear?->semesters->contains('id', $semesterId),
                422,
            );
        }

        return Inertia::render('quality/course-assignments/Index', [
            'courses' => Course::query()
                ->when(! $semesterId, fn ($query) => $query->whereRaw('1 = 0'))
                ->with([
                    'department.school:id,name',
                    'lecturers' => fn ($query) => $query->wherePivot('semester_id', $semesterId),
                    'offerings' => fn ($query) => $query
                        ->where('semester_id', $semesterId)
                        ->with('program:id,department_id,name,code'),
                    ...($canViewApprovals ? ['qualityReports' => fn ($query) => $query
                        ->whereIn('status', ['under_review', 'approved'])
                        ->where('semester_id', $semesterId)
                        ->visibleTo($request->user())
                        ->with(['lecturer:id,name,email', 'academicYear:id,name'])
                        ->latest()] : []),
                ])
                ->whereHas('offerings', fn ($query) => $query
                    ->where('semester_id', $semesterId)
                    ->when(
                        ! $canManageStructure && ! $request->user()->canUploadQualityReports(),
                        fn ($query) => $query->whereHas(
                            'program',
                            fn ($query) => $query->where('department_id', $request->user()->department_id),
                        ),
                    ))
                ->join('departments', 'departments.id', '=', 'courses.department_id')
                ->join('schools', 'schools.id', '=', 'departments.school_id')
                ->orderBy('schools.name')
                ->orderBy('departments.name')
                ->orderBy('courses.code')
                ->select('courses.*')
                ->get()
                ->flatMap(fn (Course $course) => $course->offerings->map(fn ($offering) => [
                    'offeringId' => $offering->id,
                    'id' => $course->id,
                    'code' => $course->code,
                    'title' => $course->title,
                    'courseType' => $course->course_type,
                    'program' => $offering->program ? [
                        'id' => $offering->program->id,
                        'name' => $offering->program->name,
                        'code' => $offering->program->code,
                    ] : null,
                    'level' => $offering->level ?? $course->level,
                    'department' => [
                        'name' => $course->department->name,
                        'school' => ['name' => $course->department->school->name],
                    ],
                    'lecturers' => $course->lecturers
                        ->where('pivot.program_id', $offering->program_id)
                        ->map(fn (User $lecturer) => [
                        'id' => $lecturer->id,
                        'name' => $lecturer->name,
                        'email' => $lecturer->email,
                        'studentCount' => $lecturer->pivot->student_count,
                        'allocatedHours' => $lecturer->pivot->allocated_hours,
                        ])->values(),
                    'reports' => $canViewApprovals
                        ? $course->qualityReports->map(fn ($report) => [
                            'id' => $report->id,
                            'title' => $report->title,
                            'status' => $report->status,
                            'lecturer' => $report->lecturer?->name,
                            'academicYear' => $report->academicYear?->name,
                        ])
                        : [],
                ]))
                ->groupBy(fn (array $row): string => strtolower(trim($row['code'])))
                ->map(function ($rows): array {
                    $row = $rows->first();
                    $row['offeringIds'] = $rows->pluck('offeringId')->values();
                    $row['programs'] = $rows->pluck('program')->filter()->unique('id')->values();
                    $row['programIds'] = $row['programs']->pluck('id')->values();
                    $row['levels'] = $rows->pluck('level')->filter()->unique()->sort()->values();
                    $row['variants'] = $rows->map(function (array $variant): array {
                        $variant['offeringIds'] = collect([$variant['offeringId']]);
                        $variant['programs'] = collect([$variant['program']])->filter()->values();
                        $variant['programIds'] = $variant['programs']->pluck('id')->values();
                        $variant['levels'] = collect([$variant['level']])->filter()->values();
                        $variant['variants'] = [];

                        return $variant;
                    })->values();

                    $lecturerSignatures = $rows->map(
                        fn (array $variant) => collect($variant['lecturers'])->pluck('id')->sort()->implode(','),
                    )->unique();
                    $row['hasDifferentLecturers'] = $lecturerSignatures->count() > 1;
                    if ($row['hasDifferentLecturers']) {
                        $row['lecturers'] = collect();
                    }

                    return $row;
                })
                ->values(),
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
        $validated = $request->validate([
            'lecturer_ids' => ['present', 'array'],
            'lecturer_ids.*' => ['integer', Rule::exists('users', 'id')],
            'semester_id' => ['required', 'integer', Rule::exists('semesters', 'id')],
            'program_ids' => ['required', 'array', 'min:1'],
            'program_ids.*' => ['integer', Rule::exists('programs', 'id')],
            'assignments' => ['present', 'array'],
            'assignments.*.student_count' => ['nullable', 'integer', 'min:1'],
            'assignments.*.allocated_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ]);

        $programs = Program::query()->whereIn('id', $validated['program_ids'])->get();
        abort_unless($programs->count() === count(array_unique($validated['program_ids'])), 422);
        abort_if(
            ! $request->user()->canManageQualityStructure()
                && $programs->contains(fn (Program $program) => $program->department_id !== $request->user()->department_id),
            403,
        );

        $lecturerIds = collect($validated['lecturer_ids'])->unique()->values();
        $eligibleLecturers = User::query()
            ->whereIn('id', $lecturerIds)
            ->whereIn('university_role', QualityRolePermission::rolesWith('view_own_reports'))
            ->count();

        abort_unless($eligibleLecturers === $lecturerIds->count(), 422);
        abort_unless($course->course_type === 'module' || $lecturerIds->count() <= 1, 422);
        abort_unless(
            $course->offerings()
                ->where('semester_id', $validated['semester_id'])
                ->whereIn('program_id', $validated['program_ids'])
                ->count() === count(array_unique($validated['program_ids'])),
            422,
            __('This course must be mounted for the selected semester before a lecturer can be assigned.'),
        );

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
                ->whereIn('program_id', $validated['program_ids'])
                ->delete();

            $rows = collect($validated['program_ids'])->flatMap(
                fn (int $programId) => collect($assignments)->map(
                    fn (array $assignment, int $lecturerId) => [
                        'course_id' => $course->id,
                        'program_id' => $programId,
                        'lecturer_id' => $lecturerId,
                        'semester_id' => $validated['semester_id'],
                        ...$assignment,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                ),
            )->values()->all();

            if ($rows !== []) {
                DB::table('course_lecturer')->insert($rows);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Lecturer assignments saved.')]);

        return to_route('quality.course-assignments.index');
    }

    private function canManageAssignments(User $user): bool
    {
        return $user->canManageQualityStructure() || $user->canManageCourseAssignments();
    }

    private function canViewAssignments(User $user): bool
    {
        return $this->canManageAssignments($user) || $user->canUploadQualityReports();
    }
}
