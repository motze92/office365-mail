<?php

namespace Office365Mail\Transport;

use GuzzleHttp\Client;
use Microsoft\Graph\Graph;
use Microsoft\Graph\Model\Message as GraphMessage;
use Microsoft\Graph\Model\UploadSession;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

class Office365MailTransport extends AbstractTransport
{
    /** Graph rejects a sendMail payload above this size, so it goes out as a draft instead. */
    protected const MAX_PAYLOAD_MB = 4;

    /** Attachments up to this size are posted directly, larger ones need an upload session. */
    protected const MAX_SIMPLE_ATTACHMENT_MB = 3;

    protected const BYTES_PER_MB = 1048576;

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $graph = new Graph();
        $graph->setAccessToken($this->getAccessToken());

        $sender = $this->getSender($email);
        $payload = $this->getBody($email, (bool) $email->getAttachments());

        if ($this->sizeInMb((string) json_encode($payload)) >= self::MAX_PAYLOAD_MB) {
            $this->sendAsDraft($graph, $email, $sender);

            return;
        }

        $graph->createRequest('POST', '/users/' . $sender . '/sendmail')
            ->attachBody($payload)
            ->setReturnType(GraphMessage::class)
            ->execute();
    }

    /**
     * Create the message as a draft, attach the files one by one and send it.
     *
     * Graph limits a sendMail request to 4 MB, a draft attachment to 150 MB.
     */
    protected function sendAsDraft(Graph $graph, Email $email, string $sender): void
    {
        $draft = $graph->createRequest('POST', '/users/' . $sender . '/messages')
            ->attachBody($this->getBody($email)['message'])
            ->setReturnType(GraphMessage::class)
            ->execute();

        foreach ($email->getAttachments() as $attachment) {
            $this->attachToDraft($graph, $sender, (string) $draft->getId(), $attachment);
        }

        $graph->createRequest('POST', '/users/' . $sender . '/messages/' . $draft->getId() . '/send')->execute();
    }

    protected function attachToDraft(Graph $graph, string $sender, string $messageId, DataPart $attachment): void
    {
        $content = $this->getPartContent($attachment);
        $fileSize = strlen($content);
        $endpoint = '/users/' . $sender . '/messages/' . $messageId . '/attachments';

        // ErrorAttachmentSizeShouldNotBeLessThanMinimumSize: an upload session is
        // only accepted above 3 MB, anything smaller goes in a single request.
        if ($this->sizeInMb($content) <= self::MAX_SIMPLE_ATTACHMENT_MB) {
            $graph->createRequest('POST', $endpoint)
                ->attachBody($this->getAttachmentBody($attachment))
                ->setReturnType(UploadSession::class)
                ->execute();

            return;
        }

        $uploadSession = $graph->createRequest('POST', $endpoint . '/createUploadSession')
            ->attachBody([
                'AttachmentItem' => [
                    'attachmentType' => 'file',
                    'name' => $this->getAttachmentName($attachment),
                    'size' => $fileSize,
                ],
            ])
            ->setReturnType(UploadSession::class)
            ->execute();

        $this->uploadInChunks((string) $uploadSession->getUploadUrl(), $content, $fileSize);
    }

    /**
     * Upload the attachment in chunks of 4 MB, as the upload session requires.
     */
    protected function uploadInChunks(string $url, string $content, int $fileSize): void
    {
        $client = new Client();
        $offset = 0;

        foreach (str_split($content, self::MAX_PAYLOAD_MB * self::BYTES_PER_MB) as $chunk) {
            $length = strlen($chunk);

            $client->put($url, [
                'headers' => [
                    'Content-Length' => $length,
                    'Content-Range' => 'bytes ' . $offset . '-' . ($offset + $length - 1) . '/' . $fileSize,
                ],
                'body' => $chunk,
                'allow_redirects' => false,
                'timeout' => 1000,
            ]);

            $offset += $length;
        }
    }

    /**
     * Get body for the message.
     *
     * @param \Symfony\Component\Mime\Email $message
     * @param bool $withAttachments
     * @return array
     */
    protected function getBody(Email $message, $withAttachments = false)
    {
        $from = $this->getFrom($message);

        $messageData = [
            'message' => [
                'from' => reset($from),
                'toRecipients' => $this->getTo($message),
                'ccRecipients' => $this->getCc($message),
                'bccRecipients' => $this->getBcc($message),
                'replyTo' => $this->getReplyTo($message),
                'subject' => (string) $message->getSubject(),
                'body' => [
                    'contentType' => $message->getHtmlBody() === null ? 'text' : 'html',
                    'content' => $this->stringify($message->getHtmlBody() ?? $message->getTextBody()),
                ],
                'importance' => $this->getImportance($message),
            ],
        ];

        if (!$withAttachments) {
            return $messageData;
        }

        $attachments = [];
        foreach ($message->getAttachments() as $attachment) {
            $attachments[] = $this->getAttachmentBody($attachment);
        }

        if (count($attachments) > 0) {
            $messageData['message']['attachments'] = $attachments;
        }

        return $messageData;
    }

    /**
     * Get the payload for a single attachment.
     *
     * @return array
     */
    protected function getAttachmentBody(DataPart $attachment)
    {
        $body = [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => $this->getAttachmentName($attachment),
            'contentType' => $attachment->getContentType(),
            'contentBytes' => base64_encode($this->getPartContent($attachment)),
        ];

        // Embedded images are referenced by the HTML body through their cid, so
        // the content id has to be the one Symfony generated, not a new one.
        if ($attachment->getDisposition() === 'inline') {
            $body['isInline'] = true;
            $body['contentId'] = $attachment->getContentId();
        }

        return $body;
    }

    /**
     * @return string
     */
    protected function getAttachmentName(DataPart $attachment)
    {
        return $attachment->getFilename() ?: $attachment->getContentId();
    }

    /**
     * Attachment contents can be a stream.
     *
     * @return string
     */
    protected function getPartContent(DataPart $attachment)
    {
        return $this->stringify($attachment->getBody());
    }

    /**
     * The mailbox the message is sent from; Graph addresses it by user.
     *
     * @return string
     */
    protected function getSender(Email $message)
    {
        $from = $message->getFrom();

        if (count($from) === 0) {
            throw new RuntimeException('An office365mail message needs a from address');
        }

        return $from[0]->getAddress();
    }

    /**
     * Get the "from" payload field for the API request.
     *
     * @param \Symfony\Component\Mime\Email $message
     * @return array
     */
    protected function getFrom(Email $message)
    {
        return $this->mapAddresses($message->getFrom());
    }

    /**
     * Get the "to" payload field for the API request.
     *
     * @param \Symfony\Component\Mime\Email $message
     * @return array
     */
    protected function getTo(Email $message)
    {
        return $this->mapAddresses($message->getTo());
    }

    /**
     * Get the "Cc" payload field for the API request.
     *
     * @param \Symfony\Component\Mime\Email $message
     * @return array
     */
    protected function getCc(Email $message)
    {
        return $this->mapAddresses($message->getCc());
    }

    /**
     * Get the "Bcc" payload field for the API request.
     *
     * @param \Symfony\Component\Mime\Email $message
     * @return array
     */
    protected function getBcc(Email $message)
    {
        return $this->mapAddresses($message->getBcc());
    }

    /**
     * Get the "replyTo" payload field for the API request.
     *
     * @param \Symfony\Component\Mime\Email $message
     * @return array
     */
    protected function getReplyTo(Email $message)
    {
        return $this->mapAddresses($message->getReplyTo());
    }

    /**
     * Map the message priority onto the Graph importance values.
     *
     * @return string
     */
    protected function getImportance(Email $message)
    {
        $priority = $message->getPriority();

        if ($priority < Email::PRIORITY_NORMAL) {
            return 'high';
        }

        return $priority > Email::PRIORITY_NORMAL ? 'low' : 'normal';
    }

    /**
     * @param array<int, Address> $addresses
     * @return array
     */
    protected function mapAddresses(array $addresses)
    {
        return array_values(array_map(function (Address $address) {
            $emailAddress = ['address' => $address->getAddress()];

            if ($address->getName() !== '') {
                $emailAddress['name'] = $address->getName();
            }

            return ['emailAddress' => $emailAddress];
        }, $addresses));
    }

    /**
     * Message bodies and attachment contents can be streams.
     *
     * @param resource|string|null $body
     * @return string
     */
    protected function stringify($body)
    {
        if (is_resource($body)) {
            return (string) stream_get_contents($body);
        }

        return (string) $body;
    }

    /**
     * @return float
     */
    protected function sizeInMb(string $content)
    {
        return strlen($content) / self::BYTES_PER_MB;
    }

    protected function getAccessToken()
    {
        $guzzle = new Client();
        $url = 'https://login.microsoftonline.com/' . config('office365mail.tenant') . '/oauth2/v2.0/token';
        $token = json_decode($guzzle->post($url, [
            'form_params' => [
                'client_id' => config('office365mail.client_id'),
                'client_secret' => config('office365mail.client_secret'),
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ],
        ])->getBody()->getContents());

        return $token->access_token;
    }

    /**
     * Get the string representation of the transport.
     *
     * @return string
     */
    public function __toString(): string
    {
        return 'office365mail';
    }
}
