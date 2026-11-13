<?php

namespace App\Support\BulkUpload;

use App\Models\Course;
use App\Models\Department;
use App\Models\Program;
use App\Models\QualityReport;
use App\Models\ReportImportBatch;
use App\Models\ReportImportItem;
use App\Models\User;
use App\Support\Area;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns a matched bulk-upload file into draft reports, the same way a single upload does:
 * one report per program/section the lecturer teaches in that course part, all sharing
 * the PDF. Where the uploader already has a draft for a section, its PDF is replaced.
 */
class BulkReportCreator
{
    /**
     * @return array<int, int> IDs of the reports created or updated.
     */
    public static function create(ReportImportBatch $batch, ReportImportItem $item, User $actor): array
    {
        return self::createDrafts(
            academicYearId: $batch->academic_year_id,
            semesterId: $batch->semester_id,
            category: $batch->category,
            courseId: $item->course_id,
            lecturerId: $item->lecturer_id,
            cells: $item->cells ?? [],
            pdf: Storage::disk('local')->get($item->file_path),
            actor: $actor,
            note: __('Bulk upload'),
        );
    }

    /**
     * Creates (or refreshes the PDF of) the actor's draft reports for one lecturer's course part.
     *
     * @param  array<int, array{program_id: int|null, section_id: int|null}>  $cells
     * @return array<int, int> IDs of the reports created or updated.
     */
    public static function createDrafts(
        int $academicYearId,
        int $semesterId,
        string $category,
        int $courseId,
        int $lecturerId,
        array $cells,
        string $pdf,
        User $actor,
        string $note,
    ): array {
        $course = Course::query()->findOrFail($courseId);
        $lecturer = User::query()->findOrFail($lecturerId);
        $created = [];

        DB::transaction(function () use ($academicYearId, $semesterId, $category, $cells, $pdf, $note, $actor, $course, $lecturer, &$created): void {
            foreach ($cells as $cell) {
                $program = $cell['program_id'] ? Program::query()->with('department')->find($cell['program_id']) : null;
                $department = $program?->department
                    ?? ($course->department_id ? Department::query()->find($course->department_id) : null)
                    ?? ($lecturer->department_id ? Department::query()->find($lecturer->department_id) : null);

                if (! $department || ! Area::departments(Department::query(), $actor)->whereKey($department->id)->exists()) {
                    throw new RuntimeException(__('This course part is outside your area.'));
                }

                $path = 'quality-reports/'.Str::uuid().'.pdf';
                Storage::disk('public')->put($path, $pdf);

                $draft = QualityReport::query()
                    ->where('semester_id', $semesterId)
                    ->where('lecturer_id', $lecturer->id)
                    ->where('uploaded_by', $actor->id)
                    ->where('status', 'draft')
                    ->where('program_id', $program?->id)
                    ->where('section_id', $cell['section_id'])
                    ->whereHas('courses', fn ($query) => $query->whereKey($course->id))
                    ->first();

                if ($draft) {
                    $previous = $draft->file_path;
                    $draft->update(['file_path' => $path, 'category' => $category]);
                    $draft->reviews()->create(['actor_id' => $actor->id, 'action' => 'uploaded', 'note' => $note.': '.__('PDF replaced')]);

                    if ($previous && $previous !== $path) {
                        DB::afterCommit(fn () => Storage::disk('public')->delete($previous));
                    }

                    $created[] = $draft->id;

                    continue;
                }

                $report = QualityReport::create([
                    'title' => sprintf('%s %s — %s', $course->code, $course->title, $lecturer->name),
                    'category' => $category,
                    'status' => 'draft',
                    'academic_year_id' => $academicYearId,
                    'semester_id' => $semesterId,
                    'school_id' => $department->school_id,
                    'department_id' => $department->id,
                    'program_id' => $program?->id,
                    'section_id' => $cell['section_id'],
                    'lecturer_id' => $lecturer->id,
                    'file_path' => $path,
                    'uploaded_by' => $actor->id,
                ]);

                $report->courses()->sync([$course->id]);
                $report->reviews()->create(['actor_id' => $actor->id, 'action' => 'uploaded', 'note' => $note]);
                $created[] = $report->id;
            }
        });

        return $created;
    }
}
