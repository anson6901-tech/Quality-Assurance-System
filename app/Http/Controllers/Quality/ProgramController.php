<?php

namespace App\Http\Controllers\Quality;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Program;
use App\Models\Section;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProgramController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        return Inertia::render('quality/programs/Index', [
            'programs' => Program::query()
                ->with(['department.school:id,name', 'sections'])
                ->withCount(['courseOfferings as courses_count'])
                ->orderBy('name')
                ->get()
                ->map(fn (Program $program) => [
                    ...$program->toArray(),
                    'sections' => $program->sections->map->toOption()->values(),
                    'section_keys' => $program->sections
                        ->map(fn (Section $section) => $this->sectionKey($section->type, $section->is_weekend))
                        ->values(),
                ]),
            'sectionOptions' => $this->sectionOptions(),
            'departments' => Department::query()
                ->with('school:id,name')
                ->orderBy('name')
                ->get(['id', 'school_id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        $validated = $this->validatedData($request);
        $sectionKeys = $this->validatedSectionKeys($request);

        DB::transaction(function () use ($validated, $sectionKeys): void {
            $this->syncSections(Program::create($validated), $sectionKeys);
        });

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

        $sectionKeys = $this->validatedSectionKeys($request);

        DB::transaction(function () use ($program, $validated, $sectionKeys): void {
            $program->update($validated);
            $this->syncSections($program, $sectionKeys);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Program updated.')]);

        return to_route('quality.programs.index');
    }

    public function updateSections(Request $request, Program $program): RedirectResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        $sectionKeys = $this->validatedSectionKeys($request);

        DB::transaction(fn () => $this->syncSections($program, $sectionKeys));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sections for :program saved.', [
            'program' => $program->code,
        ])]);

        return back();
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
     * @return array<int, array{value: string, label: string}>
     */
    private function sectionOptions(): array
    {
        return collect(Section::TYPES)
            ->flatMap(fn (string $label, string $type) => [
                ['value' => $this->sectionKey($type, false), 'label' => $label],
                ['value' => $this->sectionKey($type, true), 'label' => "{$label} · Weekend"],
            ])
            ->values()
            ->all();
    }

    private function sectionKey(string $type, bool $isWeekend): string
    {
        return $isWeekend ? "{$type}_weekend" : $type;
    }

    /**
     * @return array<int, string>
     */
    private function validatedSectionKeys(Request $request): array
    {
        $validated = $request->validate([
            'sections' => ['sometimes', 'array'],
            'sections.*' => ['string', 'distinct', Rule::in(array_column($this->sectionOptions(), 'value'))],
        ]);

        return array_values($validated['sections'] ?? []);
    }

    /**
     * @param  array<int, string>  $sectionKeys
     */
    private function syncSections(Program $program, array $sectionKeys): void
    {
        $existing = $program->sections()->get();
        $hadSections = $existing->isNotEmpty();
        $wanted = collect($sectionKeys)->map(fn (string $key) => [
            'type' => str_replace('_weekend', '', $key),
            'is_weekend' => str_ends_with($key, '_weekend'),
        ]);

        $removed = $existing->reject(fn (Section $section) => $wanted->contains(
            fn (array $item) => $item['type'] === $section->type && $item['is_weekend'] === $section->is_weekend,
        ));

        $inUse = $removed->filter(fn (Section $section) => DB::table('course_lecturer')->where('section_id', $section->id)->exists()
            || DB::table('quality_reports')->where('section_id', $section->id)->exists());

        if ($inUse->isNotEmpty()) {
            throw ValidationException::withMessages([
                'sections' => __('Remove lecturer assignments and reports for :sections before removing it.', [
                    'sections' => $inUse->map->label()->implode(', '),
                ]),
            ]);
        }

        $removed->each->delete();

        $created = $wanted
            ->reject(fn (array $item) => $existing->contains(
                fn (Section $section) => $section->type === $item['type'] && $section->is_weekend === $item['is_weekend'],
            ))
            ->map(fn (array $item) => $program->sections()->create($item));

        if (! $hadSections && $created->isNotEmpty()) {
            $this->moveProgramWideRecordsIntoSections($program, $created->values());
        }
    }

    /**
     * When a program gets its first sections, program-wide lecturer assignments are copied to
     * every new section and existing reports are attached to the first section.
     *
     * @param  Collection<int, Section>  $sections
     */
    private function moveProgramWideRecordsIntoSections(Program $program, $sections): void
    {
        $rows = DB::table('course_lecturer')
            ->where('program_id', $program->id)
            ->whereNull('section_id')
            ->get();

        foreach ($rows as $row) {
            foreach ($sections as $section) {
                DB::table('course_lecturer')->insertOrIgnore([
                    'course_id' => $row->course_id,
                    'program_id' => $row->program_id,
                    'section_id' => $section->id,
                    'lecturer_id' => $row->lecturer_id,
                    'semester_id' => $row->semester_id,
                    'student_count' => $row->student_count,
                    'allocated_hours' => $row->allocated_hours,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('course_lecturer')->whereIn('id', $rows->pluck('id'))->delete();

        DB::table('quality_reports')
            ->where('program_id', $program->id)
            ->whereNull('section_id')
            ->update(['section_id' => $sections->first()->id]);
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
