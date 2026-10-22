<?php

namespace App\Mail;

use App\Support\MicrosoftGraphMailer;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Laravel mail transport that delivers through Microsoft Graph, so every mail and
 * notification in the app is sent as the configured Microsoft 365 mailbox.
 */
class MicrosoftGraphTransport extends AbstractTransport
{
    public function __construct(private readonly MicrosoftGraphMailer $mailer)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $html = $email->getHtmlBody();

        $this->mailer->send(array_filter([
            'subject' => (string) $email->getSubject(),
            'body' => [
                'contentType' => $html !== null ? 'HTML' : 'Text',
                'content' => (string) ($html ?? $email->getTextBody() ?? ''),
            ],
            'toRecipients' => self::recipients($email->getTo()),
            'ccRecipients' => self::recipients($email->getCc()),
            'bccRecipients' => self::recipients($email->getBcc()),
            'replyTo' => self::recipients($email->getReplyTo()),
            'attachments' => self::attachments($email),
        ], fn ($value) => $value !== []));
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, array<string, mixed>>
     */
    private static function recipients(array $addresses): array
    {
        return array_map(fn (Address $address) => [
            'emailAddress' => array_filter([
                'address' => $address->getAddress(),
                'name' => $address->getName(),
            ]),
        ], $addresses);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function attachments(Email $email): array
    {
        return array_map(fn (DataPart $part) => [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => $part->getFilename() ?? 'attachment',
            'contentType' => $part->getContentType(),
            'contentBytes' => base64_encode($part->getBody()),
            'isInline' => $part->getDisposition() === 'inline',
        ], $email->getAttachments());
    }

    public function __toString(): string
    {
        return 'microsoft-graph';
    }
}
