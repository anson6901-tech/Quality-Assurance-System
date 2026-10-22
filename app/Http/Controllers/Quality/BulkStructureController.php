<?php

namespace App\Http\Controllers\Quality;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\Program;
use App\Models\School;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BulkStructureController extends Controller
{
    /** @var array<string, array<int, string>> */
    private const COLUMNS = [
        'schools' => ['name', 'code'],
        'departments' => ['school_code', 'name', 'code'],
        'programs' => ['school_code', 'department_code', 'name', 'code', 'max_level'],
        'academic-years' => ['name', 'starts_on', 'ends_on', 'status'],
    ];

    public function template(Request $request, string $type): StreamedResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);
        abort_unless(isset(self::COLUMNS[$type]), 404);

        return response()->streamDownload(function () use ($type): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, self::COLUMNS[$type]);

            if ($type === 'departments') {
                School::query()->orderBy('code')->pluck('code')->each(
                    fn (string $code) => fputcsv($output, [$code, '', '']),
                );
            }

            if ($type === 'programs') {
                Department::query()
                    ->with('school:id,code')
                    ->orderBy('code')
                    ->get(['id', 'school_id', 'code'])
                    ->each(fn (Department $department) => fputcsv($output, [
                        $department->school?->code,
                        $department->code,
                        '',
                        '',
                        '400',
                    ]));
            }

            fclose($output);
        }, "{$type}-import-template.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function import(Request $request, string $type): RedirectResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);
        abort_unless(isset(self::COLUMNS[$type]), 404);

        $request->validate([
            'structure_file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $handle = fopen($request->file('structure_file')->getRealPath(), 'r');
        abort_unless($handle !== false, 422);
        $header = fgetcsv($handle);
        abort_unless(is_array($header), 422);
        $columns = collect($header)
            ->mapWithKeys(fn ($column, $index) => [strtolower(trim((string) $column)) => $index]);

        foreach (self::COLUMNS[$type] as $requiredColumn) {
            abort_unless($columns->has($requiredColumn), 422, __('Missing CSV column: :column', ['column' => $requiredColumn]));
        }

        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $data = collect(self::COLUMNS[$type])->mapWithKeys(
                fn (string $column) => [$column => trim((string) ($row[$columns[$column]] ?? ''))],
            )->all();

            if (collect($data)->every(fn (string $value) => $value === '')) {
                continue;
            }

            if ($this->importRow($type, $data)) {
                $imported++;
            } else {
                $skipped++;
            }
        }

        fclose($handle);
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':count items imported; :skipped rows skipped.', [
                'count' => $imported,
                'skipped' => $skipped,
            ]),
        ]);

        return to_route("quality.{$type}.index");
    }

    /**
     * @param  array<string, string>  $data
     */
    private function importRow(string $type, array $data): bool
    {
        if ($type === 'schools') {
            $validator = Validator::make($data, [
                'name' => ['required', 'string', 'max:255', Rule::unique('schools', 'name')],
                'code' => ['required', 'string', 'max:20', Rule::unique('schools', 'code')],
            ]);
            if ($validator->fails()) {
                return false;
            }
            School::create($validator->validated());

            return true;
        }

        if ($type === 'departments') {
            $school = School::query()->where('code', $data['school_code'])->first();
            if (! $school) {
                return false;
            }
            $validator = Validator::make($data, [
                'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->where('school_id', $school->id)],
                'code' => ['required', 'string', 'max:20', Rule::unique('departments', 'code')->where('school_id', $school->id)],
            ]);
            if ($validator->fails()) {
                return false;
            }
            Department::create(['school_id' => $school->id, ...$validator->validated()]);

            return true;
        }

        if ($type === 'programs') {
            $school = School::query()->where('code', $data['school_code'])->first();
            $department = $school
                ? Department::query()
                    ->where('school_id', $school->id)
                    ->where('code', $data['department_code'])
                    ->first()
                : null;
            if (! $department) {
                return false;
            }
            $validator = Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'code' => ['required', 'string', 'max:50', Rule::unique('programs', 'code')->where('department_id', $department->id)],
                'max_level' => ['required', 'integer', 'min:100', 'max:5000', 'multiple_of:100'],
            ]);
            if ($validator->fails()) {
                return false;
            }
            Program::create(['department_id' => $department->id, ...$validator->validated()]);

            return true;
        }

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:50', Rule::unique('academic_years', 'name')],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'status' => ['required', Rule::in(['active', 'archived'])],
        ]);
        if ($validator->fails()) {
            return false;
        }
        AcademicYear::create($validator->validated());

        return true;
    }
}
