<?php

use App\Enums\UniversityRole;
use App\Mail\MicrosoftSmtpTransport;
use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\EmailSetting;
use App\Models\QualityReport;
use App\Models\School;
use App\Models\User;
use App\Notifications\ReportWorkflowNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\WelcomeNotification;
use App\Providers\AppServiceProvider;
use App\Support\MicrosoftOAuthToken;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

const GRAPH_TOKEN_URL = 'https://login.microsoftonline.com/*';
const GRAPH_SEND_URL = 'https://graph.microsoft.com/v1.0/users/*';

function configureEmail(array $overrides = []): EmailSetting
{
    $settings = EmailSetting::current();
    $settings->fill([
        'enabled' => true,
        'microsoft_method' => 'graph',
        'tenant_id' => 'tenant-123',
        'client_id' => 'client-456',
        'client_secret' => 'super-secret',
        'sender_address' => 'qa-insight@uhas.edu.gh',
        'sender_name' => 'UHAS QA Insight',
        'app_url' => 'https://qa.uhas.edu.gh',
        ...$overrides,
    ])->save();

    return $settings;
}

function fakeGraph(int $sendStatus = 202, array $sendBody = []): void
{
    Http::fake([
        GRAPH_TOKEN_URL => Http::response(['access_token' => 'graph-token', 'expires_in' => 3599]),
        GRAPH_SEND_URL => Http::response($sendBody, $sendStatus),
    ]);
}

function emailAdmin(): User
{
    return User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
}

function reportAwaitingApproval(): array
{
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'email' => 'lecturer@uhas.edu.gh',
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $officer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $director = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $report = QualityReport::create([
        'title' => 'Adult Health course review',
        'category' => 'Academic Quality',
        'status' => 'under_review',
        'academic_year_id' => AcademicYear::create(['name' => '2026/2027', 'status' => 'active'])->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $officer->id,
    ]);

    return compact('school', 'department', 'lecturer', 'officer', 'director', 'report');
}

it('lets only university-placed ict admins open email settings', function () {
    $this->actingAs(emailAdmin())
        ->get('/quality/email-settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/email-settings/Index')
            ->where('settings.enabled', false)
            ->where('settings.hasClientSecret', false));

    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $departmentAdmin = User::factory()->create([
        'university_role' => UniversityRole::IctAdmin,
        'school_id' => $school->id,
        'department_id' => Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS'])->id,
    ]);

    $this->actingAs($departmentAdmin)->get('/quality/email-settings')->assertForbidden();
    $this->actingAs(User::factory()->create(['university_role' => UniversityRole::QaDirector]))
        ->get('/quality/email-settings')
        ->assertForbidden();
});

it('saves settings with the client secret encrypted and never sent back', function () {
    $admin = emailAdmin();
    $payload = [
        'enabled' => true,
        'provider' => 'microsoft365',
        'microsoft_method' => 'graph',
        'tenant_id' => 'tenant-123',
        'client_id' => 'client-456',
        'client_secret' => 'super-secret',
        'sender_address' => 'qa-insight@uhas.edu.gh',
        'sender_name' => 'UHAS QA Insight',
        'app_url' => 'https://qa.uhas.edu.gh/',
        'send_welcome' => true,
        'send_review_requests' => true,
        'send_report_published' => true,
        'send_report_returned' => false,
    ];

    $this->actingAs($admin)->put('/quality/email-settings', $payload)->assertRedirect('/quality/email-settings');

    $settings = EmailSetting::current();
    expect($settings->client_secret)->toBe('super-secret')
        ->and(DB::table('email_settings')->value('client_secret'))->not->toContain('super-secret')
        ->and($settings->app_url)->toBe('https://qa.uhas.edu.gh')
        ->and($settings->send_report_returned)->toBeFalse();

    $this->actingAs($admin)->put('/quality/email-settings', [...$payload, 'client_secret' => ''])->assertRedirect();
    expect(EmailSetting::current()->client_secret)->toBe('super-secret');

    $this->actingAs($admin)
        ->get('/quality/email-settings')
        ->assertInertia(fn (Assert $page) => $page
            ->where('settings.hasClientSecret', true)
            ->missing('settings.clientSecret'))
        ->assertDontSee('super-secret');
});

