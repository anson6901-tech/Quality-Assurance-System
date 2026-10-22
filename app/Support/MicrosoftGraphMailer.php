<?php

namespace App\Support;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sends mail as a Microsoft 365 mailbox through Microsoft Graph, using an Entra ID app
 * registration with the Mail.Send application permission (client-credentials flow).
 */
class MicrosoftGraphMailer
{
    public function __construct(
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $senderAddress,
    ) {}

    /**
     * @param  array<string, mixed>  $message  A Graph message resource.
     */
    public function send(array $message): void
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(30)
            ->post('https://graph.microsoft.com/v1.0/users/'.rawurlencode($this->senderAddress).'/sendMail', [
                'message' => $message,
                'saveToSentItems' => false,
            ]);

        if ($response->failed()) {
            if ($response->status() === 401) {
                Cache::forget($this->tokenCacheKey());
            }

            throw new RuntimeException(self::describeFailure(
                'Microsoft 365 rejected the email',
                $response->status(),
                $response->json('error.code'),
                $response->json('error.message'),
            ));
        }
    }

    private function accessToken(): string
    {
        return Cache::remember($this->tokenCacheKey(), now()->addMinutes(50), function (): string {
            try {
                $response = Http::asForm()
                    ->acceptJson()
                    ->timeout(30)
                    ->post('https://login.microsoftonline.com/'.rawurlencode($this->tenantId).'/oauth2/v2.0/token', [
                        'client_id' => $this->clientId,
                        'client_secret' => $this->clientSecret,
                        'scope' => 'https://graph.microsoft.com/.default',
                        'grant_type' => 'client_credentials',
                    ])
                    ->throw();
            } catch (RequestException $exception) {
                throw new RuntimeException(self::describeFailure(
                    'Could not sign in to Microsoft 365',
                    $exception->response->status(),
                    $exception->response->json('error'),
                    $exception->response->json('error_description'),
                ), previous: $exception);
            }

            return (string) $response->json('access_token');
        });
    }

    private function tokenCacheKey(): string
    {
        return 'graph-token:'.hash('sha256', $this->tenantId.'|'.$this->clientId.'|'.$this->clientSecret);
    }

    private static function describeFailure(string $summary, int $status, mixed $code, mixed $message): string
    {
        $detail = trim(strtok((string) $message, "\r\n") ?: '');

        return trim(sprintf('%s (HTTP %d%s). %s', $summary, $status, $code ? ', '.$code : '', $detail));
    }
}
