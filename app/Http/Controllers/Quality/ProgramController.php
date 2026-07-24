<?php

namespace App\Http\Controllers\Quality;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Program;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProgramController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        return Inertia::render('quality/programs/Index', [
            'programs' => Program::query()
                ->with('department.school:id,name')
                ->withCount(['courseOfferings as courses_count'])
                ->orderBy('name')
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

        Program::create($this->validatedData($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Program created.')]);

        return to_route('quality.programs.index');
    }

    public function update(Request $request, Program $program): RedirectResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        $validated = $this->validatedData($request, $program);
        $highestCourseLevel = (int) $program->courseOfferings()
            ->join('courses', 'courses.id', '=', 'course_offerings.course_id')
            ->max('courses.level');

        abort_if(
            $highestCourseLevel > (int) $validated['max_level'],
            422,
            __("This program has a Level :level course. Move that course before lowering the program's highest level.", [
                'level' => $highestCourseLevel,
            ]),
        );

        $program->update($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Program updated.')]);

        return to_route('quality.programs.index');
    }

    public function destroy(Request $request, Program $program): RedirectResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        abort_if($program->courseOfferings()->exists(), 422, __('Remove this program’s mounted courses first.'));
        $program->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Program deleted.')]);

        return to_route('quality.programs.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request, ?Program $program = null): array
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50'],
            'max_level' => ['required', 'integer', 'min:100', 'max:5000', 'multiple_of:100'],
        ]);

        $request->validate([
            'code' => [
                Rule::unique('programs', 'code')
                    ->where('department_id', $validated['department_id'])
                    ->ignore($program),
            ],
        ]);

        return $validated;
    }
}
