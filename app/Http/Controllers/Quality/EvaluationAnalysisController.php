<?php

namespace App\Http\Controllers\Quality;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\DocumentFile;
use App\Models\DocumentFolder;
use App\Models\EvaluationAnalysis;
use App\Models\Program;
use App\Models\School;
use App\Models\Semester;
use App\Models\User;
use App\Support\Area;
use App\Support\BulkUpload\BulkReportCreator;
use App\Support\BulkUpload\ExpectedReports;
use App\Support\Evaluation\DatasetReader;
use App\Support\Evaluation\Frequencies;
use App\Support\Evaluation\SetDataset;
use App\Support\Evaluation\SetReport;
use App\Support\Evaluation\SetSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Teaching Evaluation: upload a Student Evaluation of Teaching data file, analyse it the way
 * SPSS does (Analyze → Descriptive Statistics → Frequencies), then turn the SET report (PDF)
 * into a draft report for the course's lecturer, or keep it waiting for a lecturer.
 */
class EvaluationAnalysisController extends Controller
{
    /** Review note that marks drafts created from an analysis. */
    public const DRAFT_NOTE = 'Teaching evaluation';

    public function index(Request $request): Response
    {
        $this->authorizeModule($request);
        $user = $request->user();

        $analyses = EvaluationAnalysis::query()
            ->where('user_id', $user->id)
            ->with(['academicYear:id,name', 'semester:id,name'])
            ->latest()
            ->get(['id', 'academic_year_id', 'semester_id', 'original_name', 'status', 'course_id', 'course_code', 'course_title', 'lecturer_name', 'dataset', 'created_at', 'drafted_at']);

        // For the "Waiting for Lecturer" folder: lecturers now assigned to each waiting course.
        $assignable = $analyses->where('status', EvaluationAnalysis::WAITING)
            ->pluck('semester_id')
            ->unique()
            ->mapWithKeys(fn (int $semesterId) => [$semesterId => $this->assignments($user, $semesterId)]);

        // Archived years can be uploaded to as well; active ones come first.
        $academicYears = AcademicYear::query()->forUploads()->get(['id', 'name', 'status'])
            ->map(fn (AcademicYear $year) => ['id' => $year->id, 'name' => $year->uploadLabel()]);

        return Inertia::render('quality/teaching-evaluation/Index', [
            'analyses' => $analyses->map(fn (EvaluationAnalysis $analysis) => [
                'id' => $analysis->id,
                'name' => $analysis->original_name,
                'status' => $analysis->status,
                'academicYear' => $analysis->academicYear?->name,
                'semester' => $analysis->semester?->name,
                'course' => trim($analysis->course_code.' '.$analysis->course_title) ?: null,
                'lecturer' => $analysis->lecturer_name,
                'responses' => count($analysis->dataset['rows'] ?? []),
                'createdAt' => $analysis->created_at->toIso8601String(),
                'draftedAt' => $analysis->drafted_at?->toIso8601String(),
                'assignments' => $analysis->status === EvaluationAnalysis::WAITING
                    ? $this->assignmentOptions($assignable[$analysis->semester_id], $analysis->semester_id, $analysis->course_id)
                    : [],
            ])->values(),
            'academicYears' => $academicYears,
            'semesters' => Semester::query()
                ->whereIn('academic_year_id', $academicYears->pluck('id'))
                ->orderBy('academic_year_id')
                ->orderBy('sequence')
                ->get(['id', 'name', 'academic_year_id']),
            'maxFileMegabytes' => config('evaluation.max_upload_kilobytes') / 1024,
            'folder' => $request->query('folder') === 'waiting' ? 'waiting' : 'all',
        ]);
    }

