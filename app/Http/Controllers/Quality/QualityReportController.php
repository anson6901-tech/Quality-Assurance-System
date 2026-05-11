<?php

namespace App\Http\Controllers\Quality;

use App\Enums\UniversityRole;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Department;
use App\Models\QualityReport;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class QualityReportController extends Controller
{
    public function index(Request $request): Response
    {
        $reports = QualityReport::query()
            ->visibleTo($request->user())
            ->with(['academicYear:id,name,status', 'school:id,name', 'department:id,name', 'lecturer:id,name,email', 'courses:id,code,title', 'uploader:id,name'])
            ->latest()
            ->get()
            ->map(fn (QualityReport $report) => $this->serializeReport($report));

        return Inertia::render('quality/reports/Index', [
            'reports' => $reports,
            'canUploadReports' => $request->user()->canUploadQualityReports(),
            'approvalQueue' => false,
        ]);
    }

    public function approvals(Request $request): Response
    {
        $reports = QualityReport::query()
            ->visibleTo($request->user())
            ->where('status', 'under_review')
            ->with(['academicYear:id,name,status', 'school:id,name', 'department:id,name', 'lecturer:id,name,email', 'courses:id,code,title', 'uploader:id,name'])
            ->latest()
            ->get()
            ->map(fn (QualityReport $report) => $this->serializeReport($report));

        return Inertia::render('quality/reports/Index', [
            'reports' => $reports,
            'canUploadReports' => $request->user()->canUploadQualityReports(),
            'approvalQueue' => true,
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->canUploadQualityReports(), 403);

        return Inertia::render('quality/reports/Create', [
            'schools' => School::query()
                ->with('departments:id,school_id,name')
                ->orderBy('name')
                ->get(['id', 'name']),
            'academicYears' => AcademicYear::query()
                ->active()
                ->orderByDesc('starts_on')
                ->orderByDesc('id')
                ->get(['id', 'name', 'status']),
            'lecturers' => User::query()
                ->where('university_role', UniversityRole::Lecturer->value)
                ->with(['school:id,name', 'department:id,name'])
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'school_id', 'department_id', 'university_role'])
                ->map(fn (User $lecturer) => [
                    'id' => $lecturer->id,
                    'name' => $lecturer->name,
                    'email' => $lecturer->email,
                    'school' => $lecturer->school ? ['id' => $lecturer->school->id, 'name' => $lecturer->school->name] : null,
                    'department' => $lecturer->department ? ['id' => $lecturer->department->id, 'name' => $lecturer->department->name] : null,
                ]),
            'courses' => Course::query()
                ->with('department:id,name')
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
                    ] : null,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canUploadQualityReports(), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'status' => ['required', 'string', Rule::in(['draft', 'submitted'])],
            'academic_year_id' => [
                'required',
                'integer',
                Rule::exists('academic_years', 'id')->where('status', 'active'),
            ],
            'summary' => ['nullable', 'string'],
            'school_id' => ['required', 'integer', Rule::exists('schools', 'id')],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'lecturer_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'course_ids' => ['required', 'array', 'min:1'],
            'course_ids.*' => ['integer', Rule::exists('courses', 'id')],
            'report_file' => ['nullable', 'file', 'max:10240'],
        ]);

        $lecturer = User::query()
            ->whereKey($validated['lecturer_id'])
            ->where('university_role', UniversityRole::Lecturer->value)
            ->firstOrFail();

        abort_unless(
            $lecturer->school_id === (int) $validated['school_id']
                && $lecturer->department_id === (int) $validated['department_id'],
            422,
        );

        abort_unless(
            Department::whereKey($validated['department_id'])
                ->where('school_id', $validated['school_id'])
                ->exists(),
            422,
        );

        $courseIds = Course::query()
            ->whereIn('id', $validated['course_ids'])
            ->pluck('id');

        abort_unless($courseIds->count() === count(array_unique($validated['course_ids'])), 422);

        $filePath = $request->file('report_file')?->store('quality-reports', 'public');

        $report = QualityReport::create([
            'title' => $validated['title'],
            'category' => $validated['category'],
            'status' => $validated['status'],
            'academic_year_id' => $validated['academic_year_id'],
            'summary' => $validated['summary'] ?? null,
            'school_id' => $validated['school_id'],
            'department_id' => $validated['department_id'],
            'lecturer_id' => $validated['lecturer_id'],
            'file_path' => $filePath,
            'uploaded_by' => $request->user()->id,
        ]);

        $report->courses()->sync($courseIds);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Quality report uploaded.')]);

        return to_route('quality.reports.index');
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

    public function show(Request $request, QualityReport $report): Response
    {
        abort_unless(
            QualityReport::query()->visibleTo($request->user())->whereKey($report->id)->exists(),
            403,
        );

        $report->load(['academicYear:id,name,status', 'school:id,name', 'department:id,name', 'lecturer:id,name,email', 'courses:id,code,title', 'uploader:id,name,email']);

        return Inertia::render('quality/reports/Show', [
            'report' => $this->serializeReport($report, includeDetails: true),
            'canPushReport' => $this->canPushReport($request, $report),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
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
}
