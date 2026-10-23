<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sends mail as a Microsoft 365 mailbox through Microsoft Graph, using an Entra ID app
 * registration with the Mail.Send application permission (client-credentials flow).
 */
class MicrosoftGraphMailer
{
    private readonly MicrosoftOAuthToken $token;

    public function __construct(
        string $tenantId,
        string $clientId,
        #[\SensitiveParameter] string $clientSecret,
        private readonly string $senderAddress,
    ) {
        $this->token = new MicrosoftOAuthToken(MicrosoftOAuthToken::urlForTenant($tenantId), $clientId, $clientSecret, MicrosoftOAuthToken::GRAPH_SCOPE);
    }

    /**
     * @param  array<string, mixed>  $message  A Graph message resource.
     */
    public function send(array $message): void
    {
        $response = Http::withToken($this->token->get())
            ->acceptJson()
            ->timeout(30)
            ->post('https://graph.microsoft.com/v1.0/users/'.rawurlencode($this->senderAddress).'/sendMail', [
                'message' => $message,
                'saveToSentItems' => false,
            ]);

        if ($response->failed()) {
            if ($response->status() === 401) {
                $this->token->forget();
            }

            throw new RuntimeException(MicrosoftOAuthToken::describeFailure(
                'Microsoft 365 rejected the email',
                $response->status(),
                $response->json('error.code'),
                $response->json('error.message'),
            ));
        }
    }
}