    /**
     * Start Analysis: reads the uploaded data file into an SPSS-style dataset.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeModule($request);

        $validated = $request->validate([
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')],
            'semester_id' => ['required', 'integer', Rule::exists('semesters', 'id')],
            'file' => ['required', 'file', 'max:'.config('evaluation.max_upload_kilobytes')],
        ]);

        abort_unless(
            Semester::query()->whereKey($validated['semester_id'])->where('academic_year_id', $validated['academic_year_id'])->exists(),
            422,
        );

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $originalName = basename((string) $file->getClientOriginalName());

        try {
            $raw = DatasetReader::read($file->getRealPath(), $extension);
            $dataset = SetDataset::build($raw['headers'], $raw['rows']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        // A course code in the file name (e.g. "SOPH132 Dispensing.csv") fills the report header,
        // linked to that course when it exists in the system.
        preg_match('/\b([A-Z]{2,6})\s?(\d{3})\b/i', pathinfo($originalName, PATHINFO_FILENAME), $code);
        $courseCode = $code ? strtoupper($code[1].$code[2]) : null;
        $course = $courseCode
            ? $this->areaCourses($request->user())->get(['id', 'code', 'title'])
                ->first(fn (Course $course) => ExpectedReports::normalise($course->code) === $courseCode)
            : null;

        $analysis = EvaluationAnalysis::create([
            'user_id' => $request->user()->id,
            'academic_year_id' => $validated['academic_year_id'],
            'semester_id' => $validated['semester_id'],
            'original_name' => $originalName,
            'file_path' => $file->store('evaluation-data', 'local'),
            'dataset' => $dataset,
            'status' => EvaluationAnalysis::ANALYSED,
            'course_id' => $course?->id,
            'course_code' => $course?->code ?? $courseCode,
            'course_title' => $course?->title,
        ]);

        return to_route('quality.evaluation.show', $analysis);
    }

    public function show(Request $request, EvaluationAnalysis $analysis): Response
    {
        $this->authorizeAnalysis($request, $analysis);
        $analysis->load(['academicYear:id,name', 'semester:id,name']);
        $user = $request->user();
        $dataset = $this->visibleDataset($analysis, $user);
        $summary = SetSummary::for($analysis->dataset);

        if (! $user->isPlacedAtUniversity()) {
            $summary['harassmentReports'] = null;
        }

        return Inertia::render('quality/teaching-evaluation/Show', [
            'analysis' => [
                'id' => $analysis->id,
                'name' => $analysis->original_name,
                'status' => $analysis->status,
                'academicYear' => $analysis->academicYear?->name,
                'semester' => $analysis->semester?->name,
                'courseId' => $analysis->course_id,
                'courseCode' => $analysis->course_code,
                'courseTitle' => $analysis->course_title,
                'lecturerName' => $analysis->lecturer_name,
                'level' => $analysis->level,
                'schoolLabel' => $analysis->school_label,
                'assignmentKey' => $analysis->assignment_key,
                'draftedAt' => $analysis->drafted_at?->toIso8601String(),
            ],
            'variables' => $dataset['variables'],
            'rows' => $dataset['rows'],
            'warnings' => $analysis->dataset['warnings'] ?? [],
            'frequencies' => $analysis->frequencies
                ? ['options' => $analysis->frequencies, 'output' => Frequencies::run($dataset, $analysis->frequencies)]
                : null,
            'frequencyDefaults' => Frequencies::defaults(),
            'summary' => $summary,
            'thresholds' => config('evaluation.thresholds'),
            'assignments' => $this->assignmentOptions($this->assignments($user, $analysis->semester_id), $analysis->semester_id),
            // Every course in the user's area, for the report header's course search.
            'courses' => $this->areaCourses($user)
                ->withExists(['offerings as offered' => fn ($query) => $query->where('semester_id', $analysis->semester_id)])
                ->orderBy('code')
                ->get(['id', 'code', 'title'])
                ->map(fn (Course $course) => ['id' => $course->id, 'code' => $course->code, 'title' => $course->title, 'offered' => (bool) $course->offered]),
            'schools' => $this->schoolOptions($user),
            'levels' => $this->levelOptions(),
            'canSeeSensitive' => $user->isPlacedAtUniversity(),
            // Folders for "Save to Document Storage", when the user may use it.
            'documentFolders' => $user->canManageDocuments() ? DocumentController::folderTree() : null,
        ]);
    }

    /**
     * Analyze → Descriptive Statistics → Frequencies… → OK.
     */
    public function frequencies(Request $request, EvaluationAnalysis $analysis): RedirectResponse
    {
        $this->authorizeAnalysis($request, $analysis);
        $names = collect($this->visibleDataset($analysis, $request->user())['variables'])->pluck('name')->all();

        $validated = $request->validate([
            'variables' => ['required', 'array', 'min:1'],
            'variables.*' => ['string', 'distinct', Rule::in($names)],
            'displayTables' => ['boolean'],
            'statistics' => ['array'],
            'statistics.*' => ['string', Rule::in(Frequencies::STATISTICS)],
            'percentiles' => ['array', 'max:10'],
            'percentiles.*' => ['numeric', 'gt:0', 'lt:100'],
            'chart' => ['required', Rule::in(['none', 'bar', 'pie', 'histogram'])],
            'chartValues' => ['required', Rule::in(['frequencies', 'percentages'])],
            'order' => ['required', Rule::in(Frequencies::ORDERS)],
            'multiple' => ['required', Rule::in(['compare', 'organize'])],
            'suppressAbove' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ], [
            'variables.required' => __('Move at least one variable into the Variable(s) list.'),
        ]);

        $analysis->update(['frequencies' => [...Frequencies::defaults(), ...$validated]]);

        return back();
    }

