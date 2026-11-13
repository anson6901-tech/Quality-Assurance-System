<?php

use App\Enums\UniversityRole;
use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\EmailSetting;
use App\Models\QualityReport;
use App\Models\School;
use App\Models\User;
use App\Notifications\ReportWorkflowNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function publishingSetup(int $reports = 1, string $status = 'under_review'): array
{
    Storage::fake('public');

    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $year = AcademicYear::create(['name' => '2026/2027', 'status' => 'active']);
    $lecturer = User::factory()->create([
        'name' => 'Grace Appiah',
        'email' => 'grace@uhas.edu.gh',
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $officer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $supervisor = User::factory()->create(['university_role' => UniversityRole::QaSupervisor]);
    $director = User::factory()->create(['university_role' => UniversityRole::QaDirector]);

    $created = collect(range(1, $reports))->map(function (int $number) use ($status, $year, $school, $department, $lecturer, $officer, $supervisor) {
        $path = "quality-reports/report-{$number}.pdf";
        Storage::disk('public')->put($path, "%PDF-1.7\nreport {$number}\n%%EOF");

        return QualityReport::create([
            'title' => "Course review {$number}",
            'category' => 'Academic Quality',
            'status' => $status,
            'academic_year_id' => $year->id,
            'semester_id' => $year->semesters()->first()->id,
            'school_id' => $school->id,
            'department_id' => $department->id,
            'lecturer_id' => $lecturer->id,
            'uploaded_by' => $officer->id,
            'assigned_supervisor_id' => $supervisor->id,
            'file_path' => $path,
        ]);
    });

    return compact('school', 'department', 'year', 'lecturer', 'officer', 'supervisor', 'director') + ['reports' => $created];
}

function turnEmailOn(): void
{
    EmailSetting::current()->fill([
        'enabled' => true,
        'microsoft_method' => 'graph',
        'tenant_id' => 'tenant-123',
        'client_id' => 'client-456',
        'client_secret' => 'super-secret',
        'sender_address' => 'qa-insight@uhas.edu.gh',
    ])->save();
}

it('attaches the report PDF to the published email', function () {
    $data = publishingSetup(status: 'approved');
    $report = $data['reports'][0];

    $mail = (new ReportWorkflowNotification($report, 'published', 'QA Director'))->toMail($data['lecturer']);

    expect($mail->attachments)->toHaveCount(1)
        ->and($mail->attachments[0]['file'])->toBe(Storage::disk('public')->path($report->file_path))
        ->and($mail->attachments[0]['options'])->toBe(['as' => 'course-review-1.pdf', 'mime' => 'application/pdf']);

    // Other workflow emails carry no attachment.
    $review = (new ReportWorkflowNotification($report, 'forwarded_to_director', 'QA Supervisor'))->toMail($data['director']);
    expect($review->attachments)->toBe([]);
});

it('lets the director and the assigned supervisor send the published email again', function () {
    $data = publishingSetup(status: 'approved');
    $report = $data['reports'][0];
    turnEmailOn();
    Notification::fake();

    $this->actingAs($data['director'])
        ->post("/quality/reports/{$report->id}/resend-email")
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    $this->actingAs($data['supervisor'])
        ->post("/quality/reports/{$report->id}/resend-email")
        ->assertRedirect();

    Notification::assertSentToTimes($data['lecturer'], ReportWorkflowNotification::class, 2);
    expect($report->reviews()->where('action', 'email_resent')->count())->toBe(2);

    // Others may not.
    $otherSupervisor = User::factory()->create(['university_role' => UniversityRole::QaSupervisor]);
    $this->actingAs($otherSupervisor)->post("/quality/reports/{$report->id}/resend-email")->assertForbidden();
    $this->actingAs($data['lecturer'])->post("/quality/reports/{$report->id}/resend-email")->assertForbidden();
    $this->actingAs($data['officer'])->post("/quality/reports/{$report->id}/resend-email")->assertForbidden();
});

it('explains why a published email could not be sent again', function () {
    $data = publishingSetup(status: 'approved');
    $report = $data['reports'][0];
    Notification::fake();

    $this->actingAs($data['director'])
        ->post("/quality/reports/{$report->id}/resend-email")
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'Email sending is off. Turn it on in Email Settings first.');

    Notification::assertNothingSent();

    // Only published reports can be sent again.
    $report->update(['status' => 'under_review']);
    $this->actingAs($data['director'])
        ->post("/quality/reports/{$report->id}/resend-email")
        ->assertStatus(409);
});

it('lets the director publish and unpublish selected reports together', function () {
    $data = publishingSetup(reports: 3);
    turnEmailOn();
    Notification::fake();
    $ids = $data['reports']->pluck('id')->all();

    $this->actingAs($data['director'])
        ->post('/quality/reports/publish-selected', ['report_ids' => $ids, 'academic_year_id' => $data['year']->id])
        ->assertRedirect("/quality/approvals?academic_year_id={$data['year']->id}");

    expect(QualityReport::query()->whereIn('id', $ids)->pluck('status')->unique()->all())->toBe(['approved']);
    Notification::assertSentToTimes($data['lecturer'], ReportWorkflowNotification::class, 3);

    $this->actingAs($data['director'])
        ->post('/quality/reports/unpublish-selected', ['report_ids' => array_slice($ids, 0, 2)])
        ->assertRedirect();

    expect(QualityReport::query()->whereIn('id', $ids)->orderBy('id')->pluck('status')->all())
        ->toBe(['under_review', 'under_review', 'approved']);

    // A selection must all be in the right status.
    $this->actingAs($data['director'])
        ->post('/quality/reports/publish-selected', ['report_ids' => $ids])
        ->assertStatus(422);

    // Only approvers can do this.
    $this->actingAs($data['supervisor'])
        ->post('/quality/reports/publish-selected', ['report_ids' => array_slice($ids, 0, 2)])
        ->assertForbidden();
});

it('shows every published report on the lecturer portal', function () {
    $data = publishingSetup(reports: 2, status: 'approved');
    $data['reports'][1]->update(['status' => 'under_review']);

    $this->actingAs($data['lecturer'])
        ->get('/quality/my-courses')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/my-courses/Index')
            ->has('publishedReports', 1)
            ->where('publishedReports.0.title', 'Course review 1')
            ->where('publishedReports.0.downloadUrl', "/quality/reports/{$data['reports'][0]->id}/download"));

    $this->actingAs($data['lecturer'])
        ->get("/quality/reports/{$data['reports'][0]->id}/download")
        ->assertOk();
});

