<?php

namespace App\Support;

use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Section;
use Illuminate\Support\Collection;

/**
 * Splits a mounted course into the parts a lecturer can be assigned to.
 *
 * The smallest unit is a "cell": one program offering of the course at its level, in one
 * section of that program (or the whole program when it has no sections). A course split
 * mode groups those cells into parts; a merged course is a single part.
 */
class CoursePartitions
{
    public const MERGED = 'merged';

    public const LEVEL_PROGRAM = 'level_program';

    public const LEVEL_SECTION = 'level_section';

    public const SECTION_PROGRAM = 'section_program';

    public const LEVEL_PROGRAM_SECTION = 'level_program_section';

    public const MODES = [
        self::LEVEL_PROGRAM => 'Program and level',
        self::LEVEL_SECTION => 'Level and section',
        self::SECTION_PROGRAM => 'Program and section',
        self::LEVEL_PROGRAM_SECTION => 'Level, program and section',
    ];

    /**
     * Cells for one course in one semester. Offerings must have program.sections loaded.
     *
     * @return Collection<int, array{programId: int|null, sectionId: int|null, level: string|null, program: array<string, mixed>|null, section: array<string, mixed>|null}>
     */
    public static function cells(Course $course, int $semesterId): Collection
    {
        return $course->offerings
            ->where('semester_id', $semesterId)
            ->sortBy(fn (CourseOffering $offering) => $offering->program?->code)
            ->flatMap(function (CourseOffering $offering) use ($course) {
                $program = $offering->program;
                $sections = $program?->sections ?? collect();
                $level = $offering->level ?? $course->level;
                $programData = $program ? [
                    'id' => $program->id,
                    'name' => $program->name,
                    'code' => $program->code,
                ] : null;

                if ($sections->isEmpty()) {
                    return [[
                        'programId' => $program?->id,
                        'sectionId' => null,
                        'level' => $level,
                        'program' => $programData,
                        'section' => null,
                    ]];
                }

                return $sections->map(fn (Section $section) => [
                    'programId' => $program->id,
                    'sectionId' => $section->id,
                    'level' => $level,
                    'program' => $programData,
                    'section' => $section->toOption(),
                ])->all();
            })
            ->values();
    }

    public static function cellKey(?int $programId, ?int $sectionId): string
    {
        return ($programId ?? 0).':'.($sectionId ?? 0);
    }

    /**
     * @param  array{programId: int|null, sectionId: int|null, level: string|null}  $cell
     */
    public static function partKey(string $mode, array $cell): string
    {
        $program = $cell['programId'] ?? 0;
        $section = $cell['sectionId'] ?? 0;
        $level = $cell['level'] ?? 0;
        $sectionKind = isset($cell['section'])
            ? $cell['section']['type'].($cell['section']['isWeekend'] ? '-weekend' : '')
            : 'none';

        return match ($mode) {
            self::LEVEL_PROGRAM => "L{$level}:P{$program}",
            // Same section type across programs is taught together at each level.
            self::LEVEL_SECTION => "L{$level}:T{$sectionKind}",
            self::SECTION_PROGRAM => "P{$program}:S{$section}",
            self::LEVEL_PROGRAM_SECTION => "L{$level}:P{$program}:S{$section}",
            default => 'all',
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $cells
     */
    public static function partLabel(string $mode, Collection $cells): string
    {
        $cell = $cells->first();
        $programCode = $cell['program']['code'] ?? 'No program';
        $sectionName = $cell['section']['name'] ?? 'All sections';
        $level = $cell['level'] ? "Level {$cell['level']}" : 'Level not set';

        $programCodes = $cells->pluck('program.code')->filter()->unique()->implode(', ') ?: 'No program';

        return match ($mode) {
            self::LEVEL_PROGRAM => "{$programCode} · {$level}",
            self::LEVEL_SECTION => "{$level} · {$sectionName} · {$programCodes}",
            self::SECTION_PROGRAM => "{$programCode} · {$sectionName}",
            self::LEVEL_PROGRAM_SECTION => "{$programCode} · {$level} · {$sectionName}",
            default => 'Whole course',
        };
    }

    /**
     * Groups cells into parts for the given split mode.
     *
     * @param  Collection<int, array<string, mixed>>  $cells
     * @return Collection<int, array{key: string, label: string, cells: Collection<int, array<string, mixed>>}>
     */
    public static function parts(string $mode, Collection $cells): Collection
    {
        return $cells
            ->groupBy(fn (array $cell) => self::partKey($mode, $cell))
            ->map(fn (Collection $partCells, string $key) => [
                'key' => $key,
                'label' => self::partLabel($mode, $partCells),
                'cells' => $partCells->values(),
            ])
            ->values();
    }

    public static function normaliseMode(?string $mode): string
    {
        return array_key_exists((string) $mode, self::MODES) ? $mode : self::MERGED;
    }

    /**
     * Summarises the lecturers assigned across a part's cells.
     *
     * @param  Collection<int, object>  $assignmentRows  course_lecturer rows with lecturer_id, program_id, section_id, student_count, allocated_hours, and a `lecturer` user.
     * @param  Collection<int, array<string, mixed>>  $cells
     * @return array{lecturers: array<int, array<string, mixed>>, mixedLecturers: bool, unassignedCells: int}
     */
    public static function lecturersFor(Collection $assignmentRows, Collection $cells): array
    {
        $cellKeys = $cells->map(fn (array $cell) => self::cellKey($cell['programId'], $cell['sectionId']));
        $rows = $assignmentRows->filter(
            fn ($row) => $cellKeys->contains(self::cellKey($row->program_id, $row->section_id)),
        );

        $signatures = $cellKeys->map(fn (string $cellKey) => $rows
            ->filter(fn ($row) => self::cellKey($row->program_id, $row->section_id) === $cellKey)
            ->pluck('lecturer_id')
            ->sort()
            ->implode(','));

        return [
            'lecturers' => $rows
                ->groupBy('lecturer_id')
                ->map(function (Collection $lecturerRows) {
                    $row = $lecturerRows->first();

                    return [
                        'id' => $row->lecturer_id,
                        'name' => $row->lecturer?->name,
                        'email' => $row->lecturer?->email,
                        'studentCount' => $row->student_count,
                        'allocatedHours' => $row->allocated_hours,
                    ];
                })
                ->sortBy('name')
                ->values()
                ->all(),
            'mixedLecturers' => $signatures->unique()->count() > 1,
            'unassignedCells' => $signatures->filter(fn (string $signature) => $signature === '')->count(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $cells
     * @return array<int, array{program_id: int|null, section_id: int|null}>
     */
    public static function cellPairs(Collection $cells): array
    {
        return $cells->map(fn (array $cell) => [
            'program_id' => $cell['programId'],
            'section_id' => $cell['sectionId'],
        ])->values()->all();
    }
}
