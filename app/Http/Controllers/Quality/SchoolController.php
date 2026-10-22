<?php

namespace App\Http\Controllers\Quality;

use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SchoolController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        return Inertia::render('quality/schools/Index', [
            'schools' => School::query()
                ->with('dean:id,name,email,school_id')
                ->withCount(['departments', 'users', 'qualityReports'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        $validated = $this->validatedSchoolData($request);

        School::create($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('School created.')]);

        return to_route('quality.schools.index');
    }

    public function update(Request $request, School $school): RedirectResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        $validated = $this->validatedSchoolData($request, $school);

        $school->update($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('School updated.')]);

        return to_route('quality.schools.index');
    }

    public function destroy(Request $request, School $school): RedirectResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        $school->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('School deleted.')]);

        return to_route('quality.schools.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedSchoolData(Request $request, ?School $school = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('schools', 'name')->ignore($school)],
            'code' => ['required', 'string', 'max:20', Rule::unique('schools', 'code')->ignore($school)],
        ]);
    }
}