it('requires the connection details before email can be switched on', function () {
    $this->actingAs(emailAdmin())
        ->put('/quality/email-settings', [
            'enabled' => true,
            'provider' => 'microsoft365',
            'microsoft_method' => 'smtp',
            'smtp_host' => '',
            'smtp_port' => '',
            'smtp_encryption' => 'starttls',
            'smtp_auth' => true,
            'smtp_username' => '',
            'smtp_auth_type' => 'token',
            'token_url' => '',
            'token_scope' => '',
            'client_id' => '',
            'client_secret' => '',
            'send_welcome' => true,
            'send_review_requests' => true,
            'send_report_published' => true,
            'send_report_returned' => true,
        ])
        ->assertInvalid(['smtp_host', 'smtp_port', 'smtp_username', 'token_url', 'token_scope', 'client_id']);

    $this->actingAs(emailAdmin())
        ->put('/quality/email-settings', [
            'enabled' => true,
            'provider' => 'microsoft365',
            'microsoft_method' => 'graph',
            'tenant_id' => '',
            'client_id' => '',
            'client_secret' => '',
            'sender_address' => '',
            'send_welcome' => true,
            'send_review_requests' => true,
            'send_report_published' => true,
            'send_report_returned' => true,
        ])
        ->assertInvalid(['tenant_id', 'client_id', 'sender_address']);

    expect(EmailSetting::current()->enabled)->toBeFalse();
});

it('sends a test email through microsoft graph and records the result', function () {
    configureEmail(['enabled' => false]);
    fakeGraph();

    $this->actingAs(emailAdmin())
        ->post('/quality/email-settings/test', ['recipient' => 'ict@uhas.edu.gh'])
        ->assertRedirect('/quality/email-settings');

    Http::assertSent(fn (HttpRequest $request) => str_starts_with($request->url(), 'https://login.microsoftonline.com/tenant-123/')
        && $request['client_id'] === 'client-456'
        && $request['client_secret'] === 'super-secret'
        && $request['grant_type'] === 'client_credentials');

    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://graph.microsoft.com/v1.0/users/qa-insight%40uhas.edu.gh/sendMail'
        && $request->hasHeader('Authorization', 'Bearer graph-token')
        && $request['message']['subject'] === 'QA Insight test email'
        && $request['message']['toRecipients'][0]['emailAddress']['address'] === 'ict@uhas.edu.gh'
        && $request['saveToSentItems'] === false);

    expect(EmailSetting::current())
        ->last_test_succeeded->toBeTrue()
        ->enabled->toBeFalse();
});

it('shows why microsoft 365 refused a test email', function () {
    configureEmail();
    fakeGraph(403, ['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied. Check credentials and try again.']]);

    $this->actingAs(emailAdmin())
        ->post('/quality/email-settings/test', ['recipient' => 'ict@uhas.edu.gh'])
        ->assertRedirect();

    expect(EmailSetting::current())
        ->last_test_succeeded->toBeFalse()
        ->last_test_message->toContain('ErrorAccessDenied')
        ->last_test_message->toContain('Access is denied');
});

it('emails the lecturer through microsoft 365 when their report is published', function () {
    configureEmail();
    fakeGraph();
    $data = reportAwaitingApproval();

    $this->actingAs($data['director'])
        ->post("/quality/reports/{$data['report']->id}/approve")
        ->assertRedirect();

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'graph.microsoft.com')
        && $request['message']['toRecipients'][0]['emailAddress']['address'] === 'lecturer@uhas.edu.gh'
        && str_contains($request['message']['subject'], 'Adult Health course review')
        && str_contains($request['message']['body']['content'], 'https://qa.uhas.edu.gh/quality/reports/'.$data['report']->id));
});

it('follows the notification switches and skips email while it is off', function () {
    $data = reportAwaitingApproval();
    Notification::fake();

    $this->actingAs($data['director'])->post("/quality/reports/{$data['report']->id}/approve");
    Notification::assertNothingSent();

    configureEmail(['send_report_published' => false]);
    $data['report']->refresh()->update(['status' => 'under_review']);
    $this->actingAs($data['director'])->post("/quality/reports/{$data['report']->id}/approve");
    Notification::assertNothingSent();

    EmailSetting::current()->update(['send_report_published' => true]);
    $data['report']->refresh()->update(['status' => 'under_review']);
    $this->actingAs($data['director'])->post("/quality/reports/{$data['report']->id}/approve");
    Notification::assertSentTo($data['lecturer'], ReportWorkflowNotification::class);
});

