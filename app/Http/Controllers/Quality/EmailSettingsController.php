<?php

namespace App\Http\Controllers\Quality;

use App\Http\Controllers\Controller;
use App\Models\EmailSetting;
use App\Providers\AppServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Email configuration (Microsoft 365 or Gmail) and notification switches, managed in the app
 * so ICT never has to edit server files.
 */
class EmailSettingsController extends Controller
{
    public function show(Request $request): Response
    {
        abort_unless($request->user()->canManageEmailSettings(), 403);

        $settings = EmailSetting::current();

        return Inertia::render('quality/email-settings/Index', [
            'settings' => [
                'enabled' => $settings->enabled,
                'provider' => $settings->provider,
                'microsoftMethod' => $settings->microsoft_method ?? EmailSetting::MICROSOFT_SMTP,
                'smtpHost' => $settings->smtp_host,
                'smtpPort' => $settings->smtp_port,
                'smtpEncryption' => $settings->smtp_encryption,
                'smtpAuth' => $settings->smtp_auth,
                'smtpUsername' => $settings->smtp_username,
                'smtpAuthType' => $settings->smtp_auth_type,
                'hasSmtpPassword' => filled($settings->smtp_password),
                'tokenUrl' => $settings->token_url,
                'tokenScope' => $settings->token_scope,
                'smtpAllowUtf8' => $settings->smtp_allow_utf8,
                'providerLabel' => $settings->providerLabel(),
                'fromAddress' => $settings->fromAddress(),
                'gmailAddress' => $settings->gmail_address,
                'hasGmailAppPassword' => filled($settings->gmail_app_password),
                'tenantId' => $settings->tenant_id,
                'clientId' => $settings->client_id,
                'hasClientSecret' => filled($settings->client_secret),
                'senderAddress' => $settings->sender_address,
                'senderName' => $settings->sender_name,
                'appUrl' => $settings->app_url ?: $request->root(),
                'sendWelcome' => $settings->send_welcome,
                'sendReviewRequests' => $settings->send_review_requests,
                'sendReportPublished' => $settings->send_report_published,
                'sendReportReturned' => $settings->send_report_returned,
                'isConfigured' => $settings->isConfigured(),
                'lastTest' => $settings->last_tested_at ? [
                    'at' => $settings->last_tested_at->toIso8601String(),
                    'succeeded' => $settings->last_test_succeeded,
                    'message' => $settings->last_test_message,
                ] : null,
            ],
            'defaultTestRecipient' => $request->user()->email,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageEmailSettings(), 403);

        $request->merge([
            'gmail_app_password' => preg_replace('/\s+/', '', (string) $request->input('gmail_app_password')) ?: null,
        ]);
        $microsoft = $request->input('provider') === EmailSetting::MICROSOFT_365;
        $gmail = $request->input('provider') === EmailSetting::GMAIL;
        $graph = $microsoft && $request->input('microsoft_method') === EmailSetting::MICROSOFT_GRAPH;
        $smtp = $microsoft && ! $graph;
        $smtpAuth = $smtp && $request->boolean('smtp_auth');
        $token = $smtpAuth && $request->input('smtp_auth_type') === EmailSetting::AUTH_TOKEN;
        $required = fn (bool $forProvider) => $forProvider && $request->boolean('enabled') ? 'required' : 'nullable';

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'provider' => ['required', Rule::in([EmailSetting::MICROSOFT_365, EmailSetting::GMAIL])],
            'microsoft_method' => ['required', Rule::in([EmailSetting::MICROSOFT_SMTP, EmailSetting::MICROSOFT_GRAPH])],
            'smtp_host' => [$smtp ? 'required' : 'nullable', 'string', 'max:255'],
            'smtp_port' => [$smtp ? 'required' : 'nullable', 'integer', 'between:1,65535'],
            'smtp_encryption' => [$smtp ? 'required' : 'nullable', Rule::in([EmailSetting::ENCRYPTION_SSL, EmailSetting::ENCRYPTION_STARTTLS])],
            'smtp_auth' => ['boolean'],
            'smtp_allow_utf8' => ['boolean'],
            'smtp_username' => [$required($smtpAuth), 'string', 'max:255'],
            'smtp_auth_type' => [$smtpAuth ? 'required' : 'nullable', Rule::in([EmailSetting::AUTH_PASSWORD, EmailSetting::AUTH_TOKEN])],
            'smtp_password' => ['nullable', 'string', 'max:500'],
            'token_url' => [$required($token), 'url:https', 'max:255'],
            'token_scope' => [$required($token), 'string', 'max:255'],
            'tenant_id' => [$required($graph), 'string', 'max:100'],
            'client_id' => [$required($graph || $token), 'string', 'max:100'],
            'client_secret' => ['nullable', 'string', 'max:500'],
            'sender_address' => [$required($graph), 'email', 'max:255'],
            'gmail_address' => [$required($gmail), 'email', 'max:255'],
            'gmail_app_password' => ['nullable', 'string', 'max:100'],
            'sender_name' => ['nullable', 'string', 'max:100'],
            'app_url' => ['nullable', 'url:http,https', 'max:255'],
            'send_welcome' => ['required', 'boolean'],
            'send_review_requests' => ['required', 'boolean'],
            'send_report_published' => ['required', 'boolean'],
            'send_report_returned' => ['required', 'boolean'],
        ]);

        $settings = EmailSetting::current();

        // A blank secret keeps the saved one, so it never has to be shown again.
        foreach (['client_secret', 'gmail_app_password', 'smtp_password'] as $secret) {
            if (blank($validated[$secret] ?? null)) {
                unset($validated[$secret]);
            }
        }

