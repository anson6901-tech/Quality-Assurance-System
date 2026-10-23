<?php

namespace App\Support;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OAuth 2.0 client-credentials tokens, cached until shortly before they expire. SMTP sending
 * uses the token URL and scope entered on the Email Settings page (as in Keycloak); Graph
 * sending builds the Microsoft Entra ID URL from the tenant.
 */
class MicrosoftOAuthToken
{
    public const GRAPH_SCOPE = 'https://graph.microsoft.com/.default';

    public const SMTP_SCOPE = 'https://outlook.office365.com/.default';

    public function __construct(
        private readonly string $tokenUrl,
        private readonly string $clientId,
        #[\SensitiveParameter] private readonly string $clientSecret,
        private readonly string $scope,
    ) {}

    public static function urlForTenant(string $tenantId): string
    {
        return 'https://login.microsoftonline.com/'.rawurlencode($tenantId).'/oauth2/v2.0/token';
    }

    public function get(): string
    {
        return Cache::remember($this->cacheKey(), now()->addMinutes(50), function (): string {
            try {
                $response = Http::asForm()
                    ->acceptJson()
                    ->timeout(30)
                    ->post($this->tokenUrl, [
                        'client_id' => $this->clientId,
                        'client_secret' => $this->clientSecret,
                        'scope' => $this->scope,
                        'grant_type' => 'client_credentials',
                    ])
                    ->throw();
            } catch (RequestException $exception) {
                throw new RuntimeException(self::describeFailure(
                    'Could not get a token from the Auth Token URL',
                    $exception->response->status(),
                    $exception->response->json('error'),
                    $exception->response->json('error_description'),
                ), previous: $exception);
            }

            return (string) $response->json('access_token');
        });
    }

    /**
     * Drops the cached token, for example after Microsoft rejects it.
     */
    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    public static function describeFailure(string $summary, int $status, mixed $code, mixed $message): string
    {
        $detail = trim(strtok((string) $message, "\r\n") ?: '');

        return trim(sprintf('%s (HTTP %d%s). %s', $summary, $status, $code ? ', '.$code : '', $detail));
    }

    private function cacheKey(): string
    {
        return 'oauth-token:'.hash('sha256', $this->tokenUrl.'|'.$this->clientId.'|'.$this->clientSecret.'|'.$this->scope);
    }
}
