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
 */
class BulkUploadController extends Controller
{
    private const MAX_KILOBYTES = 10240;

    public function index(Request $request): Response
    {
        abort_unless($request->user()->canUploadQualityReports(), 403);

        $batches = ReportImportBatch::query()
            ->where('user_id', $request->user()->id)
            ->with(['academicYear:id,name', 'semester:id,name'])
            ->withCount([
                'items',
                'items as ready_count' => fn ($query) => $query->where('status', ReportImportItem::READY),
                'items as created_count' => fn ($query) => $query->where('status', ReportImportItem::CREATED),
            ])
            ->latest()
            ->limit(20)
            ->get();

        $batch = $request->integer('batch')
            ? $batches->firstWhere('id', $request->integer('batch'))
            : null;
        abort_if($request->integer('batch') && ! $batch, 404);

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
                'created' => $item->created_count,
                'createdAt' => $item->created_at->toIso8601String(),
            ]),
            'batch' => $batch ? $this->serializeBatch($batch) : null,
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
                    $item['hasReport'] ? 'yes' : 'no',
                ]);
            }

            fclose($output);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Receives one PDF at a time, so large folders are not limited by request size and an
     * interrupted upload can carry on.
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
        $item->save();

        return response()->json(['item' => $this->serializeItem($item->fresh(['course:id,code,title', 'lecturer:id,name']))]);
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

        $this->fill($item, $choice, ExpectedReports::reportExists($choice, $batch->semester_id)
            ? __('A report already exists for this course part and lecturer.')
            : null);
        $this->markDuplicates($batch, $item);
        $item->save();

        return back();
    }

    public function destroyItem(Request $request, ReportImportBatch $batch, ReportImportItem $item): RedirectResponse
    {
        $this->authorizeItem($request, $batch, $item);
        abort_if($item->status === ReportImportItem::CREATED, 409);

        if ($item->file_path) {
            Storage::disk('local')->delete($item->file_path);
        }
        $item->delete();

        return back();
    }

    /**
     * Matches every file again, for example after fixing lecturer assignments.
     */
    public function recheck(Request $request, ReportImportBatch $batch): RedirectResponse
    {
        $this->authorizeBatch($request, $batch);

        $batch->items()->where('status', '!=', ReportImportItem::CREATED)->get()
            ->each(function (ReportImportItem $item) use ($batch): void {
                $this->applyMatch($item, $batch, $item->file_path ? null : __('This file is not a valid PDF.'));
                $item->save();
            });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Files checked again.')]);

        return back();
    }

    /**
     * Creates draft reports for every file that is ready.
     */
    public function createDrafts(Request $request, ReportImportBatch $batch): RedirectResponse
    {
        $this->authorizeBatch($request, $batch);

        $created = 0;
        $failed = 0;

        foreach ($batch->items()->where('status', ReportImportItem::READY)->get() as $item) {
            try {
                $reportIds = BulkReportCreator::create($batch, $item, $request->user());
                Storage::disk('local')->delete($item->file_path);
                $item->update(['status' => ReportImportItem::CREATED, 'report_ids' => $reportIds, 'file_path' => null, 'problem' => null]);
                $created++;
            } catch (Throwable $exception) {
                report($exception);
                $item->update(['status' => ReportImportItem::ERROR, 'problem' => $exception->getMessage()]);
                $failed++;
            }
        }

        Inertia::flash('toast', [
            'type' => $failed ? 'error' : 'success',
            'message' => __(':created files turned into draft reports; :failed could not be created.', ['created' => $created, 'failed' => $failed]),
        ]);

        return back();
    }

    /**
     * Sends this batch's draft reports to a QA Supervisor, like "Submit" on single uploads.
     */
    public function submit(Request $request, ReportImportBatch $batch): RedirectResponse
    {
        $this->authorizeBatch($request, $batch);

        $validated = $request->validate([
            'supervisor_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('university_role', UniversityRole::QaSupervisor->value)
                    ->where('is_active', true)),
            ],
        ]);

        $reports = QualityReport::query()
            ->whereIn('id', $this->createdReportIds($batch))
            ->where('uploaded_by', $request->user()->id)
            ->where('status', 'draft')
            ->get();

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
            $this->markDuplicates($batch, $item);

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
     * A second file for the same course part and lecturer in the batch is not used.
     */
    private function markDuplicates(ReportImportBatch $batch, ReportImportItem $item): void
    {
        if ($item->status !== ReportImportItem::READY) {
            return;
        }

        $other = $batch->items()
            ->whereKeyNot($item->id ?? 0)
            ->where('course_id', $item->course_id)
            ->where('lecturer_id', $item->lecturer_id)
            ->where('part_key', $item->part_key)
            ->whereIn('status', [ReportImportItem::READY, ReportImportItem::CREATED])
            ->first();

        if ($other) {
            $item->fill(['status' => ReportImportItem::ERROR, 'problem' => __('Same course part and lecturer as :file.', ['file' => $other->original_name])]);
        }
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
     * @return array<int, int>
     */
    private function createdReportIds(ReportImportBatch $batch): array
    {
        return $batch->items()->where('status', ReportImportItem::CREATED)->pluck('report_ids')->flatten()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeBatch(ReportImportBatch $batch): array
    {
        $items = $batch->items()->with(['course:id,code,title', 'lecturer:id,name'])->get();
        $draftCount = QualityReport::query()
            ->whereIn('id', $this->createdReportIds($batch))
            ->where('status', 'draft')
            ->count();

        return [
            'id' => $batch->id,
            'academicYearId' => $batch->academic_year_id,
            'semesterId' => $batch->semester_id,
            'academicYear' => $batch->academicYear?->name,
            'semester' => $batch->semester?->name,
            'category' => $batch->category,
            'items' => $items->map(fn (ReportImportItem $item) => $this->serializeItem($item))->values(),
            'draftReports' => $draftCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeItem(ReportImportItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->original_name,
            'size' => $item->size,
            'status' => $item->status,
            'problem' => $item->problem,
            'course' => $item->course ? ['code' => $item->course->code, 'title' => $item->course->title] : null,
            'lecturer' => $item->lecturer?->name,
            'part' => $this->partLabel($item),
            'candidates' => collect($item->candidates ?? [])->map(fn (array $candidate) => [
                'key' => $candidate['key'],
                'label' => "{$candidate['courseCode']} · {$candidate['partLabel']} · {$candidate['lecturerName']}",
            ])->values(),
            'reportCount' => count($item->report_ids ?? []),
        ];
    }

    private function partLabel(ReportImportItem $item): ?string
    {
        if (! $item->part_key || ! $item->course_id) {
            return null;
        }

        return $this->expectedFor($item->batch ?? ReportImportBatch::query()->find($item->report_import_batch_id))
            ->firstWhere('key', "{$item->course_id}|{$item->part_key}|{$item->lecturer_id}")['partLabel'] ?? null;
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
