<?php

namespace App\Http\Controllers\Quality;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseSplit;
use App\Models\Program;
use App\Models\QualityRolePermission;
use App\Models\Semester;
use App\Models\User;
use App\Support\Area;
use App\Support\CourseBoard;
use App\Support\CoursePartitions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            'courses' => $this->boardCourses($request, $semesterId, $canManageStructure, $canViewApprovals),
            'splitModes' => collect(CoursePartitions::MODES)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'canManageAssignments' => $this->canManageAssignments($request->user()),
            'canManageStructure' => $canManageStructure,
            'lecturers' => User::query()
                ->whereIn('university_role', QualityRolePermission::rolesWith('view_own_reports'))
                ->where('is_active', true)
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
            'cells' => ['required_without:program_ids', 'array', 'min:1'],
            'cells.*.program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')],
            'cells.*.section_id' => ['nullable', 'integer', Rule::exists('sections', 'id')],
            'program_ids' => ['required_without:cells', 'array', 'min:1'],
            'program_ids.*' => ['integer', Rule::exists('programs', 'id')],
            'assignments' => ['present', 'array'],
            'assignments.*.student_count' => ['nullable', 'integer', 'min:1'],
            'assignments.*.allocated_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ]);

        $semesterId = (int) $validated['semester_id'];
        $cells = $this->resolveCells($request, $course, $semesterId, $validated);

        $lecturerIds = collect($validated['lecturer_ids'])->unique()->values();
        $eligibleLecturers = User::query()
            ->whereIn('id', $lecturerIds)
            ->whereIn('university_role', QualityRolePermission::rolesWith('view_own_reports'))
            ->where('is_active', true)
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

        DB::transaction(function () use ($course, $semesterId, $cells, $assignments): void {
            $query = DB::table('course_lecturer')
                ->where('course_id', $course->id)
                ->where('semester_id', $semesterId);
            CourseBoard::whereCells($query, $cells);
            $query->delete();

            $rows = $cells->flatMap(
                fn (array $cell) => collect($assignments)->map(
                    fn (array $assignment, int $lecturerId) => [
                        'course_id' => $course->id,
                        'program_id' => $cell['programId'],
                        'section_id' => $cell['sectionId'],
                        'lecturer_id' => $lecturerId,
                        'semester_id' => $semesterId,
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

    /**
     * Unassigns every lecturer from the chosen part of a course for the semester. Reports
     * already uploaded for them are kept.
     */
    public function unassign(Request $request, Course $course): RedirectResponse
    {
        abort_unless($this->canManageAssignments($request->user()), 403);
        $validated = $request->validate([
            'semester_id' => ['required', 'integer', Rule::exists('semesters', 'id')],
            'cells' => ['required', 'array', 'min:1'],
            'cells.*.program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')],
            'cells.*.section_id' => ['nullable', 'integer', Rule::exists('sections', 'id')],
        ]);

        $semesterId = (int) $validated['semester_id'];
        $cells = $this->resolveCells($request, $course, $semesterId, $validated, allowUnmounted: true);

        $query = DB::table('course_lecturer')
            ->where('course_id', $course->id)
            ->where('semester_id', $semesterId);
        CourseBoard::whereCells($query, $cells);

        $names = User::query()->whereIn('id', (clone $query)->pluck('lecturer_id')->unique())->orderBy('name')->pluck('name');
        $query->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => $names->isEmpty()
            ? __('No lecturers were assigned to that part of :code.', ['code' => $course->code])
            : __(':names unassigned from :code.', ['names' => $names->implode(', '), 'code' => $course->code])]);

        return to_route('quality.course-assignments.index');
    }

    /**
     * The mounted cells of a course part named in the request, after checking the user may
     * change them.
     *
     * @param  array<string, mixed>  $validated
     * @return Collection<int, array<string, mixed>>
     */
    private function resolveCells(Request $request, Course $course, int $semesterId, array $validated, bool $allowUnmounted = false)
    {
        $course->load(CourseBoard::relations([$semesterId]));
        $courseCells = CoursePartitions::cells($course, $semesterId);

        // Assignments can outlive a course being unmounted; they can still be removed.
        if ($courseCells->isEmpty() && $allowUnmounted) {
            $courseCells = collect($validated['cells'])->map(fn (array $cell) => [
                'programId' => isset($cell['program_id']) ? (int) $cell['program_id'] : null,
                'sectionId' => isset($cell['section_id']) ? (int) $cell['section_id'] : null,
            ]);
        }

        abort_if(
            $courseCells->isEmpty(),
            422,
            __('This course must be mounted for the selected semester before a lecturer can be assigned.'),
        );

        $cells = isset($validated['cells'])
            ? collect($validated['cells'])->map(fn (array $cell) => $courseCells->first(
                fn (array $courseCell) => $courseCell['programId'] === (isset($cell['program_id']) ? (int) $cell['program_id'] : null)
                    && $courseCell['sectionId'] === (isset($cell['section_id']) ? (int) $cell['section_id'] : null),
            ))
            : $courseCells->whereIn('programId', array_map('intval', $validated['program_ids']))->values();

        if (isset($validated['program_ids'])) {
            abort_unless(
                collect($validated['program_ids'])->unique()->every(
                    fn ($programId) => $courseCells->contains('programId', (int) $programId),
                ),
                422,
                __('This course must be mounted for the selected semester before a lecturer can be assigned.'),
            );
        }

        abort_if($cells->isEmpty() || $cells->contains(null), 422, __('Select parts of this course that are mounted for the semester.'));
        $this->authorizeCells($request->user(), $cells);

        return $cells;
    }

    public function split(Request $request, Course $course): RedirectResponse
    {
        abort_unless($this->canManageAssignments($request->user()), 403);
        $validated = $request->validate([
            'semester_id' => ['required', 'integer', Rule::exists('semesters', 'id')],
            'mode' => ['required', 'string', Rule::in(array_keys(CoursePartitions::MODES))],
        ]);

        $semesterId = (int) $validated['semester_id'];
        $course->load(CourseBoard::relations([$semesterId]));
        $cells = CoursePartitions::cells($course, $semesterId);
        abort_if($cells->isEmpty(), 422, __('Mount this course for the semester before splitting it.'));
        $this->authorizeCourse($request->user(), $course, $cells);

        CourseSplit::query()->updateOrCreate(
            ['course_id' => $course->id, 'semester_id' => $semesterId],
            ['mode' => $validated['mode']],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':code split by :mode.', [
            'code' => $course->code,
            'mode' => strtolower(CoursePartitions::MODES[$validated['mode']]),
        ])]);

        return back();
    }

    /**
     * Parts of a course for each semester it is mounted in, used by the split dialog on any page.
     */
    public function splitOptions(Request $request, Course $course): JsonResponse
    {
        abort_unless($this->canManageAssignments($request->user()), 403);
        $validated = $request->validate([
            'semester_id' => ['nullable', 'integer', Rule::exists('semesters', 'id')],
        ]);

        $semesters = Semester::query()
            ->with('academicYear:id,name,status')
            ->whereIn('id', $course->offerings()->select('semester_id'))
            ->when($validated['semester_id'] ?? null, fn ($query, $semesterId) => $query->whereKey($semesterId))
            ->get()
            ->sortBy([
                fn (Semester $semester) => $semester->academicYear?->status === 'active' ? 0 : 1,
                fn (Semester $semester) => $semester->academicYear?->name,
                fn (Semester $semester) => $semester->sequence,
            ])
            ->values();

        $course->load(CourseBoard::relations($semesters->pluck('id')->all()));

        return response()->json([
            'semesters' => $semesters->map(fn (Semester $semester) => [
                'id' => $semester->id,
                'label' => trim(($semester->academicYear?->name ?? '').' · '.$semester->name, ' ·'),
                'mode' => CourseBoard::modeFor($course, $semester->id),
                'cells' => CoursePartitions::cells($course, $semester->id),
            ])->values(),
        ]);
    }

    public function merge(Request $request, Course $course): RedirectResponse
    {
        abort_unless($this->canManageAssignments($request->user()), 403);
        $validated = $request->validate([
            'semester_id' => ['required', 'integer', Rule::exists('semesters', 'id')],
        ]);

        $semesterId = (int) $validated['semester_id'];
        $course->load(CourseBoard::relations([$semesterId]));
        $this->authorizeCourse($request->user(), $course, CoursePartitions::cells($course, $semesterId));

        CourseSplit::query()
            ->where('course_id', $course->id)
            ->where('semester_id', $semesterId)
            ->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':code merged back into one course.', [
            'code' => $course->code,
        ])]);

        return back();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function boardCourses(Request $request, ?int $semesterId, bool $canManageStructure, bool $canViewApprovals)
    {
        if (! $semesterId) {
            return collect();
        }

        $courses = Course::query()
            ->with(CourseBoard::relations([$semesterId]))
            ->with($canViewApprovals ? ['qualityReports' => fn ($query) => $query
                ->whereIn('status', ['under_review', 'approved'])
                ->where('semester_id', $semesterId)
                ->visibleTo($request->user())
                ->with(['lecturer:id,name,email', 'academicYear:id,name'])
                ->latest()] : [])
            ->whereHas('offerings', fn ($query) => $query
                ->where('semester_id', $semesterId)
                ->when(
                    ! $request->user()->isPlacedAtUniversity(),
                    fn ($query) => $query->whereHas(
                        'program',
                        fn ($query) => Area::programs($query, $request->user()),
                    ),
                ))
            ->orderBy('code')
            ->get();

        $rows = CourseBoard::assignmentRows($courses->pluck('id'), [$semesterId]);

        return CourseBoard::entries($courses, [$semesterId], $rows)
            ->map(function (array $entry) use ($courses, $canViewApprovals): array {
                $course = $courses->firstWhere('id', $entry['id']);
                $entry['reports'] = $canViewApprovals
                    ? $course->qualityReports->map(fn ($report) => [
                        'id' => $report->id,
                        'title' => $report->title,
                        'status' => $report->status,
                        'lecturer' => $report->lecturer?->name,
                        'academicYear' => $report->academicYear?->name,
                    ])->values()
                    : [];

                return $entry;
            });
    }

    /**
     * Users placed below university level may split or merge only the parts of a course
     * offered in their own area's programs.
     *
     * @param  Collection<int, array<string, mixed>>  $cells
     */
    private function authorizeCourse(User $user, Course $course, $cells): void
    {
        $this->authorizeCells($user, $cells);
    }

    /**
     * Users placed below university level may only change parts of courses in their own
     * area's programs.
     *
     * @param  Collection<int, array<string, mixed>>  $cells
     */
    private function authorizeCells(User $user, $cells): void
    {
        if ($user->isPlacedAtUniversity()) {
            return;
        }

        $programIds = $cells->pluck('programId');
        $allowed = Area::programs(Program::query(), $user)
            ->whereIn('id', $programIds->filter()->unique())
            ->count();

        abort_if(
            $programIds->contains(null) || $allowed !== $programIds->filter()->unique()->count(),
            403,
        );
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