it('emails reviewers and returns to the right people', function () {
    configureEmail();
    Notification::fake();
    $data = reportAwaitingApproval();
    $supervisor = User::factory()->create(['university_role' => UniversityRole::QaSupervisor]);
    $otherSchool = School::create(['name' => 'School of Medicine', 'code' => 'SOM']);
    $outsideDirector = User::factory()->create([
        'university_role' => UniversityRole::QaDirector,
        'school_id' => $otherSchool->id,
    ]);
    $data['report']->update(['status' => 'draft']);

    $this->actingAs($data['officer'])
        ->post("/quality/reports/{$data['report']->id}/push", ['supervisor_id' => $supervisor->id])
        ->assertRedirect();
    Notification::assertSentTo($supervisor, ReportWorkflowNotification::class);

    $this->actingAs($supervisor)
        ->post("/quality/reports/{$data['report']->id}/forward", ['note' => 'Looks complete'])
        ->assertRedirect();
    Notification::assertSentTo($data['director'], ReportWorkflowNotification::class);
    Notification::assertNotSentTo($outsideDirector, ReportWorkflowNotification::class);

    $this->actingAs($data['director'])
        ->post("/quality/reports/{$data['report']->id}/return", ['note' => 'Add the attendance sheet'])
        ->assertRedirect();
    Notification::assertSentToTimes($supervisor, ReportWorkflowNotification::class, 2);
});

it('emails new users a generated temporary password they must change', function () {
    configureEmail();
    Notification::fake();

    $this->actingAs(emailAdmin())
        ->post('/quality/users', [
            'name' => 'New Lecturer',
            'email' => 'new.lecturer@uhas.edu.gh',
            'university_role' => UniversityRole::Lecturer->value,
        ])
        ->assertRedirect('/quality/users');

    $user = User::where('email', 'new.lecturer@uhas.edu.gh')->firstOrFail();
    expect($user->must_change_password)->toBeTrue();

    Notification::assertSentTo($user, WelcomeNotification::class, function (WelcomeNotification $notification) use ($user) {
        $mail = $notification->toMail($user);
        $body = collect($mail->introLines)->implode("\n");
        preg_match('/Temporary password:\*\* `([A-Za-z0-9]{12})`/', $body, $match);

        return $notification instanceof ShouldBeEncrypted
            && $mail->subject === 'Your QA Insight account is ready'
            && str_ends_with($mail->actionUrl, '/login')
            && isset($match[1])
            && Hash::check($match[1], $user->password);
    });
});

it('generates and emails passwords for imported users without one', function () {
    configureEmail();
    Notification::fake();
    $csv = UploadedFile::fake()->createWithContent(
        'users.csv',
        "first_name,last_name,email,university_role\nAma,Mensah,ama.mensah@uhas.edu.gh,lecturer\n",
    );

    $this->actingAs(emailAdmin())
        ->post('/quality/users/import', ['users_file' => $csv])
        ->assertRedirect('/quality/users');

    $user = User::where('email', 'ama.mensah@uhas.edu.gh')->firstOrFail();
    expect($user->must_change_password)->toBeTrue();
    Notification::assertSentTo($user, WelcomeNotification::class);
});

it('still requires a password when welcome emails are off', function () {
    $this->actingAs(emailAdmin())
        ->post('/quality/users', [
            'name' => 'New Lecturer',
            'email' => 'new.lecturer@uhas.edu.gh',
            'university_role' => UniversityRole::Lecturer->value,
        ])
        ->assertInvalid('password');
});

it('lets admins email a password reset link and uses the branded reset email', function () {
    configureEmail();
    Notification::fake();
    $admin = emailAdmin();
    $lecturer = User::factory()->create(['university_role' => UniversityRole::Lecturer]);

    $this->actingAs($admin)
        ->post("/quality/users/{$lecturer->id}/reset-link")
        ->assertRedirect('/quality/users');

    Notification::assertSentTo($lecturer, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($lecturer) {
        return $notification->toMail($lecturer)->subject === 'Reset your QA Insight password';
    });

    auth()->logout();
    $this->post('/forgot-password', ['email' => $admin->email]);
    Notification::assertSentTo($admin, ResetPasswordNotification::class);
});

