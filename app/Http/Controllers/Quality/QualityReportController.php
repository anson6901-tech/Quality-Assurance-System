<?php

namespace App\Http\Controllers\Quality;

use App\Enums\UniversityRole;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\CourseSplit;
use App\Models\Department;
use App\Models\Program;
use App\Models\QualityReport;
use App\Models\QualityRolePermission;
use App\Models\School;
use App\Models\Section;
use App\Models\Semester;
use App\Models\User;
use App\Support\CourseBoard;
use App\Support\CoursePartitions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class QualityReportController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->canViewQualityReports(), 403);

        if ($request->user()->canReviewQualityReports()) {
            $filters = $this->validateReportFilters($request);

            $reports = QualityReport::query()
                ->whereIn('status', ['submitted', 'under_review', 'approved'])
                ->when(
                    $request->user()->university_role !== UniversityRole::IctAdmin->value,
                    fn ($query) => $query->where('assigned_supervisor_id', $request->user()->id),
                )
                ->tap(fn ($query) => $this->applyApprovalScope($query, $request->user()))
                ->tap(fn ($query) => $this->applyReportFilters($query, $filters))
                ->with(['academicYear:id,name,status', 'semester:id,name,academic_year_id', 'school:id,name', 'department:id,name', 'program:id,name,code', 'section', 'lecturer:id,name,email', 'courses:id,code,title,level', 'uploader:id,name'])
                ->latest()
                ->get()
                ->map(fn (QualityReport $report) => $this->serializeReport($report));

            return Inertia::render('quality/reports/Index', [
                'reports' => $reports,
                'canViewReports' => true,
                'canUploadReports' => false,
                'approvalQueue' => true,
                'printQueue' => false,
                'reviewStage' => 'supervisor',
                'queueRoute' => route('quality.reports.index', absolute: false),
                'canReviewReports' => true,
                'canApproveReports' => false,
                'filters' => $filters,
                'filterOptions' => $this->reportFilterOptions(),
            ]);
        }

        $filters = $this->validateReportFilters($request, includeStatus: true);

        $reports = QualityReport::query()
            ->where('uploaded_by', $request->user()->id)
            ->tap(fn ($query) => $this->applyReportFilters($query, $filters))
            ->with(['academicYear:id,name,status', 'semester:id,name,academic_year_id', 'school:id,name', 'department:id,name', 'program:id,name,code', 'section', 'lecturer:id,name,email', 'courses:id,code,title,level', 'uploader:id,name'])
            ->latest()
            ->get()
            ->map(fn (QualityReport $report) => $this->serializeReport($report));

        return Inertia::render('quality/reports/Index', [
            'reports' => $reports,
            'canViewReports' => $request->user()->canViewQualityReports(),
            'canUploadReports' => $request->user()->canUploadQualityReports(),
            'approvalQueue' => false,
            'printQueue' => false,
            'reviewStage' => 'officer',
            'queueRoute' => route('quality.reports.index', absolute: false),
            'canReviewReports' => false,
            'canApproveReports' => false,
            'filters' => $filters,
            'filterOptions' => $this->reportFilterOptions(includeStatuses: true),
        ]);
    }

    public function approvals(Request $request): Response
    {
        abort_unless($request->user()->canApproveQualityReports(), 403);

        $filters = $this->validateReportFilters($request);

        $reports = QualityReport::query()
            ->whereIn('status', ['under_review', 'approved'])
            ->tap(fn ($query) => $this->applyApprovalScope($query, $request->user()))
            ->tap(fn ($query) => $this->applyReportFilters($query, $filters))
            ->with(['academicYear:id,name,status', 'semester:id,name,academic_year_id', 'school:id,name', 'department:id,name', 'program:id,name,code', 'section', 'lecturer:id,name,email', 'courses:id,code,title,level', 'uploader:id,name'])
            ->latest()
            ->get()
            ->map(fn (QualityReport $report) => $this->serializeReport($report));

        return Inertia::render('quality/reports/Index', [
            'reports' => $reports,
            'canViewReports' => $request->user()->canViewQualityReports(),
            'canUploadReports' => $request->user()->canUploadQualityReports(),
            'approvalQueue' => true,
            'printQueue' => false,
            'reviewStage' => 'director',
            'queueRoute' => route('quality.reports.approvals', absolute: false),
            'canReviewReports' => false,
            'canApproveReports' => $this->canApproveReports($request),
            'filters' => $filters,
            'filterOptions' => $this->reportFilterOptions(),
        ]);
    }

    public function printQueue(Request $request): Response
    {
        abort_unless($request->user()->canViewPrintQueue(), 403);

        $canFilterReports = $this->canFilterReports($request);
        $filters = $canFilterReports ? $this->validateReportFilters($request) : [];

        $reports = QualityReport::query()
            ->visibleTo($request->user())
            ->where('status', 'approved')
            ->tap(fn ($query) => $this->applyReportFilters($query, $filters))
            ->with(['academicYear:id,name,status', 'semester:id,name,academic_year_id', 'school:id,name', 'department:id,name', 'program:id,name,code', 'section', 'lecturer:id,name,email', 'courses:id,code,title,level', 'uploader:id,name'])
            ->latest()
            ->get()
            ->map(fn (QualityReport $report) => $this->serializeReport($report));

        return Inertia::render('quality/reports/Index', [
            'reports' => $reports,
            'canViewReports' => $request->user()->canViewQualityReports(),
            'canUploadReports' => $request->user()->canUploadQualityReports(),
            'approvalQueue' => false,
            'printQueue' => true,
            'reviewStage' => 'published',
            'queueRoute' => route('quality.reports.print-queue', absolute: false),
            'canReviewReports' => false,
            'canApproveReports' => false,
            'filters' => $filters,
            'filterOptions' => $canFilterReports ? $this->reportFilterOptions() : null,
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->canUploadQualityReports(), 403);

        $academicYears = AcademicYear::query()
            ->active()
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get(['id', 'name', 'status']);

        $defaultAcademicYearId = $academicYears->first()?->id;

        $semesters = Semester::query()
            ->whereIn('academic_year_id', $academicYears->pluck('id'))
            ->orderBy('academic_year_id')
            ->orderBy('sequence')
            ->get(['id', 'name', 'academic_year_id']);

        $defaultSemesterId = $semesters
            ->firstWhere('academic_year_id', $defaultAcademicYearId)?->id;
        $semesterIds = $semesters->pluck('id');

        $courses = Course::query()
            ->with(CourseBoard::relations($semesterIds->all()))
            ->with(['qualityReports' => fn ($query) => $query
                ->where('uploaded_by', $request->user()->id)
                ->whereIn('semester_id', $semesterIds)
                ->with('reviews:id,quality_report_id,action,created_at')
                ->latest()])
            ->whereHas('offerings', fn ($query) => $query->whereIn('semester_id', $semesterIds))
            ->orderBy('code')
            ->get();

        $assignmentRows = CourseBoard::assignmentRows($courses->pluck('id'), $semesterIds->all());

        $courses = CourseBoard::entries(
            $courses,
            $semesterIds->all(),
            $assignmentRows,
            fn (Course $course, int $semesterId, $cells) => [
                'reports' => $course->qualityReports
                    ->where('semester_id', $semesterId)
                    ->filter(fn (QualityReport $report) => $cells->contains(
                        fn (array $cell) => $cell['programId'] === $report->program_id
                            && $cell['sectionId'] === $report->section_id,
                    ))
                    ->map(fn (QualityReport $report) => [
                        'id' => $report->id,
                        'lecturerId' => $report->lecturer_id,
                        'programId' => $report->program_id,
                        'sectionId' => $report->section_id,
                        'title' => $report->title,
                        'status' => $report->status,
                        'uploadedAt' => $report->created_at->toIso8601String(),
                        'pushedAt' => $report->reviews
                            ->firstWhere('action', 'submitted_to_supervisor')
                            ?->created_at
                            ?->toIso8601String(),
                    ])
                    ->values(),
            ],
        )->filter(fn (array $entry) => collect($entry['parts'])->contains(
            fn (array $part) => $part['lecturers'] !== [],
        ))->values();

        $departments = Department::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $supervisors = User::query()
            ->where('university_role', UniversityRole::QaSupervisor->value)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('quality/reports/Create', [
            'academicYears' => $academicYears,
            'semesters' => $semesters,
            'departments' => $departments,
            'supervisors' => $supervisors,
            'courses' => $courses,
            'defaultAcademicYearId' => $defaultAcademicYearId,
            'defaultSemesterId' => $defaultSemesterId,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canUploadQualityReports(), 403);

        Log::debug('QualityReportController::store called', [
            'input' => $request->all(),
            'file' => $request->file('report_file') ? 'present' : 'absent',
        ]);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'academic_year_id' => [
                'required',
                'integer',
                Rule::exists('academic_years', 'id')->where('status', 'active'),
            ],
            'semester_id' => ['nullable', 'integer', Rule::exists('semesters', 'id')],
            'program_id' => [
                Rule::excludeIf(count((array) $request->input('program_ids', [])) > 0),
                'nullable',
                'integer',
                Rule::exists('programs', 'id'),
            ],
            'program_ids' => [
                Rule::excludeIf($request->filled('program_id')),
                'nullable',
                'array',
                'min:1',
            ],
            'program_ids.*' => ['integer', 'distinct', Rule::exists('programs', 'id')],
            'cells' => ['nullable', 'array', 'min:1'],
            'cells.*.program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')],
            'cells.*.section_id' => ['nullable', 'integer', Rule::exists('sections', 'id')],
            'summary' => ['nullable', 'string'],
            'lecturer_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'course_ids' => ['required', 'array', 'min:1'],
            'course_ids.*' => ['integer', Rule::exists('courses', 'id')],
            'report_file' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        Log::debug('QualityReportController::store validated', [
            'validated' => $validated,
        ]);

        $semesterId = $validated['semester_id'] ?? Semester::query()
            ->where('academic_year_id', $validated['academic_year_id'])
            ->orderBy('sequence')
            ->value('id');

        Log::debug('QualityReportController::store semesterId', [
            'semesterId' => $semesterId,
        ]);

        if (array_key_exists('semester_id', $validated) && $validated['semester_id'] !== null) {
            abort_unless($semesterId && Semester::query()->whereKey($semesterId)->where('academic_year_id', $validated['academic_year_id'])->exists(), 422);
        }

        $filePaths = [];

        try {
            $cellPairs = isset($validated['cells'])
                ? collect($validated['cells'])->map(fn (array $cell) => [
                    'program_id' => isset($cell['program_id']) ? (int) $cell['program_id'] : null,
                    'section_id' => isset($cell['section_id']) ? (int) $cell['section_id'] : null,
                    'match_section' => true,
                ])
                : collect(
                    $validated['program_ids']
                        ?? (isset($validated['program_id']) ? [$validated['program_id']] : [null])
                )->map(fn ($programId) => [
                    'program_id' => $programId === null ? null : (int) $programId,
                    'section_id' => null,
                    'match_section' => false,
                ]);
            $cellPairs = $cellPairs->unique(fn (array $cell) => "{$cell['program_id']}:{$cell['section_id']}")->values();
            $contexts = [];

            foreach ($cellPairs as $cellPair) {
                $programId = $cellPair['program_id'];
                $sectionId = $cellPair['section_id'];

                if ($sectionId !== null) {
                    abort_unless(
                        Section::query()->whereKey($sectionId)->where('program_id', $programId)->exists(),
                        422,
                    );
                }

                $courses = Course::query()
                    ->whereIn('id', $validated['course_ids'])
                    ->with(['lecturers' => function ($query) use ($semesterId, $programId, $sectionId, $cellPair): void {
                        $this->scopeCourseLecturerAssignments($query, $semesterId);
                        if ($programId !== null) {
                            $query->where('course_lecturer.program_id', $programId);
                        }
                        if ($cellPair['match_section']) {
                            $sectionId === null
                                ? $query->whereNull('course_lecturer.section_id')
                                : $query->where('course_lecturer.section_id', $sectionId);
                        }
                    }])
                    ->get(['id', 'department_id']);

                abort_unless($courses->count() === count(array_unique($validated['course_ids'])), 422);

                $program = $programId === null
                    ? null
                    : Program::query()
                        ->with('department.school:id')
                        ->findOrFail($programId);
                $department = $program?->department
                    ?? Department::query()->with('school:id')->findOrFail($courses->first()->department_id);

                if ($courses->contains(fn (Course $course) => $course->lecturers->isEmpty())) {
                    throw ValidationException::withMessages([
                        'course_ids' => __('Select only courses with assigned lecturers in every selected program.'),
                    ]);
                }

                $commonLecturerIds = $courses->reduce(function ($common, Course $course) {
                    return $common === null
                        ? $course->lecturers->pluck('id')
                        : $common->intersect($course->lecturers->pluck('id'));
                }, null)->unique();

                $lecturerId = isset($validated['lecturer_id'])
                    ? (int) $validated['lecturer_id']
                    : (int) $commonLecturerIds->sole();

                if (! $commonLecturerIds->contains($lecturerId)) {
                    throw ValidationException::withMessages([
                        'lecturer_id' => __('The selected lecturer must be assigned to every selected program offering.'),
                    ]);
                }

                $filePath = $request->file('report_file')?->store('quality-reports', 'public');
                if ($filePath !== null) {
                    $filePaths[] = $filePath;
                }

                $contexts[] = [
                    'program' => $program,
                    'sectionId' => $sectionId,
                    'department' => $department,
                    'lecturer' => User::query()->findOrFail($lecturerId),
                    'courses' => $courses,
                    'filePath' => $filePath,
                ];
            }

            DB::transaction(function () use ($validated, $semesterId, $contexts, $request): void {
                foreach ($contexts as $context) {
                    $report = QualityReport::create([
                        'title' => $validated['title'],
                        'category' => $validated['category'],
                        'status' => 'draft',
                        'academic_year_id' => $validated['academic_year_id'],
                        'semester_id' => $semesterId,
                        'summary' => $validated['summary'] ?? null,
                        'school_id' => $context['department']->school_id,
                        'department_id' => $context['department']->id,
                        'program_id' => $context['program']?->id,
                        'section_id' => $context['sectionId'],
                        'lecturer_id' => $context['lecturer']->id,
                        'file_path' => $context['filePath'],
                        'uploaded_by' => $request->user()->id,
                    ]);

                    $report->courses()->sync($context['courses']->pluck('id'));
                    $this->recordReview($report, $request->user(), 'uploaded', null);
                }
            });

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => trans_choice(
                    '{1} Report uploaded as a draft.|[2,*] Report uploaded as a draft for :count program sections.',
                    count($contexts),
                    ['count' => count($contexts)],
                ),
            ]);

            return to_route('quality.reports.create');
        } catch (\Throwable $exception) {
            foreach ($filePaths as $filePath) {
                Storage::disk('public')->delete($filePath);
            }

            if (! $exception instanceof ValidationException) {
                Log::error('QualityReportController::store failed', [
                    'exception' => $exception->getMessage(),
                    'trace' => $exception->getTraceAsString(),
                ]);
            }

            throw $exception;
        }
    }

    public function push(Request $request, QualityReport $report): RedirectResponse
    {
        abort_unless($request->user()->canUploadQualityReports(), 403);
        abort_unless($report->uploaded_by === $request->user()->id, 403);
        abort_unless($report->status === 'draft', 409);

        $validated = $this->validateSupervisorAssignment($request);

        DB::transaction(function () use ($request, $report, $validated): void {
            $report->update([
                'status' => 'submitted',
                'assigned_supervisor_id' => $validated['supervisor_id'],
            ]);
            $this->recordReview($report, $request->user(), 'submitted_to_supervisor', null);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report submitted to the QA Supervisor.')]);

        return to_route('quality.reports.index');
    }

    public function pushSelected(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canUploadQualityReports(), 403);

        $validated = $request->validate([
            'report_ids' => ['required', 'array', 'min:1'],
            'report_ids.*' => ['integer', 'distinct', 'exists:quality_reports,id'],
            'supervisor_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query
                        ->where('university_role', UniversityRole::QaSupervisor->value)
                        ->where('is_active', true),
                ),
            ],
        ]);

        $reports = QualityReport::query()
            ->whereIn('id', $validated['report_ids'])
            ->get();

        abort_unless($reports->count() === count($validated['report_ids']), 404);
        abort_unless(
            $reports->every(
                fn (QualityReport $report): bool => $report->uploaded_by === $request->user()->id
                    && $report->status === 'draft'
            ),
            403
        );

        DB::transaction(function () use ($request, $reports, $validated): void {
            foreach ($reports as $report) {
                $report->update([
                    'status' => 'submitted',
                    'assigned_supervisor_id' => $validated['supervisor_id'],
                ]);
                $this->recordReview($report, $request->user(), 'submitted_to_supervisor', null);
            }
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(
                '{1} One report submitted to the QA Supervisor.|[2,*] :count reports submitted to the QA Supervisor.',
                $reports->count(),
                ['count' => $reports->count()]
            ),
        ]);

        return to_route('quality.reports.create');
    }

    public function approve(Request $request, QualityReport $report): RedirectResponse
    {
        abort_unless($this->canManageApprovalReport($request, $report), 403);
        abort_unless(in_array($report->status, ['submitted', 'under_review'], true), 409);
        abort_if(
            $report->status === 'submitted' && ! $this->canReviewAssignedReport($request, $report),
            403,
        );

        DB::transaction(function () use ($request, $report): void {
            $report->update(['status' => 'approved']);
            $this->recordReview($report, $request->user(), 'published', $request->string('note')->toString() ?: null);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report published to the lecturer and school dean.')]);

        return $this->approvalRedirect($request, 'quality.reports.print-queue');
    }

    public function forwardToDirector(Request $request, QualityReport $report): RedirectResponse
    {
        abort_unless($request->user()->canReviewQualityReports(), 403);
        abort_unless($this->canReviewAssignedReport($request, $report), 403);
        abort_unless($this->approvalScopedReportExists($request, $report), 403);
        abort_unless($report->status === 'submitted', 409);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $report, $validated): void {
            $report->update(['status' => 'under_review']);
            $this->recordReview(
                $report,
                $request->user(),
                'forwarded_to_director',
                $validated['note'] ?? null,
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report forwarded to the QA Director.')]);

        return to_route('quality.reports.index');
    }

    public function forwardSelected(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canReviewQualityReports(), 403);

        $validated = $request->validate([
            'report_ids' => ['required', 'array', 'min:1'],
            'report_ids.*' => ['integer', 'distinct', Rule::exists('quality_reports', 'id')],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $reports = QualityReport::query()->whereIn('id', $validated['report_ids'])->get();

        abort_unless($reports->count() === count($validated['report_ids']), 422);
        abort_unless(
            $reports->every(fn (QualityReport $report): bool => $report->status === 'submitted'
                && $this->canReviewAssignedReport($request, $report)
                && $this->approvalScopedReportExists($request, $report)),
            403,
        );

        DB::transaction(function () use ($request, $reports, $validated): void {
            foreach ($reports as $report) {
                $report->update(['status' => 'under_review']);
                $this->recordReview($report, $request->user(), 'forwarded_to_director', $validated['note'] ?? null);
            }
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(
                '{1} One report forwarded to the QA Director.|[2,*] :count reports forwarded to the QA Director.',
                $reports->count(),
                ['count' => $reports->count()],
            ),
        ]);

        return to_route('quality.reports.index');
    }

    public function returnToOfficer(Request $request, QualityReport $report): RedirectResponse
    {
        abort_unless(
            $request->user()->canReviewQualityReports()
                || $this->canManageApprovalReport($request, $report),
            403,
        );
        if (! $request->user()->canApproveQualityReports()) {
            abort_unless($this->canReviewAssignedReport($request, $report), 403);
        }
        abort_unless($this->approvalScopedReportExists($request, $report), 403);
        abort_unless(in_array($report->status, ['submitted', 'under_review'], true), 409);
        abort_if(
            $report->status === 'submitted' && ! $this->canReviewAssignedReport($request, $report),
            403,
        );

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $action = $report->status === 'under_review'
            && $request->user()->canApproveQualityReports()
            ? 'returned_to_supervisor'
            : 'returned_to_officer';
        $status = $action === 'returned_to_officer' ? 'draft' : 'submitted';

        DB::transaction(function () use ($request, $report, $validated, $action, $status): void {
            $report->update(['status' => $status]);
            $this->recordReview(
                $report,
                $request->user(),
                $action,
                $validated['note'] ?? null,
            );
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $action === 'returned_to_officer'
                ? __('Report returned to the QA officer.')
                : __('Report returned to the QA supervisor.'),
        ]);

        return $this->approvalRedirect(
            $request,
            $action === 'returned_to_officer'
                ? 'quality.reports.index'
                : 'quality.reports.approvals',
        );
    }

    public function unapprove(Request $request, QualityReport $report): RedirectResponse
    {
        abort_unless($this->canManageApprovalReport($request, $report), 403);
        abort_unless($report->status === 'approved', 409);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $report, $validated): void {
            $report->update(['status' => 'under_review']);
            $this->recordReview(
                $report,
                $request->user(),
                'unapproved',
                $validated['note'] ?? null,
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report unapproved and removed from print queue.')]);

        return $this->approvalRedirect($request, 'quality.reports.approvals');
    }

    public function destroy(Request $request, QualityReport $report): RedirectResponse
    {
        if ($report->status === 'draft') {
            abort_unless($report->uploaded_by === $request->user()->id, 403);

            DB::transaction(function () use ($request, $report): void {
                $this->recordReview($report, $request->user(), 'deleted', 'Draft deleted by uploader.');

                if ($report->file_path) {
                    Storage::disk('public')->delete($report->file_path);
                }

                $report->delete();
            });

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft report deleted.')]);

            return back();
        }

        abort_unless($this->canManageApprovalReport($request, $report), 403);
        abort_unless(in_array($report->status, ['submitted', 'under_review'], true), 409);

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $report, $validated): void {
            $this->recordReview($report, $request->user(), 'deleted', $validated['note']);

            if ($report->file_path) {
                Storage::disk('public')->delete($report->file_path);
            }

            $report->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report deleted.')]);

        return $this->approvalRedirect($request, 'quality.reports.approvals');
    }

    public function show(Request $request, QualityReport $report): Response
    {
        abort_unless($this->canViewReport($request, $report), 403);

        $report->load(['academicYear:id,name,status', 'semester:id,name,academic_year_id', 'school:id,name', 'department:id,name', 'program:id,name,code', 'section', 'lecturer:id,name,email', 'courses:id,code,title,level', 'assignedSupervisor:id,name,email,university_role', 'uploader:id,name,email,university_role', 'reviews.actor:id,name,email,university_role']);

        return Inertia::render('quality/reports/Show', [
            'report' => $this->serializeReport($report, includeDetails: true),
            'canPushReport' => $this->canPushReport($request, $report),
        ]);
    }

    public function file(Request $request, QualityReport $report): BinaryFileResponse
    {
        abort_unless($this->canViewReport($request, $report), 403);
        abort_if(! $report->file_path || ! Storage::disk('public')->exists($report->file_path), 404);

        return response()->file(Storage::disk('public')->path($report->file_path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="report-'.$report->id.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function download(Request $request, QualityReport $report): BinaryFileResponse
    {
        abort_unless($report->status === 'approved', 403);
        abort_unless($this->canViewReport($request, $report), 403);
        abort_if(! $report->file_path || ! Storage::disk('public')->exists($report->file_path), 404);

        return response()->download(
            Storage::disk('public')->path($report->file_path),
            str($report->title)->slug()->append('.pdf')->toString(),
            ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function scopeCourseLecturerAssignments($query, ?int $semesterId = null): void
    {
        if ($semesterId === null) {
            return;
        }

        $query->where(function ($query) use ($semesterId): void {
            $query->where('course_lecturer.semester_id', $semesterId)
                ->orWhereNull('course_lecturer.semester_id');
        });
    }

    /** @var array<string, array{mode: string, levels: array<int, string|null>}> */
    private array $partContexts = [];

    /**
     * The course part (per its saved split) a report belongs to.
     *
     * @return array{key: string, label: string, mode: string}|null
     */
    private function reportPart(QualityReport $report): ?array
    {
        $course = $report->courses->first();

        if (! $course || ! $report->semester_id) {
            return null;
        }

        $contextKey = "{$course->id}:{$report->semester_id}";
        $this->partContexts[$contextKey] ??= [
            'mode' => CoursePartitions::normaliseMode(CourseSplit::query()
                ->where('course_id', $course->id)
                ->where('semester_id', $report->semester_id)
                ->value('mode')),
            'levels' => CourseOffering::query()
                ->where('course_id', $course->id)
                ->where('semester_id', $report->semester_id)
                ->pluck('level', 'program_id')
                ->all(),
        ];

        $context = $this->partContexts[$contextKey];
        $cell = [
            'programId' => $report->program_id,
            'sectionId' => $report->section_id,
            'level' => $context['levels'][$report->program_id] ?? $course->level,
            'program' => $report->program ? ['code' => $report->program->code] : null,
            'section' => $report->section?->toOption(),
        ];

        return [
            'key' => CoursePartitions::partKey($context['mode'], $cell),
            'label' => CoursePartitions::partLabel($context['mode'], collect([$cell])),
            'mode' => $context['mode'],
        ];
    }

    private function serializeReport(QualityReport $report, bool $includeDetails = false): array
    {
        return [
            'id' => $report->id,
            'title' => $report->title,
            'category' => $report->category,
            'status' => $report->status,
            'academicYear' => $report->academicYear ? [
                'id' => $report->academicYear->id,
                'name' => $report->academicYear->name,
                'status' => $report->academicYear->status,
            ] : null,
            'semester' => $report->semester ? [
                'id' => $report->semester->id,
                'name' => $report->semester->name,
            ] : null,
            'summary' => $includeDetails ? $report->summary : str($report->summary ?? '')->limit(140)->toString(),
            'filePath' => $report->file_path,
            'canView' => $this->canViewReport(request(), $report),
            'fileUrl' => $report->file_path ? route('quality.reports.file', $report) : null,
            'downloadUrl' => $report->file_path ? route('quality.reports.download', $report) : null,
            'location' => match ($report->status) {
                'approved' => 'Published',
                'under_review' => 'Review Queue',
                'submitted' => 'Review Queue',
                default => 'Draft',
            },
            'school' => $report->school ? ['id' => $report->school->id, 'name' => $report->school->name] : null,
            'department' => $report->department ? ['id' => $report->department->id, 'name' => $report->department->name] : null,
            'program' => $report->program ? [
                'id' => $report->program->id,
                'name' => $report->program->name,
                'code' => $report->program->code,
            ] : null,
            'section' => $report->section?->toOption(),
            'part' => $this->reportPart($report),
            'lecturer' => $report->lecturer ? [
                'id' => $report->lecturer->id,
                'name' => $report->lecturer->name,
                'email' => $report->lecturer->email,
            ] : null,
            'courses' => $report->courses->map(fn (Course $course) => [
                'id' => $course->id,
                'code' => $course->code,
                'title' => $course->title,
                'level' => $course->level,
            ]),
            'uploader' => $report->uploader ? [
                'id' => $report->uploader->id,
                'name' => $report->uploader->name,
                'email' => $includeDetails ? $report->uploader->email : null,
            ] : null,
            'reviews' => $includeDetails
                ? $report->reviews->map(fn ($review) => [
                    'id' => $review->id,
                    'action' => $review->action,
                    'note' => $review->note,
                    'actor' => $review->actor ? [
                        'id' => $review->actor->id,
                        'name' => $review->actor->name,
                        'email' => $review->actor->email,
                        'role' => QualityRolePermission::labelFor($review->actor->university_role),
                    ] : null,
                    'recipient' => match ($review->action) {
                        'submitted_to_supervisor', 'returned_to_supervisor' => $report->assignedSupervisor ? [
                            'name' => $report->assignedSupervisor->name,
                            'email' => $report->assignedSupervisor->email,
                            'role' => QualityRolePermission::labelFor($report->assignedSupervisor->university_role),
                        ] : null,
                        'returned_to_officer' => $report->uploader ? [
                            'name' => $report->uploader->name,
                            'email' => $report->uploader->email,
                            'role' => QualityRolePermission::labelFor($report->uploader->university_role),
                        ] : null,
                        default => null,
                    },
                    'createdAt' => $review->created_at->toFormattedDateString(),
                ])
                : [],
            'createdAt' => $report->created_at->toFormattedDateString(),
            'isLocked' => $report->status !== 'draft',
        ];
    }

    private function canPushReport(Request $request, QualityReport $report): bool
    {
        return $request->user()->canUploadQualityReports()
            && $report->uploaded_by === $request->user()->id
            && $report->status === 'draft';
    }

    private function canViewReport(Request $request, QualityReport $report): bool
    {
        if ($report->uploaded_by === $request->user()->id && $report->status !== 'approved') {
            return true;
        }

        if ($report->status === 'draft') {
            return false;
        }

        if ($report->status === 'submitted') {
            return $this->canReviewAssignedReport($request, $report)
                && $this->approvalScopedReportExists($request, $report);
        }

        if ($report->status === 'under_review') {
            return $request->user()->canApproveQualityReports()
                && $this->approvalScopedReportExists($request, $report);
        }

        return QualityReport::query()
            ->visibleTo($request->user())
            ->whereKey($report->id)
            ->exists();
    }

    private function canApproveReports(Request $request): bool
    {
        return $request->user()->canApproveQualityReports();
    }

    private function recordReview(QualityReport $report, User $actor, string $action, ?string $note): void
    {
        $report->reviews()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'note' => $note,
        ]);
    }

    private function canReviewAssignedReport(Request $request, QualityReport $report): bool
    {
        return $request->user()->canReviewQualityReports()
            && ($request->user()->university_role === UniversityRole::IctAdmin->value
                || $report->assigned_supervisor_id === $request->user()->id);
    }

    /** @return array{supervisor_id: int} */
    private function validateSupervisorAssignment(Request $request): array
    {
        return $request->validate([
            'supervisor_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query
                        ->where('university_role', UniversityRole::QaSupervisor->value)
                        ->where('is_active', true),
                ),
            ],
        ]);
    }

    private function approvalRedirect(Request $request, string $fallback): RedirectResponse
    {
        return $request->boolean('from_course_assignments')
            ? to_route('quality.course-assignments.index')
            : to_route($fallback);
    }

    private function canManageApprovalReport(Request $request, QualityReport $report): bool
    {
        return $this->canApproveReports($request)
            && $this->approvalScopedReportExists($request, $report);
    }

    private function canFilterReports(Request $request): bool
    {
        $role = $request->user()->university_role;

        if (in_array($role, [UniversityRole::Hod->value, UniversityRole::Lecturer->value], true)) {
            return false;
        }

        return QualityRolePermission::roleHas($role, 'view_all_reports')
            || QualityRolePermission::roleHas($role, 'view_department_reports')
            || QualityRolePermission::roleHas($role, 'view_school_reports');
    }

    private function approvalScopedReportExists(Request $request, QualityReport $report): bool
    {
        return QualityReport::query()
            ->whereKey($report->id)
            ->tap(fn ($query) => $this->applyApprovalScope($query, $request->user()))
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateReportFilters(Request $request, bool $includeStatus = false): array
    {
        $rules = [
            'lecturer_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')],
            'semester_id' => ['nullable', 'integer', Rule::exists('semesters', 'id')],
            'school_id' => ['nullable', 'integer', Rule::exists('schools', 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')],
            'level' => ['nullable', 'string', 'regex:/^[1-9][0-9]*00$/'],
        ];

        if ($includeStatus) {
            $rules['status'] = ['nullable', 'string', Rule::in(['draft', 'submitted', 'under_review', 'approved'])];
        }

        return $request->validate($rules);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyReportFilters($query, array $filters): void
    {
        $query
            ->when($filters['lecturer_id'] ?? null, fn ($query, $lecturerId) => $query->where('lecturer_id', $lecturerId))
            ->when($filters['academic_year_id'] ?? null, fn ($query, $academicYearId) => $query->where('academic_year_id', $academicYearId))
            ->when($filters['semester_id'] ?? null, fn ($query, $semesterId) => $query->where('semester_id', $semesterId))
            ->when($filters['school_id'] ?? null, fn ($query, $schoolId) => $query->where(function ($query) use ($schoolId): void {
                $query
                    ->whereHas('lecturer', fn ($query) => $query->where('school_id', $schoolId))
                    ->orWhere(function ($query) use ($schoolId): void {
                        $query
                            ->whereNull('lecturer_id')
                            ->where('school_id', $schoolId);
                    });
            }))
            ->when($filters['department_id'] ?? null, fn ($query, $departmentId) => $query->where(function ($query) use ($departmentId): void {
                $query
                    ->whereHas('lecturer', fn ($query) => $query->where('department_id', $departmentId))
                    ->orWhere(function ($query) use ($departmentId): void {
                        $query
                            ->whereNull('lecturer_id')
                            ->where('department_id', $departmentId);
                    });
            }))
            ->when($filters['program_id'] ?? null, fn ($query, $programId) => $query->where('program_id', $programId))
            ->when($filters['level'] ?? null, fn ($query, $level) => $query->whereHas(
                'courses',
                fn ($query) => $query->where('level', $level),
            ))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
    }

    private function applyApprovalScope($query, User $user): void
    {
        if (QualityRolePermission::roleHas($user->university_role, 'view_all_reports')) {
            return;
        }

        if (QualityRolePermission::roleHas($user->university_role, 'view_department_reports')) {
            $query->where(function ($query) use ($user): void {
                $query
                    ->whereHas('lecturer', fn ($query) => $query->where('department_id', $user->department_id))
                    ->orWhere(function ($query) use ($user): void {
                        $query
                            ->whereNull('lecturer_id')
                            ->where('department_id', $user->department_id);
                    });
            });

            return;
        }

        if (QualityRolePermission::roleHas($user->university_role, 'view_school_reports')) {
            $query->where(function ($query) use ($user): void {
                $query
                    ->whereHas('lecturer', fn ($query) => $query->where('school_id', $user->school_id))
                    ->orWhere(function ($query) use ($user): void {
                        $query
                            ->whereNull('lecturer_id')
                            ->where('school_id', $user->school_id);
                    });
            });
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function reportFilterOptions(bool $includeStatuses = false): array
    {
        $options = [
            'lecturers' => User::query()
                ->whereIn('university_role', QualityRolePermission::rolesWith('view_own_reports'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $lecturer) => [
                    'id' => $lecturer->id,
                    'name' => $lecturer->name,
                ]),
            'academicYears' => AcademicYear::query()
                ->orderByDesc('starts_on')
                ->orderByDesc('id')
                ->get(['id', 'name'])
                ->map(fn (AcademicYear $academicYear) => [
                    'id' => $academicYear->id,
                    'name' => $academicYear->name,
                ]),
            'semesters' => Semester::query()
                ->with('academicYear:id,name')
                ->orderBy('academic_year_id')
                ->orderBy('sequence')
                ->get(['id', 'academic_year_id', 'name', 'sequence'])
                ->map(fn (Semester $semester) => [
                    'id' => $semester->id,
                    'academicYearId' => $semester->academic_year_id,
                    'name' => $semester->name,
                ]),
            'schools' => School::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (School $school) => [
                    'id' => $school->id,
                    'name' => $school->name,
                ]),
            'departments' => Department::query()
                ->orderBy('name')
                ->get(['id', 'school_id', 'name'])
                ->map(fn (Department $department) => [
                    'id' => $department->id,
                    'schoolId' => $department->school_id,
                    'name' => $department->name,
                ]),
            'programs' => Program::query()
                ->orderBy('name')
                ->get(['id', 'department_id', 'name', 'code'])
                ->map(fn (Program $program) => [
                    'id' => $program->id,
                    'departmentId' => $program->department_id,
                    'name' => $program->name,
                    'code' => $program->code,
                ]),
            'levels' => Course::query()
                ->whereNotNull('level')
                ->distinct()
                ->orderByRaw('(level + 0)')
                ->pluck('level')
                ->map(fn (string $level) => [
                    'id' => $level,
                    'name' => "Level {$level}",
                ]),
        ];

        if ($includeStatuses) {
            $options['statuses'] = [
                ['id' => 'draft', 'name' => 'Draft'],
                ['id' => 'submitted', 'name' => 'Awaiting review'],
                ['id' => 'under_review', 'name' => 'In review'],
                ['id' => 'approved', 'name' => 'Published'],
            ];
        }

        return $options;
    }
}
