<?php

namespace App\Http\Controllers\Quality;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        return Inertia::render('quality/departments/Index', [
            'departments' => Department::query()
                ->with(['school:id,name', 'hod:id,name,email,department_id'])
                ->withCount(['users', 'qualityReports'])
                ->join('schools', 'schools.id', '=', 'departments.school_id')
                ->orderBy('schools.name')
                ->orderBy('departments.name')
                ->select('departments.*')
                ->get(),
            'schools' => School::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        $validated = $this->validatedDepartmentData($request);

        Department::create($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Department created.')]);

        return to_route('quality.departments.index');
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        $validated = $this->validatedDepartmentData($request, $department);

        $department->update($validated);

        // People placed in a department follow it if it moves to another school.
        User::query()
            ->where('department_id', $department->id)
            ->update(['school_id' => $department->school_id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Department updated.')]);

        return to_route('quality.departments.index');
    }

    public function destroy(Request $request, Department $department): RedirectResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        $department->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Department deleted.')]);

        return to_route('quality.departments.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedDepartmentData(Request $request, ?Department $department = null): array
    {
        $validated = $request->validate([
            'school_id' => ['required', 'integer', Rule::exists('schools', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20'],
        ]);

        $request->validate([
            'name' => [
                Rule::unique('departments', 'name')
                    ->where('school_id', $validated['school_id'])
                    ->ignore($department),
            ],
            'code' => [
                Rule::unique('departments', 'code')
                    ->where('school_id', $validated['school_id'])
                    ->ignore($department),
            ],
        ]);

        return $validated;
    }
}