function gmailPayload(array $overrides = []): array
{
    return [
        'enabled' => true,
        'provider' => 'gmail',
        'microsoft_method' => 'smtp',
        'gmail_address' => 'uhas.qa.insight@gmail.com',
        'gmail_app_password' => 'abcd efgh ijkl mnop',
        'sender_name' => 'UHAS QA Insight',
        'app_url' => 'https://qa.uhas.edu.gh',
        'send_welcome' => true,
        'send_review_requests' => true,
        'send_report_published' => true,
        'send_report_returned' => true,
        ...$overrides,
    ];
}

/**
 * Replaces the SMTP transport with one that records its settings and messages, or throws.
 */
function fakeSmtp(?Throwable $failure = null): object
{
    $smtp = new class
    {
        public array $config = [];

        public ?ArrayTransport $transport = null;
    };

    Mail::extend('smtp', function (array $config) use ($smtp, $failure) {
        $smtp->config = $config;

        return $smtp->transport = $failure
            ? new class($failure) extends ArrayTransport
            {
                public function __construct(private Throwable $failure)
                {
                    parent::__construct();
                }

                public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
                {
                    throw $this->failure;
                }
            }
        : new ArrayTransport;
    });
    Mail::forgetMailers();

    return $smtp;
}

it('saves gmail settings with the app password cleaned up and encrypted', function () {
    $admin = emailAdmin();

    $this->actingAs($admin)
        ->put('/quality/email-settings', gmailPayload(['gmail_app_password' => '']))
        ->assertInvalid(['gmail_app_password' => 'app password']);

    $this->actingAs($admin)
        ->put('/quality/email-settings', gmailPayload())
        ->assertRedirect('/quality/email-settings');

    $settings = EmailSetting::current();
    expect($settings->provider)->toBe('gmail')
        ->and($settings->gmail_app_password)->toBe('abcdefghijklmnop')
        ->and(DB::table('email_settings')->value('gmail_app_password'))->not->toContain('abcd')
        ->and($settings->isConfigured())->toBeTrue()
        ->and($settings->fromAddress())->toBe('uhas.qa.insight@gmail.com');

    $this->actingAs($admin)
        ->get('/quality/email-settings')
        ->assertInertia(fn (Assert $page) => $page
            ->where('settings.provider', 'gmail')
            ->where('settings.hasGmailAppPassword', true)
            ->where('settings.fromAddress', 'uhas.qa.insight@gmail.com'))
        ->assertDontSee('abcdefghijklmnop');
});

it('sends through gmail smtp when gmail is chosen', function () {
    $this->actingAs(emailAdmin())->put('/quality/email-settings', gmailPayload());
    $smtp = fakeSmtp();

    $this->actingAs(emailAdmin())
        ->post('/quality/email-settings/test', ['recipient' => 'ict@uhas.edu.gh'])
        ->assertRedirect();

    expect($smtp->config)
        ->toMatchArray([
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'username' => 'uhas.qa.insight@gmail.com',
            'password' => 'abcdefghijklmnop',
        ])
        ->and(EmailSetting::current()->last_test_succeeded)->toBeTrue();

    $sent = $smtp->transport->messages()->first()->getOriginalMessage();
    expect($sent->getFrom()[0]->getAddress())->toBe('uhas.qa.insight@gmail.com')
        ->and($sent->getTo()[0]->getAddress())->toBe('ict@uhas.edu.gh')
        ->and($sent->getSubject())->toBe('QA Insight test email');
});

it('explains a rejected gmail sign-in', function () {
    $this->actingAs(emailAdmin())->put('/quality/email-settings', gmailPayload());
    fakeSmtp(new TransportException('Expected response code "235" but got code "535", with message "535-5.7.8 Username and Password not accepted."'));

    $this->actingAs(emailAdmin())->post('/quality/email-settings/test', ['recipient' => 'ict@uhas.edu.gh']);

    expect(EmailSetting::current())
        ->last_test_succeeded->toBeFalse()
        ->last_test_message->toContain('2-Step Verification')
        ->last_test_message->toContain('535');
});

it('sends workflow emails through gmail once it is switched on', function () {
    $this->actingAs(emailAdmin())->put('/quality/email-settings', gmailPayload());
    $smtp = fakeSmtp();
    $data = reportAwaitingApproval();

    $this->actingAs($data['director'])->post("/quality/reports/{$data['report']->id}/approve");

    $sent = $smtp->transport->messages()->first()?->getOriginalMessage();
    expect($sent?->getTo()[0]->getAddress())->toBe('lecturer@uhas.edu.gh')
        ->and($sent?->getFrom()[0]->getAddress())->toBe('uhas.qa.insight@gmail.com');
});

