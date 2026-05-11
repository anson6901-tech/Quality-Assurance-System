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

class CourseController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        return Inertia::render('quality/courses/Index', [
            'courses' => Course::query()
                ->with('department.school:id,name')
                ->join('departments', 'departments.id', '=', 'courses.department_id')
                ->join('schools', 'schools.id', '=', 'departments.school_id')
                ->orderBy('schools.name')
                ->orderBy('departments.name')
                ->orderBy('courses.code')
                ->select('courses.*')
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
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('courses', 'code')->where('department_id', $request->input('department_id')),
            ],
            'title' => ['required', 'string', 'max:255'],
            'level' => ['nullable', 'string', 'max:50'],
            'credit_hours' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        Course::create([
            'department_id' => $validated['department_id'],
            'code' => $validated['code'],
            'title' => $validated['title'],
            'level' => $validated['level'] ?? null,
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

        foreach (['code', 'title', 'credit_hours'] as $requiredColumn) {
            abort_unless($columns->has($requiredColumn), 422);
        }

        $departmentColumn = collect(['department_code', 'department', 'department_name'])
            ->first(fn (string $column) => $columns->has($column));

        abort_unless($departmentColumn !== null, 422);

        $imported = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $code = trim((string) ($row[$columns['code']] ?? ''));
            $title = trim((string) ($row[$columns['title']] ?? ''));
            $creditHours = trim((string) ($row[$columns['credit_hours']] ?? ''));
            $departmentValue = trim((string) ($row[$columns[$departmentColumn]] ?? ''));

            if ($code === '' || $title === '' || $departmentValue === '') {
                continue;
            }

            $department = Department::query()
                ->where('code', $departmentValue)
                ->orWhere('name', $departmentValue)
                ->first();

            if (! $department) {
                continue;
            }

            Course::updateOrCreate(
                ['department_id' => $department->id, 'code' => $code],
                [
                    'title' => $title,
                    'credit_hours' => $creditHours === '' ? null : (int) $creditHours,
                ],
            );

            $imported++;
        }

        fclose($handle);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':count courses imported.', ['count' => $imported])]);

        return to_route('quality.courses.index');
    }

    public function update(Request $request, Course $course): RedirectResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        $validated = $request->validate([
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('courses', 'code')
                    ->where('department_id', $request->input('department_id'))
                    ->ignore($course->id),
            ],
            'title' => ['required', 'string', 'max:255'],
            'credit_hours' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $course->update([
            'department_id' => $validated['department_id'],
            'code' => $validated['code'],
            'title' => $validated['title'],
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
