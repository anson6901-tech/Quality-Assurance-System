<?php

namespace App\Http\Controllers\Quality;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Program;
use App\Models\QualityReport;
use App\Models\User;
use App\Support\Area;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CourseOfferingController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeAccess($request->user());

        $programs = $this->programsFor($request->user())
            ->with('department.school:id,name')
            ->orderBy('name')
            ->get();

        $courses = Course::query()
            ->orderByRaw('(level + 0)')
            ->orderBy('code')
            ->get()
            ->map(fn (Course $course) => [
                'id' => $course->id,
                'code' => $course->code,
                'title' => $course->title,
                'level' => $course->level,
                'courseType' => $course->course_type,
            ]);

        $semesterIds = AcademicYear::query()->with('semesters')->get()
            ->flatMap->semesters->pluck('id');

        return Inertia::render('quality/course-offerings/Index', [
            'academicYears' => AcademicYear::query()
                ->with('semesters:id,academic_year_id,name,sequence')
                ->orderByDesc('starts_on')
                ->get(['id', 'name', 'status']),
            'programs' => $programs->map(fn (Program $program) => [
                'id' => $program->id,
                'name' => $program->name,
                'code' => $program->code,
                'maxLevel' => $program->max_level,
                'department' => $program->department->name,
                'school' => $program->department->school->name,
            ]),
            'courses' => $courses,
            'offerings' => CourseOffering::query()
                ->whereIn('semester_id', $semesterIds)
                ->whereIn('course_id', $courses->pluck('id'))
                ->whereIn('program_id', $programs->pluck('id'))
                ->get(['course_id', 'program_id', 'semester_id', 'level'])
                ->map(fn (CourseOffering $offering) => [
                    'courseId' => $offering->course_id,
                    'programId' => $offering->program_id,
                    'semesterId' => $offering->semester_id,
                    'level' => $offering->level,
                ]),
            'assignedOfferings' => DB::table('course_lecturer')
                ->whereNotNull('semester_id')
                ->whereIn('program_id', $programs->pluck('id'))
                ->select(['course_id', 'program_id', 'semester_id'])
                ->distinct()
                ->get()
                ->map(fn (object $assignment) => [
                    'courseId' => $assignment->course_id,
                    'programId' => $assignment->program_id,
                    'semesterId' => $assignment->semester_id,
                ]),
        ]);
    }

    public function sync(Request $request): RedirectResponse
    {
        $this->authorizeAccess($request->user());
        $validated = $request->validate([
            'program_id' => ['required', 'integer', Rule::exists('programs', 'id')],
            'semester_id' => ['required', 'integer', Rule::exists('semesters', 'id')],
            'level' => ['sometimes', 'nullable', 'string', 'regex:/^[1-9][0-9]*00$/'],
            'course_ids' => ['present', 'array'],
            'course_ids.*' => ['integer', Rule::exists('courses', 'id')],
            'levels' => ['sometimes', 'array'],
            'levels.*' => ['nullable', 'string', 'regex:/^[1-9][0-9]*00$/'],
        ]);

        $program = $this->findAllowedProgram($request->user(), (int) $validated['program_id']);
        $courseIds = collect($validated['course_ids'])->map(fn ($id) => (int) $id)->unique();
        $validCount = Course::query()
            ->whereIn('id', $courseIds)
            ->where(fn ($query) => $query
                ->whereNull('level')
                ->orWhereRaw('(level + 0) <= ?', [$program->max_level]))
            ->count();
        abort_unless($validCount === $courseIds->count(), 422);

        $selectedLevel = $validated['level'] ?? null;
        abort_if($selectedLevel && (int) $selectedLevel > 1000, 422);

        $currentlyMounted = CourseOffering::query()
            ->where('semester_id', $validated['semester_id'])
            ->where('program_id', $program->id)
            ->when($selectedLevel, fn ($query) => $query->where('level', $selectedLevel))
            ->pluck('course_id');
        $toRemove = $currentlyMounted->diff($courseIds);
        $assignedRemoval = DB::table('course_lecturer')
            ->where('semester_id', $validated['semester_id'])
            ->where('program_id', $program->id)
            ->whereIn('course_id', $toRemove)
            ->exists();

        if ($assignedRemoval) {
            throw ValidationException::withMessages([
                'course_ids' => __('A mounted course with a lecturer assignment cannot be removed. Remove its lecturer assignment first.'),
            ]);
        }

        DB::transaction(function () use ($validated, $program, $courseIds, $toRemove, $selectedLevel): void {
            CourseOffering::query()
                ->where('semester_id', $validated['semester_id'])
                ->where('program_id', $program->id)
                ->when($selectedLevel, fn ($query) => $query->where('level', $selectedLevel))
                ->whereIn('course_id', $toRemove)
                ->delete();

            $courses = Course::query()->whereIn('id', $courseIds)->get()->keyBy('id');
            $courseIds->each(function (int $courseId) use ($validated, $program, $courses, $selectedLevel): void {
                $level = $selectedLevel
                    ?? ($validated['levels'] ?? [])[$courseId]
                    ?? $courses[$courseId]?->level;
                abort_unless($level && (int) $level <= 1000, 422);
                CourseOffering::updateOrCreate([
                    'course_id' => $courseId,
                    'program_id' => $program->id,
                    'semester_id' => $validated['semester_id'],
                ], ['level' => $level]);
            });
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mounted courses saved.')]);

        return to_route('quality.course-offerings.index');
    }

    public function copy(Request $request): RedirectResponse
    {
        $this->authorizeAccess($request->user());
        $validated = $request->validate([
            'program_id' => ['required', 'integer', Rule::exists('programs', 'id')],
            'source_semester_id' => ['required', 'integer', Rule::exists('semesters', 'id')],
            'target_semester_id' => [
                'required',
                'integer',
                Rule::exists('semesters', 'id'),
                'different:source_semester_id',
            ],
        ]);

        $program = $this->findAllowedProgram($request->user(), (int) $validated['program_id']);
        $sourceOfferings = CourseOffering::query()
            ->where('semester_id', $validated['source_semester_id'])
            ->where('program_id', $program->id)
            ->get(['course_id', 'level']);

        abort_if($sourceOfferings->isEmpty(), 422, __('There are no mounted courses to copy from the selected semester.'));

        DB::transaction(fn () => $sourceOfferings->each(
            fn (CourseOffering $offering) => CourseOffering::updateOrCreate([
                'course_id' => $offering->course_id,
                'program_id' => $program->id,
                'semester_id' => $validated['target_semester_id'],
            ], ['level' => $offering->level]),
        ));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':count mounted courses copied.', ['count' => $sourceOfferings->count()]),
        ]);

        return to_route('quality.course-offerings.index');
    }

    public function destroy(Request $request, int $course): RedirectResponse
    {
        $this->authorizeAccess($request->user());

        $validated = $request->validate([
            'program_id' => ['required', 'integer', Rule::exists('programs', 'id')],
            'semester_id' => ['required', 'integer', Rule::exists('semesters', 'id')],
        ]);

        $program = $this->findAllowedProgram($request->user(), (int) $validated['program_id']);
        $offering = CourseOffering::query()
            ->where('course_id', $course)
            ->where('program_id', $program->id)
            ->where('semester_id', $validated['semester_id'])
            ->first();

        if (! $offering) {
            return to_route('quality.course-offerings.index');
        }

        $hasReports = QualityReport::withTrashed()
            ->where('program_id', $program->id)
            ->where('semester_id', $validated['semester_id'])
            ->whereHas('courses', fn ($query) => $query->whereKey($course))
            ->exists();

        if ($hasReports) {
            throw ValidationException::withMessages([
                'course_ids' => __('This mounted course has report history and cannot be removed.'),
            ]);
        }

        DB::transaction(function () use ($validated, $program, $course, $offering): void {
            DB::table('course_lecturer')
                ->where('course_id', $course)
                ->where('program_id', $program->id)
                ->where('semester_id', $validated['semester_id'])
                ->delete();

            $offering->delete();
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Course unmounted and its lecturer assignments removed.'),
        ]);

        return to_route('quality.course-offerings.index');
    }

    private function programsFor(User $user)
    {
        return Area::programs(Program::query(), $user);
    }

    private function findAllowedProgram(User $user, int $programId): Program
    {
        return $this->programsFor($user)->findOrFail($programId);
    }

    private function authorizeAccess(User $user): void
    {
        abort_unless($user->canManageQualityStructure() || $user->canManageCourseAssignments(), 403);
    }
}