function microsoftSmtpPayload(array $overrides = []): array
{
    return [
        'enabled' => true,
        'provider' => 'microsoft365',
        'microsoft_method' => 'smtp',
        'smtp_host' => 'smtp.office365.com',
        'smtp_port' => 587,
        'smtp_encryption' => 'starttls',
        'smtp_auth' => true,
        'smtp_username' => 'selfservice@uhas.edu.gh',
        'smtp_auth_type' => 'token',
        'token_url' => 'https://login.microsoftonline.com/1b051f28-5e5a-4586-aad2-b3fa77fda86d/oauth2/v2.0/token',
        'token_scope' => 'https://outlook.office365.com/.default',
        'client_id' => 'edb16604-2f81-40f1-9483-067f3cb9fc24',
        'client_secret' => 'smtp-secret',
        'sender_name' => 'UHAS QA Insight',
        'app_url' => 'https://qa.uhas.edu.gh',
        'send_welcome' => true,
        'send_review_requests' => true,
        'send_report_published' => true,
        'send_report_returned' => true,
        ...$overrides,
    ];
}

it('sends microsoft 365 email through smtp.office365.com with an oauth token', function () {
    $this->actingAs(emailAdmin())
        ->put('/quality/email-settings', microsoftSmtpPayload())
        ->assertRedirect('/quality/email-settings');

    $settings = EmailSetting::current();
    expect($settings->mailerName())->toBe('microsoft-smtp')
        ->and($settings->providerLabel())->toBe('Microsoft 365 (SMTP with OAuth 2.0 token)')
        ->and($settings->isConfigured())->toBeTrue();

    AppServiceProvider::applyEmailSettings($settings);
    $transport = Mail::mailer('microsoft-smtp')->getSymfonyTransport();

    expect(config('mail.default'))->toBe('microsoft-smtp')
        ->and($transport)->toBeInstanceOf(MicrosoftSmtpTransport::class)
        ->and((string) $transport)->toBe('smtp://smtp.office365.com:587')
        ->and($transport->getUsername())->toBe('selfservice@uhas.edu.gh')
        ->and(config('mail.from.address'))->toBe('selfservice@uhas.edu.gh');
});

it('asks microsoft for an smtp token with the exchange online scope', function () {
    Http::fake([GRAPH_TOKEN_URL => Http::response(['access_token' => 'smtp-token', 'expires_in' => 3599])]);

    $token = (new MicrosoftOAuthToken('https://login.microsoftonline.com/1b051f28-5e5a-4586-aad2-b3fa77fda86d/oauth2/v2.0/token', 'edb16604-2f81-40f1-9483-067f3cb9fc24', 'smtp-secret', MicrosoftOAuthToken::SMTP_SCOPE))->get();

    expect($token)->toBe('smtp-token');
    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://login.microsoftonline.com/1b051f28-5e5a-4586-aad2-b3fa77fda86d/oauth2/v2.0/token'
        && $request['scope'] === 'https://outlook.office365.com/.default'
        && $request['client_id'] === 'edb16604-2f81-40f1-9483-067f3cb9fc24'
        && $request['grant_type'] === 'client_credentials');
});

it('explains a token the microsoft 365 mailbox refused', function () {
    $this->actingAs(emailAdmin())->put('/quality/email-settings', microsoftSmtpPayload());
    Mail::extend('microsoft-smtp', fn () => new class extends ArrayTransport
    {
        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            throw new TransportException('Expected response code "235" but got code "535", with message "535 5.7.3 Authentication unsuccessful".');
        }
    });
    Mail::forgetMailers();

    $this->actingAs(emailAdmin())->post('/quality/email-settings/test', ['recipient' => 'ict@uhas.edu.gh']);

    expect(EmailSetting::current())
        ->last_test_succeeded->toBeFalse()
        ->last_test_message->toContain('SMTP.SendAsApp')
        ->last_test_message->toContain('535 5.7.3');
});