    /**
     * The report header: course, lecturer, level and school printed on the SET report.
     */
    public function details(Request $request, EvaluationAnalysis $analysis): RedirectResponse
    {
        $this->authorizeAnalysis($request, $analysis);
        abort_if($analysis->status === EvaluationAnalysis::DRAFTED, 409);
        $user = $request->user();

        $validated = $request->validate([
            'course_id' => ['nullable', 'integer'],
            'assignment_key' => ['nullable', 'string'],
            'lecturer_name' => ['nullable', 'string', 'max:255'],
            'level' => ['nullable', 'string', Rule::in($this->levelOptions())],
            'school_label' => ['nullable', 'string', Rule::in(array_column($this->schoolOptions($user), 'label'))],
        ], [
            'level.in' => __('Choose a level from the list.'),
            'school_label.in' => __('Choose a school from the list.'),
        ]);

        $course = isset($validated['course_id'])
            ? $this->areaCourses($user)->whereKey($validated['course_id'])->first()
            : null;

        if (isset($validated['course_id']) && ! $course) {
            throw ValidationException::withMessages(['course_id' => __('Choose a course from the list.')]);
        }

        // An assigned lecturer comes from Course Assignments; choosing one never assigns anyone.
        $entry = $course && filled($validated['assignment_key'] ?? null)
            ? $this->assignments($user, $analysis->semester_id)
                ->first(fn (array $item) => $item['key'] === $validated['assignment_key'] && $item['courseId'] === $course->id)
            : null;

        if (filled($validated['assignment_key'] ?? null) && ! $entry) {
            throw ValidationException::withMessages(['assignment_key' => __('That lecturer is not assigned to this course. Choose again.')]);
        }

        $analysis->update([
            'course_id' => $course?->id,
            'course_code' => $course?->code,
            'course_title' => $course?->title,
            'assignment_key' => $entry['key'] ?? null,
            'lecturer_id' => $entry['lecturerId'] ?? null,
            'lecturer_name' => $entry['lecturerName'] ?? $validated['lecturer_name'] ?? null,
            // The level comes with the assignment; with no assignment it is the one chosen.
            'level' => ($entry ? $this->level($entry, $analysis->semester_id) : null)
                ?? $validated['level'] ?? null,
            'school_label' => ($entry ? $this->school($entry) : null)
                ?? ($course ? $this->courseSchool($course, $analysis->semester_id) : null)
                ?? $validated['school_label'] ?? null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $course && ! $entry
            ? __('Report details saved. No lecturer is assigned to :course yet, so finishing will put the report in Waiting for Lecturer.', ['course' => $course->code])
            : __('Report details saved.')]);

        return back();
    }

