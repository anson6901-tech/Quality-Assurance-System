<?php

namespace App\Providers;

use App\Mail\MicrosoftGraphTransport;
use App\Mail\MicrosoftSmtpTransport;
use App\Models\AuditLog;
use App\Models\EmailSetting;
use App\Support\MicrosoftGraphMailer;
use App\Support\MicrosoftOAuthToken;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureEmail();

        Event::listen(Login::class, function (Login $event): void {
            AuditLog::query()->create([
                'user_id' => $event->user->getAuthIdentifier(),
                'event' => 'login',
                'module' => 'Authentication',
                'action' => 'login',
                'method' => request()->method(),
                'path' => request()->path(),
                'ip_address' => request()->ip(),
                'user_agent' => str(request()->userAgent() ?? '')->limit(500)->toString(),
                'metadata' => [
                    'summary' => sprintf('%s signed in.', $event->user->name),
                    'status' => 302,
                    'actor' => [
                        'role' => $event->user->university_role,
                        'school_id' => $event->user->school_id,
                        'department_id' => $event->user->department_id,
                        'current_team_id' => $event->user->current_team_id,
                    ],
                ],
            ]);
        });
    }

    /**
     * Mail goes through Microsoft 365 (SMTP with OAuth 2.0, or Microsoft Graph) or Gmail (SMTP)
     * when it is switched on
     * from the Email Settings page. Settings are re-read before each queued job so a long-running worker
     * picks up changes.
     */
    protected function configureEmail(): void
    {
        Mail::extend('microsoft-graph', fn (array $config) => new MicrosoftGraphTransport(new MicrosoftGraphMailer(
            $config['tenant_id'],
            $config['client_id'],
            $config['client_secret'],
            $config['sender_address'],
        )));
        Mail::extend('microsoft-smtp', fn (array $config) => new MicrosoftSmtpTransport(
            $config['host'],
            (int) $config['port'],
            $config['encryption'],
            $config['auth'] ? $config['username'] : null,
            $config['auth'] && $config['auth_type'] === EmailSetting::AUTH_PASSWORD ? $config['password'] : null,
            $config['auth'] && $config['auth_type'] === EmailSetting::AUTH_TOKEN
                ? new MicrosoftOAuthToken($config['token_url'], $config['client_id'], $config['client_secret'], $config['token_scope'])
                : null,
            (bool) ($config['allow_utf8'] ?? true),
        ));

        self::applyEmailSettings();
        Queue::before(fn () => self::applyEmailSettings());
    }

    public static function applyEmailSettings(?EmailSetting $settings = null): void
    {
        $settings ??= EmailSetting::cached();

        if (! $settings?->enabled || ! $settings->isConfigured()) {
            return;
        }

        config([
            'mail.default' => $settings->mailerName(),
            'mail.mailers.microsoft-graph' => [
                'transport' => 'microsoft-graph',
                'tenant_id' => $settings->tenant_id,
                'client_id' => $settings->client_id,
                'client_secret' => $settings->client_secret,
                'sender_address' => $settings->sender_address,
            ],
            // SMTP set up like the Keycloak mail settings.
            'mail.mailers.microsoft-smtp' => [
                'transport' => 'microsoft-smtp',
                'host' => $settings->smtp_host,
                'port' => $settings->smtp_port,
                'encryption' => $settings->smtp_encryption,
                'auth' => $settings->smtp_auth,
                'username' => $settings->smtp_username,
                'auth_type' => $settings->smtp_auth_type,
                'password' => $settings->smtp_password,
                'token_url' => $settings->token_url,
                'token_scope' => $settings->token_scope,
                'client_id' => $settings->client_id,
                'client_secret' => $settings->client_secret,
                'allow_utf8' => $settings->smtp_allow_utf8,
            ],
            // Gmail: SMTP with STARTTLS, signed in with a Google app password.
            'mail.mailers.gmail' => [
                'transport' => 'smtp',
                'scheme' => 'smtp',
                'host' => 'smtp.gmail.com',
                'port' => 587,
                'username' => $settings->gmail_address,
                'password' => $settings->gmail_app_password,
                'timeout' => 30,
            ],
            'mail.from.address' => $settings->fromAddress(),
            'mail.from.name' => $settings->sender_name ?: config('app.name'),
        ]);

        Mail::forgetMailers();

        // Queued emails are built without a web request, so their links use this address and
        // their header shows the sender name.
        if (app()->runningInConsole()) {
            config(['app.name' => $settings->sender_name ?: config('app.name')]);

            if (filled($settings->app_url)) {
                config(['app.url' => $settings->app_url]);
                URL::forceRootUrl($settings->app_url);
                URL::forceScheme(parse_url($settings->app_url, PHP_URL_SCHEME) ?: 'https');
            }
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
