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
        $course = Course::query()->findOrFail($item->course_id);
        $lecturer = User::query()->findOrFail($item->lecturer_id);
        $created = [];

        DB::transaction(function () use ($batch, $item, $actor, $course, $lecturer, &$created): void {
            foreach ($item->cells ?? [] as $cell) {
                $program = $cell['program_id'] ? Program::query()->with('department')->find($cell['program_id']) : null;
                $department = $program?->department
                    ?? ($course->department_id ? Department::query()->find($course->department_id) : null)
                    ?? ($lecturer->department_id ? Department::query()->find($lecturer->department_id) : null);

                if (! $department || ! Area::departments(Department::query(), $actor)->whereKey($department->id)->exists()) {
                    throw new RuntimeException(__('This course part is outside your area.'));
                }

                $path = 'quality-reports/'.Str::uuid().'.pdf';
                Storage::disk('public')->put($path, Storage::disk('local')->get($item->file_path));

                $draft = QualityReport::query()
                    ->where('semester_id', $batch->semester_id)
                    ->where('lecturer_id', $lecturer->id)
                    ->where('uploaded_by', $actor->id)
                    ->where('status', 'draft')
                    ->where('program_id', $program?->id)
                    ->where('section_id', $cell['section_id'])
                    ->whereHas('courses', fn ($query) => $query->whereKey($course->id))
                    ->first();

                if ($draft) {
                    $previous = $draft->file_path;
                    $draft->update(['file_path' => $path, 'category' => $batch->category]);
                    $draft->reviews()->create(['actor_id' => $actor->id, 'action' => 'uploaded', 'note' => __('Bulk upload: PDF replaced')]);

                    if ($previous && $previous !== $path) {
                        DB::afterCommit(fn () => Storage::disk('public')->delete($previous));
                    }

                    $created[] = $draft->id;

                    continue;
                }

                $report = QualityReport::create([
                    'title' => sprintf('%s %s — %s', $course->code, $course->title, $lecturer->name),
                    'category' => $batch->category,
                    'status' => 'draft',
                    'academic_year_id' => $batch->academic_year_id,
                    'semester_id' => $batch->semester_id,
                    'school_id' => $department->school_id,
                    'department_id' => $department->id,
                    'program_id' => $program?->id,
                    'section_id' => $cell['section_id'],
                    'lecturer_id' => $lecturer->id,
                    'file_path' => $path,
                    'uploaded_by' => $actor->id,
                ]);

                $report->courses()->sync([$course->id]);
                $report->reviews()->create(['actor_id' => $actor->id, 'action' => 'uploaded', 'note' => __('Bulk upload')]);
                $created[] = $report->id;
            }
        });

        return $created;
    }
}
