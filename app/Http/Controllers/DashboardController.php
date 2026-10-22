<?php

namespace App\Http\Controllers;

use App\Enums\UniversityRole;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\QualityReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $published = QualityReport::query()
            ->visibleTo($user)
            ->where('status', 'approved');

        $pending = match (true) {
            $user->canApproveQualityReports() => QualityReport::query()->where('status', 'under_review')->count(),
            $user->canReviewQualityReports() => QualityReport::query()
                ->where('status', 'submitted')
                ->when(
                    $user->university_role !== UniversityRole::IctAdmin->value,
                    fn ($query) => $query->where('assigned_supervisor_id', $user->id),
                )->count(),
            $user->canUploadQualityReports() => QualityReport::query()
                ->where('uploaded_by', $user->id)
                ->where('status', 'draft')
                ->count(),
            default => 0,
        };

        $academicYearStats = $user->university_role === UniversityRole::IctAdmin->value
            ? AcademicYear::query()
                ->withCount([
                    'qualityReports as total_reports_count',
                    'qualityReports as draft_reports_count' => fn ($query) => $query->where('status', 'draft'),
                    'qualityReports as supervisor_review_count' => fn ($query) => $query->where('status', 'submitted'),
                    'qualityReports as director_review_count' => fn ($query) => $query->where('status', 'under_review'),
                    'qualityReports as published_reports_count' => fn ($query) => $query->where('status', 'approved'),
                ])
                ->orderByDesc('starts_on')
                ->get()
                ->map(fn (AcademicYear $academicYear) => [
                    'id' => $academicYear->id,
                    'name' => $academicYear->name,
                    'status' => $academicYear->status,
                    'totalReports' => $academicYear->total_reports_count,
                    'draftReports' => $academicYear->draft_reports_count,
                    'supervisorReview' => $academicYear->supervisor_review_count,
                    'directorReview' => $academicYear->director_review_count,
                    'publishedReports' => $academicYear->published_reports_count,
                    'publicationRate' => $academicYear->total_reports_count > 0
                        ? (int) round(($academicYear->published_reports_count / $academicYear->total_reports_count) * 100)
                        : 0,
                ])
            : [];

        return Inertia::render('Dashboard', [
            'stats' => [
                'publishedReports' => (clone $published)->count(),
                'pendingReports' => $pending,
                'assignedCourses' => Course::query()
                    ->whereHas('lecturers', fn ($query) => $query->whereKey($user->id))
                    ->count(),
                'departmentCourses' => $user->department_id
                    ? Course::query()
                        ->whereHas('offerings.program', fn ($query) => $query->where('department_id', $user->department_id))
                        ->count()
                    : 0,
            ],
            'academicYearStats' => $academicYearStats,
        ]);
    }
}
