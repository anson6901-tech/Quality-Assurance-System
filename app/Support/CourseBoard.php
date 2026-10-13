<?php

namespace App\Support;

use App\Models\Course;
use App\Models\CourseSplit;
use App\Models\Program;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the course-centred payload shared by the course listing pages: one entry per
 * course and semester, holding its split mode and the parts lecturers are assigned to.
 */
class CourseBoard
{
    /**
     * Eager loads needed by {@see self::entries()} for the given semesters.
     *
     * @param  array<int, int>  $semesterIds
     * @return array<int|string, mixed>
     */
    public static function relations(array $semesterIds): array
    {
        return [
            'department.school',
            'offerings' => fn ($query) => $query
                ->whereIn('semester_id', $semesterIds)
                ->with(['program:id,department_id,name,code', 'program.sections']),
            'splits' => fn ($query) => $query->whereIn('semester_id', $semesterIds),
        ];
    }

    /**
     * course_lecturer rows for the courses and semesters, each with its `lecturer` user.
     *
     * @param  Collection<int, int>|array<int, int>  $courseIds
     * @param  array<int, int>  $semesterIds
     * @return Collection<int, object>
     */
    public static function assignmentRows($courseIds, array $semesterIds): Collection
    {
        $rows = DB::table('course_lecturer')
            ->whereIn('course_id', collect($courseIds)->all())
            ->whereIn('semester_id', $semesterIds)
            ->get();

        $lecturers = User::query()
            ->whereIn('id', $rows->pluck('lecturer_id')->unique())
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        return $rows->map(function ($row) use ($lecturers) {
            $row->lecturer = $lecturers->get($row->lecturer_id);

            return $row;
        });
    }

    public static function modeFor(Course $course, int $semesterId): string
    {
        return CoursePartitions::normaliseMode(
            $course->splits->first(fn (CourseSplit $split) => $split->semester_id === $semesterId)?->mode,
        );
    }

    /**
     * One entry per course offered in each semester.
     *
     * @param  Collection<int, Course>  $courses
     * @param  array<int, int>  $semesterIds
     * @param  Collection<int, object>  $assignmentRows
     * @param  (callable(Course, int, Collection<int, array<string, mixed>>): array<string, mixed>)|null  $extendPart
     * @return Collection<int, array<string, mixed>>
     */
    public static function entries(
        Collection $courses,
        array $semesterIds,
        Collection $assignmentRows,
        ?callable $extendPart = null,
    ): Collection {
        return $courses->flatMap(fn (Course $course) => collect($semesterIds)
            ->map(function (int $semesterId) use ($course, $assignmentRows, $extendPart) {
                $rows = $assignmentRows
                    ->where('course_id', $course->id)
                    ->where('semester_id', $semesterId);
                $cells = CoursePartitions::cells($course, $semesterId);

                if ($cells->isEmpty()) {
                    $cells = self::cellsFromAssignments($course, $rows);
                }

                if ($cells->isEmpty()) {
                    return null;
                }

                $mode = self::modeFor($course, $semesterId);

                return [
                    'key' => "{$course->id}:{$semesterId}",
                    'id' => $course->id,
                    'code' => $course->code,
                    'title' => $course->title,
                    'courseType' => $course->course_type,
                    'creditHours' => $course->credit_hours,
                    'semesterId' => $semesterId,
                    'department' => $course->department ? [
                        'id' => $course->department->id,
                        'name' => $course->department->name,
                        'school' => $course->department->school ? [
                            'id' => $course->department->school->id,
                            'name' => $course->department->school->name,
                        ] : null,
                    ] : null,
                    'splitMode' => $mode,
                    'programs' => $cells->pluck('program')->filter()->unique('id')->values(),
                    'sections' => $cells->pluck('section')->filter()->unique('id')->values(),
                    'levels' => $cells->pluck('level')->filter()->unique()->sort()->values(),
                    'hasSections' => $cells->contains(fn (array $cell) => $cell['sectionId'] !== null),
                    'cellCount' => $cells->count(),
                    'parts' => CoursePartitions::parts($mode, $cells)
                        ->map(fn (array $part) => [
                            'key' => $part['key'],
                            'label' => $part['label'],
                            'cells' => $part['cells'],
                            ...CoursePartitions::lecturersFor($rows, $part['cells']),
                            ...($extendPart ? $extendPart($course, $semesterId, $part['cells']) : []),
                        ])
                        ->values(),
                ];
            })
            ->filter())
            ->values();
    }

    /**
     * Cells for assignments made without a mounted offering (older data).
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private static function cellsFromAssignments(Course $course, Collection $rows): Collection
    {
        $programs = Program::query()
            ->with('sections')
            ->whereIn('id', $rows->pluck('program_id')->filter()->unique())
            ->get(['id', 'name', 'code'])
            ->keyBy('id');

        return $rows
            ->unique(fn ($row) => CoursePartitions::cellKey($row->program_id, $row->section_id))
            ->map(function ($row) use ($course, $programs) {
                $program = $programs->get($row->program_id);
                $section = $program?->sections->firstWhere('id', $row->section_id);

                return [
                    'programId' => $row->program_id,
                    'sectionId' => $row->section_id,
                    'level' => $course->level,
                    'program' => $program ? ['id' => $program->id, 'name' => $program->name, 'code' => $program->code] : null,
                    'section' => $section?->toOption(),
                ];
            })
            ->values();
    }

    /**
     * Restricts a query on course_lecturer-like rows to the given cells.
     *
     * @param  Builder<Model>|\Illuminate\Database\Query\Builder  $query
     * @param  Collection<int, array<string, mixed>>  $cells
     */
    public static function whereCells($query, Collection $cells, string $programColumn = 'program_id', string $sectionColumn = 'section_id'): void
    {
        $query->where(function ($query) use ($cells, $programColumn, $sectionColumn): void {
            foreach ($cells as $cell) {
                $query->orWhere(function ($query) use ($cell, $programColumn, $sectionColumn): void {
                    $cell['programId'] === null
                        ? $query->whereNull($programColumn)
                        : $query->where($programColumn, $cell['programId']);
                    $cell['sectionId'] === null
                        ? $query->whereNull($sectionColumn)
                        : $query->where($sectionColumn, $cell['sectionId']);
                });
            }
        });
    }
}
