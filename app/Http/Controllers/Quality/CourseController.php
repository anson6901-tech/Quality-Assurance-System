<?php

namespace App\Http\Controllers\Quality;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Department;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CourseController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        return Inertia::render('quality/courses/Index', [
            'courses' => Course::query()
                ->with('department:id,school_id,name', 'department.school:id,name')
                ->orderBy('code')
                ->get(),
            'departments' => Department::query()
                ->with('school:id,name')
                ->orderBy('name')
                ->get(['id', 'school_id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('courses', 'code')],
            'title' => ['required', 'string', 'max:255'],
            'course_type' => ['required', 'string', Rule::in(['normal', 'module'])],
            'level' => ['required', 'string', 'regex:/^[1-9][0-9]*00$/'],
            'credit_hours' => ['nullable', 'integer', 'min:1', 'max:12'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
        ]);

        Course::create([
            'department_id' => $validated['department_id'] ?? null,
            'program_id' => null,
            'code' => $validated['code'],
            'title' => $validated['title'],
            'course_type' => $validated['course_type'],
            'level' => $validated['level'],
            'credit_hours' => $validated['credit_hours'] ?? null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Course created.')]);

        return to_route('quality.courses.index');
    }

    public function import(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        $validated = $request->validate([
            'courses_file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $handle = fopen($request->file('courses_file')->getRealPath(), 'r');
        abort_unless($handle !== false, 422);

        $header = fgetcsv($handle);
        abort_unless(is_array($header), 422);

        $columns = collect($header)
            ->mapWithKeys(fn ($column, $index) => [strtolower(trim((string) $column)) => $index]);

        foreach (['code', 'title', 'level', 'credit_hours'] as $requiredColumn) {
            abort_unless($columns->has($requiredColumn), 422);
        }

        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $code = trim((string) ($row[$columns['code']] ?? ''));
            $title = trim((string) ($row[$columns['title']] ?? ''));
            $creditHours = trim((string) ($row[$columns['credit_hours']] ?? ''));
            $courseType = $columns->has('course_type')
                ? strtolower(trim((string) ($row[$columns['course_type']] ?? 'normal')))
                : 'normal';
            $level = trim((string) ($row[$columns['level']] ?? ''));
            $departmentCode = $columns->has('department_code')
                ? trim((string) ($row[$columns['department_code']] ?? ''))
                : '';
            $department = $departmentCode === ''
                ? null
                : Department::query()->where('code', $departmentCode)->first();

            if ($code === '' || $title === '' || ($departmentCode !== '' && ! $department)) {
                $skipped++;

                continue;
            }

            if (! in_array($courseType, ['normal', 'module'], true)) {
                $skipped++;

                continue;
            }

            if (
                $creditHours !== ''
                && (! ctype_digit($creditHours) || (int) $creditHours < 1 || (int) $creditHours > 12)
            ) {
                $skipped++;

                continue;
            }

            if (! preg_match('/^[1-9][0-9]*00$/', $level)) {
                $skipped++;

                continue;
            }

            Course::updateOrCreate(
                ['code' => $code],
                [
                    'department_id' => $department?->id,
                    'program_id' => null,
                    'title' => $title,
                    'course_type' => $courseType,
                    'level' => $level,
                    'credit_hours' => $creditHours === '' ? null : (int) $creditHours,
                ],
            );

            $imported++;
        }

        fclose($handle);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':count courses imported; :skipped rows skipped.', [
                'count' => $imported,
                'skipped' => $skipped,
            ]),
        ]);

        return to_route('quality.courses.index');
    }

    public function downloadImportTemplate(Request $request): StreamedResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, [
                'code',
                'title',
                'course_type',
                'level',
                'credit_hours',
                'department_code',
            ]);

            fputcsv($output, ['', '', 'normal', '100', '3', '']);

            fclose($output);
        }, 'course-import-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function update(Request $request, Course $course): RedirectResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('courses', 'code')->ignore($course->id)],
            'title' => ['required', 'string', 'max:255'],
            'course_type' => ['required', 'string', Rule::in(['normal', 'module'])],
            'level' => ['required', 'string', 'regex:/^[1-9][0-9]*00$/'],
            'credit_hours' => ['nullable', 'integer', 'min:1', 'max:12'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
        ]);

        $course->update([
            'department_id' => $validated['department_id'] ?? null,
            'program_id' => null,
            'code' => $validated['code'],
            'title' => $validated['title'],
            'course_type' => $validated['course_type'],
            'level' => $validated['level'],
            'credit_hours' => $validated['credit_hours'] ?? null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Course updated.')]);

        return to_route('quality.courses.index');
    }

    public function destroy(Request $request, Course $course): RedirectResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        $course->lecturers()->detach();
        $course->qualityReports()->detach();
        $course->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Course removed.')]);

        return to_route('quality.courses.index');
    }
}