it('uses the uhas keycloak smtp settings: starttls required and 10 second timeouts', function () {
    $transport = new MicrosoftSmtpTransport(
        'smtp.office365.com',
        587,
        'starttls',
        'selfservice@uhas.edu.gh',
        token: new MicrosoftOAuthToken('https://login.example/token', 'client', 'secret', MicrosoftOAuthToken::SMTP_SCOPE),
    );

    expect($transport->isTlsRequired())->toBeTrue()
        ->and($transport->getStream()->getTimeout())->toBe(10.0)
        ->and($transport->getStream()->getHost())->toBe('smtp.office365.com')
        ->and($transport->getStream()->getPort())->toBe(587)
        ->and($transport->getStream()->isTLS())->toBeFalse()
        ->and($transport->getUsername())->toBe('selfservice@uhas.edu.gh');

    $ssl = new MicrosoftSmtpTransport('smtp.example.com', 465, 'ssl', 'user@example.com', 'secret');
    expect($ssl->getStream()->isTLS())->toBeTrue()
        ->and($ssl->getPassword())->toBe('secret');
});

it('saves password authentication or no authentication like keycloak', function () {
    $admin = emailAdmin();

    $this->actingAs($admin)
        ->put('/quality/email-settings', microsoftSmtpPayload(['smtp_auth_type' => 'password', 'smtp_password' => '']))
        ->assertInvalid(['smtp_password' => 'password']);

    $this->actingAs($admin)
        ->put('/quality/email-settings', microsoftSmtpPayload(['smtp_auth_type' => 'password', 'smtp_password' => 'mailbox-password', 'token_url' => '', 'client_id' => '', 'client_secret' => '']))
        ->assertRedirect('/quality/email-settings');

    $settings = EmailSetting::current();
    expect($settings->smtp_auth_type)->toBe('password')
        ->and($settings->smtp_password)->toBe('mailbox-password')
        ->and(DB::table('email_settings')->value('smtp_password'))->not->toContain('mailbox-password')
        ->and($settings->providerLabel())->toBe('Microsoft 365 (SMTP)')
        ->and($settings->isConfigured())->toBeTrue();

    AppServiceProvider::applyEmailSettings($settings);
    $transport = Mail::mailer('microsoft-smtp')->getSymfonyTransport();
    expect($transport->getUsername())->toBe('selfservice@uhas.edu.gh')
        ->and($transport->getPassword())->toBe('mailbox-password');

    $this->actingAs($admin)
        ->put('/quality/email-settings', microsoftSmtpPayload(['smtp_auth' => false, 'smtp_host' => 'relay.uhas.edu.gh', 'smtp_port' => 25, 'sender_address' => 'qa@uhas.edu.gh']))
        ->assertRedirect('/quality/email-settings');

    $settings = EmailSetting::current();
    AppServiceProvider::applyEmailSettings($settings);
    Mail::forgetMailers();
    $relay = Mail::mailer('microsoft-smtp')->getSymfonyTransport();
    expect($settings->smtp_auth)->toBeFalse()
        ->and($relay->getUsername())->toBe('')
        ->and((string) $relay)->toBe('smtp://relay.uhas.edu.gh:25')
        ->and(config('mail.from.address'))->toBe('qa@uhas.edu.gh');
});

it('shows the smtp conversation of a failed test with the sign-in hidden', function () {
    $this->actingAs(emailAdmin())->put('/quality/email-settings', microsoftSmtpPayload());
    Mail::extend('microsoft-smtp', fn () => new class extends ArrayTransport
    {
        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            $exception = new TransportException('Expected response code "235" but got code "535", with message "535 5.7.3 Authentication unsuccessful".');
            $exception->appendDebug("< 220 smtp.office365.com ready\n> EHLO qa.uhas.edu.gh\n< 250-STARTTLS\n> AUTH XOAUTH2 dXNlcj1zZWxmc2VydmljZUB1aGFzLmVkdS5naAFhdXRoPUJlYXJlciBzZWNyZXQtdG9rZW4BAQ==\n< 535 5.7.3 Authentication unsuccessful\n");

            throw $exception;
        }
    });
    Mail::forgetMailers();

    $this->actingAs(emailAdmin())->post('/quality/email-settings/test', ['recipient' => 'ict@uhas.edu.gh']);

    expect(EmailSetting::current()->last_test_message)
        ->toContain('SMTP log:')
        ->toContain('> EHLO qa.uhas.edu.gh')
        ->toContain('> AUTH XOAUTH2 … (hidden)')
        ->not->toContain('dXNlcj1zZWxmc2VydmljZUB1aGFz');
});

