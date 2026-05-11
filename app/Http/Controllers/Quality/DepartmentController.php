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
                ->with(['school:id,name', 'hod:id,name,email'])
                ->withCount(['users', 'qualityReports'])
                ->join('schools', 'schools.id', '=', 'departments.school_id')
                ->orderBy('schools.name')
                ->orderBy('departments.name')
                ->select('departments.*')
                ->get(),
            'schools' => School::query()->orderBy('name')->get(['id', 'name']),
            'hods' => User::query()->orderBy('name')->get(['id', 'name', 'email', 'school_id', 'department_id']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        $validated = $request->validate([
            'school_id' => ['required', 'integer', Rule::exists('schools', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20'],
            'hod_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        $request->validate([
            'name' => [
                Rule::unique('departments', 'name')->where('school_id', $validated['school_id']),
            ],
            'code' => [
                Rule::unique('departments', 'code')->where('school_id', $validated['school_id']),
            ],
        ]);

        Department::create([
            ...$validated,
            'hod_id' => $validated['hod_id'] ?? null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Department created.')]);

        return to_route('quality.departments.index');
    }
}
