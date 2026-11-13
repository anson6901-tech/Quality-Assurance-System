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
    'microsoft_method',
    'smtp_host',
    'smtp_port',
    'smtp_encryption',
    'smtp_auth',
    'smtp_username',
    'smtp_auth_type',
    'smtp_password',
    'token_url',
    'token_scope',
    'smtp_allow_utf8',
    'smtp_timeout',
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
    'published_email_subject',
    'published_email_body',
    'published_attach_pdf',
    'last_tested_at',
    'last_test_succeeded',
    'last_test_message',
    'updated_by',
])]
#[Hidden(['client_secret', 'gmail_app_password', 'smtp_password'])]
class EmailSetting extends Model
{
    public const MICROSOFT_365 = 'microsoft365';

    public const GMAIL = 'gmail';

    /** Microsoft 365 through smtp.office365.com with an OAuth 2.0 token. */
    public const MICROSOFT_SMTP = 'smtp';

    /** Microsoft 365 through the Microsoft Graph API. */
    public const MICROSOFT_GRAPH = 'graph';

    public const ENCRYPTION_SSL = 'ssl';

    public const ENCRYPTION_STARTTLS = 'starttls';

    public const AUTH_PASSWORD = 'password';

    public const AUTH_TOKEN = 'token';

    private const CACHE_KEY = 'email-settings';

    public const DEFAULT_PUBLISHED_SUBJECT = 'Your QA report has been published: {report_title}';

    public const DEFAULT_PUBLISHED_BODY = <<<'TEXT'
        Dear {lecturer_name},

        Your quality assurance report "{report_title}" for {courses} ({academic_year}, {semester}) has been approved and published.

        The report is attached to this email as a PDF. You can also view and download it at any time on your QA Insight portal, under My Courses: {portal_link}

        Thank you.
        TEXT;

    /** Words that are replaced with the report's details when the email is sent. */
    public const PUBLISHED_PLACEHOLDERS = [
        'lecturer_name' => 'The lecturer\'s name',
        'report_title' => 'The report title',
        'courses' => 'Course code and title',
        'academic_year' => 'Academic year',
        'semester' => 'Semester',
        'report_link' => 'Link to the report',
        'portal_link' => 'Link to the lecturer\'s portal (My Courses)',
        'sender_name' => 'Sender name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'client_secret' => 'encrypted',
            'gmail_app_password' => 'encrypted',
            'smtp_password' => 'encrypted',
            'smtp_auth' => 'boolean',
            'smtp_allow_utf8' => 'boolean',
            'smtp_timeout' => 'integer',
            'smtp_port' => 'integer',
            'send_welcome' => 'boolean',
            'send_review_requests' => 'boolean',
            'send_report_published' => 'boolean',
            'send_report_returned' => 'boolean',
            'published_attach_pdf' => 'boolean',
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
        $settings = self::query()->firstOrCreate([], ['enabled' => false]);

        // A new row only has its database defaults after reloading.
        return $settings->wasRecentlyCreated ? $settings->refresh() : $settings;
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

    public function publishedSubject(): string
    {
        return filled($this->published_email_subject) ? $this->published_email_subject : self::DEFAULT_PUBLISHED_SUBJECT;
    }

    public function publishedBody(): string
    {
        return filled($this->published_email_body) ? $this->published_email_body : self::DEFAULT_PUBLISHED_BODY;
    }

    /**
     * Whether the published email carries the report PDF (on unless switched off).
     */
    public function attachesPublishedPdf(): bool
    {
        return $this->published_attach_pdf ?? true;
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
        if ($this->usesGmail()) {
            return filled($this->gmail_address) && filled($this->gmail_app_password);
        }

        if ($this->microsoft_method === self::MICROSOFT_GRAPH) {
            return filled($this->tenant_id)
                && filled($this->client_id)
                && filled($this->client_secret)
                && filled($this->sender_address);
        }

        $authenticated = ! $this->smtp_auth || (filled($this->smtp_username) && ($this->smtp_auth_type === self::AUTH_PASSWORD
            ? filled($this->smtp_password)
            : filled($this->token_url) && filled($this->token_scope) && filled($this->client_id) && filled($this->client_secret)));

        return filled($this->smtp_host) && $this->smtp_port > 0 && filled($this->fromAddress()) && $authenticated;
    }

    /**
     * The mailbox emails are sent from for the chosen provider.
     */
    public function fromAddress(): ?string
    {
        if ($this->usesGmail()) {
            return $this->gmail_address;
        }

        return $this->sender_address ?: ($this->microsoft_method === self::MICROSOFT_GRAPH ? null : $this->smtp_username);
    }

    /**
     * The Laravel mailer that delivers for the chosen provider.
     */
    public function mailerName(): string
    {
        return match (true) {
            $this->usesGmail() => 'gmail',
            $this->microsoft_method === self::MICROSOFT_GRAPH => 'microsoft-graph',
            default => 'microsoft-smtp',
        };
    }

    public function providerLabel(): string
    {
        return match ($this->mailerName()) {
            'gmail' => 'Gmail',
            'microsoft-graph' => 'Microsoft 365 (Graph API)',
            default => $this->smtp_auth && $this->smtp_auth_type === self::AUTH_TOKEN
                ? 'Microsoft 365 (SMTP with OAuth 2.0 token)'
                : 'Microsoft 365 (SMTP)',
        };
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
