<?php

namespace App\Support\BulkUpload;

use App\Models\Course;
use App\Models\ReportImportItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Matches an uploaded file name to an expected report, or explains why it cannot.
 */
class ReportFileMatcher
{
    /**
     * @param  Collection<int, array<string, mixed>>  $expected  From {@see ExpectedReports::for()}.
     */
    public function __construct(
        private readonly Collection $expected,
        private readonly int $semesterId,
    ) {}

    /**
     * @return array{status: string, problem: string|null, match: array<string, mixed>|null, candidates: array<int, array<string, mixed>>}
     */
    public function match(string $originalName): array
    {
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pdf') {
            return $this->error(__('Not a PDF file.'));
        }

        $name = ExpectedReports::normalise(self::stem($originalName));
        $exact = $this->expected->filter(fn (array $item) => ExpectedReports::normalise(pathinfo($item['fileName'], PATHINFO_FILENAME)) === $name);

        if ($exact->count() === 1) {
            return $this->found($exact->first());
        }

        $candidates = $this->expected->filter(fn (array $item) => str_starts_with($name, ExpectedReports::normalise($item['courseCode']))
            && str_ends_with($name, ExpectedReports::normalise($item['staffId'])));

        if ($candidates->count() === 1) {
            return $this->found($candidates->first());
        }

        if ($candidates->count() > 1) {
            return [
                'status' => ReportImportItem::NEEDS_CHOICE,
                'problem' => __('This lecturer teaches more than one part of the course. Choose which part this report is for.'),
                'match' => null,
                'candidates' => $candidates->values()->all(),
            ];
        }

        return $this->error($this->explain($originalName));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{status: string, problem: string|null, match: array<string, mixed>, candidates: array<int, array<string, mixed>>}
     */
    private function found(array $item): array
    {
        return $item['hasReport']
            ? [...$this->error(__('A report already exists for this course part and lecturer.')), 'match' => $item]
            : ['status' => ReportImportItem::READY, 'problem' => null, 'match' => $item, 'candidates' => []];
    }

    /**
     * @return array{status: string, problem: string, match: null, candidates: array<int, mixed>}
     */
    private function error(string $problem): array
    {
        return ['status' => ReportImportItem::ERROR, 'problem' => $problem, 'match' => null, 'candidates' => []];
    }

    /**
     * Works out why a COURSE_..._STAFFID name matched nothing.
     */
    private function explain(string $originalName): string
    {
        $parts = preg_split('/[_\s]+/', self::stem($originalName)) ?: [];

        if (count($parts) < 2) {
            return __('Name the file COURSECODE_STAFFID.pdf, as shown in the checklist.');
        }

        $courseToken = ExpectedReports::normalise((string) reset($parts));
        $staffToken = ExpectedReports::normalise((string) end($parts));

        $course = Course::query()->get(['id', 'code'])->first(fn (Course $course) => ExpectedReports::normalise($course->code) === $courseToken);
        if (! $course) {
            return __('No course with the code :code.', ['code' => reset($parts)]);
        }

        $lecturer = User::query()->whereNotNull('staff_id')->get(['id', 'name', 'staff_id'])
            ->first(fn (User $user) => ExpectedReports::normalise($user->staff_id) === $staffToken);
        if (! $lecturer) {
            return __('No staff member with the ID :id.', ['id' => end($parts)]);
        }

        $assigned = DB::table('course_lecturer')
            ->where('course_id', $course->id)
            ->where('lecturer_id', $lecturer->id)
            ->where('semester_id', $this->semesterId)
            ->exists();

        return $assigned
            ? __(':name teaches :code this semester, but not in a program inside your area.', ['name' => $lecturer->name, 'code' => $course->code])
            : __(':name is not assigned to :code this semester. Assign them on Course Assignments first.', ['name' => $lecturer->name, 'code' => $course->code]);
    }

    /**
     * The file name without extension or the copy markers computers add, such as " (1)" or
     * " copy", so a second copy of a file is caught as a duplicate rather than unreadable.
     */
    private static function stem(string $originalName): string
    {
        return trim((string) preg_replace('/(\s*\(\d+\)|[\s_-]+copy(\s*\d+)?)+$/i', '', pathinfo($originalName, PATHINFO_FILENAME)));
    }
}
