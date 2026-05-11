<?php

namespace App\Http\Controllers\Quality;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AcademicYearController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        return Inertia::render('quality/academic-years/Index', [
            'academicYears' => AcademicYear::query()
                ->withCount('qualityReports')
                ->orderByDesc('starts_on')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('academic_years', 'name')],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'status' => ['required', 'string', Rule::in(['active', 'archived'])],
        ]);

        AcademicYear::create($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Academic year created.')]);

        return to_route('quality.academic-years.index');
    }

    public function update(Request $request, AcademicYear $academicYear): RedirectResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('academic_years', 'name')->ignore($academicYear->id)],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'status' => ['required', 'string', Rule::in(['active', 'archived'])],
        ]);

        $academicYear->update($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Academic year updated.')]);

        return to_route('quality.academic-years.index');
    }
}
