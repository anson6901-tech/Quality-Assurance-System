<?php

namespace App\Support\BulkUpload;

use App\Models\Course;
use App\Models\Program;
use App\Models\QualityReport;
use App\Models\User;
use App\Support\Area;
use App\Support\CourseBoard;
use App\Support\CoursePartitions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Every report that should exist for a semester: one per assigned lecturer per course part,
 * limited to the uploader's placement area. Drives both the checklist download and the
 * matching of uploaded files.
 */
class ExpectedReports
{
    /**
     * @return Collection<int, array{key: string, courseId: int, courseCode: string, courseTitle: string, partKey: string, partLabel: string, cells: array<int, array{program_id: int|null, section_id: int|null}>, lecturerId: int, lecturerName: string, staffId: string, fileName: string, hasReport: bool, replaceable: bool}>
     */
    public static function for(User $actor, int $semesterId): Collection
    {
        $courses = Course::query()
            ->with(CourseBoard::relations([$semesterId]))
            ->whereHas('offerings', fn ($query) => $query->where('semester_id', $semesterId))
            ->tap(fn ($query) => Area::courses($query, $actor))
            ->orderBy('code')
            ->get();

        $rows = CourseBoard::assignmentRows($courses->pluck('id'), [$semesterId]);
        $allowedPrograms = $actor->isPlacedAtUniversity()
            ? null
            : Area::programs(Program::query(), $actor)->pluck('id')->all();
        $staffIds = User::query()->whereIn('id', $rows->pluck('lecturer_id')->unique())->pluck('staff_id', 'id');

        $expected = CourseBoard::entries($courses, [$semesterId], $rows)
            ->flatMap(fn (array $entry) => collect($entry['parts'])->flatMap(
                fn (array $part) => collect($part['lecturers'])->map(function (array $lecturer) use ($entry, $part, $rows, $allowedPrograms, $staffIds) {
                    $cells = collect($part['cells'])
                        ->filter(fn (array $cell) => $allowedPrograms === null || in_array($cell['programId'], $allowedPrograms, true))
                        ->filter(fn (array $cell) => $rows->contains(fn ($row) => $row->course_id === $entry['id']
                            && $row->lecturer_id === $lecturer['id']
                            && CoursePartitions::cellKey($row->program_id, $row->section_id) === CoursePartitions::cellKey($cell['programId'], $cell['sectionId'])))
                        ->map(fn (array $cell) => ['program_id' => $cell['programId'], 'section_id' => $cell['sectionId']])
                        ->values()
                        ->all();

                    return $cells === [] ? null : [
                        'key' => "{$entry['id']}|{$part['key']}|{$lecturer['id']}",
                        'courseId' => $entry['id'],
                        'courseCode' => $entry['code'],
                        'courseTitle' => $entry['title'],
                        'partKey' => $part['key'],
                        'partLabel' => $part['label'],
                        'cells' => $cells,
                        'lecturerId' => $lecturer['id'],
                        'lecturerName' => (string) $lecturer['name'],
                        'staffId' => (string) (($staffIds[$lecturer['id']] ?? null) ?: 'ID'.$lecturer['id']),
                    ];
                })->filter(),
            ))
            ->values();

        $partsPerLecturerCourse = $expected->countBy(fn (array $item) => "{$item['courseId']}|{$item['lecturerId']}");

        return $expected->map(fn (array $item) => [
            ...$item,
            'fileName' => self::fileName($item, $partsPerLecturerCourse["{$item['courseId']}|{$item['lecturerId']}"] > 1),
            ...self::reportState($item, $semesterId, $actor),
        ]);
    }

    /**
     * Strips everything but letters and digits, so names match regardless of spaces,
     * separators or case.
     */
    public static function normalise(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }

    /**
     * COURSE_STAFFID.pdf, or COURSE_PART_STAFFID.pdf when the lecturer teaches several parts.
     *
     * @param  array<string, mixed>  $item
     */
    private static function fileName(array $item, bool $includePart): string
    {
        $clean = fn (string $value) => strtoupper((string) preg_replace('/[^A-Za-z0-9-]+/', '', $value));

        return implode('_', array_filter([
            $clean($item['courseCode']),
            $includePart ? strtoupper(Str::slug($item['partLabel'])) : null,
            $clean($item['staffId']),
        ])).'.pdf';
    }

    /**
     * Whether reports already exist for this course part and lecturer, and whether they can be
     * replaced: only the uploader's own drafts can, since anything submitted is in review.
     *
     * @param  array<string, mixed>  $item
     * @return array{hasReport: bool, replaceable: bool}
     */
    public static function reportState(array $item, int $semesterId, User $actor): array
    {
        $existing = self::existingReports($item, $semesterId)->get(['id', 'status', 'uploaded_by']);

        return [
            'hasReport' => $existing->isNotEmpty(),
            'replaceable' => $existing->isNotEmpty()
                && $existing->every(fn (QualityReport $report) => $report->status === 'draft' && $report->uploaded_by === $actor->id),
        ];
    }

    /**
     * Reports for this lecturer, course and semester in any of the item's program sections.
     *
     * @param  array<string, mixed>  $item
     * @return Builder<QualityReport>
     */
    public static function existingReports(array $item, int $semesterId)
    {
        return QualityReport::query()
            ->where('semester_id', $semesterId)
            ->where('lecturer_id', $item['lecturerId'])
            ->whereHas('courses', fn ($query) => $query->whereKey($item['courseId']))
            ->where(function ($query) use ($item): void {
                foreach ($item['cells'] as $cell) {
                    $query->orWhere(function ($query) use ($cell): void {
                        $cell['program_id'] === null ? $query->whereNull('program_id') : $query->where('program_id', $cell['program_id']);
                        $cell['section_id'] === null ? $query->whereNull('section_id') : $query->where('section_id', $cell['section_id']);
                    });
                }
            });
    }
}
