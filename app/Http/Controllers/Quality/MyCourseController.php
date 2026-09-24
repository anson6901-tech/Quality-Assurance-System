<?php

namespace App\Http\Controllers\Quality;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Program;
use App\Models\QualityRolePermission;
use App\Models\Semester;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MyCourseController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(QualityRolePermission::roleHas($request->user()->university_role, 'view_own_reports'), 403);

        $courses = Course::query()
            ->whereHas('lecturers', fn ($query) => $query->whereKey($request->user()->id))
            ->with([
                'department.school:id,name',
                'lecturers' => fn ($query) => $query
                    ->whereKey($request->user()->id)
                    ->withPivot(['program_id', 'semester_id', 'student_count', 'allocated_hours']),
                'qualityReports' => fn ($query) => $query
                    ->where('status', 'approved')
                    ->where('lecturer_id', $request->user()->id)
                    ->with(['program:id,name,code', 'academicYear:id,name', 'semester:id,name'])
                    ->latest(),
            ])
            ->orderBy('code')
            ->get()
            ->flatMap(fn (Course $course) => $course->lecturers->map(function ($lecturer) use ($course): array {
                $programId = $lecturer->pivot->program_id;
                $semesterId = $lecturer->pivot->semester_id;
                $program = $programId ? Program::query()->find($programId) : null;
                $semester = $semesterId ? Semester::query()->with('academicYear:id,name')->find($semesterId) : null;
                $reports = $course->qualityReports
                    ->where('program_id', $programId)
                    ->where('semester_id', $semesterId)
                    ->values();

                return [
                    'key' => "{$course->id}:{$programId}:{$semesterId}",
                    'code' => $course->code,
                    'title' => $course->title,
                    'level' => $course->level,
                    'courseType' => $course->course_type,
                    'school' => $course->department->school->name,
                    'department' => $course->department->name,
                    'program' => $program?->name,
                    'academicYear' => $semester?->academicYear?->name,
                    'academicYearId' => $semester?->academic_year_id,
                    'semester' => $semester?->name,
                    'semesterId' => $semester?->id,
                    'studentCount' => $lecturer->pivot->student_count,
                    'allocatedHours' => $lecturer->pivot->allocated_hours,
                    'reports' => $reports->map(fn ($report) => [
                        'id' => $report->id,
                        'title' => $report->title,
                        'fileUrl' => route('quality.reports.file', $report, absolute: false),
                        'downloadUrl' => route('quality.reports.download', $report, absolute: false),
                    ])->all(),
                ];
            }))
            ->values();

        return Inertia::render('quality/my-courses/Index', ['courses' => $courses]);
    }
}
