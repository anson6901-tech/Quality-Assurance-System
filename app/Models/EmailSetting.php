<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * The single row of email configuration managed from the Email Settings page.
 */
#[Fillable([
    'enabled',
    'provider',
    'tenant_id',
    'client_id',
    'client_secret',
    'sender_address',
    'gmail_address',
    'gmail_app_password',
    'sender_name',
    'app_url',
    'send_welcome',
    'send_review_requests',
    'send_report_published',
    'send_report_returned',
    'last_tested_at',
    'last_test_succeeded',
    'last_test_message',
    'updated_by',
])]
#[Hidden(['client_secret', 'gmail_app_password'])]
class EmailSetting extends Model
{
    public const MICROSOFT_365 = 'microsoft365';

    public const GMAIL = 'gmail';

    private const CACHE_KEY = 'email-settings';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'client_secret' => 'encrypted',
            'gmail_app_password' => 'encrypted',
            'send_welcome' => 'boolean',
            'send_review_requests' => 'boolean',
            'send_report_published' => 'boolean',
            'send_report_returned' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_test_succeeded' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], ['enabled' => false]);
    }

    /**
     * Cached copy for request boot; null when the table does not exist yet (before migrating).
     */
    public static function cached(): ?self
    {
        try {
            // Cache raw attributes: the cache store does not unserialize objects.
            $attributes = Cache::rememberForever(self::CACHE_KEY, fn () => self::query()->first()?->getAttributes() ?? []);
        } catch (QueryException) {
            return null;
        }

        return is_array($attributes) && $attributes !== []
            ? (new self)->newFromBuilder($attributes)
            : null;
    }

    public function usesGmail(): bool
    {
        return $this->provider === self::GMAIL;
    }

    /**
     * Whether the chosen provider has everything it needs to send.
     */
    public function isConfigured(): bool
    {
        return $this->usesGmail()
            ? filled($this->gmail_address) && filled($this->gmail_app_password)
            : filled($this->tenant_id)
                && filled($this->client_id)
                && filled($this->client_secret)
                && filled($this->sender_address);
    }

    /**
     * The mailbox emails are sent from for the chosen provider.
     */
    public function fromAddress(): ?string
    {
        return $this->usesGmail() ? $this->gmail_address : $this->sender_address;
    }

    /**
     * The Laravel mailer that delivers for the chosen provider.
     */
    public function mailerName(): string
    {
        return $this->usesGmail() ? 'gmail' : 'microsoft-graph';
    }

    /**
     * Whether a kind of notification should be emailed: sending must be on and that message type enabled.
     */
    public static function allows(string $toggle): bool
    {
        $settings = self::cached();

        return $settings !== null
            && $settings->enabled
            && $settings->isConfigured()
            && (bool) $settings->{$toggle};
    }
}
