<?php

namespace App\Http\Controllers\Quality;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\QualityReport;
use App\Models\QualityRolePermission;
use App\Models\Semester;
use App\Support\CourseBoard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MyCourseController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(QualityRolePermission::roleHas($request->user()->university_role, 'view_own_reports'), 403);

        $userId = $request->user()->id;
        $assignments = DB::table('course_lecturer')
            ->where('lecturer_id', $userId)
            ->whereNotNull('semester_id')
            ->get(['course_id', 'semester_id']);
        $semesterIds = $assignments->pluck('semester_id')->unique()->values()->all();

        $courses = Course::query()
            ->with(CourseBoard::relations($semesterIds))
            ->with(['qualityReports' => fn ($query) => $query
                ->where('status', 'approved')
                ->where('lecturer_id', $userId)])
            ->whereIn('id', $assignments->pluck('course_id')->unique())
            ->orderBy('code')
            ->get();

        $semesters = Semester::query()
            ->with('academicYear:id,name')
            ->whereIn('id', $semesterIds)
            ->get()
            ->keyBy('id');

        $entries = CourseBoard::entries(
            $courses,
            $semesterIds,
            CourseBoard::assignmentRows($courses->pluck('id'), $semesterIds),
            fn (Course $course, int $semesterId, $cells) => [
                'reports' => $course->qualityReports
                    ->where('semester_id', $semesterId)
                    ->filter(fn (QualityReport $report) => $cells->contains(
                        fn (array $cell) => $cell['programId'] === $report->program_id
                            && $cell['sectionId'] === $report->section_id,
                    ))
                    ->map(fn (QualityReport $report) => [
                        'id' => $report->id,
                        'title' => $report->title,
                        'fileUrl' => route('quality.reports.file', $report, absolute: false),
                        'downloadUrl' => route('quality.reports.download', $report, absolute: false),
                    ])
                    ->values(),
            ],
        )
            ->filter(fn (array $entry) => $assignments->contains(
                fn ($assignment) => $assignment->course_id === $entry['id'] && $assignment->semester_id === $entry['semesterId'],
            ))
            ->map(function (array $entry) use ($userId, $semesters): array {
                $semester = $semesters->get($entry['semesterId']);
                $entry['academicYear'] = $semester?->academicYear?->name;
                $entry['academicYearId'] = $semester?->academic_year_id;
                $entry['semester'] = $semester?->name;
                $entry['parts'] = collect($entry['parts'])
                    ->filter(fn (array $part) => collect($part['lecturers'])->contains('id', $userId))
                    ->map(function (array $part) use ($userId): array {
                        $mine = collect($part['lecturers'])->firstWhere('id', $userId);
                        $part['studentCount'] = $mine['studentCount'] ?? null;
                        $part['allocatedHours'] = $mine['allocatedHours'] ?? null;

                        return $part;
                    })
                    ->values()
                    ->all();

                return $entry;
            })
            ->filter(fn (array $entry) => $entry['parts'] !== [])
            ->sortBy([['academicYear', 'desc'], ['code', 'asc']])
            ->values();

        return Inertia::render('quality/my-courses/Index', ['courses' => $entries]);
    }
}
