<?php

namespace App\Mail;

use App\Support\MicrosoftOAuthToken;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * SMTP set up the way the UHAS Keycloak mail settings are: host, port, SSL or StartTLS,
 * optional authentication by password or by an OAuth 2.0 token (SASL XOAUTH2), and Allow
 * UTF-8 for email addresses written with non-ASCII characters. For Microsoft
 * 365 that is smtp.office365.com:587 with StartTLS and a token; the app registration needs
 * the Office 365 Exchange Online SMTP.SendAsApp permission and the mailbox must allow SMTP AUTH.
 */
class MicrosoftSmtpTransport extends EsmtpTransport
{
    public const TIMEOUT_SECONDS = 10;

    /**
     * @param  'ssl'|'starttls'  $encryption
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        string $encryption,
        ?string $username = null,
        #[\SensitiveParameter] ?string $password = null,
        private readonly ?MicrosoftOAuthToken $token = null,
        private readonly bool $allowUtf8 = true,
    ) {
        // SSL connects encrypted from the start; StartTLS connects, then must upgrade.
        parent::__construct($host, $port, $encryption === 'ssl', authenticators: $token ? [new XOAuth2Authenticator] : null);

        if ($encryption !== 'ssl') {
            $this->setRequireTls(true);
        }

        if ($username !== null) {
            $this->setUsername($username);
        }

        if ($password !== null) {
            $this->setPassword($password);
        }

        $this->getStream()->setTimeout(self::TIMEOUT_SECONDS);
    }

    protected function doSend(SentMessage $message): void
    {
        // With Allow UTF-8 on, such addresses go out with SMTPUTF8 (Office 365 supports it).
        if (! $this->allowUtf8) {
            $this->rejectUtf8Addresses($message);
        }

        if ($this->token === null) {
            parent::doSend($message);

            return;
        }

        // Tokens last about an hour; a fresh one is fetched (or reused from cache) per send.
        $this->setPassword($this->token->get());

        try {
            parent::doSend($message);
        } catch (TransportExceptionInterface $exception) {
            // A rejected token is not reused on the next attempt.
            if (str_contains($exception->getMessage(), '535')) {
                $this->token->forget();
            }

            throw $exception;
        }
    }

    public function allowsUtf8(): bool
    {
        return $this->allowUtf8;
    }

    private function rejectUtf8Addresses(SentMessage $message): void
    {
        $envelope = $message->getEnvelope();

        foreach ([$envelope->getSender(), ...$envelope->getRecipients()] as $address) {
            if ($address->hasUnicodeLocalpart()) {
                throw new TransportException(sprintf(
                    'The address "%s" contains non-ASCII characters. Turn on "Allow UTF-8" in Email Settings to send to it.',
                    $address->getAddress(),
                ));
            }
        }
    }

    public function __toString(): string
    {
        return "smtp://{$this->host}:{$this->port}";
    }
}
