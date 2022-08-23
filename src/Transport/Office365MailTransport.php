<?php

namespace Office365Mail\Transport;

use Microsoft\Graph\Graph;
use Microsoft\Graph\Model\UploadSession;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;

use Symfony\Component\Mime\Email;
use Illuminate\Support\Str;

class Office365MailTransport extends AbstractTransport
{

    public function __construct()
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    // public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {

        // $this->beforeSendPerformed($message);
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $graph = new Graph();

        $graph->setAccessToken($this->getAccessToken());

        // Special treatment if the message has too large attachments
        $messageBody = $this->getBody($email, !!$email->getAttachments());
        $messageBodySizeMb = json_encode($messageBody);
        $messageBodySizeMb = strlen($messageBodySizeMb);
        $messageBodySizeMb = $messageBodySizeMb / 1048576; //byte -> mb

        if ($messageBodySizeMb >= 4) {
            unset($messageBody);
            $graphMessage = $graph->createRequest("POST", "/users/" . $email->getFrom()[0]->getAddress() . "/messages")
                ->attachBody($this->getBody($email))
                ->setReturnType(\Microsoft\Graph\Model\Message::class)
                ->execute();

            foreach ($email->getAttachments() as $attachment) {
                $fileName = $attachment->getPreparedHeaders()->getHeaderParameter('Content-Disposition', 'filename');
                $content = $attachment->getBody();
                $fileSize = strlen($content);
                $size = $fileSize / 1048576; //byte -> mb
                $id = Str::random(10);
                $attachmentMessage = [
                    'AttachmentItem' => [
                        'attachmentType' => 'file',
                        'name' => $fileName,
                        'size' => strlen($content)
                    ]
                ];

                if ($size <= 3) { //ErrorAttachmentSizeShouldNotBeLessThanMinimumSize if attachment <= 3mb, then we need to add this
                    $attachmentBody = [
                        "@odata.type" => "#microsoft.graph.fileAttachment",
                        "name" => $attachment->getPreparedHeaders()->getHeaderParameter('Content-Disposition', 'filename'),
                        "contentType" => $attachment->getPreparedHeaders()->get('Content-Type')->getValue(),
                        "contentBytes" => base64_encode($attachment->getBody()),
                        'contentId'    => $id
                    ];

                    $addAttachment = $graph->createRequest("POST", "/users/" . $email->getFrom()[0]->getAddress() . "/messages/" . $graphMessage->getId() . "/attachments")
                        ->attachBody($attachmentBody)
                        ->setReturnType(UploadSession::class)
                        ->execute();
                } else {
                    //upload the files in chunks of 4mb....
                    $uploadSession = $graph->createRequest("POST", "/users/" . $email->getFrom()[0]->getAddress() . "/messages/" . $graphMessage->getId() . "/attachments/createUploadSession")
                        ->attachBody($attachmentMessage)
                        ->setReturnType(UploadSession::class)
                        ->execute();

                    $fragSize =  1024 * 1024 * 4; //4mb at once...
                    $numFragments = ceil($fileSize / $fragSize);
                    $contentChunked = str_split($content, $fragSize);
                    $bytesRemaining = $fileSize;

                    $i = 0;
                    while ($i < $numFragments) {
                        $chunkSize = $numBytes = $fragSize;
                        $start = $i * $fragSize;
                        $end = $i * $fragSize + $chunkSize - 1;
                        if ($bytesRemaining < $chunkSize) {
                            $chunkSize = $numBytes = $bytesRemaining;
                            $end = $fileSize - 1;
                        }
                        $data = $contentChunked[$i];
                        $content_range = "bytes " . $start . "-" . $end . "/" . $fileSize;
                        $headers = [
                            "Content-Length" => $numBytes,
                            "Content-Range" => $content_range
                        ];
                        $client = new \GuzzleHttp\Client();
                        $tmp = $client->put($uploadSession->getUploadUrl(), [
                            'headers'         => $headers,
                            'body'            => $data,
                            'allow_redirects' => false,
                            'timeout'         => 1000
                        ]);
                        $result = $tmp->getBody() . '';
                        $result = json_decode($result); //if body == empty, then the file was successfully uploaded
                        $bytesRemaining = $bytesRemaining - $chunkSize;
                        $i++;
                    }
                }
            }

            //definetly send the message
            $graph->createRequest("POST", "/users/" . $email->getFrom()[0]->getAddress() . "/messages/" . $graphMessage->getId() . "/send")->execute();
        } else {
            $graphMessage = $graph->createRequest("POST", "/users/" . $email->getFrom()[0]->getAddress() . "/sendmail")
                ->attachBody($messageBody)
                ->setReturnType(\Microsoft\Graph\Model\Message::class)
                ->execute();
        }
    }

    /**
     * Get body for the message.
     *
     * @param Symfony\Component\Mime\Email $message
     * @param bool $withAttachments
     * @return array
     */

    protected function getBody(Email $message, $withAttachments = false)
    {
        $messageData = [
            'from' => [
                'emailAddress' => $message->getFrom()[0]
            ],
            'toRecipients' => $this->getTo($message),
            'ccRecipients' => $this->getCc($message),
            'bccRecipients' => $this->getBcc($message),
            'replyTo' => $this->getReplyTo($message),
            'subject' => $message->getSubject(),
            'body' => [
                'contentType' => $message->getHtmlBody() ? 'html' : 'text',
                'content' => $message->getHtmlBody() ? $message->getHtmlBody() : $message->getTextBody()
            ]
        ];
        $messageData = ['message' => $messageData];

        if ($withAttachments) {
            //add attachments if any
            $attachments = [];
            foreach ($message->getAttachments() as $attachment) {
                $headers = $attachment->getPreparedHeaders();
                $attachments[] = [
                    "@odata.type" => "#microsoft.graph.fileAttachment",
                    "name" => $headers->getHeaderParameter('Content-Disposition', 'filename'),
                    "contentType" => $headers->get('Content-Type')->getValue(),
                    "contentBytes" => base64_encode($attachment->getBody()),
                    'contentId'    => Str::random(10)
                ];
            }
            if (count($attachments) > 0) {
                $messageData['message']['attachments'] = $attachments;
            }
        }

        return $messageData;
    }

    /**
     * Get the "to" payload field for the API request.
     *
     * @param Symfony\Component\Mime\Email $message
     * @return string
     */
    protected function getTo(Email $message)
    {
        return collect((array) $message->getTo())->map(function ($address) {
            return $address->getName() ? [
                'emailAddress' => [
                    'address' => $address->getAddress(),
                    'name' => $address->getName()
                ]
            ] : [
                'emailAddress' => [
                    'address' => $address->getAddress()
                ]
            ];
        })->values()->toArray();
    }

    /**
     * Get the "Cc" payload field for the API request.
     *
     * @param Symfony\Component\Mime\Email $message
     * @return string
     */
    protected function getCc(Email $message)
    {
        return collect((array) $message->getCc())->map(function ($address) {
            return $address->getName() ? [
                'emailAddress' => [
                    'address' => $address->getAddress(),
                    'name' => $address->getName()
                ]
            ] : [
                'emailAddress' => [
                    'address' => $address->getAddress()
                ]
            ];
        })->values()->toArray();
    }

    /**
     * Get the "replyTo" payload field for the API request.
     *
     * @param Symfony\Component\Mime\Email $message
     * @return string
     */
    protected function getReplyTo(Email $message)
    {
        return collect((array) $message->getReplyTo())->map(function ($address) {
            return $address->getName() ? [
                'emailAddress' => [
                    'address' => $address->getAddress(),
                    'name' => $address->getName()
                ]
            ] : [
                'emailAddress' => [
                    'address' => $address->getAddress()
                ]
            ];
        })->values()->toArray();
    }

    /**
     * Get the "Bcc" payload field for the API request.
     *
     * @param Symfony\Component\Mime\Email $message
     * @return string
     */
    protected function getBcc(Email $message)
    {
        return collect((array) $message->getBcc())->map(function ($address) {
            return $address->getName() ? [
                'emailAddress' => [
                    'address' => $address->getAddress(),
                    'name' => $address->getName()
                ]
            ] : [
                'emailAddress' => [
                    'address' => $address->getAddress()
                ]
            ];
        })->values()->toArray();
    }

    /**
     * Get all of the contacts for the message.
     *
     * @param Symfony\Component\Mime\Email $message
     * @return array
     */
    protected function allContacts(Email $message)
    {
        return array_merge(
            (array) $message->getTo(),
            (array) $message->getCc(),
            (array) $message->getBcc(),
            (array) $message->getReplyTo()
        );
    }

    protected function getAccessToken()
    {
        $guzzle = new \GuzzleHttp\Client();
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
