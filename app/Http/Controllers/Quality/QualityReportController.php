<?php

namespace App\Http\Controllers\Quality;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Department;
use App\Models\QualityReport;
use App\Models\QualityRolePermission;
use App\Models\School;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $filters = $this->validateReportFilters($request, includeStatus: true);

        $reports = QualityReport::query()
            ->where('uploaded_by', $request->user()->id)
            ->tap(fn ($query) => $this->applyReportFilters($query, $filters))
            ->with(['academicYear:id,name,status', 'school:id,name', 'department:id,name', 'lecturer:id,name,email', 'courses:id,code,title', 'uploader:id,name'])
            ->latest()
            ->get()
            ->map(fn (QualityReport $report) => $this->serializeReport($report));

        return Inertia::render('quality/reports/Index', [
            'reports' => $reports,
            'canViewReports' => $request->user()->canViewQualityReports(),
            'canUploadReports' => $request->user()->canUploadQualityReports(),
            'approvalQueue' => false,
            'printQueue' => false,
            'canApproveReports' => false,
            'filters' => $filters,
            'filterOptions' => $this->reportFilterOptions(includeStatuses: true),
        ]);
    }

    public function approvals(Request $request): Response
    {
        abort_unless($this->canViewApprovalQueue($request), 403);

        $filters = $this->validateReportFilters($request);

        $reports = QualityReport::query()
            ->whereIn('status', ['submitted', 'under_review', 'approved'])
            ->tap(fn ($query) => $this->applyApprovalScope($query, $request->user()))
            ->tap(fn ($query) => $this->applyReportFilters($query, $filters))
            ->with(['academicYear:id,name,status', 'school:id,name', 'department:id,name', 'lecturer:id,name,email', 'courses:id,code,title', 'uploader:id,name'])
            ->latest()
            ->get()
            ->map(fn (QualityReport $report) => $this->serializeReport($report));

        return Inertia::render('quality/reports/Index', [
            'reports' => $reports,
            'canViewReports' => $request->user()->canViewQualityReports(),
            'canUploadReports' => $request->user()->canUploadQualityReports(),
            'approvalQueue' => true,
            'printQueue' => false,
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
            ->with(['academicYear:id,name,status', 'school:id,name', 'department:id,name', 'lecturer:id,name,email', 'courses:id,code,title', 'uploader:id,name'])
            ->latest()
            ->get()
            ->map(fn (QualityReport $report) => $this->serializeReport($report));

        return Inertia::render('quality/reports/Index', [
            'reports' => $reports,
            'canViewReports' => $request->user()->canViewQualityReports(),
            'canUploadReports' => $request->user()->canUploadQualityReports(),
            'approvalQueue' => false,
            'printQueue' => true,
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
            ->where('academic_year_id', $defaultAcademicYearId)
            ->orderBy('sequence')
            ->get(['id', 'name', 'academic_year_id']);

        $defaultSemesterId = $semesters->first()?->id;

        $courseQuery = Course::query()
            ->with('department:id,name,school_id')
            ->with(['lecturers' => function ($query) use ($defaultSemesterId): void {
                $this->scopeCourseLecturerAssignments($query, $defaultSemesterId);
            }]);

        if ($defaultSemesterId !== null) {
            $courseQuery->whereHas('lecturers', fn ($query) => $this->scopeCourseLecturerAssignments($query, $defaultSemesterId));
        } else {
            $courseQuery->whereHas('lecturers');
        }

        $courses = $courseQuery
            ->orderBy('code')
            ->get(['id', 'department_id', 'code', 'title', 'level', 'credit_hours'])
            ->map(fn (Course $course) => [
                'id' => $course->id,
                'code' => $course->code,
                'title' => $course->title,
                'level' => $course->level,
                'creditHours' => $course->credit_hours,
                'department' => $course->department ? [
                    'id' => $course->department->id,
                    'name' => $course->department->name,
                    'schoolId' => $course->department->school_id,
                ] : null,
                'lecturerCount' => $course->lecturers->count(),
                'lecturers' => $course->lecturers->map(fn (User $lecturer) => [
                    'id' => $lecturer->id,
                    'name' => $lecturer->name,
                    'email' => $lecturer->email,
                ]),
            ]);

        $departments = Department::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('quality/reports/Create', [
            'academicYears' => $academicYears,
            'semesters' => $semesters,
            'departments' => $departments,
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
            'summary' => ['nullable', 'string'],
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

        try {
            $courses = Course::query()
                ->whereIn('id', $validated['course_ids'])
                ->with(['lecturers' => function ($query) use ($semesterId): void {
                    $this->scopeCourseLecturerAssignments($query, $semesterId);
                }])
                ->get(['id', 'department_id']);

            Log::debug('QualityReportController::store loaded courses', [
                'courseIds' => $courses->pluck('id')->all(),
            ]);

            abort_unless($courses->count() === count(array_unique($validated['course_ids'])), 422);

            $departmentIds = $courses->pluck('department_id')->unique();

            if ($departmentIds->count() !== 1) {
                throw ValidationException::withMessages([
                    'course_ids' => __('Select courses from one department for each report.'),
                ]);
            }

            if ($courses->contains(fn (Course $course) => $course->lecturers->isEmpty())) {
                throw ValidationException::withMessages([
                    'course_ids' => __('Select only courses with assigned lecturers.'),
                ]);
            }

            $commonLecturerIds = $courses->reduce(function ($common, Course $course) {
                return $common === null
                    ? $course->lecturers->pluck('id')
                    : $common->intersect($course->lecturers->pluck('id'));
            }, null)->unique();

            if ($commonLecturerIds->count() !== 1) {
                throw ValidationException::withMessages([
                    'course_ids' => __('Selected courses must map to a single lecturer. Upload reports separately for each lecturer if a course has multiple assigned lecturers.'),
                ]);
            }

            $lecturer = User::query()
                ->whereKey($commonLecturerIds->first())
                ->firstOrFail();

            $department = Department::query()
                ->with('school:id')
                ->findOrFail($departmentIds->first());

            $filePath = $request->file('report_file')?->store('quality-reports', 'public');

            $report = QualityReport::create([
                'title' => $validated['title'],
                'category' => $validated['category'],
                'status' => 'submitted',
                'academic_year_id' => $validated['academic_year_id'],
                'semester_id' => $semesterId,
                'summary' => $validated['summary'] ?? null,
                'school_id' => $department->school_id,
                'department_id' => $department->id,
                'lecturer_id' => $lecturer->id,
                'file_path' => $filePath,
                'uploaded_by' => $request->user()->id,
            ]);

            $report->courses()->sync($courses->pluck('id'));

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Quality report uploaded.')]);

            return to_route('quality.reports.index');
        } catch (\Throwable $exception) {
            Log::error('QualityReportController::store failed', [
                'exception' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    public function push(Request $request, QualityReport $report): RedirectResponse
    {
        abort_unless($request->user()->canUploadQualityReports(), 403);
        abort_unless($report->uploaded_by === $request->user()->id, 403);
        abort_unless(in_array($report->status, ['draft', 'submitted'], true), 409);

        $report->update(['status' => 'under_review']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report pushed for approval.')]);

        return to_route('quality.reports.approvals');
    }

    public function approve(Request $request, QualityReport $report): RedirectResponse
    {
        abort_unless($this->canManageApprovalReport($request, $report), 403);
        abort_unless(in_array($report->status, ['submitted', 'under_review'], true), 409);

        $report->update(['status' => 'approved']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report approved and sent to print queue.')]);

        return $this->approvalRedirect($request, 'quality.reports.print-queue');
    }

    public function returnToOfficer(Request $request, QualityReport $report): RedirectResponse
    {
        abort_unless($this->canManageApprovalReport($request, $report), 403);
        abort_unless(in_array($report->status, ['submitted', 'under_review'], true), 409);

        $report->update(['status' => 'submitted']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report pushed back to the QA officer.')]);

        return $this->approvalRedirect($request, 'quality.reports.approvals');
    }

    public function unapprove(Request $request, QualityReport $report): RedirectResponse
    {
        abort_unless($this->canManageApprovalReport($request, $report), 403);
        abort_unless($report->status === 'approved', 409);

        $report->update(['status' => 'under_review']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report unapproved and removed from print queue.')]);

        return $this->approvalRedirect($request, 'quality.reports.approvals');
    }

    public function destroy(Request $request, QualityReport $report): RedirectResponse
    {
        abort_unless($this->canManageApprovalReport($request, $report), 403);
        abort_unless(in_array($report->status, ['submitted', 'under_review'], true), 409);

        if ($report->file_path) {
            Storage::disk('public')->delete($report->file_path);
        }

        $report->courses()->detach();
        $report->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report deleted.')]);

        return $this->approvalRedirect($request, 'quality.reports.approvals');
    }

    public function show(Request $request, QualityReport $report): Response
    {
        abort_unless($this->canViewReport($request, $report), 403);

        $report->load(['academicYear:id,name,status', 'school:id,name', 'department:id,name', 'lecturer:id,name,email', 'courses:id,code,title', 'uploader:id,name,email']);

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
            $query->wherePivot('semester_id', $semesterId)
                ->orWhereNull('semester_id');
        });
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
            'summary' => $includeDetails ? $report->summary : str($report->summary ?? '')->limit(140)->toString(),
            'filePath' => $report->file_path,
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
            'lecturer' => $report->lecturer ? [
                'id' => $report->lecturer->id,
                'name' => $report->lecturer->name,
                'email' => $report->lecturer->email,
            ] : null,
            'courses' => $report->courses->map(fn (Course $course) => [
                'id' => $course->id,
                'code' => $course->code,
                'title' => $course->title,
            ]),
            'uploader' => $report->uploader ? [
                'id' => $report->uploader->id,
                'name' => $report->uploader->name,
                'email' => $includeDetails ? $report->uploader->email : null,
            ] : null,
            'createdAt' => $report->created_at->toFormattedDateString(),
            'isLocked' => ! in_array($report->status, ['draft', 'submitted'], true),
        ];
    }

    private function canPushReport(Request $request, QualityReport $report): bool
    {
        return $request->user()->canUploadQualityReports()
            && $report->uploaded_by === $request->user()->id
            && in_array($report->status, ['draft', 'submitted'], true);
    }

    private function canViewReport(Request $request, QualityReport $report): bool
    {
        if (in_array($report->status, ['draft', 'submitted'], true)) {
            return $report->uploaded_by === $request->user()->id
                || $this->canManageApprovalReport($request, $report);
        }

        if ($report->status === 'under_review') {
            return $this->canViewApprovalReport($request, $report);
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

    private function canViewApprovalQueue(Request $request): bool
    {
        return $request->user()->canViewApprovalQueue();
    }

    private function approvalRedirect(Request $request, string $fallback): RedirectResponse
    {
        return $request->boolean('from_course_assignments')
            ? to_route('quality.course-assignments.index')
            : to_route($fallback);
    }

    private function canViewApprovalReport(Request $request, QualityReport $report): bool
    {
        return $this->canViewApprovalQueue($request)
            && $this->approvalScopedReportExists($request, $report);
    }

    private function canManageApprovalReport(Request $request, QualityReport $report): bool
    {
        return $this->canApproveReports($request)
            && $this->approvalScopedReportExists($request, $report);
    }

    private function canFilterReports(Request $request): bool
    {
        return ! QualityRolePermission::roleHas($request->user()->university_role, 'view_own_reports');
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