it('saves allow utf-8 like keycloak, switched on by default', function () {
    $admin = emailAdmin();

    expect(EmailSetting::current()->smtp_allow_utf8)->toBeTrue();

    $this->actingAs($admin)
        ->put('/quality/email-settings', microsoftSmtpPayload(['smtp_allow_utf8' => false]))
        ->assertRedirect('/quality/email-settings');

    $settings = EmailSetting::current();
    expect($settings->smtp_allow_utf8)->toBeFalse();

    AppServiceProvider::applyEmailSettings($settings);
    expect(Mail::mailer('microsoft-smtp')->getSymfonyTransport()->allowsUtf8())->toBeFalse();

    $this->actingAs($admin)
        ->get('/quality/email-settings')
        ->assertInertia(fn (Assert $page) => $page->where('settings.smtpAllowUtf8', false));
});

it('refuses non-ascii addresses before connecting when allow utf-8 is off', function () {
    $transport = new MicrosoftSmtpTransport('smtp.office365.com', 587, 'starttls', allowUtf8: false);
    $email = (new Email)->from('selfservice@uhas.edu.gh')->to('kwamé@uhas.edu.gh')->subject('Test')->text('Test');

    expect(fn () => $transport->send($email))
        ->toThrow(TransportException::class, 'Turn on "Allow UTF-8"');
});

it('hides timestamped sign-in lines and explains a timeout after sign-in', function () {
    $this->actingAs(emailAdmin())->put('/quality/email-settings', microsoftSmtpPayload());
    // A JWT-shaped fake token, as Microsoft would issue (header.payload.signature).
    $fakeToken = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJhdWQiOiJodHRwczovL291dGxvb2sub2ZmaWNlMzY1LmNvbSJ9.c2lnbmF0dXJlLXNpZ25hdHVyZS1zaWduYXR1cmU';
    $authLine = base64_encode("user=selfservice@uhas.edu.gh\1auth=Bearer {$fakeToken}\1\1");

    Mail::extend('microsoft-smtp', fn () => new class($authLine) extends ArrayTransport
    {
        public function __construct(private string $authLine)
        {
            parent::__construct();
        }

        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            $exception = new TransportException('Connection to "smtp.office365.com:587" timed out.');
            $exception->appendDebug(implode("\n", [
                '[2026-11-03T20:45:13.770235Z] > STARTTLS',
                '[2026-11-03T20:45:13.869634Z] < 220 2.0.0 SMTP server ready',
                '[2026-11-03T20:45:14.114116Z] > EHLO [127.0.0.1]',
                '[2026-11-03T20:45:14.312665Z] < 250-AUTH LOGIN XOAUTH2',
                "[2026-11-03T20:45:14.312797Z] > AUTH XOAUTH2 {$this->authLine}",
                '[2026-11-03T20:45:20.000000Z] > dXNlcm5hbWVAdWhhcy5lZHUuZ2g=',
            ])."\n");

            throw $exception;
        }
    });
    Mail::forgetMailers();

    $this->actingAs(emailAdmin())->post('/quality/email-settings/test', ['recipient' => 'ict@uhas.edu.gh']);

    $message = EmailSetting::current()->last_test_message;
    expect($message)
        ->toContain('[2026-11-03T20:45:14.312797Z] > AUTH XOAUTH2 … (hidden)')
        ->toContain('[2026-11-03T20:45:20.000000Z] > … (hidden)')
        ->toContain('got no answer within 10 seconds after sending the sign-in')
        ->toContain('MTU')
        ->not->toContain($authLine)
        ->not->toContain('dXNlcj1')
        ->not->toContain('eyJ')
        ->not->toContain('Could not reach');
});

it('uses the timeout set on the email settings page', function () {
    $this->actingAs(emailAdmin())
        ->put('/quality/email-settings', microsoftSmtpPayload(['smtp_timeout' => 30]))
        ->assertRedirect('/quality/email-settings');

    $settings = EmailSetting::current();
    AppServiceProvider::applyEmailSettings($settings);

    expect($settings->smtp_timeout)->toBe(30)
        ->and(Mail::mailer('microsoft-smtp')->getSymfonyTransport()->getStream()->getTimeout())->toBe(30.0);

    $this->actingAs(emailAdmin())
        ->put('/quality/email-settings', microsoftSmtpPayload(['smtp_timeout' => 500]))
        ->assertInvalid('smtp_timeout');
});