        if ($validated['enabled'] && ($graph || $token) && blank($validated['client_secret'] ?? $settings->client_secret)) {
            throw ValidationException::withMessages(['client_secret' => __('Enter the client secret to switch email on.')]);
        }

        if ($validated['enabled'] && $smtpAuth && ! $token && blank($validated['smtp_password'] ?? $settings->smtp_password)) {
            throw ValidationException::withMessages(['smtp_password' => __('Enter the password to switch email on.')]);
        }

        if ($validated['enabled'] && $gmail && blank($validated['gmail_app_password'] ?? $settings->gmail_app_password)) {
            throw ValidationException::withMessages(['gmail_app_password' => __('Enter the Gmail app password to switch email on.')]);
        }

        $connectionChanged = collect(['provider', 'microsoft_method', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_auth', 'smtp_username', 'smtp_auth_type', 'smtp_password', 'token_url', 'token_scope', 'tenant_id', 'client_id', 'client_secret', 'sender_address', 'gmail_address', 'gmail_app_password'])
            ->contains(fn (string $key) => array_key_exists($key, $validated) && $validated[$key] !== $settings->{$key});

        $settings->fill([
            ...array_filter($validated, fn ($value) => $value !== null),
            'smtp_auth' => $request->boolean('smtp_auth'),
            'smtp_allow_utf8' => $request->boolean('smtp_allow_utf8', true),
            'sender_address' => $validated['sender_address'] ?? null,
            'app_url' => isset($validated['app_url']) ? rtrim($validated['app_url'], '/') : null,
            'updated_by' => $request->user()->id,
            ...($connectionChanged ? ['last_tested_at' => null, 'last_test_succeeded' => null, 'last_test_message' => null] : []),
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => $settings->enabled
            ? __('Email settings saved. Emails are being sent through :provider.', ['provider' => $settings->providerLabel()])
            : __('Email settings saved. Email sending is switched off.')]);

        return to_route('quality.email-settings.show');
    }

    /**
     * Sends a test email right away (not queued) so the result can be shown on the page.
     */
    public function test(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageEmailSettings(), 403);

        $validated = $request->validate([
            'recipient' => ['required', 'email', 'max:255'],
        ]);

        $settings = EmailSetting::current();

        if (! $settings->isConfigured()) {
            throw ValidationException::withMessages([
                'recipient' => $settings->usesGmail()
                    ? __('Save the Gmail address and app password first.')
                    : __('Fill in and save the connection details first.'),
            ]);
        }

        $settings->enabled = true;
        AppServiceProvider::applyEmailSettings($settings);

        try {
            Mail::mailer($settings->mailerName())->raw(
                __("This is a test email from QA Insight.\n\nIf you can read this, :provider email is working and notifications will be delivered from :sender.", [
                    'provider' => $settings->providerLabel(),
                    'sender' => $settings->fromAddress(),
                ]),
                fn ($message) => $message
                    ->to($validated['recipient'])
                    ->from($settings->fromAddress(), $settings->sender_name ?: config('app.name'))
                    ->subject(__('QA Insight test email')),
            );
            $succeeded = true;
            $message = __('Test email sent to :email.', ['email' => $validated['recipient']]);
        } catch (Throwable $exception) {
            report($exception);
            $succeeded = false;
            $message = self::explainFailure($settings, $exception->getMessage()).self::smtpLog($exception);
        }

        $settings->refresh()->forceFill([
            'last_tested_at' => now(),
            'last_test_succeeded' => $succeeded,
            'last_test_message' => str($message)->limit(4000)->toString(),
        ])->save();

        Inertia::flash('toast', [
            'type' => $succeeded ? 'success' : 'error',
            'message' => $succeeded ? $message : __('The test email could not be sent. See the details on the page.'),
        ]);

        return to_route('quality.email-settings.show');
    }

    /**
     * Adds a plain-language hint to the most common sign-in failures.
     */
    private static function explainFailure(EmailSetting $settings, string $message): string
    {
        $hint = match (true) {
            $settings->usesGmail() && str_contains($message, '535') => __('Gmail did not accept the address and app password. Use an app password (not the normal Gmail password) from an account with 2-Step Verification turned on.'),
            $settings->mailerName() === 'microsoft-smtp' && str_contains($message, '535') => __('Microsoft 365 accepted the app but the mailbox refused the token. Check that the app has the SMTP.SendAsApp permission with admin consent, that its service principal is registered in Exchange Online with access to the mailbox, and that SMTP AUTH is turned on for the mailbox.'),
            $settings->mailerName() === 'microsoft-smtp' && preg_match('/connect|timed out|Connection refused/i', $message) === 1 => __('Could not reach smtp.office365.com on port 587. Check that the server can make outgoing connections on that port.'),
            default => null,
        };

        return $hint ? $hint.' '.$message : $message;
    }

    /**
     * The SMTP conversation of a failed test send, like Keycloak's "Debug SMTP", with the
     * sign-in line hidden because it carries the access token or password.
     */
    private static function smtpLog(Throwable $exception): string
    {
        $debug = $exception instanceof TransportExceptionInterface ? trim($exception->getDebug()) : '';

        if ($debug === '') {
            return '';
        }

        $lines = collect(preg_split('/\R/', $debug))
            ->map(fn (string $line) => preg_match('/^>\s*AUTH\b/i', $line) === 1 || preg_match('/^>\s*[A-Za-z0-9+\/=]{20,}$/', $line) === 1
                ? '> AUTH … (hidden)'
                : $line)
            ->take(-20)
            ->implode("\n");

        return "\n\nSMTP log:\n".$lines;
    }
}