    public function pdf(Request $request, EvaluationAnalysis $analysis): HttpResponse
    {
        $this->authorizeAnalysis($request, $analysis);

        return response(SetReport::pdf($analysis), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.SetReport::fileName($analysis, 'pdf').'"',
        ]);
    }

    public function word(Request $request, EvaluationAnalysis $analysis): HttpResponse
    {
        $this->authorizeAnalysis($request, $analysis);

        return response(SetReport::docx($analysis), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="'.SetReport::fileName($analysis, 'docx').'"',
        ]);
    }

    /**
     * Keeps the generated SET report (PDF and/or Word) in Document Storage.
     */
    public function saveToDocuments(Request $request, EvaluationAnalysis $analysis): RedirectResponse
    {
        $this->authorizeAnalysis($request, $analysis);
        abort_unless($request->user()->canManageDocuments(), 403);

        $validated = $request->validate([
            'folder_id' => ['nullable', 'integer', 'exists:document_folders,id'],
            'formats' => ['required', 'array', 'min:1'],
            'formats.*' => ['string', 'distinct', Rule::in(['pdf', 'word'])],
        ], ['formats.required' => __('Choose PDF, Word or both.')]);

        $folderId = $validated['folder_id'] ?? null;
        $saved = [];

        if (in_array('pdf', $validated['formats'], true)) {
            $saved[] = DocumentFile::store(SetReport::pdf($analysis), SetReport::fileName($analysis, 'pdf'), 'application/pdf', $folderId, $request->user())->name;
        }

        if (in_array('word', $validated['formats'], true)) {
            $saved[] = DocumentFile::store(SetReport::docx($analysis), SetReport::fileName($analysis, 'docx'), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $folderId, $request->user())->name;
        }

        $folder = $folderId ? DocumentFolder::query()->find($folderId) : null;
        $location = collect($folder?->ancestry() ?? [])->pluck('name')->prepend(__('Documents'))->implode(' / ');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved to :location: :files', ['location' => $location, 'files' => implode(', ', $saved)])]);

        return back();
    }

    /**
     * Finish: send the report to the chosen lecturer's drafts, or keep it in the
     * "Waiting for Lecturer" folder when no lecturer (or no course) is chosen.
     */
    public function finish(Request $request, EvaluationAnalysis $analysis): RedirectResponse
    {
        $this->authorizeAnalysis($request, $analysis);
        abort_if($analysis->status === EvaluationAnalysis::DRAFTED, 409);

        $validated = $request->validate([
            'key' => ['nullable', 'string'],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
        ]);

        if (filled($validated['key'] ?? null)) {
            return $this->createDraft($request, $analysis, $validated['key']);
        }

        $course = isset($validated['course_id']) ? Course::query()->find($validated['course_id']) : null;

        $analysis->update([
            'status' => EvaluationAnalysis::WAITING,
            'course_id' => $course?->id,
            'course_code' => $course?->code ?? $analysis->course_code,
            'course_title' => $course?->title ?? $analysis->course_title,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The report is in the Waiting for Lecturer folder.')]);

        return to_route('quality.evaluation.index', ['folder' => 'waiting']);
    }

    /**
     * "Move to draft" from the Waiting for Lecturer folder, once a lecturer is assigned.
     */
    public function moveToDraft(Request $request, EvaluationAnalysis $analysis): RedirectResponse
    {
        $this->authorizeAnalysis($request, $analysis);
        abort_if($analysis->status === EvaluationAnalysis::DRAFTED, 409);

        $validated = $request->validate(['key' => ['required', 'string']]);

        return $this->createDraft($request, $analysis, $validated['key']);
    }

    public function destroy(Request $request, EvaluationAnalysis $analysis): RedirectResponse
    {
        $this->authorizeAnalysis($request, $analysis);

        Storage::disk('local')->delete($analysis->file_path);
        $analysis->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Analysis deleted.')]);

        return to_route('quality.evaluation.index');
    }

    private function createDraft(Request $request, EvaluationAnalysis $analysis, string $key): RedirectResponse
    {
        $user = $request->user();
        $entry = $this->assignments($user, $analysis->semester_id)->firstWhere('key', $key);

        if (! $entry) {
            throw ValidationException::withMessages(['key' => __('That lecturer is no longer assigned to this course. Choose again.')]);
        }

        if ($entry['hasReport'] && ! $entry['replaceable']) {
            throw ValidationException::withMessages(['key' => __('A report for this course part and lecturer is already in review or published.')]);
        }

        DB::transaction(function () use ($analysis, $entry, $user): void {
            $analysis->update([
                'course_id' => $entry['courseId'],
                'lecturer_id' => $entry['lecturerId'],
                'assignment_key' => $entry['key'],
                'course_code' => $entry['courseCode'],
                'course_title' => $entry['courseTitle'],
                'lecturer_name' => $entry['lecturerName'],
                'level' => $this->level($entry, $analysis->semester_id) ?? $analysis->level,
                'school_label' => $this->school($entry) ?? $analysis->school_label,
            ]);

            $reportIds = BulkReportCreator::createDrafts(
                academicYearId: $analysis->academic_year_id,
                semesterId: $analysis->semester_id,
                category: config('evaluation.report_category'),
                courseId: $entry['courseId'],
                lecturerId: $entry['lecturerId'],
                cells: $entry['cells'],
                pdf: SetReport::pdf($analysis),
                actor: $user,
                note: __(self::DRAFT_NOTE),
            );

            $analysis->update([
                'status' => EvaluationAnalysis::DRAFTED,
                'quality_report_ids' => $reportIds,
                'drafted_at' => now(),
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The SET report is now a draft report for :lecturer. Submit it from Reports.', ['lecturer' => $entry['lecturerName']])]);

        return to_route('quality.evaluation.index');
    }

    /**
     * Lecturers assigned to course parts this semester, within the user's area.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function assignments(User $user, int $semesterId): Collection
    {
        return ExpectedReports::for($user, $semesterId);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $assignments
     * @return array<int, array<string, mixed>>
     */
    private function assignmentOptions(Collection $assignments, int $semesterId, ?int $courseId = null): array
    {
        return $assignments
            ->when($courseId, fn (Collection $items) => $items->where('courseId', $courseId))
            ->map(fn (array $item) => [
                'key' => $item['key'],
                'courseId' => $item['courseId'],
                'course' => "{$item['courseCode']} {$item['courseTitle']}",
                'part' => $item['partLabel'],
                'lecturer' => $item['lecturerName'],
                // The level and school of the programme the lecturer is assigned to.
                'level' => $this->level($item, $semesterId),
                'school' => $this->school($item),
                'blocked' => $item['hasReport'] && ! $item['replaceable'],
                'replacesDraft' => $item['hasReport'] && $item['replaceable'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function level(array $entry, int $semesterId): ?string
    {
        $level = CourseOffering::query()
            ->where('course_id', $entry['courseId'])
            ->where('semester_id', $semesterId)
            ->whereIn('program_id', array_filter(array_column($entry['cells'], 'program_id')))
            ->orderBy('level')
            ->value('level');

        return $level === null ? null : (string) $level;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function school(array $entry): ?string
    {
        $programId = collect($entry['cells'])->pluck('program_id')->filter()->first();
        $school = $programId ? Program::query()->with('department.school')->find($programId)?->department?->school : null;

        return $school ? ($school->code ?: $school->name) : null;
    }

    /**
     * Schools for the report header, as printed on the report (the school code, or its name).
     *
     * @return array<int, array{label: string, name: string}>
     */
    private function schoolOptions(User $user): array
    {
        return School::query()
            ->tap(fn ($query) => Area::schools($query, $user))
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn (School $school) => ['label' => $school->code ?: $school->name, 'name' => $school->name])
            ->all();
    }

    /**
     * Levels for the report header: 100 up to 1000 (or a higher programme level), plus any other
     * level already used by a course.
     *
     * @return array<int, string>
     */
    private function levelOptions(): array
    {
        $highest = max(1000, (int) Program::query()->max('max_level'));

        return collect(range(100, $highest, 100))
            ->map(fn (int $level) => (string) $level)
            ->merge(CourseOffering::query()->whereNotNull('level')->distinct()->pluck('level')->map(fn ($level) => (string) $level))
            ->merge(Course::query()->whereNotNull('level')->distinct()->pluck('level')->map(fn ($level) => (string) $level))
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL)
            ->values()
            ->all();
    }

    /**
     * Courses the user can report on: every course in their area.
     *
     * @return Builder<Course>
     */
    private function areaCourses(User $user)
    {
        return Course::query()->tap(fn ($query) => Area::courses($query, $user));
    }

    private function courseSchool(Course $course, int $semesterId): ?string
    {
        $programId = CourseOffering::query()
            ->where('course_id', $course->id)
            ->where('semester_id', $semesterId)
            ->value('program_id');
        $school = $programId
            ? Program::query()->with('department.school')->find($programId)?->department?->school
            : $course->department?->school;

        return $school ? ($school->code ?: $school->name) : null;
    }

    /**
     * The dataset without sensitive variables (harassment, leak details) for users who may not
     * see them: only university-level users can.
     *
     * @return array{variables: array<int, array<string, mixed>>, rows: array<int, array<string, mixed>>}
     */
    private function visibleDataset(EvaluationAnalysis $analysis, User $user): array
    {
        $variables = collect($analysis->dataset['variables'])
            ->reject(fn (array $variable) => $variable['sensitive'] && ! $user->isPlacedAtUniversity())
            ->values();
        $names = $variables->pluck('name')->flip();

        return [
            'variables' => $variables->all(),
            'rows' => array_map(fn (array $row) => array_intersect_key($row, $names->all()), $analysis->dataset['rows']),
        ];
    }

    private function authorizeModule(Request $request): void
    {
        abort_unless($request->user()->canUploadQualityReports(), 403);
    }

    private function authorizeAnalysis(Request $request, EvaluationAnalysis $analysis): void
    {
        $this->authorizeModule($request);
        abort_unless($analysis->user_id === $request->user()->id, 403);
    }
}