it('says in the standard published email that the PDF is attached and on the portal', function () {
    $data = publishingSetup(status: 'approved');

    $mail = (new ReportWorkflowNotification($data['reports'][0], 'published', 'QA Director'))->toMail($data['lecturer']);
    $text = implode(' ', $mail->introLines);

    expect($mail->subject)->toBe('Your QA report has been published: Course review 1')
        ->and($mail->greeting)->toBe('Dear Grace Appiah,')
        ->and($text)->toContain('attached to this email as a PDF')
        ->and($text)->toContain('/quality/my-courses')
        ->and($mail->actionUrl)->toEndWith("/quality/reports/{$data['reports'][0]->id}");
});

it('sends the published email as composed on the Email Settings page', function () {
    $data = publishingSetup(status: 'approved');
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);

    $this->actingAs($admin)->get('/quality/email-settings')
        ->assertInertia(fn (Assert $page) => $page
            ->where('publishedTemplate.attachPdf', true)
            ->where('publishedTemplate.subject', EmailSetting::DEFAULT_PUBLISHED_SUBJECT)
            ->has('publishedTemplate.placeholders', 8));

    $this->actingAs($admin)->put('/quality/email-settings/templates', [
        'published_email_subject' => 'Published: {report_title} ({academic_year})',
        'published_email_body' => "Hello {lecturer_name},\n\nYour report for {courses} is ready on the portal: {portal_link}",
        'published_attach_pdf' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $mail = (new ReportWorkflowNotification($data['reports'][0]->fresh(), 'published', 'QA Director'))->toMail($data['lecturer']);

    expect($mail->subject)->toBe('Published: Course review 1 (2026/2027)')
        ->and($mail->greeting)->toBe('Hello Grace Appiah,')
        ->and($mail->introLines[0])->toStartWith('Your report for your course is ready on the portal: http')
        ->and($mail->attachments)->toBe([]);

    // Saving the standard wording goes back to following it.
    $this->actingAs($admin)->put('/quality/email-settings/templates', [
        'published_email_subject' => EmailSetting::DEFAULT_PUBLISHED_SUBJECT,
        'published_email_body' => EmailSetting::DEFAULT_PUBLISHED_BODY,
        'published_attach_pdf' => true,
    ]);

    expect(EmailSetting::current())->published_email_subject->toBeNull()->published_email_body->toBeNull()->published_attach_pdf->toBeTrue();

    // Only email administrators may change it.
    $this->actingAs($data['director'])->put('/quality/email-settings/templates', [
        'published_email_subject' => 'x', 'published_email_body' => 'y', 'published_attach_pdf' => true,
    ])->assertForbidden();
});
