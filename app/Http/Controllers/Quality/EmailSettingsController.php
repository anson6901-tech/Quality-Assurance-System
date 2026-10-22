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
        $required = fn (bool $forProvider) => $forProvider && $request->boolean('enabled') ? 'required' : 'nullable';

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'provider' => ['required', Rule::in([EmailSetting::MICROSOFT_365, EmailSetting::GMAIL])],
            'tenant_id' => [$required($microsoft), 'string', 'max:100'],
            'client_id' => [$required($microsoft), 'string', 'max:100'],
            'client_secret' => ['nullable', 'string', 'max:500'],
            'sender_address' => [$required($microsoft), 'email', 'max:255'],
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
        foreach (['client_secret', 'gmail_app_password'] as $secret) {
            if (blank($validated[$secret] ?? null)) {
                unset($validated[$secret]);
            }
        }

        if ($validated['enabled'] && $microsoft && blank($validated['client_secret'] ?? $settings->client_secret)) {
            throw ValidationException::withMessages(['client_secret' => __('Enter the client secret to switch email on.')]);
        }

        if ($validated['enabled'] && $gmail && blank($validated['gmail_app_password'] ?? $settings->gmail_app_password)) {
            throw ValidationException::withMessages(['gmail_app_password' => __('Enter the Gmail app password to switch email on.')]);
        }

        $connectionChanged = collect(['provider', 'tenant_id', 'client_id', 'client_secret', 'sender_address', 'gmail_address', 'gmail_app_password'])
            ->contains(fn (string $key) => array_key_exists($key, $validated) && $validated[$key] !== $settings->{$key});

        $settings->fill([
            ...$validated,
            'app_url' => isset($validated['app_url']) ? rtrim($validated['app_url'], '/') : null,
            'updated_by' => $request->user()->id,
            ...($connectionChanged ? ['last_tested_at' => null, 'last_test_succeeded' => null, 'last_test_message' => null] : []),
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => $settings->enabled
            ? __('Email settings saved. Emails are being sent through :provider.', ['provider' => $settings->usesGmail() ? 'Gmail' : 'Microsoft 365'])
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
                    : __('Save the tenant ID, client ID, client secret and sender mailbox first.'),
            ]);
        }

        $settings->enabled = true;
        AppServiceProvider::applyEmailSettings($settings);

        try {
            Mail::mailer($settings->mailerName())->raw(
                __("This is a test email from QA Insight.\n\nIf you can read this, :provider email is working and notifications will be delivered from :sender.", [
                    'provider' => $settings->usesGmail() ? 'Gmail' : 'Microsoft 365',
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
            $message = self::explainFailure($settings, $exception->getMessage());
        }

        $settings->refresh()->forceFill([
            'last_tested_at' => now(),
            'last_test_succeeded' => $succeeded,
            'last_test_message' => str($message)->limit(1000)->toString(),
        ])->save();

        Inertia::flash('toast', [
            'type' => $succeeded ? 'success' : 'error',
            'message' => $succeeded ? $message : __('The test email could not be sent. See the details on the page.'),
        ]);

        return to_route('quality.email-settings.show');
    }

    /**
     * Adds a plain-language hint to the most common Gmail sign-in failure.
     */
    private static function explainFailure(EmailSetting $settings, string $message): string
    {
        if ($settings->usesGmail() && str_contains($message, '535')) {
            return __('Gmail did not accept the address and app password. Use an app password (not the normal Gmail password) from an account with 2-Step Verification turned on.')
                .' '.$message;
        }

        return $message;
    }
}
