<?php

namespace App\Http\Controllers\Quality;

use App\Enums\UniversityRole;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\QualityReport;
use App\Models\ReportImportBatch;
use App\Models\ReportImportItem;
use App\Models\Semester;
use App\Models\User;
use App\Support\BulkUpload\BulkReportCreator;
use App\Support\BulkUpload\ExpectedReports;
use App\Support\BulkUpload\ReportFileMatcher;
use App\Support\ReportNotifications;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Bulk upload of report PDFs: download a checklist of the reports a semester needs, upload
 * a folder of PDFs named from it, review the matches, then create draft reports for the
 * assigned lecturers and submit them to a supervisor.
 *
 * A batch only holds files that are still waiting: once a file becomes a draft report it
 * leaves the batch, and an emptied batch is deleted.
 */
class BulkUploadController extends Controller
{
    private const MAX_KILOBYTES = 10240;

    /** Review note that marks drafts created by bulk upload. */
    public const DRAFT_NOTE = 'Bulk upload';

    public function index(Request $request): Response|RedirectResponse
    {
        abort_unless($request->user()->canUploadQualityReports(), 403);

        $batches = ReportImportBatch::query()
            ->where('user_id', $request->user()->id)
            ->with(['academicYear:id,name', 'semester:id,name'])
            ->withCount([
                'items',
                'items as ready_count' => fn ($query) => $query->where('status', ReportImportItem::READY),
            ])
            ->latest()
            ->limit(20)
            ->get();

        $batch = $request->integer('batch')
            ? $batches->firstWhere('id', $request->integer('batch'))
            : null;

        // A batch disappears once all its files became drafts.
        if ($request->integer('batch') && ! $batch) {
            return to_route('quality.bulk-upload.index');
        }

        $academicYears = AcademicYear::query()->active()->orderByDesc('starts_on')->get(['id', 'name']);

        return Inertia::render('quality/bulk-upload/Index', [
            'academicYears' => $academicYears,
            'semesters' => Semester::query()
                ->whereIn('academic_year_id', $academicYears->pluck('id'))
                ->orderBy('academic_year_id')
                ->orderBy('sequence')
                ->get(['id', 'name', 'academic_year_id']),
            'batches' => $batches->map(fn (ReportImportBatch $item) => [
                'id' => $item->id,
                'academicYear' => $item->academicYear?->name,
                'semester' => $item->semester?->name,
                'category' => $item->category,
                'files' => $item->items_count,
                'ready' => $item->ready_count,
                'createdAt' => $item->created_at->toIso8601String(),
            ]),
            'batch' => $batch ? $this->serializeBatch($batch) : null,
            'waitingDrafts' => $this->waitingDrafts($request->user())
                ->with(['semester:id,name,academic_year_id', 'semester.academicYear:id,name'])
                ->get(['id', 'semester_id'])
                ->groupBy('semester_id')
                ->map(fn (Collection $reports) => [
                    'semester' => trim(($reports->first()->semester?->academicYear?->name ?? '').' · '.($reports->first()->semester?->name ?? ''), ' ·'),
                    'count' => $reports->count(),
                ])
                ->values(),
            'supervisors' => User::query()
                ->where('university_role', UniversityRole::QaSupervisor->value)
                ->where('is_active', true)
                ->whereNotNull('placement')
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'maxFileMegabytes' => self::MAX_KILOBYTES / 1024,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canUploadQualityReports(), 403);

        $validated = $this->validateSemester($request) + $request->validate([
            'category' => ['required', 'string', 'max:100'],
        ]);

        $batch = ReportImportBatch::create([...$validated, 'user_id' => $request->user()->id]);

        return to_route('quality.bulk-upload.index', ['batch' => $batch->id]);
    }

    /**
     * CSV of every report the semester needs, with the file name to give each PDF.
     */
    public function checklist(Request $request): StreamedResponse
    {
        abort_unless($request->user()->canUploadQualityReports(), 403);

        $validated = $this->validateSemester($request);
        $semester = Semester::query()->with('academicYear:id,name')->findOrFail($validated['semester_id']);
        $expected = ExpectedReports::for($request->user(), $semester->id);
        $name = str("report-checklist {$semester->academicYear?->name} {$semester->name}")->slug().'.csv';

        return response()->streamDownload(function () use ($expected): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['file_name', 'course_code', 'course_title', 'part', 'lecturer', 'staff_id', 'report_exists']);

            foreach ($expected as $item) {
                fputcsv($output, [
                    $item['fileName'],
                    $item['courseCode'],
                    $item['courseTitle'],
                    $item['partLabel'],
                    $item['lecturerName'],
                    $item['staffId'],
                    match (true) {
                        ! $item['hasReport'] => 'no',
                        $item['replaceable'] => 'draft',
                        default => 'yes',
                    },
                ]);
            }

            fclose($output);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Receives one PDF at a time, so large folders are not limited by request size and an
     * interrupted upload can carry on. A file with the same name, or for the same course part
     * and lecturer, replaces the one already waiting in the batch.
     */
    public function upload(Request $request, ReportImportBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);

        $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES],
        ]);

        $file = $request->file('file');
        $originalName = basename((string) $file->getClientOriginalName());
        $isPdf = str_starts_with((string) file_get_contents($file->getRealPath(), length: 5), '%PDF-');

        $item = new ReportImportItem([
            'report_import_batch_id' => $batch->id,
            'original_name' => $originalName,
            'size' => $file->getSize(),
        ]);

        if ($isPdf) {
            $item->file_path = $file->store("report-imports/{$batch->id}", 'local');
        }

        $this->applyMatch($item, $batch, $isPdf ? null : __('This file is not a valid PDF.'));
        $replaced = $this->replaceWaiting($batch, $item);
        $item->save();

        return response()->json([
            'item' => $this->serializeItem($item->fresh(['course:id,code,title', 'lecturer:id,name'])),
            'replaced' => $replaced,
        ]);
    }

    /**
     * Picks which course part a file is for when the lecturer teaches several.
     */
    public function choose(Request $request, ReportImportBatch $batch, ReportImportItem $item): RedirectResponse
    {
        $this->authorizeItem($request, $batch, $item);
        abort_unless($item->status === ReportImportItem::NEEDS_CHOICE, 409);

        $validated = $request->validate(['key' => ['required', 'string']]);
        $choice = collect($item->candidates)->firstWhere('key', $validated['key']);
        abort_unless($choice !== null, 422);

        $state = ExpectedReports::reportState($choice, $batch->semester_id, $request->user());
        $this->fill($item, $choice, $state['hasReport'] && ! $state['replaceable']
            ? __('A report for this course part and lecturer is already in review or published, so it cannot be replaced here.')
            : null);
        $this->replaceWaiting($batch, $item);
        $item->save();

        return back();
    }

    public function destroyItem(Request $request, ReportImportBatch $batch, ReportImportItem $item): RedirectResponse
    {
        $this->authorizeItem($request, $batch, $item);

        $this->deleteItem($item);

        return back();
    }

    /**
     * Matches every waiting file again, for example after fixing lecturer assignments.
     */
    public function recheck(Request $request, ReportImportBatch $batch): RedirectResponse
    {
        $this->authorizeBatch($request, $batch);

        // Newest first, so when two files clash the latest upload is the one kept.
        $batch->items()->reorder()->orderByDesc('id')->get()->each(function (ReportImportItem $item) use ($batch): void {
            if (! ReportImportItem::query()->whereKey($item->id)->exists()) {
                return;
            }

            $this->applyMatch($item, $batch, $item->file_path ? null : __('This file is not a valid PDF.'));
            $this->replaceWaiting($batch, $item);
            $item->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Files checked again.')]);

        return back();
    }

    /**
     * Creates draft reports for every file that is ready. Those files then leave the batch,
     * and the batch is deleted once nothing is left in it.
     */
    public function createDrafts(Request $request, ReportImportBatch $batch): RedirectResponse
    {
        $this->authorizeBatch($request, $batch);

        $created = 0;
        $failed = 0;

        foreach ($batch->items()->where('status', ReportImportItem::READY)->get() as $item) {
            try {
                BulkReportCreator::create($batch, $item, $request->user());
                $this->deleteItem($item);
                $created++;
            } catch (Throwable $exception) {
                report($exception);
                $item->update(['status' => ReportImportItem::ERROR, 'problem' => $exception->getMessage()]);
                $failed++;
            }
        }

        $emptied = ! $batch->items()->exists();

        if ($emptied) {
            $batch->delete();
        }

        Inertia::flash('toast', [
            'type' => $failed ? 'error' : 'success',
            'message' => $failed
                ? __(':created files turned into draft reports; :failed could not be created.', ['created' => $created, 'failed' => $failed])
                : trans_choice('{1} One file turned into a draft report.|[2,*] :count files turned into draft reports.', $created, ['count' => $created]),
        ]);

        return $emptied
            ? to_route('quality.bulk-upload.index')
            : back();
    }

    /**
     * Sends the uploader's waiting bulk-upload drafts to a QA Supervisor, like "Submit" on
     * single uploads.
     */
    public function submit(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canUploadQualityReports(), 403);

        $validated = $request->validate([
            'supervisor_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('university_role', UniversityRole::QaSupervisor->value)
                    ->where('is_active', true)),
            ],
        ]);

        $reports = $this->waitingDrafts($request->user())->get();

        DB::transaction(function () use ($reports, $validated, $request): void {
            foreach ($reports as $report) {
                $report->update(['status' => 'submitted', 'assigned_supervisor_id' => $validated['supervisor_id']]);
                $report->reviews()->create(['actor_id' => $request->user()->id, 'action' => 'submitted_to_supervisor', 'note' => null]);
                ReportNotifications::dispatch($report, $request->user(), 'submitted_to_supervisor', null);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(
            '{0} No draft reports were waiting to be submitted.|{1} One report submitted to the QA Supervisor.|[2,*] :count reports submitted to the QA Supervisor.',
            $reports->count(),
            ['count' => $reports->count()],
        )]);

        return back();
    }

    /**
     * The uploader's draft reports that came from bulk upload and are not yet submitted.
     *
     * @return Builder<QualityReport>
     */
    private function waitingDrafts(User $user): Builder
    {
        return QualityReport::query()
            ->where('uploaded_by', $user->id)
            ->where('status', 'draft')
            ->whereHas('reviews', fn ($query) => $query
                ->where('action', 'uploaded')
                ->where('note', 'like', self::DRAFT_NOTE.'%'));
    }

    /**
     * @return array{academic_year_id: int, semester_id: int}
     */
    private function validateSemester(Request $request): array
    {
        $validated = $request->validate([
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('status', 'active')],
            'semester_id' => ['required', 'integer', Rule::exists('semesters', 'id')],
        ]);

        abort_unless(
            Semester::query()->whereKey($validated['semester_id'])->where('academic_year_id', $validated['academic_year_id'])->exists(),
            422,
        );

        return $validated;
    }

    private function applyMatch(ReportImportItem $item, ReportImportBatch $batch, ?string $fileProblem): void
    {
        if ($fileProblem !== null) {
            $item->fill(['status' => ReportImportItem::ERROR, 'problem' => $fileProblem, 'course_id' => null, 'lecturer_id' => null, 'part_key' => null, 'cells' => null, 'candidates' => null]);

            return;
        }

        $result = (new ReportFileMatcher($this->expectedFor($batch), $batch->semester_id))->match($item->original_name);

        if ($result['match'] !== null) {
            $this->fill($item, $result['match'], $result['problem']);

            return;
        }

        $item->fill([
            'status' => $result['status'],
            'problem' => $result['problem'],
            'course_id' => null,
            'lecturer_id' => null,
            'part_key' => null,
            'cells' => null,
            'candidates' => $result['candidates'] ?: null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $match
     */
    private function fill(ReportImportItem $item, array $match, ?string $problem): void
    {
        $item->fill([
            'status' => $problem ? ReportImportItem::ERROR : ReportImportItem::READY,
            'problem' => $problem,
            'course_id' => $match['courseId'],
            'lecturer_id' => $match['lecturerId'],
            'part_key' => $match['partKey'],
            'cells' => $match['cells'],
            'candidates' => null,
        ]);
    }

    /**
     * A corrected file replaces what is waiting: an earlier file with the same name, or one
     * matched to the same course part and lecturer.
     *
     * @return array<int, int> IDs of the files replaced.
     */
    private function replaceWaiting(ReportImportBatch $batch, ReportImportItem $item): array
    {
        $stem = ExpectedReports::normalise(ReportFileMatcher::stem($item->original_name));

        $earlier = $batch->items()
            ->when($item->exists, fn ($query) => $query->whereKeyNot($item->id))
            ->get()
            ->filter(fn (ReportImportItem $other) => ExpectedReports::normalise(ReportFileMatcher::stem($other->original_name)) === $stem
                || ($item->course_id !== null
                    && $other->course_id === $item->course_id
                    && $other->lecturer_id === $item->lecturer_id
                    && $other->part_key === $item->part_key));

        return $earlier->map(function (ReportImportItem $other) {
            $this->deleteItem($other);

            return $other->id;
        })->values()->all();
    }

    private function deleteItem(ReportImportItem $item): void
    {
        if ($item->file_path) {
            Storage::disk('local')->delete($item->file_path);
        }

        $item->delete();
    }

    /** @var array<int, Collection<int, array<string, mixed>>> */
    private array $expectedCache = [];

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function expectedFor(ReportImportBatch $batch): Collection
    {
        return $this->expectedCache[$batch->id] ??= ExpectedReports::for(request()->user(), $batch->semester_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeBatch(ReportImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'academicYearId' => $batch->academic_year_id,
            'semesterId' => $batch->semester_id,
            'academicYear' => $batch->academicYear?->name,
            'semester' => $batch->semester?->name,
            'category' => $batch->category,
            'items' => $batch->items()->with(['course:id,code,title', 'lecturer:id,name'])->get()
                ->map(fn (ReportImportItem $item) => $this->serializeItem($item))
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeItem(ReportImportItem $item): array
    {
        $expected = $item->course_id
            ? $this->expectedFor($item->batch ?? ReportImportBatch::query()->find($item->report_import_batch_id))
                ->firstWhere('key', "{$item->course_id}|{$item->part_key}|{$item->lecturer_id}")
            : null;

        return [
            'id' => $item->id,
            'name' => $item->original_name,
            'size' => $item->size,
            'status' => $item->status,
            'problem' => $item->problem,
            'course' => $item->course ? ['code' => $item->course->code, 'title' => $item->course->title] : null,
            'lecturer' => $item->lecturer?->name,
            'part' => $expected['partLabel'] ?? null,
            'replacesDraft' => $item->status === ReportImportItem::READY && ($expected['replaceable'] ?? false),
            'candidates' => collect($item->candidates ?? [])->map(fn (array $candidate) => [
                'key' => $candidate['key'],
                'label' => "{$candidate['courseCode']} · {$candidate['partLabel']} · {$candidate['lecturerName']}",
            ])->values(),
        ];
    }

    private function authorizeBatch(Request $request, ReportImportBatch $batch): void
    {
        abort_unless($request->user()->canUploadQualityReports() && $batch->user_id === $request->user()->id, 403);
    }

    private function authorizeItem(Request $request, ReportImportBatch $batch, ReportImportItem $item): void
    {
        $this->authorizeBatch($request, $batch);
        abort_unless($item->report_import_batch_id === $batch->id, 404);
    }
}
