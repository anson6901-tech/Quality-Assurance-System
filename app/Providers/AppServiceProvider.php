<?php

namespace App\Providers;

use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
